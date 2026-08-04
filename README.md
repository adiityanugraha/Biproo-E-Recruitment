# E-REQ - Sistem E-Recruitment BIPROO

Aplikasi rekrutmen dengan penilaian kemiripan CV berbantuan AI. Kandidat melamar
dan mengunggah CV, sistem membaca CV lalu menghitung kemiripannya terhadap
lowongan, recruiter meninjau dan menjadwalkan interview Zoom.

Isi repo:

| Folder | Isi |
|---|---|
| `webapp/` | Aplikasi web CodeIgniter 4 (yang Anda buka di browser) |
| `ai-service/` | Layanan FastAPI: baca PDF, strukturkan CV, hitung skor |
| `kalibrasi/` | Skrip analisis mutu model, tidak dipakai saat aplikasi berjalan |
| `docs/` | Catatan teknis dan hasil pengukuran |
| `db/` | Berkas SQL pendukung |

---

## Sekadar ingin melihat webnya

Cukup `webapp/`. Layanan AI **tidak wajib** - tanpa itu aplikasinya tetap jalan,
hanya skor CV yang tidak keluar.

### 1. Prasyarat

- **PHP 8.2** dengan ekstensi `sqlsrv` dan `pdo_sqlsrv` aktif. Cek:
  ```bash
  php -v && php -m | grep sqlsrv
  ```
  Kalau `sqlsrv` tidak muncul, unduh dari
  [Microsoft Drivers for PHP](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server),
  taruh DLL-nya di folder `ext/` PHP, lalu tambahkan `extension=sqlsrv` dan
  `extension=pdo_sqlsrv` di `php.ini`.
- **SQL Server** (Express cukup) berjalan di `localhost:1433`, dengan TCP/IP
  diaktifkan lewat SQL Server Configuration Manager.
- **Composer**.

### 2. Siapkan basis data

Buat basis data `ereq` dan sebuah login yang bisa mengaksesnya. Contoh lewat
SSMS atau sqlcmd:

```sql
CREATE DATABASE ereq;
GO
CREATE LOGIN ereq_app WITH PASSWORD = 'GantiDenganSandiAnda';
GO
USE ereq;
CREATE USER ereq_app FOR LOGIN ereq_app;
ALTER ROLE db_owner ADD MEMBER ereq_app;
GO
```

### 3. Konfigurasi

`webapp/.env` sengaja **tidak ikut di git** karena memuat kredensial. Salin
templat bawaan CodeIgniter lalu isi:

```bash
cd webapp && cp env .env
```

Perhatikan: berkas `env` itu templat polos CodeIgniter, seluruh barisnya masih
berupa komentar dan **tidak memuat kunci khusus aplikasi ini** (`zoom.*`,
`aiservice.*`). Kunci-kunci itu Anda tambahkan sendiri sesuai bagian di bawah.

Yang wajib diisi untuk sekadar membuka web:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'
app.indexPage = ''

