# Kalibrasi Saran Posisi

Dokumen bukti untuk satu keputusan desain: **saran posisi diperingkat dari
bidang pengalaman saja, bukan dari rumus berbobot 50/30/20.**

Skrip yang menghasilkannya: `app/kalibrasi/saran_posisi.py`, bisa dijalankan
ulang. Nol panggilan LLM; yang dipakai cuma kuota embedding, dan hasilnya
di-cache ke `(E-rec)/kalibrasi-out/cache_embedding.pkl` yang sama dengan
`bobot_bidang.py`.

## Pertanyaannya

Fitur saran posisi menawarkan tiga lowongan lain kepada kandidat yang
digugurkan karena salah posisi. Ia berdiri di atas skor kemiripan CV - skor
yang **sudah pernah diukur dan hasilnya lemah** untuk memilih kandidat:
ROC-AUC 0,589, dan di dalam satu posisi 0,499 (lihat `kalibrasi-gate.md`).

Tapi arah pertanyaannya berbeda:

| | Diukur di | Pertanyaan |
|---|---|---|
| Gate 1 | `kalibrasi-gate.md` | kandidat mana yang terbaik **untuk posisi ini** |
| Saran posisi | dokumen ini | posisi mana yang paling cocok **untuk kandidat ini** |

Arah kedua belum pernah diukur, dan tidak boleh diasumsikan ikut lemah maupun
ikut kuat.

**Pembandingnya:** menebak 3 dari N posisi secara acak. Kalau fitur ini tidak
mengalahkan angka itu dengan meyakinkan, ia bukan saran - ia generator angka
acak berbungkus kalimat sopan, dikirim ke orang yang sedang kecewa ditolak.

## Cara mengukur

Untuk tiap kandidat yang **benar-benar diterima kerja**, seluruh posisi
diperingkat menurut kecocokan CV-nya. Lalu dilihat: posisi yang sungguh
menerima dia, mendarat di peringkat berapa?

- `hit@3` = berapa persen kandidat yang posisi aslinya masuk tiga besar.
- Empat cara diperingkat berdampingan: berbobot 50/30/20, dan tiap bidang
  sendiri-sendiri.

Dua sumber teks CV dipakai, dan bedanya penting:

| Sumber | Kandidat diterima | Posisi | Median teks |
|---|---|---|---|
| `dataset_berlabel.csv` - ringkasan xlsx tim DS | 143 | 21 | 148 karakter |
| `dataset_ekstraksi_kita.csv` - PDF asli lewat pipeline Fase 4 | 52 | 11 | ~1.760 karakter |

Yang kedua mencerminkan teks yang benar-benar dibaca sistem berjalan, tapi
jumlahnya jauh lebih sedikit. Keduanya dilaporkan apa adanya.

## Hasil

### Data ringkasan tim DS (143 kandidat, 21 posisi)

Tebakan acak: 3/21 = **14,3%**

| Cara | n | hit@1 | hit@3 | hit@5 | Peringkat tengah | vs acak |
|---|---|---|---|---|---|---|
| berbobot 50/30/20 | 143 | 12,6% | **28,0%** | 44,8% | 8 / 21 | **1,96x** |
| pengalaman saja | 143 | 11,9% | 27,3% | 46,2% | 8 / 21 | 1,91x |
| pendidikan saja | 54 | 3,7% | 11,1% | 13,0% | 7 / 21 | **0,78x** |
| skill saja | 4 | - | - | - | - | cakupan 4%, tidak sah dinilai |

### Data teks produksi (52 kandidat, 11 posisi)

Tebakan acak: 3/11 = **27,3%**

| Cara | n | hit@1 | hit@3 | hit@5 | Peringkat tengah | vs acak |
|---|---|---|---|---|---|---|
| **pengalaman saja** | 52 | **26,9%** | **51,9%** | 75,0% | **3 / 11** | **1,90x** |
| berbobot 50/30/20 | 52 | 17,3% | 38,5% | 63,5% | 4 / 11 | 1,41x |
| skill saja | 12 | 8,3% | 8,3% | 16,7% | 7 / 11 | cakupan 26%, n=12 |

## Bacaannya

**Fiturnya nyata, tapi sederhana.** Di kedua data ia mengalahkan tebakan acak
sekitar dua kali lipat. Pada teks produksi, posisi yang benar-benar menerima
kandidat masuk tiga besar 51,9% kali - lebih dari separuh, dengan peringkat
tengah 3 dari 11.

**Pengalaman yang membawa sinyalnya.** Konsisten di kedua data (1,90x dan
1,91x), dan pada teks produksi ia mengungguli rumus berbobot dengan selisih
besar: 51,9% lawan 38,5%.

**Pendidikan bukan sinyal lemah, ia gangguan.** 0,78x, yaitu LEBIH BURUK
daripada menebak. Pada rumus berbobot ia memegang 20%, dan itulah yang menyeret
hasilnya turun.

**Skill tidak bisa dinilai di sini.** Data historisnya cuma terisi 4% dan 26%.
Angka buruknya vonis atas ketiadaan data, bukan atas skill. Pipeline produksi
mengekstraksi skill dari PDF asli dengan cakupan jauh lebih tinggi, jadi
pertanyaan ini terbuka - ukur lagi kalau sudah ada cukup CV yang bidang
skill-nya terbaca, jangan tebak.

## Keputusannya

`App\Libraries\SaranPosisi::BIDANG = 'pengalaman'`. Satu bidang, jadi tidak ada
bobot karangan yang harus dibela ke siapa pun.

**Skor kemiripan CV di gate TIDAK ikut berubah.** Yang berubah cuma cara
memeringkat saran. Keduanya menjawab pertanyaan berbeda dan berhak memakai
rumus berbeda.

Ambang penyaringnya relatif: yang disarankan hanya posisi yang kecocokannya
tidak lebih rendah daripada posisi yang baru saja menolak kandidat. Dengan
hit@3 51,9%, separuh saran memang meleset - mengarang ambang tetap "cocok"
akan menjanjikan kepastian yang datanya tidak punya.

## Batas yang wajib ikut disebut

1. **Labelnya "diterima kerja", bukan "saran ini bagus".** Kandidat yang
   sebenarnya cocok di posisi X tapi tidak diterima di sana terhitung meleset.
   Angka di atas adalah **batas bawah**.
2. **Data produksi cuma 52 orang dan 11 posisi.** Sistem berjalan punya 34
   posisi, jadi tebakan acaknya lebih sulit (8,8%) dan angka fiturnya juga akan
   bergeser. Ukur ulang setelah ada cukup kandidat sungguhan.
3. **Yang diukur peringkat, bukan kepuasan kandidat.** Apakah orang yang
   menerima saran benar-benar melamar dan diterima di sana - itu pertanyaan
   lain, dan baru bisa dijawab setelah fiturnya berjalan beberapa bulan.

## Menjalankan ulang

```bash
cd app/kalibrasi
"C:\Program Files\Python311\python.exe" saran_posisi.py --hanya-siapkan
"C:\Program Files\Python311\python.exe" saran_posisi.py
"C:\Program Files\Python311\python.exe" saran_posisi.py --dataset dataset_ekstraksi_kita.csv
```

`--hanya-siapkan` menghitung berapa teks yang belum ada di cache sebelum satu
pun kuota terpakai. Keluarannya `hasil_saran_posisi.json` dan
`hasil_saran_posisi_ekstraksi.json` di `(E-rec)/kalibrasi-out/`.
