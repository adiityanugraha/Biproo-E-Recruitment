# Pipeline Screening CV (Fase 4)

Dokumen hasil, bukan rencana. Semua angka di sini terukur, bukan perkiraan.

## Alur

```
Lamaran::kirim
  -> POST /screening (ai-service)                      202 + screening_job_id
       1. GET internal/cv/{appId}          [X-Token]   unduh berkas CV
       2. lapis 1: ekstraksi layout-aware              PyMuPDF, urutan baca per posisi
       3. lapis 2: fallback OCR bila perlu             Tesseract ind+eng, per halaman
       4. strukturisasi 3 bidang berbasis konteks      LLM, fallback parser heading
       5. buang atribut sensitif                       sanitize.py, deterministik
       6. embedding 3 bidang CV + 3 bidang lowongan    Gemini gemini-embedding-001
       7. cosine per bidang + agregat berbobot         50/30/20, dinormalkan ulang
  -> POST screening/callback (CI4)          [X-Token]
       screening_results + candidate_stage_history
```

Gagal di tahap mana pun tidak membuang CV: `failed_extraction` masuk
`retry_extraction` di ai-service dan tercatat `ai_verification:retry_queued`
di riwayat. Kandidat tidak pernah digugurkan karena datanya tidak terbaca.

## Persentase CV sulit (Blueprint A3.2a, bahan keputusan form terstruktur)

Pengukuran sendiri, 400 CV acak dari 18.941 berkas historis:

| Kategori | Jumlah | Persen |
|---|---|---|
| Mudah - text-layer utuh | 215 | 53,8% |
| Sulit - scan/gambar penuh | 145 | 36,2% |
| Sulit - mixed (ada halaman scan) | 39 | 9,8% |
| Sulit - gagal baca | 1 | 0,2% |
| **TOTAL SULIT** | **185** | **46,2%** |

Margin error 95% pada n=400: +/- 4,9 poin.

Pembanding klasifikasi tim DS atas 6.355 CV: text-layer 58,5%, ocr 31,4%,
mixed 10,0%, yaitu 41,4% sulit. Selisih 4,8 poin dengan hasil kita masih di
dalam margin error, jadi kedua pengukuran sepakat: **sekitar 4 dari 10 CV
tidak bisa dibaca tanpa OCR.**

Konsekuensinya: fallback OCR bukan pelengkap, melainkan jalur wajib. Tanpa
lapis 2, hampir separuh pelamar tidak akan punya skor sama sekali.

## Hasil uji pipeline penuh (12 CV historis, HTTP nyata)

| Strata | n | Hasil |
|---|---|---|
| text-layer (normal) | 4 | 4 berskor |
| ocr - PDF scan | 3 | 3 berskor |
| ocr - berkas gambar | 3 | 3 berskor |
| mixed (lampiran scan) | 2 | 2 berskor |

```
12/12 status done, 12/12 berskor nyata, 0 hilang
lamaran tanpa baris screening_results : 0
lamaran tanpa riwayat ai_verification : 0
total waktu 24 detik untuk 12 CV
```

Kriteria selesai Fase 4 terpenuhi: 10 CV testing semua dapat skor atau masuk
antrian ulang, dan skor dummy di alur tergantikan skor nyata end-to-end.

Bukti end-to-end pada app#20:

```
ai_verification    passed   Kemiripan CV terhadap lowongan: sedang (0,68) (fase4-embedding-cosine-v1)
online_assessment  passed   Hasil assessment: lulus
gate_1             passed   Keputusan dari hasil assessment. Kemiripan CV sedang (0,68) dipakai di Tahap 2
baris screening_results provider='dummy': 0
```

Baris `gate_1` sengaja tidak memuat skor gabungan: sejak kalibrasi, Gate 1
diputus assessment dan skor CV hanya ikut sebagai informasi (docs/gate-logic.md).

## Perbandingan jumlah teks terhadap ekstraksi tim DS

620 CV yang sama, dicocokkan per nama berkas terhadap `hasil_ekstraksi_cv (2).xlsx`
(kolom `Jml Karakter`). Dua kolom rasio karena keduanya menjawab pertanyaan
berbeda, dan jawabannya memang berbeda:

