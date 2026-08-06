# Setup Basis Data untuk Tim DS

Panduan ini menjawab satu pertanyaan: **bagaimana supaya basis data di laptop
Anda sama persis dengan yang dipakai tim aplikasi, dan tetap sama seterusnya.**

Untuk menjalankan webnya sendiri (PHP, ekstensi, `ai-service`, Zoom, email),
lihat [README.md](../README.md). Berkas ini khusus soal basis data.

---

## Prinsip yang membuat semuanya bekerja

Skema tabel **tidak** hidup di basis data siapa pun. Ia hidup di git, sebagai
berkas migrasi di [`webapp/app/Database/Migrations/`](../webapp/app/Database/Migrations).

Basis data di laptop Anda cuma hasil menjalankan berkas-berkas itu berurutan.
Karena itu Anda tidak perlu salinan basis data dari siapa pun: cukup `git pull`
lalu jalankan migrasinya, dan tabel yang terbentuk identik sampai ke tipe kolom
dan indeksnya.

Konsekuensinya satu, dan ini yang paling sering dilanggar:

> **Jangan pernah mengubah skema lewat SSMS.** Setiap `CREATE TABLE`,
> `ALTER TABLE`, atau `DROP COLUMN` yang Anda ketik langsung di SSMS hanya ada
> di laptop Anda. Besok basis data kita beda lagi. Cara yang benar ada di
> bagian [Kalau Anda perlu mengubah skema](#kalau-anda-perlu-mengubah-skema).

---

## Sekali di awal

### 1. Basis data kosong

Harus **SQL Server** (Express cukup). Jangan MySQL, alasannya ada di bagian
[Jebakan](#jebakan-yang-sudah-pernah-memakan-korban).

```sql
CREATE DATABASE ereq;
GO
CREATE LOGIN ereq_app WITH PASSWORD = 'sandi-anda-sendiri';
GO
USE ereq;
CREATE USER ereq_app FOR LOGIN ereq_app;
ALTER ROLE db_owner ADD MEMBER ereq_app;
GO
```

Sandinya bebas, tidak perlu sama dengan siapa pun. Basis data ini milik laptop
Anda sendiri.

### 2. Konfigurasi

`webapp/.env` tidak ikut di git karena memuat kredensial, jadi Anda membuatnya
sendiri:

```bash
cd webapp && cp env .env
```

Isi bagian basis datanya:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'
app.indexPage = ''

database.default.hostname = localhost
database.default.database = ereq
database.default.username = ereq_app
database.default.password = sandi-anda-sendiri
database.default.DBDriver = SQLSRV
database.default.port     = 1433
database.default.encrypt  = false
```

Kunci lain (`zoom.*`, `aiservice.*`, `email.*`) opsional. Tanpa itu webnya tetap
terbuka, cuma fiturnya yang mati. Lihat [README.md](../README.md).

### 3. Bangun skemanya

```bash
cd webapp && composer install && php spark migrate
```

Selesai. Tabelnya sudah sama dengan tim aplikasi.

### 4. Isi data uji

Skema kosong tidak bisa dites. Tiga sumber data, semuanya menghasilkan isi yang
sama di mesin siapa pun:

```bash
php spark db:seed RecruiterSeeder    # akun recruiter@biproo.test / recruiter123
php spark db:seed JobSeeder          # 3 lowongan contoh
php spark ereq:demo                  # satu kandidat dari unggah CV sampai penjadwalan
```

`ereq:demo` yang paling berguna untuk pengujian: ia mengisi
`candidate_stage_history` dan `email_queue` dengan satu alur utuh. Tambahkan
`--clean` kalau ingin datanya dihapus lagi setelah selesai.

Ada satu lagi, tapi butuh berkas dari Anda sendiri:

```bash
php spark lowongan:impor --kering     # coba dulu, belum menulis apa pun
php spark lowongan:impor              # lowongan lengkap + bank pertanyaan interview
```

Sumbernya `interview_softskill_hardskill.csv` milik tim DS, dan berkas itu
**tidak ikut di git** (CSV mentah bukan tempatnya di repo aplikasi). Tanpa
`--berkas`, perintah ini mencarinya di `data/interview_softskill_hardskill.csv`
**sejajar dengan folder repo**, bukan di dalamnya:

```
Biproo (E-rec)/
├── app/     <- folder repo git ini
└── data/
    └── interview_softskill_hardskill.csv
```

Kalau CSV Anda ada di tempat lain, tunjuk saja jalurnya:

```bash
php spark lowongan:impor --berkas "D:\data\interview_softskill_hardskill.csv"
```

Perintahnya idempoten, aman dijalankan ulang. Jalankan `--kering` lebih dulu
untuk melihat apa yang akan berubah tanpa menyentuh basis data.

---

## Setiap kali mulai kerja

Dua baris, jadikan kebiasaan:

```bash
git pull && cd webapp && php spark migrate
```

`php spark migrate` aman dijalankan berkali-kali. Kalau tidak ada migrasi baru
ia menjawab "Migrations complete" tanpa menyentuh apa pun. Kalau ada, ia
menjalankan yang belum saja.

**Data Anda tidak hilang.** Migrasi hanya mengubah struktur, bukan isi tabel.

### Memastikan sudah yang terbaru

```bash
php spark migrate:status
```

Setiap baris yang kolom `Migrated On`-nya kosong berarti migrasi itu **belum**
dijalankan di basis data Anda. Kalau semua terisi, Anda sudah sinkron.

Per 6 Agustus 2026 ada 12 migrasi, yang terakhir `2026-08-04-210000
PenilaianInterview`. Kalau daftar Anda lebih pendek dari daftar berkas di
`app/Database/Migrations/`, berarti `git pull` Anda belum jalan.

---

## Kalau Anda perlu mengubah skema

Misalnya tim DS butuh tabel baru untuk menyimpan hasil model. Jangan bikin di
SSMS. Bikin migrasi:

```bash
php spark make:migration NamaPerubahanAnda
```

Isi metode `up()` dan `down()`, jalankan `php spark migrate` untuk mengujinya di
laptop sendiri, lalu **commit berkasnya**. Begitu masuk git, semua orang yang
`git pull` otomatis ikut berubah. Itu memang satu-satunya gunanya migrasi ada.

Contoh yang bisa ditiru: [`2026-08-04-210000_PenilaianInterview.php`](../webapp/app/Database/Migrations/2026-08-04-210000_PenilaianInterview.php)
(tabel baru) dan [`2026-08-04-200000_KategoriLowongan.php`](../webapp/app/Database/Migrations/2026-08-04-200000_KategoriLowongan.php)
(menambah kolom ke tabel yang sudah ada).

---

## Kalau basis data Anda terlanjur kacau

Buang dan bangun ulang. Basis data pengembangan memang untuk dibuang.

```bash
php spark migrate:refresh
```

Ini menjalankan `down()` semua migrasi lalu `up()` semua lagi. **Seluruh isinya
hilang**, jadi jalankan seedernya lagi setelah itu.

Kalau `migrate:refresh` sendiri gagal (biasanya karena skema Anda sudah
menyimpang dari yang dicatat migrasi), cara paling cepat adalah menghapus basis
datanya dan mengulang dari [langkah 1](#1-basis-data-kosong):

```sql
DROP DATABASE ereq;
```

---

## Kalau Anda butuh data produksi, bukan sekadar skema

Seeder memberi data buatan. Kalau analisisnya butuh data nyata (misal mengukur
distribusi skor CV kandidat sungguhan), minta snapshot ke tim aplikasi.

### Di sisi tim aplikasi

Satu perintah, dijalankan dari folder repo:

```bash
.\db\snapshot-ds.ps1
```

Hasilnya `C:\temp\ereq_ds.bak`, siap dikirim. Yang dikerjakan skrip itu: mencadangkan
`ereq`, memulihkannya sebagai salinan `ereq_ds`, menyamarkan data pribadi di
salinan tersebut lewat [`bersihkan-ds.sql`](../db/bersihkan-ds.sql), memverifikasi
hasilnya, lalu mencadangkan salinan yang sudah bersih. Basis data asli tidak
pernah disentuh.

Yang disamarkan: nama dan email kandidat, akun recruiter beserta hash sandinya,
seluruh antrian email, dan tautan Zoom (ruangannya masih hidup, jadi itu bukan
sekadar data melainkan pintu yang masih bisa dibuka).

Yang tersisa untuk dianalisis: skor screening, riwayat tahapan, lowongan, dan
penilaian interview. Isi CV tidak ada di basis data sama sekali, hanya
metadatanya seperti jumlah halaman dan karakter.

Kalau verifikasinya gagal, skrip berhenti dan tidak meninggalkan berkas apa pun.
Itu disengaja: percobaan penyamaran yang gagal tetap menghasilkan `.bak` yang
terlihat wajar dari luar.

Opsi kalau perlu: `-Sumber`, `-Salinan`, `-Folder`, dan `-Simpan` (membiarkan
basis data salinan tetap ada setelah selesai).

### Yang perlu disadari

`.bak` adalah **foto sesaat**. Ia langsung basi begitu ada data baru. Untuk
memperbarui, jalankan skripnya lagi. Sepakati juga sejak awal berapa lama tim DS
boleh menyimpannya, lalu tagih: salinan data pelamar yang tertinggal di laptop
orang setelah proyek selesai adalah masalah yang muncul jauh belakangan, waktu
tidak ada lagi yang ingat berkas itu pernah dikirim.

Untuk sekadar menjalankan dan menguji alur, seeder sudah cukup dan jauh lebih
repeatable.

---

## Jebakan yang sudah pernah memakan korban

**Harus SQL Server, jangan MySQL.**
[`SlotInterviewUnik.php`](../webapp/app/Database/Migrations/2026-08-03-100000_SlotInterviewUnik.php)
memakai *filtered unique index* (`CREATE UNIQUE INDEX ... WHERE status IN (...)`)
untuk menjamin satu slot interview cuma dipegang satu kandidat. SQL Server punya
fitur ini, SQLite punya, MySQL tidak. Di MySQL migrasinya berhenti di situ.

**Jangan mengubah isi berkas migrasi yang sudah pernah dijalankan.**
CI4 mencatat migrasi yang sudah jalan berdasarkan **nomor versinya**, bukan isi
berkasnya. Kalau Anda mengedit berkas lama tanpa mengubah nama berkasnya, CI4
menganggapnya sudah beres dan **melewatinya diam-diam**. Basis data Anda tidak
berubah, tidak ada pesan error, dan baru ketahuan saat halamannya 500. Perubahan
selalu jadi berkas migrasi **baru**.

**`.env` tidak boleh masuk git.**
Sudah ada di `.gitignore`, tapi jangan dipaksa dengan `git add -f`. Yang
di-commit cuma `webapp/env`, templat kosong bawaan CodeIgniter.

**Uji otomatis tidak menyentuh basis data Anda.**
`./vendor/bin/phpunit` memakai SQLite di memori dengan prefiks tabelnya sendiri,
bukan SQL Server. Jadi menjalankan uji tidak akan merusak data di `ereq`, dan
sebaliknya, isi `ereq` tidak mempengaruhi hasil uji.

---

## Ringkasan

| Situasi | Perintah |
|---|---|
| Pertama kali | `cp env .env` lalu isi, `composer install`, `php spark migrate`, seeder |
| Mulai kerja tiap hari | `git pull && php spark migrate` |
| Cek sudah terbaru atau belum | `php spark migrate:status` |
| Perlu ubah skema | `php spark make:migration Nama`, lalu commit |
| Basis data kacau | `php spark migrate:refresh` lalu seeder lagi |
