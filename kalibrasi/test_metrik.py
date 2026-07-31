"""Test fungsi metrik pada data sintetis yang jawabannya sudah diketahui.

Jalankan: python -m pytest test_metrik.py -q
"""

import numpy as np

from latih_dan_ukur import auc_ci, fit_platt, peluang, pilih_ambang, skor_cosine
import pandas as pd


def test_auc_pemisahan_sempurna():
    y = np.array([0, 0, 0, 1, 1, 1])
    s = np.array([0.1, 0.2, 0.3, 0.7, 0.8, 0.9])

    auc, lo, hi = auc_ci(y, s, n_boot=200)

    assert auc == 1.0
    assert lo <= auc <= hi


def test_auc_acak_mendekati_setengah():
    rng = np.random.default_rng(0)
    y = rng.integers(0, 2, 4000)
    s = rng.random(4000)

    auc, lo, hi = auc_ci(y, s, n_boot=200)

    assert 0.45 < auc < 0.55
    assert lo < 0.5 < hi  # selang memuat 0.5 -> benar-benar tak ada sinyal


def test_auc_terbalik_di_bawah_setengah():
    y = np.array([0, 0, 1, 1])
    s = np.array([0.9, 0.8, 0.2, 0.1])  # sengaja dibalik

    auc, _, _ = auc_ci(y, s, n_boot=100)

    assert auc == 0.0


def test_ambang_bawah_di_persentil_hired():
    # hired berskor 0.50..0.95, not-hired jauh di bawah
    y = np.array([0] * 50 + [1] * 100)
    s = np.concatenate([np.linspace(0.0, 0.3, 50), np.linspace(0.50, 0.95, 100)])

    a = pilih_ambang(y, s, persentil_bawah=5.0)

    # persentil 5 dari hired: sekitar 0.52
    assert 0.50 <= a["lower"] <= 0.55
    # semua not-hired berada di bawah ambang -> tergugur otomatis
    assert a["tergugur_otomatis_pct"] >= 33.0


def test_ambang_bawah_konservatif_menyisakan_sedikit_hired():
    """Persentil 5 berarti maksimal ~5% kandidat diterima ikut tergugur."""
    rng = np.random.default_rng(1)
    y = np.array([0] * 900 + [1] * 100)
    s = np.concatenate([rng.normal(0.4, 0.1, 900), rng.normal(0.6, 0.1, 100)])

    a = pilih_ambang(y, s, persentil_bawah=5.0)

    assert a["hired_ikut_tergugur"] <= 6  # 5 dari 100, beri kelonggaran pembulatan


def test_ambang_atas_none_bila_presisi_tak_tercapai():
    # positif cuma 1%, dan skornya tidak memisahkan -> tidak boleh ada lolos otomatis
    rng = np.random.default_rng(2)
    y = np.array([0] * 990 + [1] * 10)
    s = rng.random(1000)

    a = pilih_ambang(y, s, presisi_target=0.50)

    assert a["upper"] is None


def test_platt_menghasilkan_peluang_monoton():
    y = np.array([0] * 100 + [1] * 100)
    s = np.concatenate([np.linspace(0.0, 0.5, 100), np.linspace(0.5, 1.0, 100)])

    a, b = fit_platt(s, y)
    p = peluang(np.array([0.1, 0.5, 0.9]), a, b)

    assert a > 0                      # skor naik -> peluang naik
    assert p[0] < p[1] < p[2]
    assert ((0.0 <= p) & (p <= 1.0)).all()


def test_cosine_teks_identik_mendekati_satu():
    t = pd.Series(["kasir melayani pelanggan toko retail", "gudang stok opname barang"])

    s = skor_cosine(t, t.copy())

    assert (s > 0.99).all()


def test_cosine_teks_tak_berhubungan_rendah():
    cv = pd.Series(["kasir melayani pelanggan toko retail harian"])
    job = pd.Series(["anestesi intubasi resusitasi kamar operasi bedah"])

    s = skor_cosine(cv, job)

    assert s[0] < 0.2


# --- metrik klasifikasi (accuracy/F1/PR-AUC) ---

def test_metrik_pemisahan_sempurna():
    from latih_dan_ukur import metrik_klasifikasi
    y = np.array([0]*90 + [1]*10)
    s = np.concatenate([np.linspace(0.0, 0.4, 90), np.linspace(0.6, 1.0, 10)])

    m = metrik_klasifikasi(y, s)

    assert m['f1'] == 1.0 and m['presisi'] == 1.0 and m['recall'] == 1.0
    assert m['pr_auc'] > 0.99
    assert m['lift_pr_auc'] > 9   # basis 0.1 -> lift ~10x


def test_metrik_menyertakan_pembanding_tolak_semua():
    """Angka yang menjaga kita dari tertipu akurasi tinggi pada data tak seimbang."""
    from latih_dan_ukur import metrik_klasifikasi
    rng = np.random.default_rng(7)
    y = np.array([0]*958 + [1]*42)          # 4.2% positif, seperti data nyata
    s = rng.random(1000)

    m = metrik_klasifikasi(y, s)

    assert abs(m['akurasi_tolak_semua'] - 0.958) < 0.001
    assert abs(m['basis_positif'] - 0.042) < 0.001
    assert m['lift_pr_auc'] < 2.0            # skor acak -> nyaris tanpa lift


def test_tabel_ambang_recall_menurun_saat_ambang_naik():
    from latih_dan_ukur import tabel_ambang
    rng = np.random.default_rng(3)
    y = np.array([0]*900 + [1]*100)
    s = np.concatenate([rng.normal(0.4, 0.1, 900), rng.normal(0.6, 0.1, 100)])

    t = tabel_ambang(y, s)

    assert list(t['persentil']) == sorted(t['persentil'])
    assert t['recall'].is_monotonic_decreasing
    assert (t['diloloskan'].diff().dropna() <= 0).all()
