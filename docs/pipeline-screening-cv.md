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
ai_verification    passed   skor_cv=0.6798 (ai-service, fase4-embedding-cosine-v1)
online_assessment  passed   nilai=1
gate_1             passed   skor_gabungan=0.8399
baris screening_results provider='dummy': 0
```

## Perbandingan jumlah teks terhadap ekstraksi tim DS

| Metode | Rasio karakter kita / DS |
|---|---|
| text-layer | 1,37x |
| ocr | 1,24x |
| mixed | 2,94x |

Keunggulan pada `mixed` berasal dari deteksi per halaman: dokumen lolos ambang
total, tapi 70-93% halamannya lampiran scan tanpa teks. Ambang tingkat-dokumen
melewatkan halaman-halaman itu tanpa jejak.

## Dua batasan yang belum selesai

### 1. Ambang gate belum terkalibrasi

Skor cosine absolut terkumpul di rentang sempit. Dari 12 CV di atas:

```
min 0,6411 | median 0,6798 | max 0,7117
```

Ambang `GateOne` bawaan (lolos >= 0,75, gagal < 0,45) membuat **semua**
kandidat masuk zona flagged, sehingga otomasi gate praktis mati.

Skornya valid, hanya skalanya berbeda. Uji daya beda 4 CV retail terhadap 3
lowongan:

| CV | Frontliner Retail | Backend Developer | Dokter Anestesi |
|---|---|---|---|
| rata-rata | 0,689 | 0,591 | 0,564 |

Urutannya benar untuk keempat CV tanpa kecuali. Celah relevan versus tidak
relevan 0,096-0,152.

Kalibrasi ambang dijadwalkan Fase 5 dan butuh distribusi dari lebih banyak CV.
Angka awal yang tersedia: median 0,68 dan celah sekitar 0,10-0,15.

### 2. Bobot 50/30/20 jarang berlaku utuh

Dari 12 CV, hanya 3 yang ketiga bidangnya terisi. Sisanya dinilai atas 1-2
bidang dengan bobot dinormalkan ulang:

| Bidang terisi | Jumlah CV |
|---|---|
| 3 bidang | 3 |
| 2 bidang | 6 |
| 1 bidang | 3 |

Bidang kosong sengaja TIDAK dinilai 0. Menilai 0 karena data tidak terbaca
adalah pola bug yang menggugurkan 1.839 kandidat di pipeline tim DS ("umur
nan" dianggap di luar rentang syarat). Di sini bobotnya dipindahkan ke bidang
yang ada dan kekosongannya di-flag untuk review recruiter.

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

Butuh Tesseract terpasang. Di mesin dev ini `ind.traineddata` berada di
`%LOCALAPPDATA%\tessdata` karena Program Files menolak tulis tanpa admin;
`ocr.py` menemukannya otomatis, atau hormati `TESSDATA_PREFIX` bila diset.