| Metode | n | Median kita | Median DS | Rasio median | Rasio total korpus |
|---|---|---|---|---|---|
| text-layer | 392 | 1.912 | 1.931 | 0,99x | 1,00x |
| ocr | 164 | 1.484 | 1.570 | 0,95x | 1,31x |
| mixed | 64 | 4.849 | 3.162 | 1,53x | 1,50x |
| **seluruhnya** | **620** | | | | **1,14x** |

Bacaan jujurnya: **pada CV yang khas, ekstraksi kita setara dengan DS, bukan
lebih unggul.** Hanya pada 28,2% CV teks kita lebih banyak. Rasio total 1,14x
didorong dokumen panjang, bukan perbaikan merata.

Satu-satunya keunggulan nyata ada di `mixed` (1,5x, konsisten pada median maupun
total) dan sebabnya jelas: deteksi per halaman. Dokumen jenis ini lolos ambang
karakter tingkat-dokumen, padahal 70-93% halamannya lampiran scan tanpa teks.
Ambang tingkat-dokumen melewatkan halaman-halaman itu tanpa jejak; ambang per
halaman menangkapnya.

> Koreksi: versi awal dokumen ini memuat tabel 1,37x / 1,24x / 2,94x dari
> benchmark 15 CV. Angka itu SALAH, sampelnya terlalu kecil dan kebetulan
> memihak. Tabel di atas menggantikannya.

## Skala skor: lantainya 0,54, bukan 0

Cosine similarity antar teks berbahasa manusia tidak pernah mendekati nol.
Diukur terhadap lowongan Backend Developer yang sama:

| Yang dinilai | Skor |
|---|---|
| Resep rendang (jelas bukan CV) | **0,5404** |
| CV perawat, D3 Keperawatan, 3 tahun rawat inap | 0,6099 |
| Kandidat nyata di basis data ini | 0,58 - 0,66 |
| CV backend yang cocok betul | **0,8896** |

Dua akibatnya harus diketahui siapa pun yang membaca skor ini:

1. **Angka 0-100 mentah berbohong.** "54 dari 100" dibaca sebagai "cocok 54
   persen", padahal artinya "tidak cocok sama sekali". Karena itu UI tidak lagi
   menampilkan angka mentah, melainkan pita `rendah` / `sedang` / `tinggi`
   beserta angkanya dalam kurung: `sedang (0,66)`. Ambang pita diambil langsung
   dari tabel di atas (0,60 dan 0,75), bukan dikarang.
2. **Ambang rekomendasi Gate 2 (0,7) tidak tercapai dari sisi CV.** Kandidat
   nyata berhenti di 0,66. Bobot 40/60 menutupinya karena interview dominan,
   tapi angka 0,7 itu memang belum pernah dikalibrasi dan masih [ASUMSI].

Skala tidak diregangkan ke 0-100 dengan sengaja. Lantai 0,54 berasal dari SATU
contoh, terlalu tipis untuk jadi konstanta kalibrasi, dan bila angka regangan
itu ikut masuk rumus Gate 2 maka kandidat nyata jatuh ke sekitar 0,20 sehingga
hampir semua orang gagal - keputusan berubah gara-gara perubahan tampilan.

Daya bedanya sendiri benar arahnya. Satu CV yang sama terhadap tiga lowongan:

```
Backend Developer 0,6412  >  Admin Gudang 0,5872  >  Frontliner Retail 0,5810
```

Urutannya betul, tapi rentangnya cuma 0,06 sementara AUC di dalam satu posisi
terukur sekitar 0,50 (docs/kalibrasi-gate.md). Itu sebabnya pita tiga tingkat,
bukan angka dua digit: model ini sanggup memisahkan "jelas tidak relevan" dari
"cocok betul", tapi tidak sanggup mengurutkan kandidat ke-1 versus ke-2.

## Yang diukur skor ini: kosakata, bukan kompetensi

