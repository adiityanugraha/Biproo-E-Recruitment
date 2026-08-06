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


class FakeStrukturLLM:
    """LLM strukturisasi palsu. WAJIB dipasang di tiap test pipeline - tanpa ini
    process_jobs jatuh ke get_chat_provider() dan memanggil Gemini sungguhan."""

    def generate(self, system, history, question):
        return (
            '{"pengalaman":"Backend developer PHP dan SQL Server di PT Contoh 2021-2024",'
            '"skill":"PHP, SQL Server, Git",'
            '"pendidikan":"S1 Teknik Informatika Universitas Contoh"}'
        )


@pytest.fixture()
def wiring(monkeypatch):
    """Mock unduhan CV + LLM strukturisasi + tangkap callback."""
    terkirim = []
    monkeypatch.setattr(main, "_unduh_cv", lambda url, token: SAMPLE_PDF)
    monkeypatch.setattr(main, "_kirim_callback", lambda url, token, body: terkirim.append((url, token, body)))
    monkeypatch.setattr(main, "retry_extraction", [])
    app.state.chat_provider = FakeStrukturLLM()
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
        # 3 bidang CV + 3 bidang lowongan yang berpasangan = 6 vektor
        assert job["embedding_dims"] == [3] * 6
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


# --- Day 3: skor nyata di callback ---

def test_callback_membawa_skor_nyata(wiring):
    """FakeProvider mengembalikan vektor identik utk semua teks -> cosine 1.0."""
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        wait_done(client, job_id)

    (_, _, body), = wiring
    s = body["scores"]
    assert s["overall"] == 1.0                      # vektor identik -> cocok penuh
    assert s["skill"] == s["pendidikan"] == s["pengalaman"] == 1.0
    assert body["status"] == "success"


def test_atribut_sensitif_tidak_ikut_di_embed(wiring, monkeypatch):
    """Alamat/HP/agama dari CV tidak boleh sampai ke provider embedding."""
    class LLMBocor:
        def generate(self, system, history, question):
            return (
                '{"pengalaman":"Kasir di Toko Maju. Alamat: Jl. Melati No. 5. '
                'Agama: Islam. Telepon: 081234567890",'
                '"skill":"Excel","pendidikan":"SMK Negeri 1"}'
            )

    app.state.chat_provider = LLMBocor()
    dikirim = []

    class Perekam:
        def embed(self, texts):
            dikirim.extend(texts)
            return [[0.1, 0.2, 0.3] for _ in texts]

    app.state.provider = Perekam()
    with TestClient(app) as client:
        wait_done(client, client.post("/screening", json=VALID_BODY).json()["screening_job_id"])

    gabung = "\n".join(dikirim)
    for bocor in ("Jl. Melati", "081234567890", "Islam"):
        assert bocor not in gabung, f"atribut sensitif ikut ter-embed: {bocor}"
    assert "Kasir di Toko Maju" in gabung  # kompetensi tetap ikut


def test_bidang_cv_kosong_tidak_dinilai_dan_bobot_dinormalkan(wiring):
    class LLMHanyaPengalaman:
        def generate(self, system, history, question):
            return '{"pengalaman":"Staff gudang 2020-2023","skill":"","pendidikan":""}'

    app.state.chat_provider = LLMHanyaPengalaman()
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        wait_done(client, client.post("/screening", json=VALID_BODY).json()["screening_job_id"])

    (_, _, body), = wiring
    s = body["scores"]
    assert s["pengalaman"] == 1.0
    assert s["skill"] is None and s["pendidikan"] is None  # bukan 0
    assert s["overall"] == 1.0                             # bobot dinormalkan ulang
    assert "bobot_dinormalkan_ulang" in body["flags"]


def test_semua_bidang_kosong_tetap_success_tanpa_skor(wiring):
    """
    LLM menjawab dokumen ini tidak memuat isi CV -> TIDAK ada skor.

    Dulu jawaban itu dibatalkan oleh jalur heading, dan karena PDF-nya tanpa
    heading maka seluruh teks mentah masuk ke pengalaman lalu diberi skor.
    Di produksi hasilnya 0,6567 - tak terbedakan dari CV yang terurai benar
    (0,6568). Kandidat tetap TIDAK digugurkan: status sukses, skornya null,
    dan CI4 mencatatnya sebagai ai_verification/flagged untuk ditinjau manusia.
    """
    class LLMKosong:
        def generate(self, system, history, question):
            return '{"pengalaman":"","skill":"","pendidikan":""}'

    app.state.chat_provider = LLMKosong()
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job = wait_done(client, client.post("/screening", json=VALID_BODY).json()["screening_job_id"])

    assert job["status"] == "done"
    (_, _, body), = wiring
    assert body["status"] == "success"          # kandidat tidak gugur
    assert body["scores"]["overall"] is None    # tapi tidak diberi angka karangan
    assert "tanpa_isi_cv" in body["flags"]
    assert "tidak_dapat_dinilai" in body["flags"]


