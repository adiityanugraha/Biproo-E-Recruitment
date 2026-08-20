# Checklist Deploy E-REQ

Daftar centang untuk memindahkan E-REQ dari laptop ke server. Urutannya
disengaja: bagian A sampai D dikerjakan berurutan, bagian E dan F butuh
keputusan atau akses orang lain jadi sebaiknya diurus lebih awal.

Keadaan yang dicatat di sini per **6 Agustus 2026**. Kalau ada yang sudah
berubah, perbarui berkas ini sekalian.

Yang **tidak** berubah dari pengembangan: skemanya tetap dibangun dengan
`php spark migrate`, sama persis seperti di [setup-tim-ds.md](setup-tim-ds.md).
Tidak ada langkah khusus produksi selain tiga statement SQL di bagian D.

---

## A. Konfigurasi yang wajib diubah

Semua ini masih bernilai pengembangan di repo. Dibiarkan begitu, aplikasinya
tetap jalan, dan itu justru bahayanya: tidak ada pesan error yang memberi tahu.

- [ ] **`CI_ENVIRONMENT = production`** di `.env`
      Sekarang `development`. Kalau tertinggal, setiap error menampilkan stack
      trace lengkap beserta isi variabel ke pengunjung, termasuk kredensial.

- [ ] **`php spark key:generate`**
      `encryption.key` di `.env` masih kosong dan dikomentari.

- [ ] **`app.baseURL`** diisi domain sungguhan, dengan `https://` dan garis
      miring di akhir.
      Bukan sekadar kosmetik: `callback_url` yang dikirim ke `ai-service`
      dirakit dari `site_url()`, jadi baseURL yang salah membuat hasil
      screening tidak pernah kembali dan skor CV selamanya kosong.

- [ ] **Sandi recruiter diganti.**
      `RecruiterSeeder` memasang `recruiter123`, dan sandi itu tertulis di
      README. Seedernya tetap perlu dijalankan sekali supaya ada akun untuk
      masuk, sandinya ganti segera setelah itu.

- [ ] **Document root menunjuk `webapp/public`**, bukan `webapp`.
      Ini kesalahan yang paling mahal di daftar ini. Kalau menunjuk `webapp`,
      `.env` bisa diunduh siapa saja lewat browser, lengkap dengan sandi basis
      data, kunci Zoom, dan sandi SMTP. Satu salah konfigurasi, semua
      kredensial hilang sekaligus. Cara mengujinya ada di bagian G.

### Kalau server sudah HTTPS

- [ ] `$secure = true` di [`app/Config/Cookie.php`](../webapp/app/Config/Cookie.php)
      (sekarang `false`, artinya cookie sesi recruiter ikut terkirim lewat HTTP polos)
- [ ] `$forceGlobalSecureRequests = true` di [`app/Config/App.php`](../webapp/app/Config/App.php)
- [ ] `database.default.encrypt = true` di `.env`, kalau basis datanya di mesin lain

### Contoh `.env` produksi

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://erec.contoh.co.id/'
app.indexPage = ''
encryption.key = hex2bin:...        ; diisi oleh php spark key:generate

database.default.hostname = nama-server-db
database.default.database = ereq
database.default.username = ereq_app
database.default.password = ...
database.default.DBDriver = SQLSRV
database.default.port     = 1433
database.default.encrypt  = true

aiservice.baseURL     = http://127.0.0.1:8000
aiservice.sharedToken = ...          ; harus sama persis dengan sisi ai-service

zoom.accountId    = ...
zoom.clientId     = ...
zoom.clientSecret = ...
zoom.hostEmail    = ...