Pertanyaan yang wajar: kalau pelamar backend melamar lowongan backend, apakah
skornya tinggi? Diukur pada embedding produksi (`gemini-embedding-001`) terhadap
lowongan Backend Developer PHP/Laravel/MySQL:

| CV | overall | skill | pendidikan | pengalaman |
|---|---|---|---|---|
| Backend, stack sama | 0,9042 | 0,9754 | 0,8281 | 0,8362 |
| Backend, stack beda (Node/Postgres) | 0,7268 | 0,6586 | 0,8119 | 0,7837 |
| Frontend (React) | 0,6876 | 0,6296 | 0,8261 | 0,6920 |
| Perawat | 0,5905 | 0,5578 | 0,6540 | 0,6026 |
| Sales retail | 0,5785 | 0,5574 | 0,6160 | 0,5887 |

Urutannya benar, jadi jawabannya ya. Tapi backend Node.js cuma unggul 0,039 dari
frontend React, dan itu terlalu tipis untuk sesuatu yang katanya "paham konsep
backend". Uji lanjutan menunjukkan apa yang sebenarnya diukur:

| CV | overall | skill | pengalaman |
|---|---|---|---|
| Penyalin kata kunci, **nol pengalaman** | **0,9592** | 1,0000 | 0,8640 |
| Magang backend **1 bulan**, SMK | 0,7706 | 0,8592 | 0,6610 |
| Backend **5 tahun di Gojek**, kosakata beda (Go, gRPC, 40rb rps) | 0,6819 | 0,6082 | 0,6936 |

CV yang isinya menyalin kata dari iklan lowongan plus kalimat "Backend Developer
adalah cita-cita saya" mengalahkan backend sungguhan berpengalaman 3 tahun
(0,9042). Engineer 5 tahun yang memecah monolit dan menangani 40 ribu permintaan
per detik jatuh di bawah frontend. Urutan kompetensi sebenarnya Gojek > magang >
penyalin; model memberi urutan terbalik sempurna.

Kesimpulannya: **skor ini mengukur tumpang tindih kosakata antara CV dan teks
lowongan**, bukan kemampuan kandidat. Konsisten dengan AUC 0,589 di
docs/kalibrasi-gate.md. Untuk lowongan BIPROO (sales, staf gerai) ia tetap
berguna sebagai penyaring kasar, karena pelamar memang menulis "melayani
pelanggan" dan "target penjualan" persis seperti di iklannya.

Dua modus gagalnya terpisah dan ditangani terpisah.

### Modus 1: kompeten tapi kosakatanya beda (ditangani lewat teks lowongan)

Nol baris kode. Tulis **kegiatannya** dulu di kolom lowongan, merek jadi contoh:

> Pengembangan perangkat lunak sisi peladen: merancang dan membangun antarmuka
> layanan (API), memodelkan dan mengelola basis data, mengoptimalkan performa,
> kontrol versi, kontainerisasi, dan pengujian otomatis. Stack tim saat ini PHP,
> Laravel, MySQL, Git, Docker; pengalaman setara di bahasa dan basis data lain
> tetap dipertimbangkan.

Bukan: `PHP, Laravel, MySQL, REST API, Git, Docker`.

| kandidat | lowongan merek | lowongan konsep | delta |
|---|---|---|---|
| Backend stack sama | 0,9042 | 0,7649 | -0,139 |
| Backend stack beda | 0,7268 | 0,6916 | -0,035 |
| Backend senior Gojek | 0,6819 | **0,6870** | +0,005 |
| Frontend | 0,6876 | **0,6393** | -0,048 |
| Sales retail | 0,5785 | 0,5804 | +0,002 |

Sebelumnya frontend mengalahkan senior Gojek. Sesudahnya ketiga backend berada di
atas frontend tanpa tumpang tindih.

Perhatikan semua angka absolut turun, karena teks lowongan jadi lebih panjang dan
umum. **Ambang skor tidak bisa dipindah antar gaya penulisan lowongan** dan harus
dikalibrasi ulang kalau gayanya berubah.

### Modus 2: kosakata benar tapi kosong isinya (ditangani lewat bukti)

