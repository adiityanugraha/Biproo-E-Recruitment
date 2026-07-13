# Kerangka KPI Final (Fase 0 Day 3 - siap presentasi 17 Juli)

Prinsip (Blueprint A8, "Impact by Design"): tiap perpindahan tahap tercatat
otomatis di `candidate_stage_history` dengan timestamp, sehingga semua KPI
dihitung dari data yang sudah ada - tidak ada pencatatan manual tambahan.

| # | KPI | Definisi | Cara hitung | Sumber data |
|---|-----|----------|-------------|-------------|
| 1 | Waktu screening per CV | Lama proses dari CV dikirim ke AI sampai skor diterima | Rata-rata selisih `created_at` baris `ai_verification/entered` → `ai_verification/passed|failed` per application | `stage_history` |
| 2 | Throughput kandidat per hari per tahap | Jumlah kandidat yang masuk tiap tahap per hari | `COUNT(*)` baris `entered`, `GROUP BY stage, tanggal` | `stage_history` |
| 3 | Persentase ekstraksi berhasil vs gagal | Seberapa banyak CV terbaca otomatis vs perlu proses ulang | `status='success'` vs `status='failed_extraction'` dibagi total, per periode | `screening_results` |
| 4 | Waktu registrasi → keputusan Gate 1 | Lama kandidat menunggu keputusan pertama | Rata-rata selisih `upload_cv/entered` → `gate_1/passed|failed` | `stage_history` |
| 5 | Waktu registrasi → keputusan Gate 2 | Lama proses seleksi end-to-end | Rata-rata selisih `upload_cv/entered` → `gate_2/passed|failed` | `stage_history` |
| 6 | Distribusi kandidat per source & wilayah | Bahan insight tim DS (arahan pimpinan, MoM poin 11) | `COUNT(*)` `GROUP BY source, wilayah` | `candidates` + data DS |

**Pembanding (baseline manual):** waktu screening manual per CV (menit,
diestimasi bersama recruiter/tim DS dari proses saat ini) vs otomatis (detik,
KPI #1). Ini sumber klaim "screening N× lebih cepat" di demo 14 Agustus.

**Catatan:**
- KPI #1-#5 otomatis terisi begitu pipeline jalan; angka pertama muncul di
  Fase 1 dari data testing, angka produksi menyusul setelah dataset DS bersih
  (mitigasi risiko B4).
- KPI #3 juga jadi alat pantau risiko "ekstraksi CV format aneh gagal massal":
  bila persentase gagal tinggi di Fase 1 → bahan keputusan form terstruktur
  (A3.2a).
- Titik pencatatan timestamp per tahap: lihat `skema-database.md` bagian
  "Titik Pencatatan Timestamp".
