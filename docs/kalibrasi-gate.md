# Kalibrasi Skor CV terhadap Kelolosan Kandidat

Dokumen bukti untuk satu keputusan desain: **skor kecocokan CV dicabut dari
Gate 1.** Isinya angka terukur, termasuk yang tidak enak dibaca.

Skrip yang menghasilkannya ada di `app/kalibrasi/` dan bisa dijalankan ulang.
Keluarannya ditulis ke `(E-rec)/kalibrasi-out/`, di LUAR repo, karena turunan
data pribadi.

## Pertanyaannya

Bukan "berapa skor kandidat ini", melainkan: **kalau skor CV dipakai untuk
meloloskan atau menggugurkan orang, apakah keputusannya lebih baik daripada
lempar koin?**

Label yang dipakai: kandidat historis yang akhirnya `Hired` versus tidak.

## Dua tahap, dua sumber teks

| Tahap | Sumber teks CV | Baris | Hired | Median karakter |
|---|---|---|---|---|
| 1 | ringkasan terstruktur tim DS (xlsx) | 7.815 | 339 (4,3%) | 148 |
| 2 | PDF asli lewat pipeline Fase 4 | 619 | 120 (19,4%) | 1.891 |

Tahap 2 ada karena tahap 1 mengukur teks yang bukan teks produksi. Sistem
berjalan membaca PDF asli dan menghasilkan 1.300-15.000 karakter, jadi ambang
yang dikalibrasi atas teks 148 karakter tidak sah dipakai.

Tahap 1 tetap dipakai untuk menjawab "ada sinyal atau tidak", karena datanya
12x lebih banyak sehingga selang kepercayaannya jauh lebih rapat.

## Hasil tahap 1 (7.815 kandidat)

Tiga skorer diuji pada label yang sama:

| Skorer | n | AUC | CI95 | F1 |
|---|---|---|---|---|
| A cosine TF-IDF (padanan skor produksi) | 3.396 | 0,589 | 0,540 - 0,639 | 0,122 |
| B1 classifier, teks CV saja | 7.815 | 0,594 | 0,562 - 0,626 | 0,112 |
| B2 classifier, teks CV + posisi | 7.815 | 0,712 | 0,681 - 0,742 | 0,200 |

Patokan AUC: 0,5 setara lempar koin, 0,6 sinyal lemah, 0,7 cukup berguna.

Lonjakan B2 menggoda, tapi selisih B2 dikurangi B1 adalah porsi sinyal yang
datang dari **tingkat penerimaan posisi**, bukan dari isi CV. Posisi dengan
banyak lowongan meloloskan lebih banyak orang, dan model belajar itu. Menyebut
0,712 sebagai "akurasi model CV" akan menyesatkan.

### Di dalam satu posisi, sinyalnya habis

Perbandingan yang sebenarnya dilakukan gate bukan antar-posisi, melainkan
antar-kandidat di posisi yang sama. AUC dipecah per posisi:

| Posisi | n | Hired | AUC |
|---|---|---|---|
| Sales Assistant - Retail Gadget (Ibox) | 770 | 19 | **0,499** |
| Retail Gadget Ibox | 577 | 10 | 0,662 |
| Sales Advisor | 293 | 6 | 0,517 |
| Retail Gadget Erafone | 266 | 44 | 0,559 |
| Sales Assistant - Retail Gadget (Erafone) | 196 | 18 | **0,513** |
| Frontliner Junior Staff | 125 | 9 | **0,446** |

Tiga posisi terbesar berada di 0,50, dan satu di bawahnya. Pada posisi tempat
sistem ini akan paling sering dipakai, skor CV tidak membedakan apa pun.

### Tidak ada ambang gugur-otomatis yang aman

Pencarian ambang dengan target presisi 0,15 mengembalikan:

```
lower = 0,0    tergugur otomatis 0,0%    hired yang ikut tergugur 0
```

Artinya: tidak ada titik potong yang menggugurkan sebagian kandidat tanpa ikut
menggugurkan kandidat yang sebenarnya diterima. Aturan konservatifnya memilih
tidak menggugurkan siapa pun, dan itu jawaban yang benar.

