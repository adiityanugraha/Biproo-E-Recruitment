# Desain Skema Tabel Inti (Fase 0 - belum dimigrasikan)

Desain untuk SQL Server, mengikuti Blueprint A7. Baru dua tabel yang dirancang
detail sesuai tugas Fase 0 Day 2; tabel lain (candidates, jobs, applications,
interviews, email_queue) menyusul saat modulnya dibangun.

## screening_results

Satu baris per hasil screening dari AI microservice (via callback). Menyimpan
breakdown skor + provider + versi model sebagai **audit trail** (A3.4): setiap
skor bisa ditelusuri dihasilkan model apa.

```sql
CREATE TABLE screening_results (
    id              BIGINT IDENTITY(1,1) PRIMARY KEY,
    application_id  BIGINT       NOT NULL,  -- FK -> applications(id)
    screening_job_id VARCHAR(64) NOT NULL,  -- id dari FastAPI, penghubung request-callback
    status          VARCHAR(20)  NOT NULL,  -- success | failed_extraction | failed_provider
    score_overall   DECIMAL(5,4) NULL,      -- 0.0000 - 1.0000, NULL bila gagal
    score_skill     DECIMAL(5,4) NULL,
    score_pendidikan DECIMAL(5,4) NULL,
    score_pengalaman DECIMAL(5,4) NULL,
    extracted_json  NVARCHAR(MAX) NULL,     -- 3 field hasil strukturisasi
    flags_json      NVARCHAR(MAX) NULL,     -- soft-flag zona abu-abu
    provider        VARCHAR(50)  NOT NULL,  -- mis. 'gemini'
    model_version   VARCHAR(100) NOT NULL,  -- mis. 'gemini-embedding-001'
    created_at      DATETIME2    NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT ck_screening_status
        CHECK (status IN ('success','failed_extraction','failed_provider'))
);

CREATE INDEX ix_screening_app ON screening_results(application_id, created_at);
```

Catatan desain:

- Satu application bisa punya **beberapa baris** (proses ulang setelah
  `failed_extraction`) - riwayat tidak ditimpa, baris terbaru = hasil berlaku.
- `status` disimpan di sini (tidak ada di A7) supaya antrian proses ulang CI4
  cukup query `WHERE status = 'failed_extraction'` tanpa tabel tambahan.

## candidate_stage_history (append-only)

Tulang punggung sistem: sumber kebenaran posisi kandidat, bahan audit, dan
sumber data KPI (A8). **Tidak pernah di-UPDATE/DELETE, hanya INSERT.**

```sql
CREATE TABLE candidate_stage_history (
    id             BIGINT IDENTITY(1,1) PRIMARY KEY,
    application_id BIGINT        NOT NULL,  -- FK -> applications(id)
    stage          VARCHAR(30)   NOT NULL,
    status         VARCHAR(20)   NOT NULL,
    actor          VARCHAR(100)  NOT NULL,  -- 'system' atau id/nama recruiter
    note           NVARCHAR(500) NULL,
    created_at     DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT ck_stage CHECK (stage IN (
        'upload_cv', 'ai_verification', 'online_assessment', 'gate_1',
        'penjadwalan', 'interview_online', 'gate_2', 'berkas_kontrak'
    )),
    CONSTRAINT ck_stage_status CHECK (status IN (
        'entered',      -- kandidat masuk tahap ini
        'passed',       -- lolos tahap
        'failed',       -- tidak lolos
        'flagged',      -- soft-flag, menunggu review manual
        'retry_queued'  -- khusus ai_verification: gagal ekstrak, antri proses ulang
    ))
);

CREATE INDEX ix_stage_history_app ON candidate_stage_history(application_id, created_at);
CREATE INDEX ix_stage_history_stage ON candidate_stage_history(stage, created_at);
```

Catatan desain:

- Nama `stage` mengikuti alur 6 tahap di PPT (Bagian C blueprint): Upload CV →
  AI Verification → Online Assessment → Gate → Penjadwalan → Interview Online →
  Gate → Berkas + Kontrak.
- Append-only ditegakkan lewat disiplin aplikasi (model CI4 tanpa method
  update/delete) + `DENY UPDATE, DELETE` pada user database aplikasi saat
  deploy.
- KPI (A8) dihitung dari selisih `created_at` antar baris per
  `application_id` - tidak butuh kolom tambahan.
- Index kedua (`stage, created_at`) untuk KPI throughput per tahap per hari.

## Titik Pencatatan Timestamp (Fase 0 Day 3)

Aturan: **satu INSERT ke `stage_history` di setiap kejadian di bawah** - tidak
kurang (KPI bolong), tidak lebih (noise). Semua pencatatan dilakukan CI4;
FastAPI tidak menulis DB (stateless).

| Kejadian | stage | status | actor |
|---|---|---|---|
| Registrasi + CV tersimpan | `upload_cv` | `entered` | system |
| CI4 kirim request ke FastAPI (dapat 202) | `ai_verification` | `entered` | system |
| Callback diterima: skor sukses | `ai_verification` | `passed` | system |
| Callback: `failed_extraction` (antri proses ulang) | `ai_verification` | `retry_queued` | system |
| Kandidat mulai assessment | `online_assessment` | `entered` | system |
| Hasil assessment masuk | `online_assessment` | `passed`/`failed` | system |
| Evaluasi Gate 1: lolos/tidak otomatis | `gate_1` | `passed`/`failed` | system |
| Evaluasi Gate 1: zona tengah | `gate_1` | `flagged` | system |
| Recruiter memutuskan kandidat ber-flag | `gate_1` | `passed`/`failed` | recruiter |
| Recruiter menjadwalkan interview (meeting dibuat) | `penjadwalan` | `entered` | recruiter |
| Jadwal interview tiba / interview berlangsung | `interview_online` | `entered` | system |
| Recruiter isi hasil interview | `interview_online` | `passed`/`failed` | recruiter |
| Keputusan akhir Gate 2 (selalu recruiter) | `gate_2` | `passed`/`failed` | recruiter |
| Kandidat masuk tahap berkas + kontrak | `berkas_kontrak` | `entered` | system |
| Berkas lengkap / kontrak ditandatangani | `berkas_kontrak` | `passed` | recruiter |

Catatan:

- `ai_verification` bisa punya beberapa siklus `retry_queued` → `passed`
  untuk CV yang diproses ulang; baris terakhir = status berlaku.
- `gate_1/flagged` lalu keputusan recruiter menghasilkan **dua baris** - jejak
  human-in-the-loop terlihat di audit (siapa memutuskan, berapa lama review).
- KPI #1 (waktu screening) = `ai_verification/entered` → `passed`;
  KPI #4/#5 = `upload_cv/entered` → `gate_1`/`gate_2` final.
