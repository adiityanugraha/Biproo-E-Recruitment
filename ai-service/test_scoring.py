"""Cosine similarity + skor agregat berbobot (Blueprint A3.2, bobot 50/30/20)."""

import math

import pytest

from scoring import BOBOT_DEFAULT, cosine, hitung, ke_0_1


def test_cosine_vektor_identik_satu():
    assert cosine([1, 2, 3], [1, 2, 3]) == pytest.approx(1.0)


def test_cosine_ortogonal_nol():
    assert cosine([1, 0], [0, 1]) == pytest.approx(0.0)


def test_cosine_berlawanan_minus_satu():
    assert cosine([1, 0], [-1, 0]) == pytest.approx(-1.0)


def test_cosine_tak_peduli_skala():
    assert cosine([1, 1], [5, 5]) == pytest.approx(1.0)


@pytest.mark.parametrize("a,b", [([], [1]), ([1], []), ([1, 2], [1]), ([0, 0], [1, 1])])
def test_cosine_input_tidak_layak_jadi_nol(a, b):
    assert cosine(a, b) == 0.0


def test_negatif_dipangkas_ke_nol_bukan_setengah():
    assert ke_0_1(-0.8) == 0.0
    assert ke_0_1(1.5) == 1.0
    assert ke_0_1(0.6) == 0.6


# --- skor agregat ---

def vek(sudut_derajat: float) -> list[float]:
    r = math.radians(sudut_derajat)
    return [math.cos(r), math.sin(r)]


def test_semua_bidang_cocok_sempurna():
    v = {b: [1.0, 0.0] for b in ("skill", "pengalaman", "pendidikan")}

    s = hitung(v, v)

    assert s.overall == 1.0
    assert s.skill == s.pengalaman == s.pendidikan == 1.0
    assert s.flags == ()


def test_bobot_default_50_30_20_diterapkan():
    # skill cocok penuh, pengalaman & pendidikan ortogonal (0)
    cv  = {"skill": [1, 0], "pengalaman": [1, 0], "pendidikan": [1, 0]}
    job = {"skill": [1, 0], "pengalaman": [0, 1], "pendidikan": [0, 1]}

    s = hitung(cv, job)

    assert s.skill == 1.0 and s.pengalaman == 0.0 and s.pendidikan == 0.0
    assert s.overall == pytest.approx(BOBOT_DEFAULT["skill"], abs=1e-4)  # 0.50


def test_bidang_kosong_tidak_dinilai_nol_tapi_bobot_dinormalkan():
    """Pelajaran bug umur-nan DS: data tak terbaca != nilai 0."""
    cv  = {"skill": [1, 0], "pengalaman": None, "pendidikan": None}
    job = {"skill": [1, 0], "pengalaman": [1, 0], "pendidikan": [1, 0]}

    s = hitung(cv, job)

    assert s.skill == 1.0
    assert s.pengalaman is None and s.pendidikan is None
    # hanya skill yang dinilai -> overall = skor skill, BUKAN 0.5 (yang terjadi
    # kalau bidang kosong dihitung 0 dengan bobot penuh)
    assert s.overall == 1.0
    assert "pengalaman_tidak_dinilai" in s.flags
    assert "bobot_dinormalkan_ulang" in s.flags


def test_job_requirement_kosong_juga_membuat_bidang_tak_dinilai():
    cv  = {"skill": [1, 0], "pengalaman": [1, 0], "pendidikan": [1, 0]}
    job = {"skill": [1, 0], "pengalaman": [], "pendidikan": None}

    s = hitung(cv, job)

    assert s.pengalaman is None and s.pendidikan is None
    assert s.overall == 1.0


def test_tidak_ada_bidang_sama_sekali():
    kosong = {"skill": None, "pengalaman": None, "pendidikan": None}

    s = hitung(kosong, kosong)

    assert s.overall is None
    assert "tidak_dapat_dinilai" in s.flags
    assert s.as_dict() == {"overall": None, "skill": None, "pendidikan": None, "pengalaman": None}


def test_bobot_per_posisi_menimpa_default():
    cv  = {"skill": [1, 0], "pengalaman": [1, 0], "pendidikan": [1, 0]}
    job = {"skill": [0, 1], "pengalaman": [1, 0], "pendidikan": [0, 1]}

    # pengalaman dinaikkan jadi penentu utama (jobs.bobot_json per posisi)
    s = hitung(cv, job, {"skill": 0.1, "pengalaman": 0.8, "pendidikan": 0.1})

    assert s.overall == pytest.approx(0.8, abs=1e-4)


def test_skor_berada_di_rentang_gate_0_1():
    cv  = {b: vek(d) for b, d in (("skill", 10), ("pengalaman", 80), ("pendidikan", 170))}
    job = {b: vek(0) for b in ("skill", "pengalaman", "pendidikan")}

    s = hitung(cv, job)

    for nilai in (s.overall, s.skill, s.pengalaman, s.pendidikan):
        assert 0.0 <= nilai <= 1.0


def test_as_dict_sesuai_kontrak_callback():
    v = {b: [1.0, 0.0] for b in ("skill", "pengalaman", "pendidikan")}

    assert set(hitung(v, v).as_dict()) == {"overall", "skill", "pendidikan", "pengalaman"}