email.protocol   = smtp
email.SMTPHost   = ...
email.SMTPUser   = ...
email.SMTPPass   = ...
email.SMTPPort   = 587
email.SMTPCrypto = tls
email.fromEmail  = ...
email.fromName   = ...
```

`.env` dibuat langsung di server, tidak pernah lewat git.

---

## B. Yang harus tersedia di server

- [ ] **PHP 8.2** dengan ekstensi `sqlsrv` dan `pdo_sqlsrv` aktif
      (`php -m` harus memuat keduanya)
- [ ] **SQL Server**, basis data `ereq` kosong plus satu login khusus aplikasi:

      ```sql
      CREATE DATABASE ereq;
      GO
      CREATE LOGIN ereq_app WITH PASSWORD = '...';
      GO
      USE ereq;
      CREATE USER ereq_app FOR LOGIN ereq_app;
      ALTER ROLE db_owner ADD MEMBER ereq_app;
      GO
      ```

      Tiga baris terakhir mudah terlewat. Login di SQL Server berlaku di level
      server, tapi izin masuk ke sebuah basis data harus diberikan di dalam
      basis data itu sendiri. Tanpa `CREATE USER`, `php spark migrate` berhenti
      di `Login failed for user 'ereq_app'` walaupun login-nya jelas ada.
- [ ] **Composer**
- [ ] **Python 3** untuk `ai-service`
- [ ] **Sertifikat HTTPS**
- [ ] Folder `webapp/writable/` bisa ditulis oleh akun web server

Kalau `ai-service` dipasang di mesin berbeda dari webapp, dua arah komunikasi
harus terbuka: webapp memanggil `ai-service` untuk memulai screening, dan
`ai-service` menghubungi balik `app.baseURL` untuk mengunduh berkas CV dan
mengirim hasilnya. Membuka satu arah saja tidak cukup.

---

## C. Langkah pasang

```bash
git clone <repo> && cd webapp
composer install --no-dev --optimize-autoloader
# buat .env sesuai bagian A
php spark key:generate
php spark migrate
php spark db:seed RecruiterSeeder    # lalu ganti sandinya
php spark phpini:check               # periksa php.ini untuk produksi
```

`JobSeeder` tidak perlu di produksi, isinya lowongan contoh. Lowongan
sungguhan diisi lewat aplikasi, atau lewat `php spark lowongan:impor` kalau
sumbernya CSV tim DS.

- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `.env` dibuat dan diisi
- [ ] `php spark key:generate`
- [ ] `php spark migrate` selesai tanpa error
- [ ] `php spark db:seed RecruiterSeeder`, lalu sandinya diganti
- [ ] `php spark phpini:check` diperiksa
- [ ] `upload_max_filesize` dan `post_max_size` >= 40M (lihat php.ini di bawah)
- [ ] folder `writable/uploads/rekaman` bisa ditulis web server

Seluruh urutan ini sudah diuji pada basis data SQL Server kosong (6 Agustus
2026): 12 migrasi jalan dari nol tanpa error, termasuk filtered index di
`SlotInterviewUnik`, dan seedernya masuk. Jadi kalau di server gagal, sebabnya
ada di lingkungan, bukan di berkas migrasinya.

### php.ini

`php spark phpini:check` pada php.ini XAMPP yang dipakai sekarang menandai lima
hal yang harus berbeda di produksi:

| Directive | Sekarang | Produksi |
|---|---|---|
| `display_errors` | `1` | `0` |
| `display_startup_errors` | `1` | `0` |
| `error_reporting` | `32767` | `5111` |
| `zend.assertions` | `1` | `-1` |
| `opcache.enable` | mati | `1` |

Dua yang pertama paling penting. `CI_ENVIRONMENT = production` sudah menahan
halaman error CI4, tapi `display_errors` di level PHP masih bisa membocorkan
pesan fatal sebelum CI4 sempat mengambil alih.

`opcache` bukan soal keamanan melainkan kecepatan, tapi bedanya besar dan
menyalakannya cuma satu baris.

Satu lagi di luar daftar itu: `date.timezone` di php.ini ini terisi
`Europe/Berlin`. Aplikasi sudah memaksa `Asia/Jakarta` lewat `$appTimezone` dan
dikunci uji otomatis, jadi jam interview tetap benar. Tetap saja setel php.ini
server ke `Asia/Jakarta` supaya log dan galat PHP tidak memakai zona yang lain
sendiri.

#### Batas unggah (rekaman wawancara)

`phpini:check` TIDAK memeriksa ini, dan kegagalannya paling menyesatkan: PHP
membuang berkas yang kebesaran **sebelum** CI4 sempat memeriksanya, sehingga
yang terlihat recruiter cuma form yang kembali kosong tanpa pesan apa pun.

| Directive | Minimal | Kenapa |
|---|---|---|
| `upload_max_filesize` | `40M` | rekaman audio Zoom 30 menit 15-30 MB |
| `post_max_size` | `40M` | harus >= `upload_max_filesize` |
| `max_execution_time` | `120` | unggah 30 MB di jaringan kantor bisa lama |

Angkanya harus di ATAS `Recruiter::REKAMAN_MAKS_KB` (35 MB), yang jadi batas
sebenarnya dan yang punya pesan galat manusiawi. Mesin pengembangan sekarang
sudah `40M`; server produksi bawaannya biasanya `2M`.

---

## D. Tiga statement SQL manual

Ini yang paling mudah terlewat, karena tidak ada apa pun yang gagal kalau lupa.
Fitur khusus SQL Server yang tidak bisa ditulis lewat Forge CI4, jadi tidak ikut
migrasi. Sumbernya [skema-database.md](skema-database.md).

```sql
-- sesuaikan nama login dengan yang benar-benar dipakai aplikasi
DENY UPDATE, DELETE ON candidate_stage_history TO [ereq_app];

