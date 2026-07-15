# Materi Presentasi Minggu 2 - Jumat, 24 Juli 2026

Fase 1 (revisi): Alur + Gate + Email (logika). Estimasi 10-15 menit.

## Slide 1 - Ringkasan Minggu Ini

- Mesin alur seleksi selesai dan teruji: riwayat tahapan, Gate 1 & 2, antrian email (25 test otomatis).
- Email nyata terkirim ke inbox sungguhan (SMTP), bukan simulasi.
- 2 usulan revisi jadwal diajukan hari ini (butuh persetujuan).

## Slide 2 - Yang Dibangun

| Komponen | Fungsi |
|---|---|
| StageLogger + candidate_stage_history | Riwayat tahapan append-only: sumber kebenaran posisi kandidat, audit, dan KPI |
| Gate 1 | Lolos/gagal otomatis di luar ambang; zona tengah wajib review manusia (soft-flag) |
| Gate 2 | Sistem hanya merekomendasikan; keputusan akhir selalu recruiter |
| Mesin email | Antrian + retry 3x + 4 template inti; kegagalan kirim tidak menghambat alur |
| SQL Server | Semua berjalan di database nyata (bukan mock) |

## Slide 3 - Demo Langsung

1. Jalankan `php spark ereq:demo --email <alamat>` - satu kandidat berjalan
   registrasi → AI verification → assessment → Gate 1 (lolos, skor 0.76) →
   penjadwalan; 7 peristiwa tercatat.
2. Tunjukkan riwayat di Azure Data Studio (tabel candidate_stage_history).
3. Buka inbox - 3 email nyata masuk: konfirmasi, hasil gate, undangan interview.

Catatan jujur: skor CV masih dummy - pipeline screening nyata dijadwalkan
minggu 5; kontrak API sudah final sehingga skor nyata tinggal menggantikan.

## Slide 4 - Kualitas & Temuan Internal

Review internal minggu ini menemukan dan memperbaiki:

| Temuan | Status |
|---|---|
| Kebocoran data antar-email dalam batch (payload kandidat A bisa muncul di email kandidat B) | Diperbaiki + test regresi |
| Skor di batas ambang bisa tidak konsisten dengan audit trail (floating point) | Diperbaiki |
| UI/frontend belum terjadwalkan eksplisit | Dijadwalkan minggu 3 (usulan revisi) |
| Penyambungan nyata CI4-AI service belum terjadwalkan | Dijadwalkan minggu 5 |
| Autentikasi, penyimpanan CV, CSRF | Dijadwalkan minggu 3 |
| SMTP | Selesai (akun testing sendiri, pola sama dgn API embedding) |

Carry-over: audit alur BIPROO asli (checklist siap, belum dieksekusi).

## Slide 5 - Usulan Revisi Jadwal (butuh persetujuan)

| Minggu | Fokus |
|---|---|
| 1 (13-17 Jul) | Fondasi - SELESAI |
| 2 (20-24 Jul) | Alur + Gate + Email (logika) - SELESAI hari ini |
| 3 (27-31 Jul) | **BARU: UI/Frontend** (form registrasi, portal kandidat, dashboard recruiter) |
| 4 (3-7 Agu) | Interview Zoom + Chatbot |
| 5 (10-14 Agu) | Pipeline Screening CV (menunggu sampel CV historis DS) |
| 6 (17-21 Agu) | Konsolidasi + **Demo Final: 21 Agustus** (mundur 1 minggu) |

Alasan: (a) screening CV bergantung dataset tim DS yang serah terimanya
bertahap; (b) rencana asli mengasumsikan UI BIPROO bisa dipakai langsung,
padahal akses hanya sebagai user sehingga E-REQ dibangun dari nol dan UI
butuh slot sendiri.

## Slide 6 - Kriteria Selesai Minggu 2: Status

| Kriteria | Status |
|---|---|
| Kandidat testing berjalan registrasi → keputusan Gate 1 (skor dummy) | OK |
| Email terkirim di tiap perubahan status | OK - email nyata, bukan simulasi |
| Gate 1 & Gate 2 dengan soft-flag zona tengah | OK |
| 4 template email inti | OK |
| Migrasi tabel inti di SQL Server | OK (naik + rollback teruji) |

## Slide 7 - Minggu Depan: UI/Frontend

Form registrasi + upload CV, portal status kandidat, dashboard recruiter
(approve/reject kandidat ber-flag), login kandidat/recruiter. Target 31 Juli:
alur yang hari ini didemokan via terminal bisa dijalankan penuh lewat browser,
tampilan mengikuti desain BIPROO.

---

**Antisipasi pertanyaan:**

- *"Kenapa mundur 1 minggu?"* - Bukan keterlambatan pengerjaan: dua asumsi
  rencana awal tidak berlaku (dataset DS belum siap di minggu 2; UI BIPROO
  tidak bisa dipakai langsung). Logika inti justru selesai lebih cepat dan
  sudah teruji.
- *"Kenapa skor masih dummy?"* - Menunggu sampel CV historis DS; kontrak API
  final sejak minggu 1 sehingga penggantian ke skor nyata tidak mengubah
  aplikasi utama.
- *"Email pakai apa?"* - SMTP akun testing sendiri (pola sama dengan API
  embedding gratis); produksi tinggal ganti kredensial tanpa ubah kode.
