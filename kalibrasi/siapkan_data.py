"""
Bangun dataset berlabel untuk kalibrasi/pelatihan skor CV (Fase 5).

Menggabungkan 4 berkas dari tim DS menjadi satu tabel: satu baris per orang,
berisi teks pengalaman, teks pendidikan, teks kriteria lowongan, dan label
diterima/tidak.

Label berasal dari SOC_SOH pebaikan.xlsx:
  sheet SOC = Not Hired (0), sheet SOH = Hired (1).
Kunci join satu-satunya yang tersedia adalah NAMA (kolom NoKTP dan email pada
berkas experience/education kosong total). Nama yang muncul di SOC dan SOH
sekaligus dibuang karena tidak bisa ditentukan labelnya.

Keluaran ditulis DI LUAR repo karena memuat data pribadi. Nama tidak disimpan,
diganti hash pendek yang cukup untuk menelusuri baris bila perlu.

Pemakaian:
    python siapkan_data.py                 # jalur bawaan
    python siapkan_data.py --data DIR --out DIR
"""

from __future__ import annotations

import argparse
import hashlib
import re
import sys
from pathlib import Path

import pandas as pd

# sanitize.py milik ai-service hanya bergantung pada `re`, jadi bisa dipakai
# ulang di sini tanpa venv-nya. Lebih baik daripada menyalin pola sensitif.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "ai-service"))
from sanitize import bersihkan  # noqa: E402

DATA_BAWAAN = Path(__file__).resolve().parents[2] / "data"
OUT_BAWAAN = Path(__file__).resolve().parents[2] / "kalibrasi-out"

# Kolom yang TIDAK BOLEH ikut jadi fitur (fairness-by-design, Blueprint A3.2).
# Didaftar eksplisit supaya penambahan kolom baru tidak lolos diam-diam.
TERLARANG = {
    "salary", "FirstSalary", "LastSalary", "Currency",
    "NoKTP", "Phone", "no_telp", "CompanyPhone",
    "address", "Address", "CompanyAddress", "ZipCode",
    "City", "kota", "State", "StateName", "Region",
    "Gender", "Usia", "Generasi", "MinAge", "MaxAge",
}


def norm_nama(s: pd.Series) -> pd.Series:
    return (s.astype(str).str.strip().str.lower()
             .str.replace(r"\s+", " ", regex=True)
             .replace({"nan": None, "": None}))


def hash_id(nama: str) -> str:
    return hashlib.sha256(nama.encode("utf-8")).hexdigest()[:12]


def bersih_html(t) -> str:
    """Teks work_description berbentuk HTML (<ul><li>...)."""
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", str(t))).strip()


def muat_label(data: Path) -> pd.DataFrame:
    x = pd.ExcelFile(data / "SOC_SOH pebaikan.xlsx")
    soc = x.parse("SOC", header=0)
    soh = x.parse("SOH", header=0)
    soc["nama"] = norm_nama(soc["FullName"])
    soh["nama"] = norm_nama(soh["FullName"])

    S = set(soc["nama"].dropna())
    H = set(soh["nama"].dropna())
    ambigu = S & H
    print(f"  label: SOC {len(S)} nama, SOH {len(H)} nama, ambigu dibuang {len(ambigu)}")

    baris = [{"nama": n, "label": 1} for n in H - ambigu]
    baris += [{"nama": n, "label": 0} for n in S - ambigu]
    lab = pd.DataFrame(baris)

    # posisi yang dilamar: dipakai sebagai kunci ke work_description
    pos = (pd.concat([soc[["nama", "PositionName"]], soh[["nama", "PositionName"]]])
             .dropna().drop_duplicates("nama"))

    return lab.merge(pos, on="nama", how="left")


def muat_pengalaman(data: Path) -> pd.DataFrame:
    """New_Kandidat_Experience: satu-satunya sheet yang `description`-nya terisi.

    Kandidat_experience (49.622 baris) sengaja TIDAK dipakai - kolom
    JobDescription dan NoKTP di sana kosong 100%, jadi tak ada teks untuk NLP.
    """
    d = pd.ExcelFile(data / "experience_data.xlsx").parse("New_Kandidat_Experience")
    d["nama"] = norm_nama(d["Nama"])
    d = d.dropna(subset=["nama"])

    d["satuan"] = (d["jobtitle"].fillna("").astype(str) + ". "
                   + d["description"].fillna("").astype(str))
    g = d.groupby("nama").agg(
        teks_pengalaman=("satuan", lambda s: " ".join(s).strip()),
        n_pengalaman=("satuan", "size"),
    ).reset_index()
    print(f"  pengalaman: {len(g)} orang dari {len(d)} baris")

    return g


