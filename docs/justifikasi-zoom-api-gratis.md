# Justifikasi Teknis: Zoom Generate Link & API Embedding Gratis

**Status: FINAL (Day 4)** - siap dipresentasikan Jumat 17 Juli. Bahan pendukung
untuk dua keputusan yang sudah terkunci, agar management memahami trade-off-nya.

## Keputusan 1: Video Interview via Zoom Generate Link (bukan Embedded SDK)

**Apa keputusannya:** sistem membuat meeting Zoom lewat API (Server-to-Server
OAuth), kandidat menerima `join_url` via email, interview berlangsung di
aplikasi/klien Zoom - bukan video tertanam di halaman web kita.

**Mengapa:**

1. **Integrasi jauh lebih sederhana** - buat meeting via API + kirim link via
   email. Embedded SDK menuntut penanganan video di frontend, kompatibilitas
   browser/perangkat, dan beban maintenance yang tidak sebanding untuk tim
   dan timeline 5 minggu ini.
2. **Kandidat sudah familiar dengan Zoom** - tidak ada friksi belajar aplikasi
   interview baru; kualitas video/audio ditangani infrastruktur Zoom.
3. **Risiko teknis rendah saat demo** - tidak ada dependensi pada stabilitas
   video pipeline buatan sendiri.

**Trade-off yang jujur: anti-cheat teknis TIDAK berlaku.** Pemantauan browser
(Page Visibility API dll.) hanya bekerja bila interview berlangsung di halaman
web kita. Dengan link eksternal, kandidat berada di luar kendali halaman kita.

**Batas free tier Zoom (diverifikasi 16 Juli 2026):**

| Batasan | Nilai | Dampak ke E-REQ |
|---|---|---|
| Durasi meeting ≥3 peserta | 40 menit | Interview panel (2+ pewawancara) terpotong; perlu dijadwalkan ≤40 menit atau upgrade |
| Durasi meeting 1-lawan-1 | s.d. 30 jam | Interview recruiter-kandidat berdua saja praktis tanpa batas |
| Cloud recording | Tidak ada (hanya rekam lokal) | Fitur backlog auto-transcribe butuh tier berbayar, atau rekam lokal manual |
| Peserta maksimum | 100 | Lebih dari cukup |

Kesimpulan: free tier memadai untuk MVP selama interview berformat 1-lawan-1;
keputusan upgrade baru relevan bila panel interview atau auto-transcribe jadi
kebutuhan.

**Tiga opsi penanganan (keputusan management, diajukan Fase 4):**

| Opsi | Cara | Trade-off |
|---|---|---|
| 1. Protokol observasi (rekomendasi MVP) | SOP recruiter: kamera wajib nyala, share screen saat diminta, amati gerak mata/jeda | Tanpa biaya; bergantung ketelitian recruiter |
| 2. Embedded SDK (masa depan) | Video tertanam via Zoom Meeting/Video SDK, anti-cheat browser jadi mungkin | Kompleksitas naik signifikan; membalikkan keputusan generate link |
| 3. Layanan proctoring berbayar | Pihak ketiga khusus pengawasan ujian/interview | Biaya per kandidat; integrasi tambahan |

## Keputusan 2: API Embedding Eksternal Gratis untuk Fase Testing

**Apa keputusannya:** skor kecocokan CV dihitung memakai API embedding gratis
selama pengembangan/testing. Untuk produksi, model dilatih sendiri dari data
historis (alur A3.4) dan menggantikan API eksternal.

**Mengapa:**

1. **Biaya nol saat memvalidasi konsep** - belum keluar uang sebelum terbukti
   pipeline screening bekerja untuk CV nyata.
2. **Tidak terkunci ke satu vendor** - layer `EmbeddingProvider` swappable;
   ganti provider = ganti konfigurasi, pipeline tidak berubah.
3. **Jalur produksi sudah dirancang** - model hasil training tim sendiri masuk
   lewat interface yang sama; tidak ada kerja ulang arsitektur.

**Provider terpilih untuk testing: Google Gemini Embedding** - kuota gratis
paling lega (1.500 request/hari tanpa kartu kredit), dukungan 100+ bahasa
termasuk Indonesia, dokumentasi baik. Cadangan: Jina (1 juta token/bulan).

**Risiko & mitigasi:**

| Risiko | Mitigasi |
|---|---|
| Rate limit harian habis saat volume tinggi | Antrian internal + retry backoff; job ditunda, bukan gagal (A3.3) |
| Kualitas embedding untuk CV Bahasa Indonesia kurang | Diuji dengan sampel CV nyata di Fase 1; provider mudah diganti bila buruk |
| Data CV dikirim ke pihak ketiga | Atribut sensitif (gender, usia, agama, alamat, foto) dibuang SEBELUM embedding; hanya ringkasan pengalaman/skill/pendidikan yang terkirim. Provider dengan syarat opt-in data training (mis. Mistral Experiment tier) tidak dipakai |

## Bukti Teknis (uji end-to-end, 16 Juli 2026)

Skeleton sudah terhubung ke provider nyata dan diuji end-to-end:

```
POST /screening            -> 202 { screening_job_id }
worker background          -> panggil Gemini embedding API (3 field)
GET /screening/{id}        -> { status: "done", attempts: 1,
                               embedding_dims: [3072, 3072, 3072] }
```

- Request dummy diproses tuntas: antrian → embedding asli → hasil tercatat.
- Retry backoff teruji (simulasi 429 dua kali → sukses di percobaan ke-3;
  limit habis → job berstatus `failed_provider`, ditunda, tidak hilang).
- Test suite: 8 passed (termasuk live test ke Gemini).
