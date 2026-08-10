# Skema Kondisi Gate 1 & Gate 2

Sumber: Blueprint A4. Dokumen ini menggambarkan perilaku yang BERJALAN, bukan
rencana. Aturan Gate 1 di sini menggantikan skema skor gabungan dari Fase 1;
dasar perubahannya ada di `docs/kalibrasi-gate.md`.

Implementasi:

| Bagian | Berkas | Uji |
|---|---|---|
| Gate 1 | `webapp/app/Libraries/GateOne.php` | `tests/unit/GateOneTest.php` |
| Gate 2 | `webapp/app/Libraries/GateTwo.php` | `tests/unit/GateTwoTest.php` |

---

# Gate 1: diputus assessment

## Aturan

```
gate_1 = lulus assessment ? 'passed' : 'failed'
```

Itu saja. Tidak ada bobot, tidak ada ambang, tidak ada zona flagged.

**Skor CV TIDAK ikut memutus Gate 1.** Skornya tetap dihitung dan disimpan di
`screening_results`, lalu dipakai di Gate 2.

## Kenapa skor CV dikeluarkan dari Gate 1

Fase 1 merancang Gate 1 sebagai skor gabungan CV 50% + assessment 50% dengan
ambang 0,75 / 0,45. Angka-angka itu ditandai [ASUMSI] dan belum pernah diuji.
Setelah diukur pada 7.815 kandidat berlabel historis, asumsinya tidak bertahan:

| Temuan | Angka |
|---|---|
| Daya beda skor kecocokan CV | ROC-AUC 0,589 (CI95 0,540 - 0,639) |
| Daya beda di dalam satu posisi, 3 posisi terbesar | 0,499 / 0,513 / 0,559 |
| Ambang gugur-otomatis paling aman yang bisa ditemukan | 0,0, yaitu tidak ada |

AUC 0,50 berarti setara lempar koin. Di dalam satu posisi, yaitu justru
perbandingan yang dipakai gate, skor CV nyaris tidak memisahkan kandidat yang
akhirnya diterima dari yang ditolak. Dan pencarian ambang dengan target presisi
0,15 mengembalikan `lower = 0,0`: tidak ada titik potong yang menggugurkan
siapa pun tanpa ikut menggugurkan kandidat yang sebenarnya diterima.

Menggugurkan orang di Gate 1 memakai angka itu berarti menolak pelamar
berdasarkan sinyal yang tidak terbukti. Assessment adalah sinyal yang jelas dan
bisa dipertanggungjawabkan ke kandidat, jadi Gate 1 memakai itu.

## Kapan Gate 1 dievaluasi

Saat kandidat menyelesaikan assessment (`Lamaran::jawabAssessment`). Gate 1
tidak lagi menunggu skor CV, sehingga balapan "assessment selesai sebelum
callback screening tiba" hilang dengan sendirinya.

## Baris riwayat yang ditulis

| Kejadian | Baris `candidate_stage_history` | Email |
|---|---|---|
| Assessment selesai | `online_assessment / passed atau failed / system` | tidak ada |
| Keputusan gate | `gate_1 / passed atau failed / system` | `hasil_gate` |

Catatan pada baris `gate_1` menyebut skor CV bila ada, sebagai informasi untuk
recruiter, bukan sebagai dasar keputusan:

```
Keputusan dari hasil assessment. Skor CV 66/100 dipakai di Tahap 2
```

Bila skor CV belum ada, catatannya berhenti di kalimat pertama dan alurnya
tetap jalan. Tidak ada angka yang dikarang untuk mengisi kekosongan.

---

# Gate 2: skor CV + skor interview

Prinsipnya tidak berubah: **sistem tidak pernah memutus otomatis di Gate 2.**
Sistem menghitung rekomendasi, keputusan akhir selalu recruiter (Blueprint A4).

Di sinilah skor CV terpakai.

## Input

| Input | Sumber | Skala |
|---|---|---|
| Skor kecocokan CV | `screening_results.score_overall` baris terbaru | 0,00 - 1,00 |
| Skor interview | diisi recruiter setelah `interview_online` | 0 - 100, dinormalkan |