def test_embedding_gagal_tetap_kirim_callback_failed_provider(wiring, monkeypatch):
    monkeypatch.setattr(main, "RETRY_BASE_DELAY", 0)
    app.state.provider = FakeProvider(fail_first=99)
    with TestClient(app) as client:
        wait_done(client, client.post("/screening", json=VALID_BODY).json()["screening_job_id"])

    (_, _, body), = wiring
    assert body["status"] == "failed_provider"
    assert body["scores"]["overall"] is None


# --- Riwayat kerja bertanda bukti ikut ke CI4 ---

def test_callback_membawa_riwayat_kerja(wiring):
    """
    CI4 menampilkan riwayat ini di halaman review recruiter sebagai penyeimbang
    skor kemiripan (BuktiPengalamanTest di sisi CI4). Kalau ia tidak ikut di
    extracted_fields, kolom di halaman itu diam-diam kosong selamanya.
    """
    class LLMRiwayat:
        def generate(self, system, history, question):
            return (
                '{"pengalaman":"Kasir di Toko Maju 2020-2022","skill":"Excel",'
                '"pendidikan":"SMK Negeri 1","riwayat":['
                '{"jabatan":"Kasir","perusahaan":"Toko Maju","periode":"2020-2022"}]}'
            )

    app.state.chat_provider = LLMRiwayat()
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        wait_done(client, job_id)

    (_, _, body), = wiring
    riwayat = body["extracted_fields"]["riwayat"]
    assert riwayat == [{"jabatan": "Kasir", "perusahaan": "Toko Maju", "periode": "2020-2022"}]
    assert "tanpa_riwayat_kerja" not in body["flags"]


def test_callback_membawa_flag_tanpa_riwayat_kerja(wiring):
    """Penyalin kata kunci: skornya tetap dikirim, flagnya yang memberi konteks."""
    class LLMPenyalin:
        def generate(self, system, history, question):
            return '{"pengalaman":"","skill":"PHP, Laravel, MySQL","pendidikan":"S1 TI"}'

    app.state.chat_provider = LLMPenyalin()
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        wait_done(client, job_id)

    (_, _, body), = wiring
    assert "tanpa_riwayat_kerja" in body["flags"]
    assert body["extracted_fields"]["riwayat"] == []
    assert body["scores"]["overall"] is not None   # skor TIDAK diturunkan flag


def test_strukturisasi_memakai_budget_token_besar_bukan_default_chatbot(wiring, monkeypatch):
    """
    Regresi: strukturisasi memakai provider yang sama dengan chatbot status, dan
    batas 600 token chatbot memotong jawaban di tengah JSON. Pada CV asli 4.893
    karakter jawabannya putus persis di tengah array riwayat, parser gagal, dan
    pipeline diam-diam jatuh ke parser heading yang jauh lebih buruk.
    """
    import main as m
    dicatat = []
    monkeypatch.setattr(m, "get_chat_provider", lambda maks=None, *a, **k: dicatat.append(maks) or _LLMBiasa())
    app.state.chat_provider = None
    app.state.provider = FakeProvider()
    with TestClient(app) as client:
        job_id = client.post("/screening", json=VALID_BODY).json()["screening_job_id"]
        wait_done(client, job_id)

    assert dicatat == [m.MAKS_TOKEN_CV]
    assert m.MAKS_TOKEN_CV >= 4096


class _LLMBiasa:
    def generate(self, system, history, question):
        return '{"pengalaman":"Kasir 2020-2022","skill":"Excel","pendidikan":"SMK","riwayat":[{"jabatan":"Kasir","perusahaan":"Toko Maju","periode":"2020-2022"}]}'


# --- Pertanyaan interview per lowongan ---

VALID_LOWONGAN = {
    "judul": "Admin Gudang",
    "skill": "Administrasi stok, Excel, ketelitian data",
    "pendidikan": "D3 semua jurusan",
    "pengalaman": "1 tahun administrasi gudang/logistik",
    "deskripsi": "Mengelola pencatatan stok masuk-keluar gudang.",
}


