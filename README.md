# E-REQ - Sistem E-Recruitment BIPROO

Sistem rekrutmen berbantuan AI, dari pelamar mengunggah CV sampai keputusan
diterima atau tidak.

Alurnya: kandidat melamar dan mengunggah CV, sistem membaca CV lalu menghitung
kemiripannya terhadap lowongan, kandidat mengerjakan assessment, memilih jadwal,
lalu diwawancara lewat Zoom. Rekaman wawancara ditranskripsi dan dinilai
otomatis, dan AI memberi rekomendasi diterima atau tidak. Untuk posisi tertentu
ada tahap Interview User, yaitu wawancara dengan calon atasan yang memakai akun
tersendiri, dan di situlah keputusan akhirnya.

Isi repo:

| Folder | Isi |
|---|---|
| `webapp/` | Aplikasi web CodeIgniter 4 (yang Anda buka di browser) |
| `ai-service/` | Layanan FastAPI: baca PDF, strukturkan CV, hitung skor |
| `kalibrasi/` | Skrip analisis mutu model, tidak dipakai saat aplikasi berjalan |
| `docs/` | Catatan teknis dan hasil pengukuran |
| `db/` | Berkas SQL pendukung |

---

## Status serah terima (24 Agustus 2026)

Dikerjakan selama magang 13 Juli sampai 24 Agustus 2026. **Sistemnya berjalan
utuh di lingkungan lokal dan belum pernah di-deploy ke server.**

### Yang sudah berjalan ujung ke ujung

Kandidat mendaftar, mengunggah CV, CV dibaca dan diskor AI, assessment, Gate 1,
memilih jadwal, ruang Zoom dibuat otomatis, recruiter mewawancara dan mengunggah
rekaman, rekaman ditranskripsi lalu dinilai AI, AI memutuskan rekomendasi, Gate
2 menutup sendiri, dan untuk posisi ber-Interview User keputusan akhir pindah ke
akun atasan. Kandidat yang gugur karena salah posisi diberi tiga saran lowongan
lain. Tiap perpindahan tahap tercatat dan sebagian memicu email otomatis.

**551 uji PHP dan 208 uji Python, seluruhnya lulus.**

### Yang SENGAJA belum selesai, dan perlu diputuskan penerusnya

| Perkara | Keterangan |
|---|---|
| Assessment masih placeholder | Satu pertanyaan ya/tidak yang sama untuk semua posisi. Assessment sungguhan (TIU, DISC, Excel Test) di luar cakupan magang ini |
| Delapan tahap opsional belum bisa ditandai selesai | Excel Test, Training Class, dan sejenisnya bisa dipasang di alur lewat Settings, tapi belum ada aksi untuk menutupnya. Kandidat akan melihatnya terkunci selamanya |
| Belum ada lupa sandi dan verifikasi email | Kandidat yang lupa sandi terkunci permanen, dan siapa pun bisa mendaftar memakai email orang lain |
| Belum ada pembatasan percobaan login | Tiga halaman login tidak dibatasi |
| Lowongan belum punya penanda buka/tutup | Ke-34 lowongan selalu dianggap menerima pelamar |
| Saran posisi baru terukur pada 52 kandidat | Perlu diukur ulang setelah ada cukup kandidat sungguhan, lihat `docs/kalibrasi-saran-posisi.md` |

### Sebelum dipakai sungguhan

1. **Ganti sandi recruiter bawaan** (`recruiter123` dari `RecruiterSeeder`).
2. **Putar ulang kredensial** `GEMINI_API_KEY`, `zoom.clientSecret`, dan
   `email.SMTPPass` bila berkas `.env` pernah berpindah tangan. `.env` sengaja
   tidak ikut di git.
3. **Naikkan kuota Gemini** dari tier gratis. Batas 20 permintaan sehari cukup
   untuk uji coba, tidak untuk rekrutmen sungguhan. Lihat bagian batas kuota
   di bawah.
4. **Jalankan checklist** di [docs/deploy.md](docs/deploy.md).
5. Baca [docs/kalibrasi-gate.md](docs/kalibrasi-gate.md) sebelum mengubah
   ambang apa pun. Beberapa angka yang tampak wajar sudah diuji dan gagal.

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
| `gemini-2.5-flash` | **20 permintaan/hari** | membaca CV, dan membuat pertanyaan interview |
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

## Yang harus jalan sehari-hari

Empat hal. Kalau salah satu mati, yang hilang cuma bagiannya - sistemnya tidak
tumbang.