Tidak ada teks lowongan yang bisa mengalahkan penyalinan - penyalin menyalin apa
pun yang tertulis. Penyalin tetap peringkat 1 di kedua versi (0,9592 dan 0,7888).

Yang membedakan penyalin dari CV asli bukan makna, tapi **bukti**: nama tempat
kerja dan rentang waktu. Cosine tidak bisa melihat itu, tapi LLM yang sudah
membaca CV untuk memisah tiga bidang bisa - jadi diminta di panggilan yang sama,
tanpa kuota tambahan (`structure.py`, `SYSTEM_STRUKTUR` butir 5-8).

Diuji pada teks CV mentah lewat jalur produksi (LLM sungguhan, bukan tiruan):

| CV | riwayat terekstrak | hasil |
|---|---|---|
| Penyalin kata kunci | 0 | flag `tanpa_riwayat_kerja` |
| Backend asli | 2 (PT Sinar Digital `Maret 2022 - Januari 2025`, asisten praktikum `2021-2022`) | lolos |
| Sales retail asli | 1 (gerai Erafone Semarang `Agustus 2019 - sekarang`) | lolos |
| Magang sebulan | 1 (CV Mitra Solusi `Juli 2024, 1 bulan`) | lolos |

Syarat "punya bukti" ditegakkan di kode (`structure._riwayat`), bukan dititipkan
ke kepatuhan LLM: entri tanpa perusahaan DAN tanpa periode dibuang walau
jabatannya terisi. Pola yang sama dengan `sanitize.bersihkan()`.

**Skor penyalin sebenarnya 1,0000, bukan 0,9592.** Uji jalur produksi penuh
memperlihatkan hal yang tidak terlihat saat bidang CV dipisah tangan: LLM
mengosongkan bidang `pengalaman` penyalin, karena ia benar menilai "Backend
Developer adalah cita-cita saya" bukan pengalaman. Bidang kosong tidak dinilai
dan bobotnya dinormalkan ulang ke skill dan pendidikan - dua bidang yang persis
disalin dari iklan lowongan. Hasilnya skor sempurna, sementara backend sungguhan
di lowongan yang sama dapat 0,8917. Jadi renormalisasi bobot yang melindungi dari
bug "umur nan" justru menaikkan skor penyalin; keduanya benar dan tidak
saling meniadakan, tapi harus diketahui.

Karena itu syarat flag-nya `not riwayat` saja, bukan `pengalaman terisi AND not
riwayat`. Syarat kedua tidak pernah menyala di kasus yang paling perlu ditangkap.

Fresh graduate jujur ikut tertandai, dan itu memang benar: "CV ini tidak memuat
riwayat kerja" adalah fakta yang perlu dilihat recruiter, bukan tuduhan. Teks
peringatannya menyebut kedua kemungkinan dan menyuruh recruiter membuka CV-nya.

Skornya **tidak diturunkan** oleh flag ini. Menghukum lewat angka berarti menebak
seberapa besar hukumannya; yang dilakukan cuma menampilkan riwayatnya di halaman
review recruiter berikut peringatan.

### Yang sengaja tidak dilakukan

**Membandingkan periode dengan syarat "minimal N tahun" secara otomatis.** Format
periode di CV asli liar: `2021-2022`, `Maret 2022 - Januari 2025`, `Juli 2024, 1
bulan`, sebagian tanpa tahun sama sekali. Parser yang gagal membaca menghasilkan
0 tahun dan menggugurkan kandidat berpengalaman karena format tanggalnya aneh -
persis bug "umur nan" tim DS yang menjatuhkan 1.888 orang. Riwayatnya ditampilkan
apa adanya, manusia yang menilai.

**Melatih model skoring sendiri (A3.4).** Buntu, bukan dugaan: tiga pengukuran
independen memberi AUC 0,510 sampai 0,595 dan AUC di dalam satu posisi sekitar
0,50 (docs/kalibrasi-gate.md). Labelnya tidak memuat sinyal untuk dipelajari.