database.default.hostname = localhost
database.default.database = ereq
database.default.username = ereq_app
database.default.password = GantiDenganSandiAnda
database.default.DBDriver = SQLSRV
database.default.port     = 1433
database.default.encrypt  = false
```

Yang **opsional** (lihat bagian "Fitur yang butuh konfigurasi tambahan"):
`email.*`, `zoom.*`, `aiservice.*`.

### 4. Pasang dan jalankan

```bash
cd webapp && composer install && php spark migrate && php spark db:seed JobSeeder && php spark db:seed RecruiterSeeder && php spark serve
```

Buka **http://localhost:8080**.

### 5. Masuk

| Peran | Alamat | Email | Sandi |
|---|---|---|---|
| Recruiter | `/recruiter/login` | `recruiter@biproo.test` | `recruiter123` |
| Kandidat | `/daftar` | daftar sendiri | bebas |

Sandi recruiter itu untuk pengembangan saja, ganti sebelum dipakai sungguhan.

Seeder mengisi tiga lowongan: Frontliner Retail Gadget, Admin Gudang, dan
Backend Developer.

---

## Fitur yang butuh konfigurasi tambahan

Tanpa bagian ini aplikasinya tetap terbuka dan bisa ditelusuri. Yang hilang cuma
fiturnya, bukan halamannya.

### Skor kemiripan CV - butuh `ai-service`

Tanpa ini, CV tetap terunggah tapi kolom skor kosong dan tahap verifikasi tidak
pernah selesai.

```bash
cd ai-service
python -m venv .venv
./.venv/Scripts/pip install -r requirements.txt
cp .env.example .env          # lalu isi GEMINI_API_KEY
./.venv/Scripts/python -m uvicorn main:app --port 8000
```

Ambil kunci gratis di [Google AI Studio](https://aistudio.google.com/apikey).

Di `webapp/.env` isi juga:

```ini
aiservice.baseURL     = 'http://localhost:8000'
aiservice.sharedToken = 'karangan-bebas-asal-sama-di-kedua-sisi'
```

Token itu bebas Anda tentukan, syaratnya sama persis di kedua sisi. Kalau
dikosongkan, jalur internalnya menutup diri dan menolak semua permintaan.

**Batas tier gratis Gemini** yang perlu diketahui sejak awal:

| Kuota | Batas | Dipakai untuk |
|---|---|---|
| `gemini-2.5-flash` | **20 permintaan/hari** | membaca dan menstrukturkan CV |
| `gemini-embedding-001` | **1.000 item/hari** | menghitung kemiripan |

Satu CV memakai 1-2 panggilan LLM, jadi atapnya sekitar 10-20 CV per hari. Kalau
habis, sistem turun ke pembaca CV sederhana berbasis judul section - skornya
tetap keluar tapi ditandai **"pembacaan kasar"** di halaman review recruiter.
Rinciannya di [docs/pipeline-screening-cv.md](docs/pipeline-screening-cv.md).

### Penjadwalan interview - butuh kredensial Zoom

Buat Server-to-Server OAuth app di [Zoom Marketplace](https://marketplace.zoom.us/),
lalu isi di `webapp/.env`:

```ini
zoom.accountId    = ...
zoom.clientId     = ...
zoom.clientSecret = ...
zoom.hostEmail    = email-host-zoom-anda
```

Tanpa ini kandidat masih bisa memilih slot, tapi tautan meeting-nya gagal dibuat.

### Pengiriman email

Email tidak terkirim langsung, melainkan masuk antrian di tabel `email_queue`.
Ada pengirim latar yang mengosongkannya tiap 30 detik:

**Klik dua kali `webapp/kirim-email-otomatis.bat`**, lalu biarkan jendelanya
terbuka. Ctrl+C untuk berhenti.

Sekali jalan saja:

```bash
cd webapp && php spark email:send
```

> **Hati-hati:** perintah itu mengirim **seluruh** baris berstatus `pending` ke
> alamat aslinya. Periksa isi antrian dulu kalau basis data Anda memuat alamat
> email sungguhan.

Untuk Gmail, `email.SMTPPass` harus **App Password**, bukan sandi akun biasa.

---

## Menjalankan uji

```bash
cd webapp && ./vendor/bin/phpunit
```

```bash
cd ai-service && ./.venv/Scripts/python -m pytest -q
```

Uji webapp memakai SQLite di memori, jadi tidak menyentuh basis data SQL Server
Anda dan tidak butuh `ai-service` hidup.

---

## Kalau macet

| Gejala | Sebab yang paling sering |
|---|---|
| `Unable to connect to the database` | Layanan SQL Server mati, atau TCP/IP belum diaktifkan di Configuration Manager |
| `Call to undefined function sqlsrv_connect()` | Ekstensi `sqlsrv` belum aktif di `php.ini` |
| Halaman putih / 500 | `writable/` tidak bisa ditulis, atau `.env` belum dibuat |
| Jam interview meleset 7 jam | `$appTimezone` di `app/Config/App.php` harus `Asia/Jakarta`, bukan UTC. Dikunci oleh `tests/unit/InterviewLinkTest.php` |
| Skor CV selalu kosong | `ai-service` mati, atau `aiservice.sharedToken` beda antara kedua sisi |
| Skor bertanda "pembacaan kasar" | Kuota harian Gemini habis, tunggu reset |

---

## Dokumentasi lanjutan

| Berkas | Isi |
|---|---|
| [docs/pipeline-screening-cv.md](docs/pipeline-screening-cv.md) | Cara kerja penilaian CV, hasil pengukuran, dan batasannya |
| [docs/gate-logic.md](docs/gate-logic.md) | Aturan kelulusan tiap tahap |
| [docs/skema-database.md](docs/skema-database.md) | Skema tabel |
| [docs/kalibrasi-gate.md](docs/kalibrasi-gate.md) | Kalibrasi ambang dan metrik |
| [ai-service/README.md](ai-service/README.md) | Kontrak API layanan AI |

**Yang perlu diketahui sebelum menilai hasil skornya:** skor kemiripan mengukur
tumpang tindih makna antara CV dan teks lowongan, **bukan kompetensi kandidat**.
Ia tidak menentukan kelulusan tahap mana pun sendirian - keputusan selalu di
tangan recruiter. Alasannya beserta angkanya ada di
[docs/pipeline-screening-cv.md](docs/pipeline-screening-cv.md).
