"""Strukturisasi 3 field: CV ber-heading, CV naratif, dan pemisahan atribut sensitif."""

from structure import strukturkan

CV_HEADING = """Budi Contoh
Jl. Melati No. 5, Jakarta - budi@contoh.com - 0812345678

PENGALAMAN KERJA
Backend Developer, PT Contoh Sejahtera (2021-2024)
Membangun REST API dengan PHP dan SQL Server.

PENDIDIKAN
S1 Teknik Informatika, Universitas Contoh (2015-2019)

SKILL
PHP, Python, SQL Server, Git
"""

CV_NARATIF = (
    "Saya seorang programmer dengan pengalaman empat tahun membangun aplikasi "
    "web di perusahaan retail. Sehari-hari bekerja dengan PHP dan SQL Server, "
    "lulusan S1 Teknik Informatika, terbiasa memimpin tim kecil."
)


def test_cv_ber_heading_terpecah_tiga_field():
    t = strukturkan(CV_HEADING)

    assert "Backend Developer" in t.pengalaman
    assert "Universitas Contoh" in t.pendidikan
    assert "PHP, Python" in t.skill
    assert t.flags == ()


def test_baris_heading_tidak_ikut_jadi_isi():
    t = strukturkan(CV_HEADING)

    assert "PENGALAMAN KERJA" not in t.pengalaman
    assert "SKILL" not in t.skill


def test_atribut_kontak_tertinggal_di_lain():
    """Alamat/telepon/email tidak boleh masuk field yang di-embed (fairness A3.2)."""
    t = strukturkan(CV_HEADING)

    assert "Jl. Melati" in t.lain
    for field in (t.pengalaman, t.skill, t.pendidikan):
        assert "0812345678" not in field
        assert "Jl. Melati" not in field


def test_cv_naratif_fallback_dengan_flag():
    t = strukturkan(CV_NARATIF)

    assert t.flags == ("tanpa_heading",)
    assert "programmer" in t.pengalaman  # tetap ada bahan utk embedding
    assert t.skill == "" and t.pendidikan == ""


def test_heading_variasi_bahasa_inggris():
    t = strukturkan("EDUCATION\nS1 Contoh\n\nWORK EXPERIENCE\nDeveloper 2020\n\nSKILLS\nPython")

    assert "S1 Contoh" in t.pendidikan
    assert "Developer 2020" in t.pengalaman
    assert "Python" in t.skill


def test_kalimat_mengandung_kata_skill_bukan_heading():
    teks = "PENGALAMAN\nMengasah skill komunikasi selama menjadi kasir di toko retail."
    t = strukturkan(teks)

    # kalimat berisi kata 'skill' tetap milik section pengalaman
    assert "kasir" in t.pengalaman
    assert t.skill == ""


def test_field_kosong_tercatat_di_flags():
    t = strukturkan("PENGALAMAN KERJA\nKasir toko (2020-2022)")

    assert "skill_kosong" in t.flags
    assert "pendidikan_kosong" in t.flags
