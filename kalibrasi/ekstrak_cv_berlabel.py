"""
Ekstrak teks CV asli untuk kandidat berlabel, memakai pipeline produksi (Fase 4).

Kenapa perlu: `siapkan_data.py` mengambil teks dari xlsx tim DS yang median
panjangnya cuma 148 karakter. Produksi membaca PDF/gambar asli dan menghasilkan
1.300-15.000 karakter. Ambang yang dikalibrasi pada teks pendek TIDAK berlaku
untuk sistem yang berjalan. Skrip ini menutup selisih itu.

Modul `extract.py` dan `ocr.py` diimpor LANGSUNG dari ai-service - bukan
disalin - supaya teks yang diukur benar-benar keluar dari kode yang sama dengan
produksi. Keduanya hanya bergantung pada fitz, jadi jalan di Python sistem.

Bisa dilanjutkan: hasil ditulis per baris ke CSV, dan CV yang sudah selesai
dilewati saat dijalankan ulang. Aman ditekan Ctrl+C.

Pemakaian:
    python ekstrak_cv_berlabel.py
    python ekstrak_cv_berlabel.py --batas 50      # coba dulu 50 CV
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import re
import sys
import time
from pathlib import Path

import pandas as pd

AI = Path(__file__).resolve().parent.parent / "ai-service"
sys.path.insert(0, str(AI))
from extract import ekstrak          # noqa: E402  pipeline produksi, lapis 1
from ocr import ocr_lengkapi, tersedia  # noqa: E402  lapis 2
from sanitize import bersihkan       # noqa: E402

DATA_BAWAAN = Path(__file__).resolve().parents[2] / "data"
CV_BAWAAN = Path(__file__).resolve().parents[2] / "cv" / "cv"
OUT_BAWAAN = Path(__file__).resolve().parents[2] / "kalibrasi-out"

KOLOM = ["id", "label", "posisi", "ada_teks_lowongan", "teks_pengalaman",
         "teks_pendidikan", "teks_lowongan", "n_pengalaman", "jenjang",
         "MinimumEducation", "WorkExperience", "metode", "n_karakter", "file"]


def norm(s: pd.Series) -> pd.Series:
    return (s.astype(str).str.strip().str.lower()
             .str.replace(r"\s+", " ", regex=True).replace({"nan": None, "": None}))


def bersih_html(t) -> str:
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", str(t))).strip()


def daftar_kerja(data: Path, cv_dir: Path) -> pd.DataFrame:
    """Kandidat berlabel yang berkas CV-nya benar-benar ada di disk."""
    x = pd.ExcelFile(data / "SOC_SOH pebaikan.xlsx")
    soc, soh = x.parse("SOC", header=0), x.parse("SOH", header=0)
    ring = pd.ExcelFile(data / "hasil_ekstraksi_cv (2).xlsx").parse("Ringkasan")
    soc["n"], soh["n"], ring["n"] = norm(soc["FullName"]), norm(soh["FullName"]), norm(ring["Nama"])

    S, H = set(soc["n"].dropna()), set(soh["n"].dropna())
    ambigu = S & H
    r = ring[ring["n"].notna()].copy()
    r["label"] = None
    r.loc[r["n"].isin(H - ambigu), "label"] = 1
    r.loc[r["n"].isin(S - ambigu), "label"] = 0
    r = r[r["label"].notna()].drop_duplicates("n")

    ada = {p.name for p in cv_dir.iterdir()}
    r = r[r["File"].isin(ada)]

    pos = (pd.concat([soc[["n", "PositionName"]], soh[["n", "PositionName"]]])
             .dropna().drop_duplicates("n"))
    r = r.merge(pos, on="n", how="left")

    # sisi lowongan: sama seperti siapkan_data.py
    w = pd.ExcelFile(data / "work_description.xlsx").parse("Query1")
    w["teks_lowongan"] = (w["Requirements"].fillna("").map(bersih_html) + " "
                          + w["JobDescriptions"].fillna("").map(bersih_html)).str.strip()
    w = w[w["teks_lowongan"].str.len() > 0]
    low = pd.concat([
        w.assign(k=norm(w["JobTitle"]))[["k", "teks_lowongan", "MinimumEducation", "WorkExperience"]],
        w.assign(k=norm(w["PositionCategory"]))[["k", "teks_lowongan", "MinimumEducation", "WorkExperience"]],
    ]).dropna(subset=["k"]).drop_duplicates("k")
    r["k"] = norm(r["PositionName"])
    r = r.merge(low, on="k", how="left")

    return r


def main() -> int:
    p = argparse.ArgumentParser(description="Ekstrak teks CV asli untuk kandidat berlabel")
    p.add_argument("--data", type=Path, default=DATA_BAWAAN)
    p.add_argument("--cv", type=Path, default=CV_BAWAAN)
    p.add_argument("--out", type=Path, default=OUT_BAWAAN)
    p.add_argument("--batas", type=int, default=0, help="berhenti setelah N CV (0 = semua)")
    p.add_argument("--maks-negatif", type=int, default=0,
                   help="ambil SEMUA kandidat diterima + maksimal N yang tidak diterima. "
                        "0 = pakai semua. Sangat menghemat waktu: yang mengikat selang "
                        "kepercayaan adalah jumlah positif, bukan negatif. "
                        "121 positif + 400 negatif memberi selang +/-0,093 versus "
                        "+/-0,085 untuk seluruh 1.729, dengan waktu sepertiga.")
    a = p.parse_args()

    if not a.cv.is_dir():
        print(f"BERHENTI: folder CV tidak ada: {a.cv}", file=sys.stderr)
        return 1
    if not tersedia():
        print("PERINGATAN: tesseract tidak ditemukan. CV hasil scan akan gagal ekstraksi.\n")

    print("Menyusun daftar kandidat berlabel...")
    df = daftar_kerja(a.data, a.cv)
    print(f"  {len(df)} kandidat berlabel punya berkas CV  (hired {int(df['label'].sum())})")

    if a.maks_negatif:
        pos = df[df["label"] == 1]
        neg = df[df["label"] == 0].sample(min(a.maks_negatif, int((df["label"] == 0).sum())),
                                          random_state=42)
        df = pd.concat([pos, neg]).sample(frac=1, random_state=42).reset_index(drop=True)
        print(f"  disaring: semua {len(pos)} hired + {len(neg)} not-hired = {len(df)} CV")

    a.out.mkdir(parents=True, exist_ok=True)
    tujuan = a.out / "dataset_ekstraksi_kita.csv"

    selesai: set[str] = set()
    if tujuan.is_file():
        selesai = set(pd.read_csv(tujuan, usecols=["file"])["file"])
        print(f"  {len(selesai)} sudah ada di CSV, dilewati (lanjutan)")

    sisa = df[~df["File"].isin(selesai)]
    if a.batas:
        sisa = sisa.head(a.batas)
    print(f"  akan diproses: {len(sisa)}\n")

    baru = tujuan.is_file()
    f = tujuan.open("a", newline="", encoding="utf-8")
    tulis = csv.DictWriter(f, fieldnames=KOLOM)
    if not baru:
        tulis.writeheader()

    t0 = time.time()
    hitung = {"text-layer": 0, "ocr": 0, "mixed": 0, "gagal": 0}
    try:
        for i, (_, row) in enumerate(sisa.iterrows(), 1):
            jalan = a.cv / row["File"]
            h = ekstrak(jalan)
            if not h.utuh:
                h = ocr_lengkapi(jalan.read_bytes(), h)

            teks = bersihkan(h.teks) if h.berhasil else ""
            hitung[h.metode if h.metode in hitung else "gagal"] += 1

            low = row.get("teks_lowongan")
            low = "" if pd.isna(low) else bersihkan(str(low))
            tulis.writerow({
                "id": hashlib.sha256(str(row["n"]).encode()).hexdigest()[:12],
                "label": int(row["label"]),
                "posisi": row.get("PositionName") or "",
                "ada_teks_lowongan": bool(low),
                "teks_pengalaman": teks,          # teks CV UTUH, bukan cuma pengalaman
                "teks_pendidikan": "",
                "teks_lowongan": low,
                "n_pengalaman": "",
                "jenjang": row.get("Jenjang Tertinggi") or "",
                "MinimumEducation": row.get("MinimumEducation") or "",
                "WorkExperience": row.get("WorkExperience") or "",
                "metode": h.metode,
                "n_karakter": len(teks),
                "file": row["File"],
            })

            # flush setiap baris: pekerjaan ini 25+ menit, dan tanpa flush progres
            # tertahan di buffer 8KB sehingga terlihat seperti macet. Biayanya nol.
            f.flush()
            if i % 25 == 0:
                laju = i / (time.time() - t0)
                print(f"  {i}/{len(sisa)}  {laju:.1f} CV/detik  sisa ~{(len(sisa)-i)/laju/60:.0f} menit  {hitung}",
                      flush=True)  # stdout juga di-buffer saat dialihkan ke berkas
    except KeyboardInterrupt:
        print("\n  dihentikan pengguna - yang sudah diproses tetap tersimpan")
    finally:
        f.close()

    print(f"\n=== HASIL ({time.time()-t0:.0f} detik) ===")
    for k, v in hitung.items():
        print(f"  {k:12s} {v}")
    d = pd.read_csv(tujuan)
    berteks = d[d["n_karakter"] > 0]
    print(f"\n  total baris di CSV : {len(d)}  (hired {int(d['label'].sum())})")
    print(f"  berteks            : {len(berteks)}  (hired {int(berteks['label'].sum())})")
    if len(berteks):
        print(f"  panjang teks       : median {int(berteks['n_karakter'].median())}, "
              f"p90 {int(berteks['n_karakter'].quantile(.9))}, maks {int(berteks['n_karakter'].max())}")
    print(f"\n  -> {tujuan}")
    print(f"\nLangkah berikutnya:")
    print(f'  python latih_dan_ukur.py --sumber "{tujuan.name}"')

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
