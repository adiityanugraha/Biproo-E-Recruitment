"""
Latih dan ukur skorer CV terhadap label diterima/tidak (Fase 5).

Tiga skorer dibandingkan berdampingan pada label yang SAMA:

  A  cosine TF-IDF antara teks CV dan teks kriteria lowongan.
     Tanpa pelatihan. Butuh teks lowongan, jadi hanya sebagian data terpakai.
  B1 classifier dari teks CV saja (TF-IDF -> regresi logistik).
  B2 classifier dari teks CV + posisi yang dilamar.

B1 dan B2 dipisah dengan sengaja. Selisih keduanya menunjukkan berapa banyak
sinyal yang datang dari isi CV dan berapa yang cuma dari tingkat penerimaan
rata-rata posisi. Tanpa pemisahan ini, AUC B2 mudah terlihat bagus padahal
model hanya menghafal "posisi Security lebih sering menerima".

Semua evaluasi memakai StratifiedKFold: vectorizer di-fit DI DALAM lipatan
supaya kosakata data uji tidak bocor ke pelatihan.

Pemakaian:
    python latih_dan_ukur.py
    python latih_dan_ukur.py --out DIR --lipatan 5 --bootstrap 2000
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import matplotlib
matplotlib.use("Agg")  # tanpa layar; menulis PNG
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (accuracy_score, average_precision_score,
                             precision_recall_fscore_support, roc_auc_score, roc_curve)
from sklearn.model_selection import StratifiedKFold, cross_val_predict
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder

OUT_BAWAAN = Path(__file__).resolve().parents[2] / "kalibrasi-out"
MIN_HIRED_POSISI = 5   # posisi di bawah ini tidak dilaporkan per-posisi
ACAK = 42


# --- metrik (diuji di test_metrik.py) ---

def auc_ci(y, skor, n_boot: int = 2000, rng: np.random.Generator | None = None) -> tuple[float, float, float]:
    """AUC beserta selang kepercayaan 95% lewat bootstrap. (auc, lo, hi)"""
    y, skor = np.asarray(y), np.asarray(skor)
    auc = roc_auc_score(y, skor)
    rng = rng or np.random.default_rng(ACAK)

    hasil = []
    idx_pos = np.flatnonzero(y == 1)
    idx_neg = np.flatnonzero(y == 0)
    for _ in range(n_boot):
        # bootstrap berlapis: jaga jumlah positif/negatif supaya AUC selalu terdefinisi
        s = np.concatenate([rng.choice(idx_pos, idx_pos.size, replace=True),
                            rng.choice(idx_neg, idx_neg.size, replace=True)])
        hasil.append(roc_auc_score(y[s], skor[s]))

    return float(auc), float(np.percentile(hasil, 2.5)), float(np.percentile(hasil, 97.5))


def metrik_klasifikasi(y, skor) -> dict:
    """
    Accuracy, precision, recall, F1 pada ambang F1-TERBAIK, plus PR-AUC.

    Tiga hal yang wajib dibaca bersamaan, kalau tidak angkanya menipu:

    1. `akurasi_tolak_semua` - akurasi model yang menolak semua orang tanpa
       membaca CV. Pada data 4% positif angkanya ~96%. Setiap akurasi yang tidak
       jauh melampaui ini artinya nol kegunaan.
    2. `pr_auc` (average precision) lebih informatif daripada ROC-AUC saat kelas
       tidak seimbang, karena ia peduli pada presisi di ujung atas peringkat -
       yang justru dipakai gate.
    3. `lift` = pr_auc / basis. Berapa kali lebih baik dari menebak acak.

    Ambang F1 dipilih pada data yang sama, jadi F1 di sini OPTIMIS. Untuk
    membandingkan antar skorer tetap adil karena semuanya diperlakukan sama.
    """
    y, skor = np.asarray(y), np.asarray(skor)
    basis = float(y.mean())

    terbaik = {"f1": -1.0}
    for t in np.unique(skor):
        pred = (skor >= t).astype(int)
        p, r, f, _ = precision_recall_fscore_support(y, pred, average="binary", zero_division=0)
        if f > terbaik["f1"]:
            terbaik = {"ambang": round(float(t), 6), "presisi": round(float(p), 4),
                       "recall": round(float(r), 4), "f1": round(float(f), 4),
                       "akurasi": round(float(accuracy_score(y, pred)), 4),
                       "diloloskan": int(pred.sum())}

    ap = float(average_precision_score(y, skor))

    return {
        **terbaik,
        "pr_auc": round(ap, 4),
        "basis_positif": round(basis, 4),
        "lift_pr_auc": round(ap / basis, 2) if basis else None,
        "akurasi_tolak_semua": round(float(accuracy_score(y, np.zeros_like(y))), 4),
    }


def tabel_ambang(y, skor, persentil=(50, 60, 70, 75, 80, 85, 90, 95, 99)) -> pd.DataFrame:
    """Presisi/recall/F1 di beberapa titik operasi, supaya trade-off-nya terlihat."""
    y, skor = np.asarray(y), np.asarray(skor)
    baris = []
    for q in persentil:
        t = float(np.percentile(skor, q))
        pred = (skor >= t).astype(int)
        p, r, f, _ = precision_recall_fscore_support(y, pred, average="binary", zero_division=0)
        baris.append({"persentil": q, "ambang": round(t, 6), "diloloskan": int(pred.sum()),
                      "presisi": round(float(p), 4), "recall": round(float(r), 4),
                      "f1": round(float(f), 4),
                      "akurasi": round(float(accuracy_score(y, pred)), 4)})

    return pd.DataFrame(baris)


def pilih_ambang(y, skor, persentil_bawah: float = 5.0, presisi_target: float = 0.15) -> dict:
    """
    Ambang untuk GateOne.

    lower (gugur otomatis) = persentil ke-`persentil_bawah` skor kandidat DITERIMA.
      Artinya kita hanya menggugurkan otomatis di bawah titik yang 95% kandidat
      diterima berada di atasnya. Konservatif dengan sengaja: salah menggugurkan
      lebih mahal daripada salah melempar ke review manusia.

    upper (lolos otomatis) = skor terendah yang presisinya masih >= presisi_target.
      None bila tidak ada titik yang mencapainya - berarti jangan pernah
      meloloskan otomatis pada data ini.
    """
    y, skor = np.asarray(y), np.asarray(skor)
    lower = float(np.percentile(skor[y == 1], persentil_bawah))

    upper = None
    for t in np.unique(skor)[::-1]:
        lolos = skor >= t
        if lolos.sum() >= 10:  # jangan pungut presisi dari 1-2 baris
            if y[lolos].mean() >= presisi_target:
                upper = float(t)
            else:
                break

    return {
        "lower": round(lower, 4),
        "upper": None if upper is None else round(upper, 4),
        "presisi_target": presisi_target,
        "tergugur_otomatis_pct": round(100 * float((skor < lower).mean()), 1),
        "hired_ikut_tergugur": int(((skor < lower) & (y == 1)).sum()),
    }


def fit_platt(skor, y) -> tuple[float, float]:
    """Ubah skor mentah jadi peluang. Model 1 fitur -> cukup DUA angka, tanpa pickle."""
    m = LogisticRegression().fit(np.asarray(skor).reshape(-1, 1), np.asarray(y))

    return float(m.coef_[0][0]), float(m.intercept_[0])


def peluang(skor, a: float, b: float) -> np.ndarray:
    return 1.0 / (1.0 + np.exp(-(a * np.asarray(skor) + b)))


# --- skorer ---

def skor_cosine(cv_teks: pd.Series, job_teks: pd.Series) -> np.ndarray:
    """Cosine TF-IDF baris demi baris. Tanpa label, jadi tidak ada kebocoran."""
    korpus = pd.concat([cv_teks, job_teks])
    # min_df=2 menyaring salah tulis dan token sekali-muncul, tapi pada korpus
    # kecil ia membuang SEMUA term lalu melempar error. Turunkan ke 1 di situ.
    vec = TfidfVectorizer(sublinear_tf=True, min_df=2 if len(korpus) >= 50 else 1,
                          ngram_range=(1, 2))
    vec.fit(korpus)
    A, B = vec.transform(cv_teks), vec.transform(job_teks)

    # baris ternormalkan (TfidfVectorizer norm='l2'), jadi dot = cosine
    return np.asarray(A.multiply(B).sum(axis=1)).ravel()


def skor_classifier(df: pd.DataFrame, pakai_posisi: bool, lipatan: int) -> np.ndarray:
    """Peluang diterima dari cross-validation. Vectorizer di-fit di dalam lipatan."""
    tfidf = TfidfVectorizer(sublinear_tf=True, min_df=3, ngram_range=(1, 2), max_features=40000)
    if pakai_posisi:
        pra = ColumnTransformer([
            ("teks", tfidf, "teks_pengalaman"),
            ("posisi", OneHotEncoder(handle_unknown="ignore"), ["posisi"]),
        ])
    else:
        pra = ColumnTransformer([("teks", tfidf, "teks_pengalaman")])

    pipe = Pipeline([
        ("pra", pra),
        ("clf", LogisticRegression(max_iter=2000, class_weight="balanced")),
    ])
    cv = StratifiedKFold(n_splits=lipatan, shuffle=True, random_state=ACAK)

    return cross_val_predict(pipe, df, df["label"], cv=cv, method="predict_proba")[:, 1]


# --- pelaporan ---

def auc_per_posisi(df: pd.DataFrame, skor: np.ndarray) -> pd.DataFrame:
    baris = []
    for pos, g in df.assign(_s=skor).groupby("posisi"):
        n_h = int(g["label"].sum())
        if n_h >= MIN_HIRED_POSISI and n_h < len(g):
            baris.append({"posisi": pos, "n": len(g), "hired": n_h,
                          "auc": round(roc_auc_score(g["label"], g["_s"]), 3)})

    return pd.DataFrame(baris).sort_values("hired", ascending=False)


def gambar(hasil: dict, df_a: pd.DataFrame, skor_a: np.ndarray, out: Path, sfx: str = "") -> Path:
    fig, (kiri, kanan) = plt.subplots(1, 2, figsize=(13, 5))

    kiri.hist(skor_a[df_a["label"] == 0], bins=40, alpha=0.6, density=True, label="Not Hired")
    kiri.hist(skor_a[df_a["label"] == 1], bins=40, alpha=0.6, density=True, label="Hired")
    kiri.set_title("Sebaran skor cosine TF-IDF (A)")
    kiri.set_xlabel("skor")
    kiri.set_ylabel("kepadatan")
    kiri.legend()

    for nama, (y, s) in hasil.items():
        fpr, tpr, _ = roc_curve(y, s)
        kanan.plot(fpr, tpr, label=f"{nama} (AUC {roc_auc_score(y, s):.3f})")
    kanan.plot([0, 1], [0, 1], "k--", lw=0.8, label="acak")
    kanan.set_title("Kurva ROC")
    kanan.set_xlabel("false positive rate")
    kanan.set_ylabel("true positive rate")
    kanan.legend()

    fig.tight_layout()
    p = out / f"kalibrasi{sfx}.png"
    fig.savefig(p, dpi=130)
    plt.close(fig)

    return p


def main() -> int:
    p = argparse.ArgumentParser(description="Latih dan ukur skorer CV")
    p.add_argument("--out", type=Path, default=OUT_BAWAAN)
    p.add_argument("--sumber", default="dataset_berlabel.csv",
                   help="nama CSV di folder --out. Pakai dataset_ekstraksi_kita.csv "
                        "untuk mengukur teks hasil pipeline produksi.")
    p.add_argument("--label", default="", help="label untuk nama berkas keluaran, mis. 'ekstraksi'")
    p.add_argument("--lipatan", type=int, default=5)
    p.add_argument("--bootstrap", type=int, default=2000)
    a = p.parse_args()

    sumber = a.out / a.sumber
    if not sumber.is_file():
        print(f"BERHENTI: {sumber} tidak ada. Jalankan siapkan_data.py "
              f"atau ekstrak_cv_berlabel.py dulu.")
        return 1

    # akhiran nama berkas keluaran, supaya hasil dua sumber tidak saling menimpa
    sfx = f"_{a.label}" if a.label else ""

    df = pd.read_csv(sumber)
    for kol in ("teks_pengalaman", "teks_pendidikan", "teks_lowongan", "posisi"):
        df[kol] = df[kol].fillna("")
    # baris tanpa teks CV tidak bisa diskor - buang, dan laporkan berapa
    n_awal = len(df)
    df = df[df["teks_pengalaman"].str.len() > 0].reset_index(drop=True)
    if len(df) < n_awal:
        print(f"  {n_awal - len(df)} baris dibuang karena teks CV kosong (gagal ekstraksi)")
    print(f"dataset: {sumber.name} - {len(df)} baris, Hired {int(df['label'].sum())}\n")

    hasil, ringkas, tabel = {}, {}, {}

    def lapor(nama: str, kunci: str, y, s) -> None:
        auc, lo, hi = auc_ci(y, s, a.bootstrap)
        m = metrik_klasifikasi(y, s)
        hasil[nama] = (np.asarray(y), s)
        tabel[kunci] = tabel_ambang(y, s)
        ringkas[kunci] = {"n": len(y), "hired": int(np.sum(y)),
                          "auc": round(auc, 4), "ci95": [round(lo, 4), round(hi, 4)], **m}

        print(f"{nama:20s} n={len(y):5d} hired={int(np.sum(y)):3d}")
        print(f"{'':20s}   AUC     {auc:.3f} [{lo:.3f} - {hi:.3f}]")
        print(f"{'':20s}   PR-AUC  {m['pr_auc']:.3f}  (basis {m['basis_positif']:.3f}, lift {m['lift_pr_auc']}x)")
        print(f"{'':20s}   F1 terbaik {m['f1']:.3f} @ambang {m['ambang']:.4f}"
              f"  presisi {m['presisi']:.3f}  recall {m['recall']:.3f}")
        print(f"{'':20s}   akurasi {m['akurasi']:.3f}"
              f"   <- pembanding: 'tolak semua' = {m['akurasi_tolak_semua']:.3f}")

    # A: cosine, hanya baris yang punya teks lowongan
    df_a = df[df["ada_teks_lowongan"]].reset_index(drop=True)
    skor_a = skor_cosine(df_a["teks_pengalaman"], df_a["teks_lowongan"])
    lapor("A cosine TF-IDF", "A_cosine_tfidf", df_a["label"].to_numpy(), skor_a)

    # B1 / B2: classifier
    for nama, kunci, pakai_posisi in [("B1 teks CV saja", "B1_teks_saja", False),
                                      ("B2 teks CV + posisi", "B2_teks_posisi", True)]:
        s = skor_classifier(df, pakai_posisi, a.lipatan)
        lapor(nama, kunci, df["label"].to_numpy(), s)

    # ambang + Platt dari skorer A (yang dipakai GateOne di produksi)
    ambang = pilih_ambang(df_a["label"], skor_a)
    pa, pb = fit_platt(skor_a, df_a["label"])
    ringkas["ambang_dari_A"] = ambang
    ringkas["platt_A"] = {"a": round(pa, 4), "b": round(pb, 4)}
    print(f"\nambang usulan (dari A): lower={ambang['lower']}  upper={ambang['upper']}")
    print(f"  tergugur otomatis {ambang['tergugur_otomatis_pct']}%, termasuk {ambang['hired_ikut_tergugur']} kandidat yang sebenarnya diterima")
    print(f"Platt A: a={pa:.4f} b={pb:.4f}")

    per_pos = auc_per_posisi(df_a, skor_a)
    png = gambar(hasil, df_a, skor_a, a.out, sfx)

    ringkas["_sumber"] = {"berkas": sumber.name, "baris": len(df),
                          "hired": int(df["label"].sum()),
                          "median_karakter_teks": int(df["teks_pengalaman"].str.len().median())}

    (a.out / f"hasil_kalibrasi{sfx}.json").write_text(
        json.dumps(ringkas, indent=2, ensure_ascii=False), encoding="utf-8")
    per_pos.to_csv(a.out / f"auc_per_posisi{sfx}.csv", index=False)

    # tabel titik operasi: presisi/recall/F1 di berbagai ambang, per skorer
    gabung = pd.concat([t.assign(skorer=k) for k, t in tabel.items()], ignore_index=True)
    gabung.to_csv(a.out / f"metrik_per_ambang{sfx}.csv", index=False)

    print("\n=== titik operasi skorer A (presisi/recall/F1 per ambang) ===")
    print(tabel["A_cosine_tfidf"].to_string(index=False))

    print(f"\n  -> {a.out / f'hasil_kalibrasi{sfx}.json'}")
    print(f"  -> {a.out / f'auc_per_posisi{sfx}.csv'}  ({len(per_pos)} posisi)")
    print(f"  -> {a.out / f'metrik_per_ambang{sfx}.csv'}  ({len(gabung)} baris)")
    print(f"  -> {png}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
