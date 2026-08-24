"""
Ukur mutu SARAN POSISI: seberapa sering posisi yang benar-benar menerima
kandidat muncul di 3 besar saran kita.

Kenapa ada
----------
Fitur saran posisi menawarkan 3 lowongan lain kepada kandidat yang digugurkan
karena salah posisi. Ia berdiri di atas skor kemiripan CV - skor yang SUDAH
pernah diukur dan hasilnya lemah untuk memilih kandidat (ROC-AUC 0,589; di
dalam satu posisi 0,499, lihat docs/kalibrasi-gate.md).

Tapi arah pertanyaannya berbeda. Yang dulu diukur "kandidat mana yang terbaik
untuk posisi ini". Yang ini "posisi mana yang paling cocok untuk kandidat ini".
Arah kedua belum pernah diukur sama sekali, dan tidak boleh diasumsikan ikut
lemah maupun ikut kuat.

Pembandingnya jelas dan tidak bisa ditawar: menebak 3 dari N posisi secara acak.
Kalau fitur ini tidak mengalahkan angka itu dengan meyakinkan, ia bukan saran -
ia generator angka acak berbungkus kalimat sopan, dikirim ke orang yang sedang
kecewa.

Batas yang harus disebut saat membaca hasilnya
----------------------------------------------
Labelnya "diterima kerja", bukan "saran ini bagus". Kandidat yang sebenarnya
cocok di posisi X tapi tidak diterima di sana terhitung meleset. Jadi angka yang
keluar adalah BATAS BAWAH: fitur ini setidaknya sebagus itu.

Nol LLM. Yang dipakai cuma kuota embedding (1.000/hari), dan hasilnya di-cache
ke disk yang sama dengan bobot_bidang.py - jadi sebagian besar teks kemungkinan
sudah terbayar.

Pemakaian:
    python saran_posisi.py --hanya-siapkan     # cek berapa yang perlu di-embed
    python saran_posisi.py
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import numpy as np
import pandas as pd

from bobot_bidang import (
    BIDANG,
    BOBOT_SEKARANG,
    _kunci,
    cosine,
    embed_semua,
    muat_lowongan_perbidang,
    muat_skill,
)
from siapkan_data import norm_nama

AKAR = Path(__file__).resolve().parents[1]
DATA_BAWAAN = Path(__file__).resolve().parents[2] / "data"
OUT_BAWAAN = Path(__file__).resolve().parents[2] / "kalibrasi-out"

MODEL = "gemini-embedding-001"
TOP_K = 3   # jumlah saran yang ditampilkan sistem


def skor_berbobot(cos: dict[str, float | None], bobot: dict[str, float]) -> float | None:
    """Rata-rata berbobot atas bidang yang punya pasangan, dinormalkan ulang.

    Sama persis dengan scoring.hitung() di ai-service dan SaranPosisi::skor()
    di CI4 - kalau tidak sama, yang diukur di sini bukan yang berjalan di sana.
    """
    jumlah = w = 0.0
    for b, nilai in cos.items():
        if nilai is None:
            continue
        jumlah += max(0.0, min(1.0, nilai)) * bobot[b]
        w += bobot[b]

    return None if w == 0.0 else jumlah / w


CARA = {
    "berbobot 50/30/20": lambda c: skor_berbobot(c, BOBOT_SEKARANG),
    "skill saja": lambda c: None if c.get("skill") is None else max(0.0, min(1.0, c["skill"])),
    "pengalaman saja": lambda c: None if c.get("pengalaman") is None else max(0.0, min(1.0, c["pengalaman"])),
    "pendidikan saja": lambda c: None if c.get("pendidikan") is None else max(0.0, min(1.0, c["pendidikan"])),
}


def main() -> int:
    p = argparse.ArgumentParser(description="Ukur mutu saran posisi")
    p.add_argument("--data", type=Path, default=DATA_BAWAAN)
    p.add_argument("--out", type=Path, default=OUT_BAWAAN)
    p.add_argument("--hanya-siapkan", action="store_true", help="berhenti sebelum embedding")
    # dataset_berlabel.csv = ringkasan xlsx tim DS, median 148 karakter.
    # dataset_ekstraksi_kita.csv = PDF asli lewat pipeline Fase 4, ~1.760
    # karakter - itulah teks yang benar-benar dibaca sistem berjalan.
    p.add_argument("--dataset", default="dataset_berlabel.csv")
    a = p.parse_args()

    print("1. Memuat kandidat yang DITERIMA")
    print(f"  sumber: {a.dataset}")
    df = pd.read_csv(a.out / a.dataset)
    df = df[df["ada_teks_lowongan"] & (df["label"] == 1)].copy()
    df = df.merge(muat_skill(a.data), on="id", how="left")
    df["k"] = norm_nama(df["posisi"])
    print(f"  {len(df)} kandidat diterima, {df['k'].nunique()} posisi berbeda")

    print("\n2. Katalog posisi (sisi lowongan, dipecah tiga bidang)")
    katalog = muat_lowongan_perbidang(a.data)
    # Hanya posisi yang benar-benar pernah menerima orang di data ini. Katalog
    # penuh memuat posisi yang tak seorang pun pernah diterima di sana, dan
    # memasukkannya cuma memperbesar penyebut tanpa menambah kemungkinan benar.
    katalog = katalog[katalog["k"].isin(set(df["k"]))].reset_index(drop=True)
    n_posisi = len(katalog)
    print(f"  {n_posisi} posisi jadi pilihan saran")

    # Kandidat yang posisinya tidak ada di katalog tidak bisa dinilai: jawaban
    # benarnya tidak ada di antara pilihan.
    df = df[df["k"].isin(set(katalog["k"]))].copy()
    print(f"  {len(df)} kandidat bisa dinilai")

    cv_kol = {"skill": "teks_skill", "pengalaman": "teks_pengalaman", "pendidikan": "teks_pendidikan"}
    job_kol = {"skill": "job_skill", "pengalaman": "job_pengalaman", "pendidikan": "job_pendidikan"}
    for b in BIDANG:
        df[cv_kol[b]] = df[cv_kol[b]].fillna("").astype(str).str.strip()
        katalog[job_kol[b]] = katalog[job_kol[b]].fillna("").astype(str).str.strip()

    print("\n3. Cakupan bidang")
    for b in BIDANG:
        ada_cv = (df[cv_kol[b]].str.len() > 0).mean() * 100
        ada_job = (katalog[job_kol[b]].str.len() > 0).mean() * 100
        print(f"  {b:<11} CV {ada_cv:5.1f}%   lowongan {ada_job:5.1f}%")

    teks = []
    for b in BIDANG:
        teks += df[cv_kol[b]].tolist() + katalog[job_kol[b]].tolist()
    unik = sorted({t for t in teks if t.strip()})
    print(f"\n4. Teks unik yang dibutuhkan: {len(unik)}")

    if a.hanya_siapkan:
        import pickle
        cache_path = a.out / "cache_embedding.pkl"
        cache = pickle.loads(cache_path.read_bytes()) if cache_path.is_file() else {}
        perlu = [t for t in unik if _kunci(t, MODEL) not in cache]
        print(f"  sudah di cache : {len(unik) - len(perlu)}")
        print(f"  perlu di-embed : {len(perlu)}  (jatah 1.000/hari)")

        return 0

    cache = embed_semua(teks, a.out / "cache_embedding.pkl", MODEL)

    def vek(t: str) -> np.ndarray | None:
        t = t.strip()
        if not t:
            return None
        k = _kunci(t, MODEL)

        return np.array(cache[k], dtype=np.float32) if k in cache else None

    print("\n5. Menghitung peringkat tiap kandidat terhadap seluruh posisi")
    vek_job = {
        row["k"]: {b: vek(row[job_kol[b]]) for b in BIDANG}
        for _, row in katalog.iterrows()
    }

    peringkat: dict[str, list[int]] = {nama: [] for nama in CARA}
    for _, r in df.iterrows():
        vc = {b: vek(r[cv_kol[b]]) for b in BIDANG}
        if all(v is None for v in vc.values()):
            continue

        skor: dict[str, dict[str, float]] = {nama: {} for nama in CARA}
        for k, vj in vek_job.items():
            cos = {
                b: (None if vc[b] is None or vj[b] is None else cosine(vc[b], vj[b]))
                for b in BIDANG
            }
            for nama, f in CARA.items():
                s = f(cos)
                if s is not None:
                    skor[nama][k] = s

        for nama in CARA:
            if r["k"] not in skor[nama]:
                continue
            urut = sorted(skor[nama], key=lambda x: (-skor[nama][x], x))
            peringkat[nama].append(urut.index(r["k"]) + 1)

    print("\n6. HASIL")
    acak = TOP_K / n_posisi * 100
    print(f"  Pembanding: menebak {TOP_K} dari {n_posisi} posisi secara acak = {acak:.1f}%\n")
    print(f"  {'Cara':<20} {'n':>5} {'hit@1':>8} {'hit@3':>8} {'hit@5':>8} {'peringkat tengah':>18}")

    hasil = {"sumber": a.dataset, "n_posisi": n_posisi,
             "acak_hit3_persen": round(acak, 2), "cara": {}}
    for nama, ps in peringkat.items():
        if not ps:
            continue
        arr = np.array(ps)
        h1 = (arr <= 1).mean() * 100
        h3 = (arr <= TOP_K).mean() * 100
        h5 = (arr <= 5).mean() * 100
        print(f"  {nama:<20} {len(arr):>5} {h1:>7.1f}% {h3:>7.1f}% {h5:>7.1f}% {int(np.median(arr)):>13} / {n_posisi}")
        hasil["cara"][nama] = {
            "n": int(len(arr)), "hit1": round(h1, 2), "hit3": round(h3, 2),
            "hit5": round(h5, 2), "peringkat_tengah": int(np.median(arr)),
            "lipat_dari_acak": round(h3 / acak, 2),
        }

    print("\n  Berapa kali lebih baik daripada menebak (hit@3 / acak):")
    for nama, h in hasil["cara"].items():
        print(f"    {nama:<20} {h['lipat_dari_acak']:>5.2f}x")

    akhiran = "" if a.dataset.startswith("dataset_berlabel") else "_ekstraksi"
    nama_keluaran = f"hasil_saran_posisi{akhiran}.json"
    (a.out / nama_keluaran).write_text(
        json.dumps(hasil, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\n  Tersimpan: {a.out / nama_keluaran}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