class _LLMPertanyaan:
    def __init__(self, jawab):
        self.jawab = jawab
        self.terakhir = None

    def generate(self, system, history, question):
        self.terakhir = {"system": system, "question": question}
        if isinstance(self.jawab, Exception):
            raise self.jawab
        return self.jawab


def test_pertanyaan_dibuat_dari_uraian_lowongan():
    app.state.chat_provider = _LLMPertanyaan(
        '{"pertanyaan":["Ceritakan saat Anda menemukan selisih stok.",'
        '"Bagaimana Anda memastikan pencatatan barang masuk akurat?"]}'
    )
    with TestClient(app) as client:
        r = client.post("/pertanyaan", json=VALID_LOWONGAN)

    assert r.status_code == 200
    assert len(r.json()["pertanyaan"]) == 2
    assert "selisih stok" in r.json()["pertanyaan"][0]


def test_uraian_lowongan_ikut_dikirim_ke_llm():
    llm = _LLMPertanyaan('{"pertanyaan":["a","b","c"]}')
    app.state.chat_provider = llm
    with TestClient(app) as client:
        client.post("/pertanyaan", json=VALID_LOWONGAN)

    q = llm.terakhir["question"]
    assert "Admin Gudang" in q and "Excel" in q and "stok masuk-keluar" in q


def test_larangan_pertanyaan_pribadi_ada_di_system_prompt():
    """
    Pertanyaan usia/agama/status pernikahan bukan cuma tidak sopan - ia sumber
    diskriminasi, dan pipeline ini sudah membuang atribut itu sebelum embedding
    (sanitize.bersihkan). Tidak masuk akal kalau LLM justru menyuruh recruiter
    menanyakannya langsung.
    """
    llm = _LLMPertanyaan('{"pertanyaan":["a","b","c"]}')
    app.state.chat_provider = llm
    with TestClient(app) as client:
        client.post("/pertanyaan", json=VALID_LOWONGAN)

    s = llm.terakhir["system"].lower()
    for dilarang in ("usia", "agama", "suku", "status pernikahan"):
        assert dilarang in s


def test_jumlah_pertanyaan_dibatasi_atas_dan_bawah():
    llm = _LLMPertanyaan('{"pertanyaan":["a","b","c"]}')
    app.state.chat_provider = llm
    with TestClient(app) as client:
        client.post("/pertanyaan", json={**VALID_LOWONGAN, "jumlah": 999})
        assert "Buat tepat 12 pertanyaan" in llm.terakhir["question"]
        client.post("/pertanyaan", json={**VALID_LOWONGAN, "jumlah": 0})
        assert "Buat tepat 3 pertanyaan" in llm.terakhir["question"]


def test_keluaran_llm_kebanyakan_dipotong():
    app.state.chat_provider = _LLMPertanyaan(
        '{"pertanyaan":[' + ",".join(f'"p{i}"' for i in range(50)) + ']}'
    )
    with TestClient(app) as client:
        r = client.post("/pertanyaan", json=VALID_LOWONGAN)

    assert len(r.json()["pertanyaan"]) == 12


def test_judul_kosong_ditolak():
    app.state.chat_provider = _LLMPertanyaan('{"pertanyaan":["a"]}')
    with TestClient(app) as client:
        assert client.post("/pertanyaan", json={**VALID_LOWONGAN, "judul": "  "}).status_code == 400


def test_llm_gagal_jadi_502_tanpa_membocorkan_api_key():
    bocor = RuntimeError(
        "Client error '429' for url 'https://generativelanguage.googleapis.com/v1beta/x?key=RAHASIA123'"
    )
    app.state.chat_provider = _LLMPertanyaan(bocor)
    with TestClient(app) as client:
        r = client.post("/pertanyaan", json=VALID_LOWONGAN)

    assert r.status_code == 502
    assert "RAHASIA123" not in r.text


def test_jawaban_llm_tidak_berbentuk_daftar_ditolak():
    app.state.chat_provider = _LLMPertanyaan('{"pertanyaan":"bukan daftar"}')
    with TestClient(app) as client:
        assert client.post("/pertanyaan", json=VALID_LOWONGAN).status_code == 502


def test_daftar_kosong_ditolak_bukan_dikembalikan_kosong():
    """Set pertanyaan kosong tidak berguna dan akan tersimpan diam-diam di CI4."""
    app.state.chat_provider = _LLMPertanyaan('{"pertanyaan":["", "   "]}')
    with TestClient(app) as client:
        assert client.post("/pertanyaan", json=VALID_LOWONGAN).status_code == 502