**LLM sebagai juri** ("apakah CV ini memenuhi syarat, sebutkan buktinya"). Ini
bisa membedakan menyebut dari memakai, tapi hasilnya tidak deterministik, sulit
dipertanggungjawabkan ke kandidat yang protes, dan kuota Gemini gratis sudah
pernah habis (429). Opsi bila pindah ke tier berbayar.

### Yang masih terbuka

Magang 1 bulan tetap berskor 0,7706, di atas senior Gojek. Riwayatnya lolos flag
karena memang ada tempat dan waktunya. Penutupnya bukan perubahan skor, melainkan
baris `Magang @ CV Mitra Solusi [Juli 2024, 1 bulan]` yang kini terbaca recruiter
dalam dua detik di halaman review.

## Ketersediaan bidang: skill hilang di separuh CV

Dari 6.319 CV yang berhasil diekstrak tim DS (`hasil_ekstraksi_cv (2).xlsx`):

| Bidang | Terisi |
|---|---|
| Jenjang pendidikan | 87,9% |
| Institusi | 82,8% |
| **Skill** | **49,6%** |

Dipecah per metode: text-layer 58,0%, mixed 52,9%, **ocr 32,8%**.

Ini bukan kegagalan pipeline kita, melainkan sifat CV-nya: banyak pelamar
memang tidak menulis daftar keahlian. Konsekuensinya struktural terhadap bobot
A3.2:

```
skill      bobot 50%   tersedia ~50% waktu
pengalaman bobot 30%
pendidikan bobot 20%   tersedia ~88% waktu
```

Bidang berbobot terbesar adalah yang paling sering tidak ada. Setiap kali
hilang, bobotnya dinormalkan ulang, sehingga dua angka yang terlihat sama
sebenarnya mengukur hal berbeda. Belum diputuskan cara menanganinya; dua opsi
yang terbuka adalah menurunkan bobot skill agar sebanding dengan
ketersediaannya, atau menandai skor tanpa skill sebagai kategori terpisah di
dashboard recruiter.

## Kegagalan LLM: kuota, bukan gangguan sesaat

Flag `llm_gagal` sempat dikira sporadis. Setelah logging dipasang, penyebabnya
terbaca jelas:

```
WARNING: strukturisasi LLM gagal (percobaan 1/2, 1040 karakter):
         HTTPStatusError: Client error '429 Too Many Requests'
WARNING: strukturisasi LLM gagal (percobaan 2/2, 1040 karakter):
         HTTPStatusError: Client error '429 Too Many Requests'
```

**Kuota Gemini tingkat gratis habis.** Percobaan kedua berjarak 20 detik dan
tetap 429, jadi yang tersentuh batas harian, bukan batas per menit. Ini sebab
utama bidang skill kosong di lamaran produksi, bukan CV-nya.

Yang dilakukan sekarang:

- Setiap kegagalan DICATAT (tipe exception, pesan, panjang teks). Sebelumnya
  `except Exception` telanjang menelan semuanya sehingga tidak bisa didiagnosis.
- Satu kali coba ulang dengan jeda 20 detik, bukan 1 detik. Kuota dihitung per
  satuan waktu, jadi mengulang setelah 1 detik pasti kena 429 lagi.
- **API key disensor** sebelum masuk log (`chat.tanpa_kunci`). httpx menyertakan
  URL lengkap di pesan errornya dan URL Gemini membawa `?key=`, sehingga logging
  polos menulis kredensial ke berkas. Ini sempat terjadi di `uvicorn.log`, sudah
  dibersihkan, dan ada test yang menjaganya.

## Kuota LLM habis: jalur cadangan dan mutunya

Batas tier gratis, dibaca langsung dari balasan 429 (3 Agustus 2026):

```
LLM  gemini-2.5-flash     -> GenerateRequestsPerDayPerProjectPerModel-FreeTier | batas: 20
EMB  gemini-embedding-001 -> HTTP 200 (kuota model terpisah, tidak ikut habis)
```

**20 permintaan per HARI**, bukan per menit. Satu CV memakai 1 panggilan, atau 2
bila percobaan pertama gagal, jadi atap sebenarnya sekitar 10-20 CV per hari.
Endpoint `/chat` memakai model yang sama sehingga ikut memotong jatah itu.

