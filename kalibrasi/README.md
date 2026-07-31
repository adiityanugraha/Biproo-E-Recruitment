# Kalibrasi & Pelatihan Skorer CV (Fase 5)

Membangun dan mengukur skorer CV terhadap label diterima/tidak dari data
historis tim DS. Sepenuhnya lokal: nol panggilan API, nol kuota Gemini.

## Prasyarat

- Python sistem `C:\Program Files\Python311\python.exe` (sudah berisi pandas,
  sklearn, scipy, matplotlib - JANGAN pakai venv ai-service, tidak ada pandas).
- 4 berkas tim DS di `(E-rec)\data\`: `SOC_SOH pebaikan.xlsx`,
  `experience_data.xlsx`, `education_data.xlsx`, `work_description.xlsx`.

## Dua sumber teks CV - beda jauh, jangan tertukar

| Sumber | Median teks | Berlaku utk produksi? |
|---|---|---|
| `dataset_berlabel.csv` (dari xlsx DS) | 148 karakter | **tidak** |
| `dataset_ekstraksi_kita.csv` (PDF asli + pipeline Fase 4) | ~1.760 karakter | ya |

Produksi membaca PDF/gambar asli dan menghasilkan 1.300-15.000 karakter. Ambang
yang dikalibrasi pada teks 148 karakter TIDAK sah dipakai di sistem berjalan.
Tahap 2 di bawah menutup selisih itu.

Sumber pertama tetap berguna: datanya 4,5x lebih banyak (7.815 vs 1.729), jadi
selang kepercayaannya lebih rapat untuk menjawab "ada sinyal atau tidak".

## Cara menjalankan (3 perintah, urut)

Dari folder ini (`app\kalibrasi`):

```bash
"C:\Program Files\Python311\python.exe" jalankan_test.py
```

Harus berakhir `9 lolos, 0 gagal`. Kalau tidak, berhenti dan laporkan.

```bash
"C:\Program Files\Python311\python.exe" siapkan_data.py
```

Sekitar 1-2 menit (membaca 4 Excel besar). Menulis
`(E-rec)\kalibrasi-out\dataset_berlabel.csv` - DI LUAR repo karena turunan
data PII. Sanity check keluarannya: baris sekitar 7.800, Hired sekitar 340.

```bash
"C:\Program Files\Python311\python.exe" latih_dan_ukur.py
```

Sekitar 1-3 menit. Ini langkah "training"-nya: fit TF-IDF + regresi logistik
dalam 5-fold cross-validation, plus bootstrap 2.000 kali untuk selang
kepercayaan. Keluaran di `(E-rec)\kalibrasi-out\`:

| Berkas | Isi |
|---|---|
| `hasil_kalibrasi.json` | AUC ketiga skorer + ambang usulan + koefisien Platt |
| `auc_per_posisi.csv` | AUC dipecah per posisi (hanya posisi dengan >= 5 hired) |
| `kalibrasi.png` | sebaran skor Hired vs Not Hired + kurva ROC |

## Cara membaca hasilnya

Tiga skorer dibandingkan pada label yang sama:

- **A cosine TF-IDF** - kemiripan teks CV vs teks lowongan, tanpa pelatihan.
  Inilah padanan lokal dari skor produksi (yang memakai embedding Gemini).
- **B1 classifier teks CV saja** - model terlatih, fitur murni isi CV.
- **B2 classifier teks CV + posisi** - B1 plus posisi yang dilamar.

Selisih B2 - B1 = porsi "sinyal" yang cuma berasal dari tingkat penerimaan
posisi, bukan dari isi CV. Jangan terkecoh AUC B2 yang lebih tinggi.

Patokan AUC: 0,5 = tebak koin; 0,6 = sinyal lemah; 0,7 = cukup berguna;
di atas 0,8 = kuat (dan patut dicurigai bocor pada data sekecil ini).

## Angka smoke test (bootstrap 300, punya Anda akan sedikit berbeda)

```
A  cosine TF-IDF     AUC 0.589 [0.539 - 0.644]
B1 teks CV saja      AUC 0.605 [0.577 - 0.631]
B2 teks CV + posisi  AUC 0.714 [0.689 - 0.741]
```

Bacaan jujurnya: isi CV sendiri membawa sinyal LEMAH (0,59-0,61). Lonjakan ke
0,71 di B2 sebagian besar dari posisi, bukan CV. Ambang `lower` yang keluar
dari aturan konservatif adalah 0,0 - artinya pada data ini TIDAK ADA titik
gugur-otomatis yang aman: sebagian kandidat yang akhirnya diterima punya skor
cosine mendekati nol.

## Batasan (ikut dilaporkan, jangan dihilangkan)

- Label "Hired" = hasil seluruh funnel (assessment, interview, headcount),
  bukan kualitas CV semata. Model belajar preferensi historis, termasuk biasnya.
- Join CV ke label lewat NAMA (kolom NIK/email di berkas experience kosong).
  Nama kembar yang ada di dua label sudah dibuang (163 orang).
- Atribut sensitif (umur, gender, alamat, telepon, gaji) dibuang dari fitur
  dan dari teks lowongan; ada assert yang menjaga ini di siapkan_data.py.
- Skorer A di sini memakai TF-IDF, sedangkan produksi memakai embedding Gemini.
  Kalibrasi ambang produksi tetap butuh pengukuran pada skor produksi.
