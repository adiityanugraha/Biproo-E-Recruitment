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
| `GEMINI_API_KEY` | untuk embedding & chat | API key dari https://aistudio.google.com/apikey |
| `EMBEDDING_MODEL` | tidak | default `gemini-embedding-001` |
| `GENERATION_MODEL` | tidak | model chatbot, default `gemini-2.5-flash` |
| `RETRY_BASE_DELAY` | tidak | detik backoff dasar retry provider, default `2` (deret 2-4-8) |
| `WHISPER_MODEL` | tidak | ukuran model transkripsi, default `medium` |
| `WHISPER_DEVICE` | tidak | `cpu` (default) atau `cuda` |
| `WHISPER_COMPUTE` | tidak | `int8` (default) untuk CPU, `float16` untuk cuda |

Tanpa `GEMINI_API_KEY`, test live embedding otomatis di-skip; test lain tetap jalan.

## Transkripsi wawancara

Rekaman ditranskripsi **di komputer sendiri** dengan faster-whisper; Gemini cuma
cadangan bila faster-whisper belum terpasang atau hasilnya tidak layak. Alasannya
kuota: jatah gratis Gemini 20 panggilan sehari, dan transkripsi tidak butuh
penalaran sama sekali - cuma menyalin ucapan.

- **Unduhan pertama ~1,5 GB** (model `medium` dari HuggingFace), tersimpan di
  `~/.cache/huggingface`. Sesudah itu tidak butuh jaringan lagi.
- Terukur **3,5x realtime** pada CPU i5-11400H `int8`: wawancara 30 menit selesai
  sekitar 8,5 menit. Jalur ini berjalan di latar - tidak ada yang menunggu di layar.
- `small` (~460 MB, 4,6x realtime) merusak istilah penting dalam bahasa
  Indonesia - "Admin Gudang" jadi "atming gudang", "inbound" jadi "inbond".
  Penilaiannya tetap sama karena modelnya menebak dari konteks, tapi transkrip
  inilah yang ditunjukkan kepada orang yang bertanya kenapa kandidat gugur.
- `WHISPER_DEVICE=cuda` jauh lebih cepat tapi menuntut `cublas64_12.dll` dan
  cuDNN terpasang. Tanpa keduanya, model tetap terbentuk dan baru gagal saat
  menyalin - jadi jangan disetel sebelum yakin.
- Yang lokal **tidak memberi penanda pembicara** (`Pewawancara:`/`Kandidat:`).
  Tiap segmen ditulis satu baris supaya batas gilirannya masih terbaca. Mesin
  yang dipakai dicatat CI4 di `interview_transkrip.model_version`.

Lepas faster-whisper dari `requirements.txt` bila mesinnya tidak sanggup;
seluruh jalur otomatis kembali ke Gemini tanpa perubahan kode.

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

### `POST /chat` (CI4 → FastAPI) - chatbot status kandidat (Fase 3)

Sinkron (bukan 202/callback): user menunggu jawaban. CI4 merakit `context`
dari `candidate_stage_history` milik kandidat yang login; FastAPI menambah
system prompt grounding ketat lalu memanggil Gemini generateContent.

Request body:

```json
{
  "question": "sampai tahap mana lamaran saya?",
  "context": "Lamaran \"Backend Developer\":\n  - CV Terkirim: berjalan\n  ...",
  "history": [
    { "role": "user",  "text": "..." },
    { "role": "model", "text": "..." }
  ]
}
```

Response: `200 OK` → `{ "answer": "..." }`

- `question` kosong → `400`.
- Provider LLM gagal setelah dipanggil → `502` (CI4 menampilkan pesan ramah).
- Grounding ketat: LLM diinstruksikan menjawab HANYA dari `context`; di luar
  topik/data kandidat lain ditolak sopan. `context` hanya berisi lamaran milik
  kandidat yang login (dirakit CI4), jadi tak ada kebocoran antar-kandidat.

### `GET /health`

Balas `{ "status": "ok" }` - untuk pengecekan sederhana bahwa service hidup.
