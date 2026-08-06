"""
Ukur bobot bidang (skill / pengalaman / pendidikan) memakai EMBEDDING PRODUKSI.

Kenapa ada
----------
scoring.py memakai bobot 50/30/20 yang berasal dari Blueprint A3.2, bukan dari
pengukuran. Dua hal membuatnya patut diragukan:

  1. Skill memegang bobot terbesar (50%) tapi terisi cuma di sekitar separuh CV.
     Saat kosong, bobotnya dinormalkan ulang ke bidang lain.
  2. Renormalisasi itulah yang mengangkat CV penyalin kata kunci ke skor 1,0000
     (docs/pipeline-screening-cv.md), karena bidang yang tersisa persis yang ia
     salin dari iklan lowongan.

Seluruh kalibrasi sebelumnya memakai TF-IDF sebagai pengganti embedding. Skrip
ini yang pertama menyentuh scorer yang benar-benar berjalan.

Tidak memakai LLM sama sekali - bidang CV diambil dari ekstraksi terstruktur tim
DS, jadi kuota generateContent (20/hari) tidak tersentuh. Yang dipakai hanya
kuota embedding, dan hasilnya di-cache ke disk supaya menjalankan ulang tidak
menghabiskan kuota dua kali.

Pemakaian:
    python bobot_bidang.py                      # 144 hired + 1000 not-hired
    python bobot_bidang.py --maks-negatif 3000
    python bobot_bidang.py --hanya-siapkan      # cek cakupan tanpa embedding
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import pickle
import re
import sys
import time
from itertools import product
from pathlib import Path

import numpy as np
import pandas as pd

AKAR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(AKAR / "ai-service"))

from sanitize import bersihkan  # noqa: E402
from siapkan_data import bersih_html, hash_id, norm_nama  # noqa: E402

DATA_BAWAAN = Path(__file__).resolve().parents[2] / "data"
OUT_BAWAAN = Path(__file__).resolve().parents[2] / "kalibrasi-out"

BIDANG = ("skill", "pengalaman", "pendidikan")
BOBOT_SEKARANG = {"skill": 0.50, "pengalaman": 0.30, "pendidikan": 0.20}

# --- Embedding produksi, dengan cache ---

GEMINI = "https://generativelanguage.googleapis.com/v1beta"

# Tier gratis: EmbedContentRequestsPerDayPerUserPerProjectPerModel = 1.000/hari,
# dan batchEmbedContents dihitung PER ITEM, bukan per panggilan. Batch 20 sudah
# kena 429 saat jatah harian menipis, batch 10 lolos. Cache di disk membuat
# pekerjaan lintas hari: jalankan ulang besok, ia lanjut dari yang sudah ada.
UKURAN_BATCH = 10
KUOTA_HARIAN = 1000


def _kunci(teks: str, model: str) -> str:
    return hashlib.sha256((model + "\x00" + teks).encode("utf-8")).hexdigest()


def embed_semua(teks: list[str], cache_path: Path, model: str = "gemini-embedding-001") -> dict[str, list[float]]:
    """
    Embed daftar teks unik. Cache di disk: kuota embedding tetap terbatas, dan
    menjalankan ulang skrip ini tidak boleh membayar dua kali.
    """
    import httpx

    # python-dotenv tidak terpasang di interpreter yang punya pandas, dan .env
    # ini cuma butuh satu baris KEY=VALUE. Tidak perlu menambah dependensi.
    kunci_api = os.environ.get("GEMINI_API_KEY", "")
    if not kunci_api:
        for baris in (AKAR / "ai-service" / ".env").read_text(encoding="utf-8").splitlines():
            if baris.strip().startswith("GEMINI_API_KEY"):
                kunci_api = baris.split("=", 1)[1].strip().strip("'\"")
                break
    if not kunci_api:
        raise SystemExit("GEMINI_API_KEY tidak ditemukan di env maupun ai-service/.env")

    cache: dict[str, list[float]] = {}
    if cache_path.is_file():
        cache = pickle.loads(cache_path.read_bytes())
        print(f"  cache embedding: {len(cache)} vektor dimuat")

    unik = sorted({t for t in teks if t.strip()})
    perlu = [t for t in unik if _kunci(t, model) not in cache]
    print(f"  teks unik {len(unik)}, perlu di-embed {len(perlu)}")

    klien = httpx.Client(timeout=120)
    for i in range(0, len(perlu), UKURAN_BATCH):
        potongan = perlu[i:i + UKURAN_BATCH]
        for percobaan in range(5):
            r = klien.post(
                f"{GEMINI}/models/{model}:batchEmbedContents",
                params={"key": kunci_api},
                json={"requests": [
                    {"model": f"models/{model}", "content": {"parts": [{"text": t[:8000]}]}}
                    for t in potongan
                ]},
            )
            if r.status_code == 200:
                for t, e in zip(potongan, r.json()["embeddings"]):
                    cache[_kunci(t, model)] = e["values"]
                break
            if r.status_code in (500, 503) and percobaan < 4:
                time.sleep(5 * 2 ** percobaan)
                continue
            if r.status_code == 429:
                # 429 punya DUA arti yang sangat berbeda, dan membedakannya wajib:
                #   PerMinute -> lonjakan sesaat, tunggu lalu lanjut
                #   PerDay    -> jatah habis, mengulang cuma membuang waktu
                # Versi pertama skrip ini menganggap semua 429 berarti harian dan
                # berhenti setelah 100 vektor, padahal embedding tunggal sesaat
                # kemudian berhasil. Sekarang alasannya dibaca, bukan ditebak.
                langgar = [
                    v.get("quotaId", "")
                    for d in r.json().get("error", {}).get("details", [])
                    if d.get("@type", "").endswith("QuotaFailure")
                    for v in d.get("violations", [])
                ]
                harian = any("PerDay" in q for q in langgar)

                if not harian and percobaan < 4:
                    jeda = 30 * (percobaan + 1)
                    print(f"    429 ({', '.join(langgar) or 'tanpa rincian'}) - batas per menit, tunggu {jeda}s")
                    time.sleep(jeda)

                    continue

                cache_path.write_bytes(pickle.dumps(cache))
                sebab = "JATAH HARIAN HABIS" if harian else "429 berulang"
                print(f"\n  BERHENTI ({sebab}) di {len(cache)} vektor.")
                print(f"  Pelanggaran: {', '.join(langgar) or 'tidak dirinci Google'}")
                print(f"  Tersimpan di {cache_path}; jalankan lagi untuk melanjutkan.")
                print(f"  Sisa yang perlu di-embed: {len(perlu) - i} teks.")
                raise SystemExit(3)
            raise SystemExit(f"embedding gagal HTTP {r.status_code}: {r.text[:300]}")

        cache_path.write_bytes(pickle.dumps(cache))   # simpan tiap batch, bukan di akhir
        if (i // UKURAN_BATCH) % 10 == 0:
            print(f"    {min(i + UKURAN_BATCH, len(perlu))}/{len(perlu)}", flush=True)

        # Jeda kecil antar batch. Tanpa ini satu jalan menabrak batas per menit
        # belasan kali dan menghabiskan menit-menit di mundur-teratur 30-60 detik;
        # menahan diri 4 detik jauh lebih murah daripada menunggu dihukum.
        time.sleep(4)

    return cache


def cosine(a: np.ndarray, b: np.ndarray) -> float:
    na, nb = np.linalg.norm(a), np.linalg.norm(b)
    if na == 0 or nb == 0:
        return 0.0
    return float(np.dot(a, b) / (na * nb))


# --- Metrik ---

def auc(y: np.ndarray, s: np.ndarray) -> float:
    """AUC lewat peringkat (Mann-Whitney). Tahan nilai seri."""
    pos, neg = (y == 1).sum(), (y == 0).sum()
    if pos == 0 or neg == 0:
        return float("nan")
    r = pd.Series(s).rank().to_numpy()
    return float((r[y == 1].sum() - pos * (pos + 1) / 2) / (pos * neg))


def auc_ci(y: np.ndarray, s: np.ndarray, n_boot: int = 400, seed: int = 0) -> tuple[float, float]:
    rng = np.random.default_rng(seed)
    n = len(y)
    hasil = []
    for _ in range(n_boot):
        idx = rng.integers(0, n, n)
        if len(np.unique(y[idx])) < 2:
            continue
        hasil.append(auc(y[idx], s[idx]))
    if not hasil:
        return (float("nan"), float("nan"))
    return (float(np.percentile(hasil, 2.5)), float(np.percentile(hasil, 97.5)))


def skor_berbobot(cos: pd.DataFrame, ada: pd.DataFrame, w: dict[str, float]) -> np.ndarray:
    """
    Replikasi persis scoring.hitung(): bidang yang salah satu sisinya kosong
    TIDAK dinilai, bobotnya dinormalkan ulang atas bidang yang tersisa.
    """
    num = np.zeros(len(cos))
    den = np.zeros(len(cos))
    for b in BIDANG:
        m = ada[b].to_numpy()
        num += np.where(m, cos[b].to_numpy() * w[b], 0.0)
        den += np.where(m, w[b], 0.0)
    return np.where(den > 0, num / np.maximum(den, 1e-9), np.nan)


# --- Penyiapan data ---

def norm_email(s: pd.Series) -> pd.Series:
    return (s.astype(str).str.strip().str.lower()
             .replace({"nan": None, "": None, "none": None}))


def muat_skill(data: Path) -> pd.DataFrame:
    """
    Kolom Skills dari ekstraksi tim DS, disambungkan lewat EMAIL.

    Kunci nama yang dipakai siapkan_data.py cuma mempertemukan 1.766 orang;
    lewat email 2.780 (+57%). Nama gagal karena ejaan, gelar, dan urutan kata
    berbeda antar berkas. Email dipakai HANYA sebagai kunci join dan tidak ikut
    keluar - keluaran tetap berupa hash nama yang sama dengan dataset_berlabel.

    experience/education tetap terpaksa lewat nama: kolom emailnya kosong total
    (lihat docstring siapkan_data.muat_pengalaman).
    """
    lab = pd.ExcelFile(data / "SOC_SOH pebaikan.xlsx")
    peta = pd.concat([lab.parse("SOC")[["FullName", "Email"]],
                      lab.parse("SOH")[["FullName", "Email"]]])
    peta["nama"] = norm_nama(peta["FullName"])
    peta["email"] = norm_email(peta["Email"])
    peta = peta.dropna(subset=["nama", "email"]).drop_duplicates("email")
    peta["id"] = peta["nama"].map(hash_id)

    d = pd.read_excel(data / "hasil_ekstraksi_cv (2).xlsx")
    d["email"] = norm_email(d["Email"])
    d = d.dropna(subset=["email"])
    d["teks_skill"] = d["Skills"].fillna("").astype(str).map(bersihkan)
    d = d[d["teks_skill"].str.strip() != ""]

    g = (d.groupby("email")["teks_skill"]
          .agg(lambda s: "; ".join(sorted(set(s)))[:4000])
          .reset_index()
          .merge(peta[["email", "id"]], on="email", how="inner")
          .drop_duplicates("id"))
    print(f"  skill: {len(g)} orang punya teks skill (join lewat email)")

    return g[["id", "teks_skill"]]


def muat_lowongan_perbidang(data: Path) -> pd.DataFrame:
    """
    Sisi lowongan dipecah tiga, meniru JobRequirement di produksi:
      skill      <- Requirements
      pengalaman <- WorkExperience + JobDescriptions
      pendidikan <- MinimumEducation
    """
    w = pd.ExcelFile(data / "work_description.xlsx").parse("Query1")
    w["job_skill"] = w["Requirements"].fillna("").map(bersih_html)
    w["job_pengalaman"] = (w["WorkExperience"].fillna("").astype(str) + " "
                           + w["JobDescriptions"].fillna("").map(bersih_html)).str.strip()
    w["job_pendidikan"] = w["MinimumEducation"].fillna("").astype(str).str.strip()

    kol = ["job_skill", "job_pengalaman", "job_pendidikan"]
    for c in kol:
        w[c] = w[c].map(bersihkan)   # buang "usia maksimal 30 tahun" dsb

    kunci = pd.concat([
        w.assign(k=norm_nama(w["JobTitle"]))[["k"] + kol],
        w.assign(k=norm_nama(w["PositionCategory"]))[["k"] + kol],
    ]).dropna(subset=["k"]).drop_duplicates("k")
    print(f"  lowongan: {len(kunci)} kunci posisi")

    return kunci


def main() -> int:
    p = argparse.ArgumentParser(description="Kalibrasi bobot bidang dengan embedding produksi")
    p.add_argument("--data", type=Path, default=DATA_BAWAAN)
    p.add_argument("--out", type=Path, default=OUT_BAWAAN)
    p.add_argument("--maks-negatif", type=int, default=1000,
                   help="batasi jumlah Not Hired demi kuota embedding; AUC tidak terpengaruh base rate")
    p.add_argument("--seed", type=int, default=7)
    p.add_argument("--hanya-siapkan", action="store_true", help="berhenti sebelum embedding")
    p.add_argument("--hanya-mengikat", action="store_true",
                   help="hanya baris dengan >=2 bidang, yaitu satu-satunya tempat bobot berpengaruh. "
                        "Menghemat kuota embedding yang cuma 1.000/hari.")
    a = p.parse_args()

    print("1. Memuat dataset berlabel")
    df = pd.read_csv(a.out / "dataset_berlabel.csv")
    df = df[df["ada_teks_lowongan"]].copy()
    print(f"  {len(df)} baris punya teks lowongan, hired {int(df.label.sum())}")

    print("2. Menyambung teks skill dan memecah sisi lowongan")
    df = df.merge(muat_skill(a.data), on="id", how="left")
    df["k"] = norm_nama(df["posisi"])
    df = df.merge(muat_lowongan_perbidang(a.data), on="k", how="left")

    cv = {"skill": "teks_skill", "pengalaman": "teks_pengalaman", "pendidikan": "teks_pendidikan"}
    job = {"skill": "job_skill", "pengalaman": "job_pengalaman", "pendidikan": "job_pendidikan"}
    for b in BIDANG:
        df[cv[b]] = df[cv[b]].fillna("").astype(str).str.strip()
        df[job[b]] = df[job[b]].fillna("").astype(str).str.strip()
        df[f"ada_{b}"] = (df[cv[b]].str.len() > 0) & (df[job[b]].str.len() > 0)

    print("\n3. Cakupan bidang (dua sisi terisi, jadi benar-benar dinilai)")
    for b in BIDANG:
        print(f"  {b:<11} {df[f'ada_{b}'].sum():>5} / {len(df)}  ({df[f'ada_{b}'].mean() * 100:5.1f}%)")
    n_bidang = df[[f"ada_{b}" for b in BIDANG]].sum(axis=1)
    print("  jumlah bidang dinilai per baris:")
    for k, v in n_bidang.value_counts().sort_index().items():
        print(f"    {k} bidang : {v:>5} ({v / len(df) * 100:5.1f}%)")

    df = df[n_bidang > 0].copy()
    print(f"  terpakai (minimal 1 bidang): {len(df)}, hired {int(df.label.sum())}")

    # --- Hasil yang tidak butuh embedding sama sekali ---
    # Saat cuma SATU bidang yang dinilai, renormalisasi membuat skor = cosine
    # bidang itu, berapa pun bobotnya. Bobot baru berpengaruh mulai dua bidang.
    n_b = df[[f"ada_{b}" for b in BIDANG]].sum(axis=1)
    mengikat = (n_b >= 2)
    print("\n3b. Seberapa sering bobot benar-benar berlaku?")
    print(f"  skor ditentukan SATU bidang saja : {int((n_b == 1).sum()):>5} "
          f"({(n_b == 1).mean() * 100:5.1f}%)  <- bobot tidak berpengaruh")
    print(f"  bobot benar-benar mengikat       : {int(mengikat.sum()):>5} "
          f"({mengikat.mean() * 100:5.1f}%)")
    if mengikat.sum():
        print(f"    di antaranya hired             : {int(df.loc[mengikat, 'label'].sum())}")
    esa = df.loc[n_b == 1, [f"ada_{b}" for b in BIDANG]].idxmax(axis=1).str.replace("ada_", "")
    print("  bidang tunggal itu, rinciannya:")
    for k, v in esa.value_counts().items():
        print(f"    {k:<11} {v:>5} ({v / len(df) * 100:5.1f}%)")

    if a.hanya_siapkan:
        return 0

    if a.hanya_mengikat:
        df = df[mengikat].copy()
        print(f"\n  --hanya-mengikat: {len(df)} baris, hired {int(df.label.sum())}")

    # subsample negatif demi kuota; SEMUA hired dipertahankan
    hired = df[df.label == 1]
    nots = df[df.label == 0]
    if len(nots) > a.maks_negatif:
        nots = nots.sample(a.maks_negatif, random_state=a.seed)
    df = pd.concat([hired, nots]).sample(frac=1, random_state=a.seed).reset_index(drop=True)
    print(f"\n4. Sampel untuk embedding: {len(df)} baris ({len(hired)} hired, {len(nots)} not hired)")

    semua_teks = []
    for b in BIDANG:
        semua_teks += df[cv[b]].tolist() + df[job[b]].tolist()
    cache = embed_semua(semua_teks, a.out / "cache_embedding.pkl")

    print("\n5. Menghitung cosine per bidang")
    vek = lambda t: np.array(cache[_kunci(t, "gemini-embedding-001")], dtype=np.float32)
    cos = pd.DataFrame(index=df.index)
    ada = pd.DataFrame(index=df.index)
    for b in BIDANG:
        nilai = []
        for t_cv, t_job, punya in zip(df[cv[b]], df[job[b]], df[f"ada_{b}"]):
            nilai.append(max(0.0, min(1.0, cosine(vek(t_cv), vek(t_job)))) if punya else np.nan)
        cos[b] = nilai
        ada[b] = df[f"ada_{b}"].to_numpy()
        v = cos.loc[ada[b], b]
        print(f"  {b:<11} n={len(v):>5}  rata2={v.mean():.4f}  min={v.min():.4f}  maks={v.max():.4f}")

    y = df["label"].to_numpy()

    print("\n6. Daya beda TIAP BIDANG sendirian")
    per_bidang = {}
    for b in BIDANG:
        m = ada[b].to_numpy()
        if m.sum() < 30 or len(np.unique(y[m])) < 2:
            print(f"  {b:<11} data tidak cukup")
            continue
        A = auc(y[m], cos.loc[m, b].to_numpy())
        lo, hi = auc_ci(y[m], cos.loc[m, b].to_numpy())
        per_bidang[b] = {"n": int(m.sum()), "hired": int(y[m].sum()), "auc": round(A, 4),
                         "ci95": [round(lo, 4), round(hi, 4)]}
        print(f"  {b:<11} n={m.sum():>5} hired={int(y[m].sum()):>3}  AUC={A:.4f}  CI95 [{lo:.4f}, {hi:.4f}]")

    print("\n7. Pencarian bobot (langkah 0,05)")
    langkah = [i / 20 for i in range(21)]
    hasil = []
    for ws, wp, wd in product(langkah, repeat=3):
        if abs(ws + wp + wd - 1.0) > 1e-9:
            continue
        w = {"skill": ws, "pengalaman": wp, "pendidikan": wd}
        s = skor_berbobot(cos, ada, w)
        ok = ~np.isnan(s)
        if len(np.unique(y[ok])) < 2:
            continue
        hasil.append({**w, "auc": auc(y[ok], s[ok]), "n": int(ok.sum())})

    H = pd.DataFrame(hasil).sort_values("auc", ascending=False).reset_index(drop=True)

    s_now = skor_berbobot(cos, ada, BOBOT_SEKARANG)
    ok = ~np.isnan(s_now)
    auc_now = auc(y[ok], s_now[ok])
    lo_now, hi_now = auc_ci(y[ok], s_now[ok])

    print(f"\n  bobot SEKARANG 50/30/20 : AUC={auc_now:.4f}  CI95 [{lo_now:.4f}, {hi_now:.4f}]  n={ok.sum()}")
    print("\n  10 bobot terbaik:")
    print(f"  {'skill':>6} {'alaman':>7} {'didik':>6} {'AUC':>8}")
    for _, r in H.head(10).iterrows():
        print(f"  {r.skill:>6.2f} {r.pengalaman:>7.2f} {r.pendidikan:>6.2f} {r.auc:>8.4f}")

    best = H.iloc[0]
    lo_b, hi_b = auc_ci(y[ok], skor_berbobot(cos, ada, {b: float(best[b]) for b in BIDANG})[ok])
    print(f"\n  terbaik CI95 [{lo_b:.4f}, {hi_b:.4f}]")
    print("  CI tumpang tindih dengan bobot sekarang?  "
          + ("YA - selisihnya belum bermakna" if lo_b < hi_now and lo_now < hi_b else "TIDAK"))

    a.out.mkdir(parents=True, exist_ok=True)
    H.to_csv(a.out / "bobot_grid.csv", index=False)
    ringkas = {
        "n": int(ok.sum()), "hired": int(y[ok].sum()),
        "cakupan_bidang": {b: round(float(ada[b].mean()), 4) for b in BIDANG},
        "auc_per_bidang": per_bidang,
        "bobot_sekarang": {**BOBOT_SEKARANG, "auc": round(auc_now, 4), "ci95": [round(lo_now, 4), round(hi_now, 4)]},
        "bobot_terbaik": {b: float(best[b]) for b in BIDANG} | {"auc": round(float(best.auc), 4),
                                                               "ci95": [round(lo_b, 4), round(hi_b, 4)]},
    }
    (a.out / "hasil_bobot.json").write_text(json.dumps(ringkas, indent=2), encoding="utf-8")
    print(f"\n  -> {a.out / 'hasil_bobot.json'}")
    print(f"  -> {a.out / 'bobot_grid.csv'}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