Embedding tidak terpengaruh: mesin skornya tetap hidup, yang mati pembacaan CV-nya.

### Mutu parser heading, diukur pada 299 CV korpus

Saat LLM gagal, `strukturkan_kontekstual()` jatuh ke `strukturkan()` berbasis
heading. Sebaran flagnya:

| flag | jumlah | % |
|---|---|---|
| `tanpa_heading` (seluruh teks ditumpahkan ke pengalaman) | 65 | 21,7% |
| `skill_kosong` (bobot 50% dibuang) | 93 | 31,1% |
| `pendidikan_kosong` | 58 | 19,4% |
| `pengalaman_kosong` | 47 | 15,7% |
| tiga bidang lengkap | 92 | **30,8%** |

Hanya 3 dari 10 CV terbaca utuh. Akibatnya pada skor, lowongan Admin Gudang
dengan CV yang sama:

| CV | kuota habis | kuota sehat |
|---|---|---|
| Berkaitan - admin/stok | **0,6298** | **0,7025** |
| Berkaitan - clerk distribution center | 0,7034 | 0,6567 |
| Tidak berkaitan - S1 Teknik Mesin | 0,6346 | 0,6295 |
| Tidak berkaitan - S1 Biologi | 0,6239 | 0,6254 |

Urutan benar `admin/stok > clerk > Mesin > Biologi` berubah jadi
`clerk > Mesin > admin/stok > Biologi`. Kandidat paling relevan jatuh ke
peringkat 3, di bawah lulusan Teknik Mesin, karena parser heading tidak
menemukan section skill di CV-nya lalu membuang bobot 50%.

### Yang diperbaiki, dan yang sengaja tidak

Skor jalur cadangan dulu tampil **persis sama** dengan skor sehat: `sedang (0,70)`
tanpa penanda apa pun. Sekarang halaman review menandainya `pembacaan kasar`,
menyebutkan bidang mana yang tidak terbaca, dan meminta recruiter tidak
membandingkannya dengan kandidat lain.

Sengaja TIDAK dilakukan: memaksa skor jadi `null` saat `llm_gagal` +
`tanpa_heading`. Sempat diusulkan karena resep rendang mendapat 0,5400 di jalur
ini, tapi 0,54 memang lantai skala yang sudah terukur di bagian "Skala skor" dan
UI menampilkannya sebagai `rendah` - sistemnya sudah menjawab benar. Memaksa null
akan membuang 21,7% CV ke antrian manual selama kuota habis, termasuk CV naratif
sungguhan yang teksnya masih memuat riwayat karier nyata.

Akar masalahnya tetap kuota, bukan kode. Tier berbayar menaikkan batas 20/hari
itu drastis; selama masih gratis, jalankan screening demo di awal hari.

## Dokumen tanpa isi CV tidak diberi skor

Bila LLM mengembalikan ketiga bidang kosong, itu **jawaban**, bukan kegagalan:
dokumennya memang tidak memuat riwayat kerja, skill, maupun pendidikan (mis.
berkas yang isinya cuma transkrip atau sertifikat hasil scan).

Perilaku lama membatalkan jawaban itu dan jatuh ke parser heading. Pada dokumen
tanpa heading, parser menjalankan aturan terakhirnya: SELURUH teks mentah masuk
ke bidang pengalaman. Skornya lalu dihitung dari tumpahan teks itu:

```
tumpahan OCR mentah      0,6567
CV terstruktur benar     0,6568
```

Selisih 0,0001. Skor semacam itu tidak bisa membedakan CV yang terurai rapi dari
dump mentah, dan itu pola bug "umur nan" yang sama.

Sekarang kasus ini menghasilkan flag `tanpa_isi_cv`, skor `null`, dan baris
`ai_verification / flagged` supaya recruiter meninjau. Kandidat TIDAK
digugurkan: status callback tetap `success`.

## Batasan dan tindak lanjutnya

### 1. Skor ini tidak cukup kuat untuk menggugurkan orang (SELESAI)