CREATE INDEX ix_screening_retry ON screening_results(status)
    WHERE status = 'failed_extraction';
CREATE INDEX ix_email_pending ON email_queue(status, created_at)
    WHERE status = 'pending';
```

- [ ] `DENY UPDATE, DELETE` pada `candidate_stage_history`
- [ ] Dua filtered index dibuat

Yang `DENY` bukan optimasi. Riwayat tahapan kandidat dirancang append-only
sebagai jejak audit, dan yang benar-benar menegakkannya cuma izin di level
basis data. Tanpa baris itu aplikasi tetap berjalan normal, dan tidak akan ada
yang tahu jejaknya bisa diubah.

Jalankan lewat SSMS, atau lewat `sqlcmd -Q "..."`. Ditempel langsung ke
PowerShell, semuanya ditolak dengan `The term 'DENY' is not recognized`.

Ketiganya sudah diuji pada basis data hasil migrasi bersih (6 Agustus 2026) dan
diterima tanpa error. Setelah itu `sys.database_permissions` mencatat
`UPDATE -> DENY` dan `DELETE -> DENY` pada `candidate_stage_history`.

**Efek sampingnya:** `php spark ereq:demo --clean` akan gagal setelah `DENY`
terpasang, karena perintah itu menghapus baris riwayat lewat query langsung
([DemoFlow.php:70](../webapp/app/Commands/DemoFlow.php)) sehingga tidak melewati
penjagaan di `StageHistoryModel`. Ini bukan kerusakan, justru bukti `DENY`-nya
bekerja: `ereq:demo` memang perintah demo untuk pengembangan dan tidak
seharusnya dijalankan di produksi.

---

## E. Syarat di luar kode

Tanpa bagian ini aplikasinya tetap terbuka dan semua halamannya bisa dibuka.
Yang hilang cuma fiturnya, dan diamnya tidak selalu kelihatan. Dua yang pertama
bukan keputusan Anda, jadi tanyakan ke atasan sedini mungkin.

- [ ] **Akun Gemini berbayar.**
      Tier gratis hanya 20 permintaan LLM per hari. Di produksi artinya
      screening berhenti di kandidat ke-20 setiap harinya, dan sisanya jatuh ke
      pembacaan kasar tanpa ada yang menyadarinya.
      Sejak 14 Agustus 2026 transkripsi tidak lagi ikut memakan jatah itu (lihat
      di bawah), jadi per kandidat tinggal tiga panggilan - tapi 20 sehari tetap
      tidak cukup untuk satu hari rekrutmen yang sungguhan.

- [ ] **Model transkripsi terunduh di server.**
      `ai-service` mentranskripsi dengan faster-whisper di mesin sendiri, dan
      unduhan pertamanya ~1,5 GB ke `~/.cache/huggingface` milik user yang
      menjalankan layanannya. Panaskan sekali sesudah deploy, jangan biarkan
      recruiter pertama yang menunggunya. Kalau `pip install faster-whisper`
      dilewat, seluruh jalur otomatis kembali ke Gemini - jalan, tapi kembali
      memakan jatah harian dan bisa 429 di tengah hari.

- [x] ~~**Scope Zoom `meeting:delete:meeting`**~~ sudah ditambahkan di Zoom
      Marketplace (6 Agustus 2026) dan sudah diuji terhadap meeting sungguhan:
      [`ZoomService::hapusMeeting()`](../webapp/app/Libraries/ZoomService.php)
      berhasil, dan percobaan kedua pada id yang sama membalas 404, jadi
      penghapusannya nyata. Interview yang dijadwal ulang sekarang benar-benar
      mencabut ruangan Zoom-nya, bukan cuma mengubah status di basis data.
      Kalau nanti aplikasi Zoom-nya diganti atau kredensialnya dibuat ulang,
      scope ini harus ikut dipasang lagi.

- [ ] **Akun SMTP perusahaan**, bukan Gmail App Password pribadi.

- [ ] **Penjadwal pengiriman email.**
      Sekarang masih [`kirim-email-otomatis.bat`](../webapp/kirim-email-otomatis.bat),
      yaitu jendela CMD yang harus dibiarkan terbuka. Di server diganti Task
      Scheduler yang memanggil `php spark email:send` tiap menit.

- [ ] **`ai-service` dijalankan sebagai layanan**, bukan uvicorn di terminal
      maupun `ai-service.bat`. Berkas .bat itu jalan tengah untuk
      mesin pengembangan - ia menghidupkan layanan kembali bila mati, tapi
      tetap menuntut satu jendela dibiarkan terbuka dan ikut tertutup saat
      orangnya logout. Di server dipakai NSSM atau Task Scheduler,
      supaya hidup lagi sendiri setelah server restart. Isi `GEMINI_API_KEY` di
      `ai-service/.env`, dan pastikan token bersamanya sama persis dengan
      `aiservice.sharedToken` di `webapp/.env`.

---

## F. Operasional

- [ ] **Backup terjadwal untuk `ereq`.**
      Ini yang paling mendesak dan sebenarnya sudah berlaku sejak sekarang,
      bukan cuma nanti. Sampai berkas ini ditulis belum ada jadwal backup sama
      sekali. SQL Server Express tidak punya SQL Agent, jadi pakai Task
      Scheduler:

      ```bash
      sqlcmd -S <server> -E -Q "BACKUP DATABASE ereq TO DISK = 'D:\backup\ereq.bak' WITH INIT;"
      ```

      Jangan pakai `WITH COMPRESSION`, tidak ada di edisi Express (`Msg 1844`).

- [ ] **Pemantauan `ai-service`.** Kalau layanannya mati, aplikasi tidak
      menampilkan error apa pun. Gejalanya cuma skor CV yang tidak pernah
      terisi, dan itu bisa berhari-hari tidak disadari.
- [ ] **Rotasi log** `webapp/writable/logs/`.
- [ ] **Rekaman wawancara menumpuk.** `writable/uploads/rekaman/` tumbuh jauh
      lebih cepat daripada `uploads/cv/` (puluhan MB per kandidat, dan unggah
      ulang menambah berkas tanpa menimpa). Belum ada pembersihan otomatis.
      `php spark lamaran:hapus` ikut menghapusnya, tapi hanya untuk lamaran yang
      memang dihapus.

      Berkas ini yang PALING PEKA di sistem: isinya suara kandidat dan seluruh
      isi pembicaraan, bukan ringkasan yang ia pilih sendiri seperti CV.
      Perlakukan folder itu setidaknya seketat basis datanya.

### Transkripsi yang tersangkut

`ai-service` menyimpan status pekerjaannya di memori saja. Kalau callback-nya
gagal mendarat - jaringan putus sesaat, CI4 direstart, layanannya mati di tengah
jalan - transkrip yang sudah jadi ikut hilang, dan barisnya tertinggal
berstatus `proses` sementara layar recruiter berbunyi "sedang ditranskripsi".

```bash
php spark transkrip:resend --kering    # lihat mana yang tersangkut
php spark transkrip:resend             # kirim ulang yang antre/proses
php spark transkrip:resend --gagal     # ikut yang sudah ditandai gagal
```

Yang berstatus `selesai` tidak pernah ikut. Tiap pengiriman ulang memakan satu
panggilan LLM dari jatah harian - transkripsinya jalan lokal, yang dihitung cuma
penilaiannya.

---

## G. Verifikasi setelah deploy

- [ ] Buka `https://domain-anda/.env` di browser. **Harus 403 atau 404.**
      Kalau isinya justru terunduh, document root salah. Hentikan, perbaiki,
      lalu ganti semua kredensial yang sempat terpapar.