def muat_pendidikan(data: Path) -> pd.DataFrame:
    d = pd.ExcelFile(data / "education_data.xlsx").parse("EducationCandidate")
    d["nama"] = norm_nama(d["FullName"])
    d = d.dropna(subset=["nama"])

    d["satuan"] = (d["lulusan"].fillna("").astype(str) + " "
                   + d["jurusan"].fillna("").astype(str) + " "
                   + d["NamaInstitusiPendidikan"].fillna("").astype(str))
    g = d.groupby("nama").agg(
        teks_pendidikan=("satuan", lambda s: "; ".join(sorted(set(s))).strip()),
        jenjang=("lulusan", lambda s: s.dropna().astype(str).str.upper().max()),
    ).reset_index()
    print(f"  pendidikan: {len(g)} orang")

    return g


def muat_lowongan(data: Path) -> pd.DataFrame:
    """Kriteria per posisi. Kunci join: JobTitle ATAU PositionCategory."""
    w = pd.ExcelFile(data / "work_description.xlsx").parse("Query1")
    w["teks_lowongan"] = (w["Requirements"].fillna("").map(bersih_html) + " "
                          + w["JobDescriptions"].fillna("").map(bersih_html)).str.strip()
    w = w[w["teks_lowongan"].str.len() > 0]

    # satu posisi bisa dikenali lewat dua nama; buat baris untuk masing-masing
    kunci = pd.concat([
        w.assign(k=norm_nama(w["JobTitle"]))[["k", "teks_lowongan", "MinimumEducation", "WorkExperience"]],
        w.assign(k=norm_nama(w["PositionCategory"]))[["k", "teks_lowongan", "MinimumEducation", "WorkExperience"]],
    ]).dropna(subset=["k"]).drop_duplicates("k")
    print(f"  lowongan: {len(w)} punya teks kriteria, {len(kunci)} kunci posisi")

    return kunci


def main() -> int:
    p = argparse.ArgumentParser(description="Bangun dataset berlabel untuk kalibrasi skor CV")
    p.add_argument("--data", type=Path, default=DATA_BAWAAN)
    p.add_argument("--out", type=Path, default=OUT_BAWAAN)
    a = p.parse_args()

    if not (a.data / "SOC_SOH pebaikan.xlsx").is_file():
        print(f"BERHENTI: tidak menemukan berkas DS di {a.data}", file=sys.stderr)
        return 1

    print(f"Membaca dari {a.data}")
    lab = muat_label(a.data)
    peng = muat_pengalaman(a.data)
    didik = muat_pendidikan(a.data)
    low = muat_lowongan(a.data)

    df = lab.merge(peng, on="nama", how="inner")          # teks pengalaman WAJIB ada
    df = df.merge(didik, on="nama", how="left")
    df["k"] = norm_nama(df["PositionName"])
    df = df.merge(low, on="k", how="left")

    # atribut sensitif dibuang dari SEMUA teks sebelum keluar, termasuk teks
    # lowongan: Requirements memuat "Usia maksimal 30 tahun" yang akan mengajari
    # model menghargai penyebutan umur.
    for kol in ("teks_pengalaman", "teks_pendidikan", "teks_lowongan"):
        df[kol] = df[kol].fillna("").map(bersihkan)

    df["id"] = df["nama"].map(hash_id)
    df["posisi"] = df["PositionName"]
    df["ada_teks_lowongan"] = df["teks_lowongan"].str.len() > 0

    keluar = df[[
        "id", "label", "posisi", "ada_teks_lowongan",
        "teks_pengalaman", "teks_pendidikan", "teks_lowongan",
        "n_pengalaman", "jenjang", "MinimumEducation", "WorkExperience",
    ]].copy()

    bocor = TERLARANG & set(keluar.columns)
    assert not bocor, f"kolom terlarang lolos ke keluaran: {bocor}"

    a.out.mkdir(parents=True, exist_ok=True)
    tujuan = a.out / "dataset_berlabel.csv"
    keluar.to_csv(tujuan, index=False, encoding="utf-8")

    n_h = int(keluar["label"].sum())
    print(f"\n=== HASIL ===")
    print(f"  baris           : {len(keluar)}")
    print(f"  Hired           : {n_h}  ({100 * n_h / len(keluar):.1f}%)")
    print(f"  Not Hired       : {len(keluar) - n_h}")
    print(f"  ada teks lowongan: {int(keluar['ada_teks_lowongan'].sum())}"
          f"  (Hired {int(keluar.loc[keluar['ada_teks_lowongan'], 'label'].sum())})")
    print(f"  posisi unik     : {keluar['posisi'].nunique()}")
    print(f"\n  -> {tujuan}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
