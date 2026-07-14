-- ============================================================
-- E-REQ: Migrasi tabel inti (Fase 1 revisi, Day 1)
-- Target: SQL Server. Desain: docs/skema-database.md
-- Status: siap dijalankan; FK ke tabel aplikasi eksisting masih
-- dikomentari sampai nama tabel asli BIPROO dikonfirmasi.
-- ============================================================

-- 1. Hasil screening dari AI microservice (audit trail per A3.4)
CREATE TABLE screening_results (
    id               BIGINT IDENTITY(1,1) PRIMARY KEY,
    application_id   BIGINT        NOT NULL,
    screening_job_id VARCHAR(64)   NOT NULL,
    status           VARCHAR(20)   NOT NULL,
    score_overall    DECIMAL(5,4)  NULL,
    score_skill      DECIMAL(5,4)  NULL,
    score_pendidikan DECIMAL(5,4)  NULL,
    score_pengalaman DECIMAL(5,4)  NULL,
    extracted_json   NVARCHAR(MAX) NULL,
    flags_json       NVARCHAR(MAX) NULL,
    provider         VARCHAR(50)   NOT NULL,
    model_version    VARCHAR(100)  NOT NULL,
    created_at       DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT ck_screening_status
        CHECK (status IN ('success','failed_extraction','failed_provider'))
    -- , CONSTRAINT fk_screening_app FOREIGN KEY (application_id) REFERENCES applications(id)
);
CREATE INDEX ix_screening_app ON screening_results(application_id, created_at);
CREATE INDEX ix_screening_retry ON screening_results(status) WHERE status = 'failed_extraction';

-- 2. Riwayat tahapan kandidat (append-only; tulang punggung KPI & audit)
CREATE TABLE candidate_stage_history (
    id             BIGINT IDENTITY(1,1) PRIMARY KEY,
    application_id BIGINT        NOT NULL,
    stage          VARCHAR(30)   NOT NULL,
    status         VARCHAR(20)   NOT NULL,
    actor          VARCHAR(100)  NOT NULL,
    note           NVARCHAR(500) NULL,
    created_at     DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT ck_stage CHECK (stage IN (
        'upload_cv','ai_verification','online_assessment','gate_1',
        'penjadwalan','interview_online','gate_2','berkas_kontrak'
    )),
    CONSTRAINT ck_stage_status CHECK (status IN (
        'entered','passed','failed','flagged','retry_queued'
    ))
    -- , CONSTRAINT fk_stage_app FOREIGN KEY (application_id) REFERENCES applications(id)
);
CREATE INDEX ix_stage_history_app ON candidate_stage_history(application_id, created_at);
CREATE INDEX ix_stage_history_stage ON candidate_stage_history(stage, created_at);

-- 3. Antrian email (agar kegagalan SMTP tidak menghambat alur utama, A2.5)
CREATE TABLE email_queue (
    id           BIGINT IDENTITY(1,1) PRIMARY KEY,
    to_email     VARCHAR(255)  NOT NULL,   -- 'to' kata kunci SQL, dihindari
    template     VARCHAR(50)   NOT NULL,
    payload_json NVARCHAR(MAX) NOT NULL,
    status       VARCHAR(20)   NOT NULL DEFAULT 'pending',
    attempts     INT           NOT NULL DEFAULT 0,
    last_error   NVARCHAR(500) NULL,
    created_at   DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
    sent_at      DATETIME2     NULL,

    CONSTRAINT ck_email_status CHECK (status IN ('pending','sent','failed')),
    CONSTRAINT ck_email_template CHECK (template IN (
        'konfirmasi_registrasi','undangan_interview','hasil_gate','pengingat_h1'
    ))
);
CREATE INDEX ix_email_pending ON email_queue(status, created_at) WHERE status = 'pending';

-- 4. Kolom konfigurasi gate pada tabel lowongan eksisting (jalankan
--    setelah nama tabel lowongan/FPK BIPROO dikonfirmasi):
-- ALTER TABLE jobs ADD bobot_json NVARCHAR(MAX) NULL, threshold_json NVARCHAR(MAX) NULL;

-- 5. Penegakan append-only stage_history (jalankan saat deploy,
--    sesuaikan nama user database aplikasi):
-- DENY UPDATE, DELETE ON candidate_stage_history TO [ereq_app_user];
