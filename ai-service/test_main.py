import time

import fitz
import httpx
import pytest
from fastapi.testclient import TestClient

import main
from main import app

VALID_BODY = {
    "job_id_internal": 1,
    "cv_file_url": "https://example.com/cv/1.pdf",
    "job_requirement": {
        "skill": "PHP, SQL Server",
        "pendidikan": "S1 Informatika",
        "pengalaman": "2 tahun web development",
        "deskripsi": "Backend developer untuk sistem internal",
    },
    "callback_url": "https://example.com/api/screening/callback",
    "callback_token": "token-uji",
}


def _pdf_sintetis() -> bytes:
    """PDF kecil dengan teks cukup panjang supaya lolos ambang ekstraksi."""
    doc = fitz.open()
    page = doc.new_page()
    for i in range(8):
        page.insert_text((72, 72 + 14 * i), f"Baris pengalaman kerja nomor {i}: backend developer PHP dan SQL Server.")
    data = doc.tobytes()
    doc.close()
    return data


SAMPLE_PDF = _pdf_sintetis()


@pytest.fixture()
def wiring(monkeypatch):
    """Mock unduhan CV + tangkap callback; kembalikan daftar callback terkirim."""
    terkirim = []
    monkeypatch.setattr(main, "_unduh_cv", lambda url, token: SAMPLE_PDF)
    monkeypatch.setattr(main, "_kirim_callback", lambda url, token, body: terkirim.append((url, token, body)))
    monkeypatch.setattr(main, "retry_extraction", [])
    return terkirim


class FakeProvider:
    """Provider palsu: gagal `fail_first` kali (429), lalu sukses."""

    def __init__(self, fail_first: int = 0):
        self.fail_first = fail_first
        self.calls = 0

    def embed(self, texts):
        self.calls += 1
        if self.calls <= self.fail_first:
            req = httpx.Request("POST", "https://fake")
            resp = httpx.Response(429, request=req)
            raise httpx.HTTPStatusError("rate limited", request=req, response=resp)
        return [[0.1, 0.2, 0.3] for _ in texts]


def wait_done(client, job_id, timeout=5.0):
    deadline = time.time() + timeout
    while time.time() < deadline:
        job = client.get(f"/screening/{job_id}").json()
        if job["status"] in ("done", "failed_provider", "failed_extraction"):
            return job
        time.sleep(0.05)
    raise TimeoutError(f"job masih {job['status']}")


def test_screening_queues_and_calls_embedding(wiring):
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        resp = client.post("/screening", json=VALID_BODY)
        assert resp.status_code == 202
        job = wait_done(client, resp.json()["screening_job_id"])
        assert job["status"] == "done"
        assert job["embedding_dims"] == [3, 3, 3]  # 3 field ter-embed
        assert app.state.provider.calls == 1
        # CV benar-benar terekstrak sebelum embedding
        assert job["extracted"]["metode"] == "text-layer"
        assert job["extracted"]["n_karakter"] > 200


def test_rate_limit_retries_with_backoff(wiring, monkeypatch):
    monkeypatch.setattr(main, "RETRY_BASE_DELAY", 0)
    app.state.provider = FakeProvider(fail_first=2)  # 429 dua kali, lalu sukses
    with TestClient(app) as client:
        resp = client.post("/screening", json=VALID_BODY)
        job = wait_done(client, resp.json()["screening_job_id"])
        assert job["status"] == "done"
        assert job["attempts"] == 3


def test_provider_exhausted_marks_failed_not_lost(wiring, monkeypatch):
    monkeypatch.setattr(main, "RETRY_BASE_DELAY", 0)
    app.state.provider = FakeProvider(fail_first=99)  # tidak pernah pulih
    with TestClient(app) as client:
        resp = client.post("/screening", json=VALID_BODY)
        job = wait_done(client, resp.json()["screening_job_id"])
        assert job["status"] == "failed_provider"  # ditunda, tercatat, tidak hilang
        assert job["attempts"] == main.RETRY_ATTEMPTS


# --- Wiring Day 1: ekstraksi + callback + antrian ulang ---

def test_callback_sukses_membawa_kontrak_a31(wiring):
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        wait_done(client, job_id)

    (url, token, body), = wiring
    assert url == VALID_BODY["callback_url"]
    assert token == "token-uji"
    assert body["screening_job_id"] == job_id
    assert body["job_id_internal"] == 1  # echo utk mapping lamaran di CI4
    assert body["status"] == "success"
    assert set(body["scores"]) == {"overall", "skill", "pendidikan", "pengalaman"}
    assert body["extracted_fields"]["metode"] == "text-layer"


