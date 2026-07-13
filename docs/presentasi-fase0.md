# Materi Presentasi Fase 0 — Jumat, 17 Juli 2026

Format: 3 keputusan siap diketok + progres skeleton + demo. Estimasi 10–15 menit.

## Slide 1 — Ringkasan Minggu Ini

Fase 0 "Sprint Keputusan + Fondasi" selesai sesuai rencana:

- 3 keputusan siap diketok (video interview, infrastruktur AI, kerangka impact/KPI).
- Skeleton AI microservice berjalan dan **sudah terhubung ke API embedding nyata**.
- Bonus: keputusan chatbot sudah ditutup lebih awal (koordinasi Pak Irfan, 13 Juli).

## Slide 2 — Keputusan 1: Video Interview via Zoom Generate Link

- Sistem membuat meeting otomatis via API, kandidat menerima link via email.
- Sederhana, familiar bagi kandidat, risiko teknis demo rendah.
- **Trade-off jujur:** anti-cheat browser tidak berlaku (link eksternal) →
  mitigasi MVP: SOP protokol observasi recruiter; 3 opsi jangka panjang
  diajukan di Fase 4.
- Free tier terverifikasi: 1-lawan-1 praktis tanpa batas; panel ≥3 peserta
  terpotong 40 menit; cloud recording butuh berbayar.

## Slide 3 — Keputusan 2: API Embedding Gratis untuk Testing

- Biaya nol saat validasi konsep; provider terpilih: **Google Gemini**
  (kuota 1.500 request/hari, multilingual, tanpa kartu kredit).
- Tidak terkunci vendor: layer `EmbeddingProvider` swappable — ganti provider =
  ganti konfigurasi.
- Jalur produksi tetap: model dilatih sendiri dari data historis (dataset tim
  DS), masuk lewat layer yang sama.
- Privasi: atribut sensitif dibuang **sebelum** dikirim ke API eksternal.

## Slide 4 — Keputusan 3: Kerangka Impact (KPI)

6 KPI dihitung otomatis dari pencatatan riwayat tahapan (tanpa input manual):

1. Waktu screening per CV (baseline manual: menit → target otomatis: detik)
2. Throughput kandidat per hari per tahap
3. % CV terbaca otomatis vs perlu proses ulang
4. Waktu registrasi → keputusan Gate 1
5. Waktu registrasi → keputusan Gate 2
6. Distribusi kandidat per source & wilayah (bahan insight DS)

Angka pertama muncul di Fase 1 dari data testing; angka produksi menyusul
setelah dataset DS bersih.

## Slide 5 — Progres Teknis (Demo)

Alur demo langsung:

1. Kirim request screening → sistem langsung membalas `202 + job_id` (tidak
   menunggu proses).
2. Cek status job → berubah `queued → processing → done`, embedding nyata
   dari Gemini (vektor 3072 dimensi).
3. Tunjukkan ketahanan: rate limit → retry otomatis (2-4-8 detik); kuota habis
   → job ditunda, **tidak ada kandidat hilang**.

Kriteria selesai Fase 0 — status:

| Kriteria | Status |
|---|---|
| Endpoint /screening balas 202 + job_id | ✅ |
| Koneksi API embedding teruji | ✅ (uji end-to-end 16 Juli) |
| Interface EmbeddingProvider swappable | ✅ |
| Kerangka KPI final (tabel) | ✅ |
| Dokumen justifikasi Zoom + API gratis | ✅ |
| Meeting Pak Irfan + scope chatbot | ✅ (13 Juli — keputusan ditutup) |
| Desain skema tabel inti | ✅ |

## Slide 6 — Bonus: Keputusan Chatbot Sudah Ditutup

Hasil koordinasi Pak Irfan (13 Juli): chatbot **kandidat** untuk cek status
lamaran (bukan dashboard internal HR), provider **Gemini API** (sama dengan
embedding — satu vendor untuk testing), channel web di portal kandidat,
dibangun di **Fase 3** sesuai jadwal. Yang tersisa untuk keputusan management
di Fase 4 tinggal **anti-cheat**.

## Slide 7 — Minggu Depan (Fase 1, presentasi 24 Juli)

Pipeline screening CV nyata end-to-end: ekstraksi teks (layout-aware + fallback
OCR), strukturisasi 3 field, skor kecocokan, callback. Target: 10 CV testing
semua dapat skor atau masuk antrian ulang — tidak ada yang hilang. Output
tambahan: data % CV "sulit" sebagai bahan keputusan form terstruktur.

---

**Antisipasi pertanyaan:**

- *"Kenapa tidak pakai WhatsApp?"* — Keputusan terkunci email saja: satu jalur
  notifikasi, template per event, tanpa biaya/kompleksitas WA Business API.
- *"Bagaimana kalau kuota Gemini habis?"* — Antrian + retry + job ditunda;
  provider bisa diganti via konfigurasi (Jina sebagai cadangan); produksi
  memakai model sendiri.
- *"Kapan angka KPI nyata?"* — Fase 1 dari data testing; angka produksi
  setelah serah terima dataset bersih DS (mulai bertahap minggu ke-3).
