# Skema Kondisi Gate 1 & Gate 2 (Fase 1 revisi, Day 1-2)

Sumber: Blueprint A4 (ditandai [ASUMSI, perlu validasi atasan]). Selama Fase 1,
input skor CV memakai **skor dummy** dari skeleton FastAPI; logika di bawah
tidak berubah saat skor nyata masuk (Fase 3).

Implementasi Gate 1: `webapp/app/Libraries/GateOne.php` (fungsi murni,
teruji 9 skenario di `tests/unit/GateOneTest.php`).

## Input

| Input | Sumber | Skala |
|---|---|---|
| Skor screening CV (`score_overall`) | `screening_results` baris terbaru ber-status `success` | 0.00 - 1.00 |
| Hasil assessment | `online_assessment` (nilai dari sistem assessment BIPROO) | dinormalkan ke 0.00 - 1.00 |

## Skor Gabungan

```
skor_gabungan = (w_cv * skor_cv) + (w_assessment * skor_assessment)
```

Default: `w_cv = 0.5`, `w_assessment = 0.5` [ASUMSI - belum ada di blueprint,
perlu validasi atasan]. Dapat diubah per posisi via `jobs.bobot_json`:

```json
{ "gate1": { "cv": 0.5, "assessment": 0.5 } }
```

## Threshold (konfigurasi per posisi, `jobs.threshold_json`)

```json
{ "gate1": { "upper": 0.75, "lower": 0.45 } }
```

Angka 0.75/0.45 adalah **placeholder** - kalibrasi dari distribusi skor nyata
dilakukan di Fase 4 (setelah pipeline screening jalan di minggu 4).

## Tabel Keputusan

| Kondisi | Keputusan | Baris stage_history | Email |
|---|---|---|---|
| `skor_gabungan >= upper` | Lolos otomatis | `gate_1 / passed / system` | `hasil_gate` (lolos) |
| `skor_gabungan < lower` | Tidak lolos otomatis | `gate_1 / failed / system` | `hasil_gate` (tidak lolos) |
| di antara keduanya | Soft-flag, review manual | `gate_1 / flagged / system` | tidak ada (internal) |
| Recruiter putuskan flag | Sesuai keputusan | `gate_1 / passed atau failed / recruiter` | `hasil_gate` |

Prinsip human-in-the-loop: zona tengah TIDAK pernah diputus otomatis;
kandidat ber-flag masuk daftar review di dashboard recruiter.

## Kapan Gate 1 Dievaluasi

Gate 1 dievaluasi otomatis saat **kedua input lengkap**, yaitu ketika event
terakhir dari pasangan berikut terjadi:

1. `ai_verification / passed` tercatat (skor CV tersedia), DAN
2. `online_assessment / passed` tercatat (nilai assessment tersedia).

Kondisi tunggu:

- CV masih `retry_queued` → gate menunggu; kandidat tetap di tahap assessment.
- Assessment belum selesai → gate menunggu.
- Keduanya ada → hitung skor gabungan → tulis baris `gate_1` → kirim email
  (jika passed/failed) → kandidat lanjut ke `penjadwalan` bila passed.

## Pseudocode

```
function evaluateGate1(application):
    cv = latestScreeningResult(application, status='success')
    asm = latestAssessment(application)
    if cv is null or asm is null: return  # belum lengkap, tunggu event berikutnya

    cfg = job(application).gate1Config()  # bobot + threshold, fallback default
    skor = cfg.w_cv * cv.score_overall + cfg.w_asm * normalize(asm.nilai)

    if   skor >= cfg.upper: decide('passed',  actor='system')
    elif skor <  cfg.lower: decide('failed',  actor='system')
    else:                   decide('flagged', actor='system')

function decide(status, actor):
    insertStageHistory('gate_1', status, actor)   # append-only
    if status in ('passed', 'failed'):
        queueEmail('hasil_gate', ...)             # via email_queue, bukan kirim langsung
```

Idempotensi: sebelum menulis, cek belum ada baris `gate_1` final
(`passed`/`failed`) untuk application tsb - evaluasi ulang tidak boleh
menggandakan keputusan.

---

# Skema Kondisi Gate 2 (keputusan akhir)

Prinsip beda dengan Gate 1: **sistem TIDAK pernah memutus otomatis di Gate 2**.
Sistem hanya menghitung rekomendasi; keputusan akhir selalu recruiter
(Blueprint A4: "Keputusan akhir selalu dikonfirmasi recruiter").

## Input

| Input | Sumber |
|---|---|
| Skor gabungan Gate 1 (CV + assessment) | hasil evaluasi Gate 1 |
| Hasil interview | recruiter mengisi nilai/catatan setelah `interview_online` |

## Rekomendasi Sistem

```
skor_akhir = (w_gate1 * skor_gate1) + (w_interview * skor_interview)
rekomendasi = skor_akhir >= threshold_rekomendasi ? 'hire' : 'no-hire'
```

Default [ASUMSI]: `w_gate1 = 0.4`, `w_interview = 0.6` (interview lebih berat
karena sinyal terbaru dan paling kaya), `threshold_rekomendasi = 0.7`.
Konfigurasi per posisi via `jobs.bobot_json` / `threshold_json` key `gate2`.

## Alur Keputusan

| Kejadian | Baris stage_history | Email |
|---|---|---|
| Interview selesai, skor akhir dihitung | (tidak ada baris; rekomendasi tampil di dashboard) | tidak ada |
| Recruiter approve (hire) | `gate_2 / passed / recruiter` | tidak ada (lanjut proses berkas, di luar email inti) |
| Recruiter reject | `gate_2 / failed / recruiter` | `hasil_gate` (tidak lolos) otomatis |

Catatan:

- Rekomendasi sistem disimpan sebagai `note` pada baris `gate_2` (mis.
  "rekomendasi sistem: hire, skor 0.82") supaya selisih rekomendasi vs
  keputusan manusia bisa diaudit belakangan.
- Tidak ada zona flagged di Gate 2 - semua keputusan memang manual, flag tidak
  bermakna.
- Setelah `gate_2 / passed`, kandidat masuk `berkas_kontrak / entered` (system).

## Yang Perlu Validasi Atasan

1. Bobot gabungan Gate 1: CV vs assessment (default 50/50).
2. Angka threshold Gate 1 placeholder 0.75 / 0.45.
3. Apakah kandidat ber-flag Gate 1 perlu diberi tahu (saat ini: tidak, murni internal).
4. Bobot Gate 2: gate1 vs interview (default 40/60) + threshold rekomendasi 0.7.
5. Bentuk penilaian interview oleh recruiter: skala angka atau rubrik (mempengaruhi normalisasi).
