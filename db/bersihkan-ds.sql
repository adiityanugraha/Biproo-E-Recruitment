/*
 * Menyamarkan data pribadi pada SALINAN basis data E-REQ sebelum diserahkan
 * ke tim DS.
 *
 * Dipanggil oleh snapshot-ds.ps1. Kalau dijalankan sendiri:
 *
 *   sqlcmd -S localhost\SQLEXPRESS -E -I -b -d ereq_ds -i db\bersihkan-ds.sql
 *
 * Flag -I wajib. Basis data ini memakai filtered index (ux_interviews_slot_aktif),
 * dan SQL Server menolak SELURUH UPDATE pada basis data semacam itu kalau
 * QUOTED_IDENTIFIER mati:
 *
 *   Msg 1934 ... SET options have incorrect settings: 'QUOTED_IDENTIFIER'
 *
 * SSMS menyalakannya sendiri, sqlcmd tidak. Tanpa -I skrip ini gagal di
 * perintah pertama dan tidak ada satu pun data yang tersamarkan.
 */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* Penjaga. Basis data asli bernama 'ereq'; salinan selalu berakhiran '_ds'.
   Tanpa ini, satu salah ketik -d berarti data asli yang tersamarkan. */
IF DB_NAME() NOT LIKE '%[_]ds'
    THROW 50001, 'Hanya boleh dijalankan pada salinan (nama berakhiran _ds), bukan basis data asli.', 1;
GO

-- 1. Identitas kandidat. Kolomnya NOT NULL, jadi diisi nilai generik.
--    Domain .invalid dipakai sengaja: TLD itu dijamin tidak pernah ada, jadi
--    kode yang keliru mengirim email ke sana tidak akan sampai ke siapa pun.
UPDATE candidates SET
    nama          = CONCAT('Kandidat ', id),
    email         = CONCAT('kandidat', id, '@contoh.invalid'),
    password_hash = '';

-- 2. Akun recruiter beserta hash sandinya.
UPDATE recruiters SET
    nama          = CONCAT('Recruiter ', id),
    email         = CONCAT('recruiter', id, '@contoh.invalid'),
    password_hash = '';

-- 3. Antrian email: memuat alamat sungguhan di to_email dan nama di
--    payload_json. Tidak ada yang bisa dipakai tim DS dari sini.
DELETE FROM email_queue;

-- 4. Tautan Zoom. Ruangannya masih hidup, jadi ini bukan sekadar data
--    melainkan pintu yang masih bisa dibuka pemegang tautannya.
UPDATE interviews SET
    meeting_id    = NULL,
    join_url      = NULL,
    start_url     = NULL,
    recording_url = NULL;
GO

/* Verifikasi. Harus 0. snapshot-ds.ps1 membaca angka ini dan menolak
   melanjutkan kalau bukan nol, karena percobaan pertama yang gagal karena
   QUOTED_IDENTIFIER tetap menghasilkan .bak yang terlihat wajar. */
SELECT (SELECT COUNT(*) FROM candidates WHERE email NOT LIKE '%@contoh.invalid')
     + (SELECT COUNT(*) FROM recruiters WHERE email NOT LIKE '%@contoh.invalid')
     + (SELECT COUNT(*) FROM email_queue)
     + (SELECT COUNT(*) FROM interviews WHERE join_url IS NOT NULL);
GO