Skor cosine absolut terkumpul di rentang sempit. Dari 12 CV di atas:

```
min 0,6411 | median 0,6798 | max 0,7117
```

Ambang `GateOne` lama (lolos >= 0,75, gagal < 0,45) membuat **semua** kandidat
masuk zona flagged, sehingga otomasi gate praktis mati.

Skornya bukan asal: uji daya beda 4 CV retail terhadap 3 lowongan menempatkan
urutannya benar tanpa kecuali, dengan celah relevan versus tidak relevan
0,096-0,152.

| CV | Frontliner Retail | Backend Developer | Dokter Anestesi |
|---|---|---|---|
| rata-rata | 0,689 | 0,591 | 0,564 |

Tapi bisa membedakan LOWONGAN tidak sama dengan bisa membedakan KANDIDAT.
Kalibrasi terhadap 7.815 kandidat berlabel (docs/kalibrasi-gate.md) menunjukkan
di dalam satu posisi daya bedanya jatuh ke AUC ~0,50, dan tidak ada ambang
gugur-otomatis yang aman.

Tindak lanjutnya bukan menggeser ambang, melainkan mencabut skor CV dari Gate 1.
Gate 1 sekarang diputus assessment; skor CV dipakai di Gate 2 bersama skor
interview. Lihat `docs/gate-logic.md`.

### 2. Bobot 50/30/20 hampir tidak pernah berlaku (TERUKUR, KEPUTUSAN TERBUKA)

Dari 12 CV uji, hanya 3 yang ketiga bidangnya terisi:

| Bidang terisi | Jumlah CV |
|---|---|
| 3 bidang | 3 |
| 2 bidang | 6 |
| 1 bidang | 3 |

Diukur ulang pada **3.391 lamaran berlabel** yang punya teks lowongan
(`kalibrasi/bobot_bidang.py`, kunci join email). Sebuah bidang hanya dinilai bila
KEDUA sisi terisi - sisi CV dan sisi lowongan:

| Bidang | dinilai | % |
|---|---|---|
| pengalaman | 3.391 | 99,9% |
| pendidikan | 640 | 18,8% |
| **skill** | **240** | **7,1%** |

| Jumlah bidang per lamaran | | |
|---|---|---|
| 1 bidang | 2.544 | **75,0%** |
| 2 bidang | 814 | 24,0% |
| 3 bidang | 33 | 1,0% |

Konsekuensinya tajam. Saat cuma satu bidang yang dinilai, renormalisasi membuat
skor akhir **sama persis dengan cosine bidang itu, berapa pun bobotnya**. Dan
bidang tunggal itu selalu `pengalaman` - 2.544 dari 2.544 kasus.

> **Untuk 75% lamaran, bobot 50/30/20 tidak berpengaruh sama sekali. Skornya
> adalah kemiripan pengalaman, titik.** Bobot baru mengikat di 847 lamaran (25%,
> 57 di antaranya Hired).

Ironisnya skill memegang bobot terbesar (50%) tapi paling jarang bisa dipakai
(7,1%). Sisi lowongan ikut menyumbang: kolom `Requirements` kosong justru pada
posisi terbanyak - Sales Assistant Retail Gadget (770 lamaran), Sales Advisor
(293), Sales Assistant Erafone (196).

Ini juga menjelaskan celah penyalin kata kunci dari arah lain. Skor 1,0000 itu
lahir dari pola yang KEBALIKAN dari data nyata: pengalaman kosong sementara skill
dan pendidikan terisi. Di lapangan yang lazim justru sebaliknya.

**Kunci join nama diganti email.** Nama hanya mempertemukan 1.766 orang antara
berkas label dan berkas ekstraksi; email mempertemukan 2.780 (+57%), karena
ejaan, gelar, dan urutan kata berbeda antar berkas. `experience_data` dan
`education_data` tetap terpaksa lewat nama - kolom emailnya kosong total.

Yang belum: AUC per bidang dan pencarian bobot terbaik pada 847 lamaran yang
mengikat itu. Terhalang kuota, bukan metode - lihat catatan kuota di bawah.