def test_cv_gambar_jadi_failed_extraction_dan_masuk_antrian_ulang(wiring, monkeypatch):
    monkeypatch.setattr(main, "_unduh_cv", lambda url, token: b"\xff\xd8\xff\xe0jpeg")
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        job = wait_done(client, job_id)

    assert job["status"] == "failed_extraction"
    assert job_id in main.retry_extraction  # tidak hilang, siap OCR Day 2
    (_, _, body), = wiring
    assert body["status"] == "failed_extraction"


def test_unduh_gagal_jadi_failed_extraction(wiring, monkeypatch):
    def boom(url, token):
        raise httpx.ConnectError("CI4 tidak terjangkau")

    monkeypatch.setattr(main, "_unduh_cv", boom)
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        job = wait_done(client, job_id)

    assert job["status"] == "failed_extraction"
    assert job_id in main.retry_extraction


def test_callback_gagal_tidak_menjatuhkan_worker(wiring, monkeypatch):
    def boom(url, token, body):
        raise httpx.ConnectError("callback mati")

    monkeypatch.setattr(main, "_kirim_callback", boom)
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        job = wait_done(client, job_id)
        # job selesai walau callback gagal; kegagalan tercatat utk ditelusuri
        assert job["status"] == "done"
        assert job["callback"].startswith("failed")
        # worker masih hidup: job berikutnya tetap diproses
        monkeypatch.setattr(main, "_kirim_callback", lambda u, t, b: None)
        job2 = wait_done(client, client.post("/screening", json=VALID_BODY).json()["screening_job_id"])
        assert job2["status"] == "done"


def test_polling_tidak_membocorkan_token_dan_teks_cv(wiring):
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        job = wait_done(client, job_id)

    for rahasia in ("token", "cv_text", "texts", "callback_url", "cv_file_url"):
        assert rahasia not in job


def test_screening_rejects_invalid_body():
    with TestClient(app) as client:
        assert client.post("/screening", json={"job_id_internal": 1}).status_code == 422


def test_unknown_job_returns_404():
    with TestClient(app) as client:
        assert client.get("/screening/tidak-ada").status_code == 404


def test_health():
    with TestClient(app) as client:
        assert client.get("/health").json() == {"status": "ok"}


# --- Chatbot /chat ---

class FakeChatProvider:
    def __init__(self, answer="Lamaranmu di tahap Assessment."):
        self.answer = answer
        self.last = None

    def generate(self, system, history, question):
        self.last = {"system": system, "history": history, "question": question}
        return self.answer


def test_chat_returns_grounded_answer():
    app.state.chat_provider = FakeChatProvider("Kamu di tahap Screening CV (AI).")
    with TestClient(app) as client:
        resp = client.post("/chat", json={
            "question": "sampai tahap mana lamaran saya?",
            "context": 'Lamaran "Backend": Screening CV (AI): berjalan',
        })
        assert resp.status_code == 200
        assert resp.json()["answer"] == "Kamu di tahap Screening CV (AI)."
        # konteks status benar-benar masuk ke system prompt (bukti grounding)
        assert "Screening CV" in app.state.chat_provider.last["system"]


def test_chat_forwards_history():
    fake = FakeChatProvider()
    app.state.chat_provider = fake
    with TestClient(app) as client:
        client.post("/chat", json={
            "question": "kenapa?",
            "context": "x",
            "history": [{"role": "user", "text": "halo"}, {"role": "model", "text": "hai"}],
        })
        assert fake.last["history"] == [
            {"role": "user", "text": "halo"},
            {"role": "model", "text": "hai"},
        ]


def test_chat_rejects_empty_question():
    app.state.chat_provider = FakeChatProvider()
    with TestClient(app) as client:
        assert client.post("/chat", json={"question": "   ", "context": "x"}).status_code == 400


def test_chat_llm_failure_returns_502():
    class Boom:
        def generate(self, *a):
            raise RuntimeError("api down")

    app.state.chat_provider = Boom()
    with TestClient(app) as client:
        assert client.post("/chat", json={"question": "halo", "context": "x"}).status_code == 502
