from fastapi.testclient import TestClient

from main import app

client = TestClient(app)

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
}


def test_screening_returns_202_with_job_id():
    resp = client.post("/screening", json=VALID_BODY)
    assert resp.status_code == 202
    assert resp.json()["screening_job_id"]


def test_screening_rejects_invalid_body():
    resp = client.post("/screening", json={"job_id_internal": 1})
    assert resp.status_code == 422


def test_health():
    assert client.get("/health").json() == {"status": "ok"}