- [ ] Login recruiter berhasil dengan sandi baru
- [ ] Kirim satu lamaran uji, lalu tunggu skornya terisi. Skor yang muncul
      membuktikan tiga hal sekaligus: `ai-service` hidup, token bersamanya
      cocok, dan `app.baseURL` benar sehingga callback-nya sampai.
- [ ] Satu email dari antrian benar-benar terkirim
- [ ] Buat satu jadwal interview, pastikan tautan Zoom terbentuk
- [ ] `webapp/writable/logs/` tidak memuat error baru
- [ ] Backup pertama benar-benar menghasilkan berkas

---

## Yang sudah beres, tidak perlu diurus

- CSRF sudah aktif untuk semua form, dengan pengecualian yang disengaja pada
  `screening/callback` (dijaga token `X-Token` sendiri)
- Cookie sudah `httponly` dan `samesite=Lax`
- `.htaccess` sudah ada di `public/`
- 12 migrasi rapi, tidak ada langkah manual selain bagian D
- Zona waktu sudah `Asia/Jakarta` dan dikunci uji otomatis

## Yang sengaja belum ditangani

- **Foreign key antar tabel belum ada.** Keterkaitan data dijaga di level
  aplikasi. Menambahkannya butuh migrasi tersendiri dan pengujian ulang.
- **Sesi memakai FileHandler** di `writable/session/`. Cukup untuk satu server.
  Kalau nanti ada dua server di belakang load balancer, sesinya harus pindah ke
  basis data atau Redis.
- **`ai-service` belum punya rate limit sendiri.** Pengamannya baru token
  bersama.