**Kuota embedding: 1.000 per hari.** `EmbedContentRequestsPerDayPerUserPerProject
PerModel-FreeTier`, dan `batchEmbedContents` dihitung PER ITEM bukan per
panggilan. Analisis ini butuh 1.775 teks unik, jadi minimal dua hari di tier
gratis. Vektornya di-cache ke `kalibrasi-out/cache_embedding.pkl` sehingga
menjalankan ulang melanjutkan, bukan membayar ulang.

Bidang kosong sengaja TIDAK dinilai 0. Menilai 0 karena data tidak terbaca
adalah pola bug yang menggugurkan kandidat di pipeline tim DS. Angkanya
terverifikasi langsung dari `hasil_verifikasi_per_cv.csv`:

```
GUGUR total 1.981, yang kolom Umur-nya KOSONG 1.888 (95,3%)
alasan tertulis harfiah: "umur nan di luar syarat 18-28"  783
                         "umur nan di luar syarat 19-27"  606
                         "umur nan di luar syarat 18-26"  252
```

`nan` adalah penanda data kosong. Jadi 1.888 kandidat digugurkan bukan karena
umurnya di luar syarat, melainkan karena umurnya tidak terbaca. (Dokumen versi
sebelumnya menyebut 1.839; angka terverifikasi adalah 1.888.)

Di sini bobotnya dipindahkan ke bidang yang ada dan kekosongannya di-flag untuk
review recruiter.

### 3. Kalibrasi belum pernah menyentuh model yang berjalan (BELUM)

Seluruh angka AUC di `docs/kalibrasi-gate.md` berasal dari TF-IDF sebagai
padanan lokal. Produksi memakai embedding Gemini. Keduanya cosine atas teks yang
sama, tapi bukan angka yang identik, sehingga ambang produksi tetap perlu diukur
pada skor produksi. Ini pekerjaan Fase 5.

## Ambang yang dipakai dan alasannya

| Konstanta | Nilai | Dasar |
|---|---|---|
| `MIN_KARAKTER` | 200 | Sampel: berhasil >= 1.318 karakter, gagal tepat 0 |
| `MIN_KARAKTER_HALAMAN` | 100 | Sampel: halaman teks 1.470-6.558, halaman gambar 0 |
| `DPI` OCR | 300 | Rekomendasi Tesseract untuk font kecil |
| `BAHASA` OCR | `ind+eng` | CV Indonesia, istilah teknis sering Inggris |
| `MAX_TEKS_LLM` | 12.000 | CV terpanjang di sampel sekitar 15.000 karakter |

## Cara menjalankan

```bash
cd app/ai-service && .venv/Scripts/python -m uvicorn main:app --port 8000
cd app/webapp && php spark serve --port 8080
```

Butuh di `webapp/.env`: `aiservice.sharedToken` (token bersama jalur internal;
kosong = kedua endpoint internal menolak semua request).

**ai-service harus hidup saat kandidat melamar.** `Lamaran::kirim` sengaja tidak
menggagalkan lamaran bila ai-service mati, tapi job screening-nya ikut hilang dan
kandidat berakhir tanpa skor. Ini bukan hipotesis: pernah terjadi di basis data
lokal, 7 lamaran tanpa skor dan 4 warning "screening tidak terkirim" di log.

Jaring pengamannya:

```bash
php spark screening:resend          # kirim ulang semua lamaran yang belum berskor
php spark screening:resend --dry    # lihat dulu, tidak mengirim apa pun
php spark screening:resend --id 37  # satu lamaran saja
```

Idempoten: lamaran yang sudah punya skor nyata dilewati, jadi aman dijalankan
berulang. Skor susulan masuk sebagai baris BARU di `candidate_stage_history`,
bukan menimpa baris "belum ada skor" yang lama, supaya koreksinya terlihat di
riwayat.

Butuh Tesseract terpasang. Di mesin dev ini `ind.traineddata` berada di
`%LOCALAPPDATA%\tessdata` karena Program Files menolak tulis tanpa admin;
`ocr.py` menemukannya otomatis, atau hormati `TESSDATA_PREFIX` bila diset.