## Hasil tahap 2 (619 CV, ekstraksi pipeline sendiri)

| Skorer | n | AUC | CI95 |
|---|---|---|---|
| A cosine TF-IDF | 211 | 0,503 | 0,413 - 0,593 |
| B1 teks CV saja | 619 | 0,595 | 0,536 - 0,649 |
| B2 teks CV + posisi | 619 | 0,777 | 0,731 - 0,820 |

Teks 12x lebih panjang **tidak memperbaiki apa pun**: B1 bergerak dari 0,594 ke
0,595. Selang kepercayaan skorer A mencakup 0,50, artinya pada data ini kita
tidak bisa membedakannya dari tebakan acak.

Ini menutup satu dugaan yang wajar: bahwa sinyalnya lemah karena teks DS terlalu
pendek. Ternyata bukan. Isi CV memang tidak memprediksi kelolosan untuk posisi
retail entry-level ini.

### Cacat yang harus ikut dilaporkan

Opsi `--maks-negatif` dipakai untuk memangkas waktu ekstraksi dari 2,7 jam
menjadi sekitar 50 menit, dengan cara mengambil sebagian kandidat tidak-diterima
saja. Akibatnya base rate sampel tahap 2 menjadi 19,4%, padahal populasinya
7,0%.

Konsekuensinya, dari tahap 2:

| Metrik | Boleh dibandingkan dengan tahap 1? |
|---|---|
| ROC-AUC | ya, tidak terpengaruh base rate |
| PR-AUC, presisi, F1, akurasi, ambang | **tidak** |

Angka `upper = 0,0` pada tahap 2 adalah gejala cacat ini, bukan temuan.
Perbaikannya belum dikerjakan: sampel bertahap seharusnya ditulis ke berkas
terpisah, dan skrip seharusnya memperingatkan saat base rate tidak mewakili.

## Batasan yang berlaku untuk kedua tahap

- Label `Hired` adalah hasil seluruh funnel: assessment, interview, headcount.
  Bukan kualitas CV semata. Model belajar preferensi historis, termasuk biasnya.
- CV dijodohkan ke label lewat NAMA, karena kolom NIK dan email di berkas
  experience kosong. 163 nama kembar yang muncul di dua label sudah dibuang.
- Atribut sensitif (umur, gender, alamat, telepon, gaji) dibuang dari fitur dan
  dari teks lowongan. Ada assert yang menjaga ini di `siapkan_data.py`.
- Skorer A memakai TF-IDF, sedangkan produksi memakai embedding Gemini. Keduanya
  cosine atas teks yang sama, tapi bukan angka yang identik.

## Keputusan yang diambil

| Sebelum | Sesudah |
|---|---|
| Gate 1 = 0,5 skor CV + 0,5 assessment, ambang 0,75 / 0,45 | Gate 1 = hasil assessment saja |
| Skor CV bisa menggugurkan kandidat | Skor CV tidak pernah menggugurkan |
| Skor CV tidak dipakai di Gate 2 | Skor CV = 40% bobot Gate 2, bersama interview 60% |
| Skor CV kosong diisi `mt_rand` | Skor CV kosong dibiarkan kosong, bobot dialihkan |

Bobot 40/60 di Gate 2 sendiri **belum dikalibrasi** dan masih [ASUMSI], karena
sistem ini belum mengumpulkan skor interview sendiri. Yang sudah terbukti hanya
arahnya: skor CV pantas mendapat porsi lebih kecil daripada penilaian manusia.

## Menjalankan ulang

```bash
cd app/kalibrasi
"C:\Program Files\Python311\python.exe" jalankan_test.py      # harus 9 lolos, 0 gagal
"C:\Program Files\Python311\python.exe" siapkan_data.py       # 1-2 menit
"C:\Program Files\Python311\python.exe" latih_dan_ukur.py     # 1-3 menit
```

Pakai Python sistem, bukan venv ai-service (tidak ada pandas di sana).
Seluruhnya lokal: nol panggilan API, nol kuota Gemini.