| Yang dijalankan | Cara | Kalau mati |
|---|---|---|
| SQL Server | layanan Windows | seluruh aplikasi berhenti |
| Aplikasi web | `cd webapp && php spark serve` | web tidak terbuka |
| Layanan AI | klik dua kali `ai-service/ai-service.bat` | skor CV, transkripsi, dan penilaian wawancara berhenti; sisanya jalan |
| Pengirim email | klik dua kali `webapp/kirim-email-otomatis.bat` | email menumpuk di tabel `email_queue`, tidak hilang |

Dua `.bat` itu punya gelung penyalaan ulang: kalau layanannya mati sendiri, ia
dinyalakan lagi. Biarkan jendelanya terbuka.

**Yang tersangkut bisa dikejar, bukan hilang.** Skor CV yang tidak sampai bisa
dikirim ulang, rekaman yang gagal ditranskripsi bisa dikirim ulang, dan email
yang belum terkirim tetap menunggu di antrian.

---

## Perintah pemeliharaan

Dijalankan dari folder `webapp`. Semuanya punya mode kering untuk melihat
dampaknya lebih dulu.

| Perintah | Gunanya |
|---|---|
| `php spark screening:resend --dry` | Kirim ulang screening CV yang belum berskor. `--paksa` menilai ulang yang sudah punya skor |
| `php spark transkrip:resend --kering` | Kirim ulang rekaman yang transkripsinya tersangkut. `--gagal` menyertakan yang berstatus gagal |
| `php spark vektor:isi --kering` | Hitung vektor syarat lowongan, bahan saran posisi. Perlu dijalankan setelah menambah lowongan lewat impor |
| `php spark lamaran:hapus --email X --kering` | Hapus seluruh lamaran satu orang beserta ruang Zoom dan berkasnya. **Akunnya tidak disentuh** |
| `php spark email:send` | Kosongkan antrian email sekali jalan |
| `php spark lowongan:impor` | Impor posisi dan bank pertanyaan dari CSV tim DS |

> **Hati-hati dengan `lamaran:hapus`.** Ia mencabut ruang Zoom di server Zoom
> dan menghapus rekaman wawancara dari disk. Rekaman adalah berkas paling peka
> di sistem ini. Perintahnya membuat cadangan lebih dulu, tapi periksa mode
> kering sebelum menjalankannya sungguhan.

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
| [docs/setup-tim-ds.md](docs/setup-tim-ds.md) | Menyiapkan basis data sendiri dan menjaganya tetap sinkron lewat migrasi |
| [docs/deploy.md](docs/deploy.md) | Checklist memindahkan aplikasi ke server |
| [docs/kalibrasi-gate.md](docs/kalibrasi-gate.md) | Kalibrasi ambang dan metrik |
| [docs/kalibrasi-saran-posisi.md](docs/kalibrasi-saran-posisi.md) | Mutu saran posisi: terukur 1,9x lebih baik daripada menebak |
| [ai-service/README.md](ai-service/README.md) | Kontrak API layanan AI |

---

## Dua hal yang wajib dimengerti penerus proyek ini

**Skor kemiripan CV mengukur tumpang tindih makna antara CV dan teks lowongan,
bukan kompetensi kandidat.** Ia sudah diukur atas 7.815 kandidat berlabel:
ROC-AUC 0,589, dan di dalam satu posisi 0,499 yaitu setara lempar koin. Karena
itu skor CV **dicabut dari Gate 1** dan tidak pernah menggugurkan siapa pun
sendirian. Jangan dikembalikan tanpa mengukur ulang. Angkanya di
[docs/kalibrasi-gate.md](docs/kalibrasi-gate.md) dan
[docs/pipeline-screening-cv.md](docs/pipeline-screening-cv.md).

**Sejak 24 Agustus 2026, AI memutuskan sendiri di Gate 2** atas permintaan
manajemen: kandidat digugurkan otomatis berikut email penolakannya, tanpa
recruiter menyentuh apa pun. Yang menahan keputusan itu tinggal tiga hal -
transkripsi gagal, skor CV tidak tersedia, dan model memilih tidak memutuskan.
Aturan lengkapnya beserta kapan mesin menolak memutuskan ada di
[docs/gate-logic.md](docs/gate-logic.md). **Baca bagian itu sebelum mengubah
apa pun di jalur penilaian wawancara**, karena yang berubah di sana langsung
menyangkut orang yang menerima surat penolakan.
