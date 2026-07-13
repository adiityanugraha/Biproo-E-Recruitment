# E-REQ AI Microservice (FastAPI)

Microservice screening CV untuk sistem E-REQ. Stateless, komunikasi async
dengan aplikasi utama (CodeIgniter 4) via pola **202 + callback** (Blueprint A3.1).

Status: **Fase 0 Day 3.** Endpoint `/screening` membalas 202 + `screening_job_id`,
lalu worker background memanggil API embedding (3 field job requirement sebagai
data dummy) lewat antrian + retry backoff (A3.3). Ekstraksi CV asli, skor, dan
callback dibangun di Fase 1. Progres job bisa dicek via `GET /screening/{id}`.

## Cara Menjalankan

```
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt
.venv\Scripts\uvicorn main:app --reload
```

Test: `.venv\Scripts\python -m pytest`

## Konfigurasi (environment variable)

| Variabel | Wajib | Keterangan |
|---|---|---|
| `GEMINI_API_KEY` | untuk embedding | API key dari https://aistudio.google.com/apikey |
| `EMBEDDING_MODEL` | tidak | default `gemini-embedding-001` |
| `RETRY_BASE_DELAY` | tidak | detik backoff dasar retry provider, default `2` (deret 2-4-8) |

Tanpa `GEMINI_API_KEY`, test live embedding otomatis di-skip; test lain tetap jalan.

Dokumentasi interaktif otomatis: http://127.0.0.1:8000/docs

## Kontrak API

### `POST /screening` (CI4 → FastAPI)

Request body:

```json
{
  "job_id_internal": 123,
  "cv_file_url": "https://.../cv/123.pdf",
  "job_requirement": {
    "skill": "...",
    "pendidikan": "...",
    "pengalaman": "...",
    "deskripsi": "..."
  },
  "callback_url": "https://.../api/screening/callback"
}
```

Response: `202 Accepted`

```json
{ "screening_job_id": "abc123..." }
```

Body tidak valid → `422` dengan detail field yang salah.

### Callback (FastAPI → CI4) - dipakai mulai Fase 1

`POST {callback_url}`

```json
{
  "screening_job_id": "abc123...",
  "status": "success | failed_extraction | failed_provider",
  "scores": {
    "overall": 0.0,
    "skill": 0.0,
    "pendidikan": 0.0,
    "pengalaman": 0.0
  },
  "extracted_fields": { "pengalaman": "...", "skill": "...", "pendidikan": "..." },
  "flags": []
}
```

- `failed_extraction` → CV masuk antrian proses ulang di sisi CI4 (tidak dibuang).
- `failed_provider` → job ditunda (rate limit/error provider), bukan gagal permanen.
- `scores` dan `extracted_fields` bernilai `null` bila status bukan `success`.
- `flags` berisi penanda soft-flag (zona abu-abu) untuk review manusia.

### `GET /screening/{screening_job_id}`

Cek progres job (untuk testing/debug internal, bukan bagian kontrak CI4):
`{ "status": "queued|processing|done|failed_provider", "attempts": n, ... }`

### `GET /health`

Balas `{ "status": "ok" }` - untuk pengecekan sederhana bahwa service hidup.