## Rumus

```
skor_akhir  = (w_cv * skor_cv) + (w_interview * skor_interview)
rekomendasi = skor_akhir >= threshold_rekomendasi ? 'hire' : 'no-hire'
```

Default: `w_cv = 0.4`, `w_interview = 0.6`, `threshold_rekomendasi = 0.7`.
Interview diberi porsi lebih besar karena penilaian manusia langsung, sedangkan
skor CV terbukti berdaya beda lemah.

Bisa ditimpa per posisi lewat `jobs.bobot_json` dan `jobs.threshold_json`:

```json
{ "gate2": { "cv": 0.4, "interview": 0.6 } }
{ "gate2": { "rekomendasi": 0.7 } }
```

JSON kosong atau rusak jatuh ke default, tidak melempar error.

## Bila skor CV tidak ada: keputusan manual

**Rumus di atas tidak dipakai.** Kandidat ditandai `gate_2 / flagged` dan
keputusannya diserahkan sepenuhnya ke recruiter lewat tombol Loloskan / Tidak
Lolos di tab Completed (`Recruiter::putusGate2`).

Sebelum 10 Agustus 2026 bobot CV dialihkan seluruhnya ke interview. Itu terlihat
adil, tapi diam-diam berarti kandidat yang CV-nya gagal terbaca dinilai dengan
**aturan yang berbeda** dari kandidat di baris sebelahnya, tanpa penanda apa pun
di layar. Satu komponen hilang berubah jadi rumus lain, bukan jadi pertanyaan.

Skor interview tetap dihitung, disimpan, dan ditampilkan sebagai bahan
pertimbangan; yang dicabut cuma kewenangannya memutus sendirian. Catatan
riwayatnya:

```
Skor interview 83/100 (dari 12 kompetensi), terlemah: Ketelitian.
Skor CV tidak tersedia, keputusan diserahkan ke recruiter
```

Email ke kandidat baru terkirim setelah recruiter benar-benar memutuskan, bukan
saat penilaian interview disimpan.

## Alur keputusan

Recruiter mengisi skor interview lalu menekan putusan; `Recruiter::putusInterview`
menulis dua baris sekaligus:

| Kejadian | Baris `candidate_stage_history` | Email |
|---|---|---|
| Skor interview masuk | `interview_online / passed / recruiter:<nama>` | tidak ada |
| Putusan gate, skor CV ada | `gate_2 / passed atau failed / recruiter:<nama>` | `hasil_gate` |
| Putusan gate, skor CV tidak ada | `gate_2 / flagged / recruiter:<nama>` | tidak ada |
| Keputusan manual menyusul | `gate_2 / passed atau failed / recruiter:<nama>` | `hasil_gate` |

Kedua hasil Gate 2 mengirim email, lolos maupun tidak (`StageLogger::EMAIL_MAP`).
Setelah `gate_2 / passed`, kandidat masuk `berkas_kontrak / entered`.

Rekomendasi sistem ikut disimpan sebagai `note` pada baris `gate_2`, supaya
selisih antara rekomendasi mesin dan keputusan manusia bisa diaudit belakangan.

Satu-satunya zona `flagged` di Gate 2 adalah kandidat tanpa skor CV. Untuk yang
skor CV-nya ada, tidak ada zona flagged: rekomendasi sistem langsung menjadi
baris `passed`/`failed`, dan recruiter yang menekan tombolnya.

---

# Yang perlu validasi atasan

1. Bobot Gate 2: CV 40 vs interview 60, dan threshold rekomendasi 0,7. Ketiganya
   masih [ASUMSI] dan belum dikalibrasi terhadap data hasil interview, karena
   sistem ini belum mengumpulkan skor interview sendiri.
2. Bentuk penilaian interview: skala angka atau rubrik. Mempengaruhi normalisasi.
3. Apakah skor CV yang rendah perlu ditampilkan sebagai peringatan di dashboard
   recruiter, atau cukup sebagai angka netral. Saat ini: angka netral dengan
   warna, tanpa anjuran keputusan.
