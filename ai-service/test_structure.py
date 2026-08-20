"""Strukturisasi 3 field: CV ber-heading, CV naratif, dan pemisahan atribut sensitif."""

import logging

import pytest

import structure
from structure import MAKS_RIWAYAT, MAX_TEKS_LLM, strukturkan, strukturkan_kontekstual


@pytest.fixture(autouse=True)
def _tanpa_jeda(monkeypatch):
    """
    Produksi menunggu 20 detik sebelum mengulang karena kuota 429 dihitung per
    menit. Uji tidak perlu menunggu itu - yang diuji perilakunya, bukan jedanya.
    """
    monkeypatch.setattr(structure, "RETRY_JEDA", 0)

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


# --- Jalur kontekstual via LLM (Day 3) ---

class FakeLLM:
    def __init__(self, jawab):
        self.jawab = jawab
        self.dipanggil = 0
        self.terakhir = None

    def generate(self, system, history, question):
        self.dipanggil += 1
        self.terakhir = {"system": system, "question": question}
        if isinstance(self.jawab, Exception):
            raise self.jawab
        return self.jawab


def test_kontekstual_memakai_hasil_llm():
    llm = FakeLLM('{"pengalaman":"Kasir toko 2020-2022","skill":"Excel","pendidikan":"SMK Negeri 1"}')

    t = strukturkan_kontekstual("teks cv apa saja", llm)

    assert t.pengalaman == "Kasir toko 2020-2022"
    assert t.skill == "Excel"
    assert t.pendidikan == "SMK Negeri 1"
    assert "kontekstual" in t.flags


def test_kontekstual_membuang_lampiran_scan():
    """Inti perbaikan Day 3: transkrip/sertifikat tidak lagi dipaksa jadi skill."""
    llm = FakeLLM('{"pengalaman":"Staff gudang","skill":"","pendidikan":"S1 Contoh"}')

    t = strukturkan_kontekstual("CV + 14 halaman transkrip hasil OCR", llm)

    assert t.skill == ""
    assert "skill_kosong" in t.flags  # kosong TERCATAT, bukan diisi derau


def test_llm_error_jatuh_ke_jalur_heading():
    llm = FakeLLM(RuntimeError("kuota habis"))

    t = strukturkan_kontekstual(CV_HEADING, llm)

    assert "llm_gagal" in t.flags
    assert "Backend Developer" in t.pengalaman  # tetap dapat hasil


def test_llm_error_dicoba_ulang_sekali_sebelum_menyerah():
    """Kegagalan LLM sporadis (429/5xx sesaat) - sekali ulang menutup sebagian."""
    llm = FakeLLM(RuntimeError("429 rate limit"))

    strukturkan_kontekstual(CV_HEADING, llm)

    assert llm.dipanggil == 2


def test_llm_error_dicatat_bukan_ditelan(caplog):
    """Tanpa catatan ini, llm_gagal tidak bisa didiagnosis sama sekali."""
    with caplog.at_level(logging.WARNING, logger="uvicorn.error"):
        strukturkan_kontekstual(CV_HEADING, FakeLLM(RuntimeError("kuota habis")))

    assert "kuota habis" in caplog.text
    assert "RuntimeError" in caplog.text


def test_llm_sukses_tidak_dicoba_ulang():
    llm = FakeLLM('{"pengalaman":"Kasir","skill":"Excel","pendidikan":"SMK"}')

    strukturkan_kontekstual("teks cv", llm)

    assert llm.dipanggil == 1


def test_llm_json_rusak_jatuh_ke_jalur_heading():
    t = strukturkan_kontekstual(CV_HEADING, FakeLLM("maaf saya tidak bisa"))

    assert "llm_json_invalid" in t.flags
    assert "PHP, Python" in t.skill


def test_llm_ketiga_bidang_kosong_tidak_dijatuhkan_ke_jalur_heading():
    """
    LLM menjawab kosong = dokumen tidak memuat isi CV. Itu jawaban, bukan
    kegagalan. Dulu jawaban ini dibatalkan oleh jalur heading; pada dokumen
    tanpa heading akibatnya SELURUH teks mentah masuk ke pengalaman dan
    menghasilkan skor karangan yang tak terbedakan dari CV asli.
    """
    t = strukturkan_kontekstual(CV_HEADING, FakeLLM('{"pengalaman":"","skill":"","pendidikan":""}'))

    assert t.flags == ("tanpa_isi_cv",)
    assert (t.pengalaman, t.skill, t.pendidikan) == ("", "", "")
    # bukti bahwa jalur heading TIDAK dipakai walau teksnya jelas punya heading
    assert "Universitas Contoh" not in t.pendidikan


def test_dokumen_tanpa_isi_cv_tidak_menghasilkan_skor():
    """Rantai lengkapnya: bidang kosong -> tidak ada yang bisa di-embed."""
    t = strukturkan_kontekstual("14 halaman transkrip hasil scan", FakeLLM('{"pengalaman":"","skill":"","pendidikan":""}'))

    assert not any((t.pengalaman, t.skill, t.pendidikan))


def test_jawaban_terbungkus_code_fence_tetap_terparse():
    llm = FakeLLM('```json\n{"pengalaman":"Sales","skill":"negosiasi","pendidikan":"SMA"}\n```')

    t = strukturkan_kontekstual("x", llm)

    assert t.pengalaman == "Sales"
    assert "kontekstual" in t.flags


def test_teks_kosong_tidak_memanggil_llm():
    llm = FakeLLM("{}")

    t = strukturkan_kontekstual("   ", llm)

    assert llm.dipanggil == 0
    assert t.flags == ("teks_kosong",)


def test_teks_panjang_dipotong_sebelum_dikirim():
    llm = FakeLLM('{"pengalaman":"a","skill":"","pendidikan":""}')

    strukturkan_kontekstual("x" * 50000, llm)

    assert len(llm.terakhir["question"]) == MAX_TEKS_LLM


def test_api_key_tidak_pernah_masuk_log(caplog):
    """
    httpx menaruh URL lengkap di pesan error, dan URL Gemini membawa ?key=.
    Kredensial tidak boleh mendarat di berkas log - ini pernah terjadi.
    """
    bocor = RuntimeError(
        "Client error '429 Too Many Requests' for url "
        "'https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=RAHASIA123'"
    )
    with caplog.at_level(logging.WARNING, logger="uvicorn.error"):
        strukturkan_kontekstual(CV_HEADING, FakeLLM(bocor))

    assert "RAHASIA123" not in caplog.text
    assert "key=***" in caplog.text
    assert "429" in caplog.text  # sisa pesannya tetap berguna untuk diagnosis


# --- Riwayat kerja bertanda bukti (penangkal penyalin kata kunci) ---

def _jawab(peng="Backend Developer di PT Sinar", riwayat=None):
    import json as _json
    d = {"pengalaman": peng, "skill": "PHP", "pendidikan": "S1 TI"}
    if riwayat is not None:
        d["riwayat"] = riwayat
    return _json.dumps(d)


def test_riwayat_terekstrak_dari_jawaban_llm():
    llm = FakeLLM(_jawab(riwayat=[
        {"jabatan": "Backend Developer", "perusahaan": "PT Sinar Digital", "periode": "2022-2025"},
    ]))

    t = strukturkan_kontekstual("teks cv", llm)

    assert len(t.riwayat) == 1
    assert t.riwayat[0]["perusahaan"] == "PT Sinar Digital"
    assert "tanpa_riwayat_kerja" not in t.flags


def test_penyalin_kata_kunci_ditandai():
    """
    Inti perbaikan: CV berisi istilah teknis tapi tanpa satu pun tempat kerja.
    Terukur mendapat 0,9592 - di atas backend sungguhan (0,9042).
    """
    llm = FakeLLM(_jawab(peng="Tertarik pada REST API. Backend Developer cita-cita saya.", riwayat=[]))

    t = strukturkan_kontekstual("teks cv", llm)

    assert t.riwayat == ()
    assert "tanpa_riwayat_kerja" in t.flags


def test_penyalin_ditandai_walau_llm_mengosongkan_bidang_pengalaman():
    """
    Kasus yang BENAR-BENAR terjadi dengan LLM sungguhan: untuk CV penyalin, LLM
    mengosongkan bidang pengalaman karena "cita-cita saya" memang bukan
    pengalaman. Syarat lama (peng AND not riwayat) tidak menyala di sini, padahal
    justru di sini skornya melonjak ke 1,0000 - bobot pengalaman yang kosong
    dinormalkan ke skill dan pendidikan, dua bidang yang persis disalin dari
    iklan lowongan, sementara backend sungguhan cuma dapat 0,8917.
    """
    llm = FakeLLM(_jawab(peng="", riwayat=[]))

    t = strukturkan_kontekstual("teks cv", llm)

    assert "tanpa_riwayat_kerja" in t.flags


def test_jabatan_tanpa_perusahaan_dan_periode_bukan_bukti():
    """Syarat bukti ditegakkan di kode, bukan dititipkan ke kepatuhan LLM."""
    llm = FakeLLM(_jawab(riwayat=[{"jabatan": "Backend Developer", "perusahaan": "", "periode": ""}]))

    t = strukturkan_kontekstual("teks cv", llm)

    assert t.riwayat == ()
    assert "tanpa_riwayat_kerja" in t.flags


def test_periode_saja_sudah_cukup_jadi_bukti():
    """CV yang menulis tahun tanpa nama tempat tidak boleh dihukum."""
    llm = FakeLLM(_jawab(riwayat=[{"jabatan": "Kasir", "perusahaan": "", "periode": "2019-2021"}]))

    t = strukturkan_kontekstual("teks cv", llm)

    assert len(t.riwayat) == 1
    assert "tanpa_riwayat_kerja" not in t.flags


def test_dokumen_tanpa_isi_cv_tidak_dituduh_tanpa_riwayat():
    """
    Tidak ada isi CV sama sekali != terbukti tidak punya riwayat kerja. Yang
    pertama sudah punya flag sendiri (tanpa_isi_cv) dan tidak boleh ditambahi
    tuduhan yang datanya memang tidak ada.
    """
    t = strukturkan_kontekstual("14 halaman transkrip", FakeLLM('{"pengalaman":"","skill":"","pendidikan":""}'))

    assert t.flags == ("tanpa_isi_cv",)


def test_riwayat_bentuk_aneh_tidak_menjatuhkan_parser():
    for aneh in (None, "bukan list", 42, ["string", 7, {"jabatan": "x", "periode": "2020"}]):
        t = strukturkan_kontekstual("teks cv", FakeLLM(_jawab(riwayat=aneh)))
        assert isinstance(t.riwayat, tuple)
    assert len(t.riwayat) == 1  # hanya entri dict yang berbukti yang lolos


def test_riwayat_dibatasi_supaya_llm_kabur_tidak_membanjiri_db():
    banyak = [{"jabatan": f"j{i}", "perusahaan": "PT X", "periode": "2020"} for i in range(500)]

    t = strukturkan_kontekstual("teks cv", FakeLLM(_jawab(riwayat=banyak)))

    assert len(t.riwayat) == MAKS_RIWAYAT


def test_cv_mahasiswa_dengan_banyak_kepanitiaan_tidak_terpotong():
    """
    Batas 20 yang semula dipakai memotong CV nyata: satu mahasiswa Biologi
    menghasilkan 22 posisi berbukti (asisten praktikum + kepanitiaan), dua
    di antaranya hilang tanpa jejak. Pagarnya untuk LLM kabur, bukan untuk
    kandidat yang memang aktif.
    """
    dua_puluh_dua = [{"jabatan": f"Staff Divisi {i}", "perusahaan": "BEM FMIPA", "periode": "2023"} for i in range(22)]

    t = strukturkan_kontekstual("teks cv", FakeLLM(_jawab(riwayat=dua_puluh_dua)))

    assert len(t.riwayat) == 22


def test_jalur_heading_tidak_pernah_menandai_tanpa_bukti():
    """
    LLM gagal = kita tidak tahu isi CV-nya. Ketiadaan riwayat di jalur cadangan
    bukan bukti apa-apa, jadi tidak boleh jadi tuduhan ke kandidat.
    """
    t = strukturkan_kontekstual(CV_HEADING, FakeLLM(RuntimeError("kuota habis")))

    assert "llm_gagal" in t.flags
    assert "tanpa_riwayat_kerja" not in t.flags
    assert t.riwayat == ()


# --- jejak saat JSON dari LLM tidak sah (20 Agustus 2026) ---

def test_json_gagal_dibaca_meninggalkan_jejak(caplog):
    """
    Sebelum ini kegagalannya senyap: _json_pertama mengembalikan None, recruiter
    melihat 'jawaban LLM tidak bisa dibaca', dan tidak ada apa pun yang bisa
    ditelusuri. Menemukan sebabnya jadi menuntut panggilan API berulang.
    """
    rusak = '{"penilaian": [{"alasan": "dia bilang "halo" lalu pergi"}]}'

    with caplog.at_level(logging.WARNING):
        assert structure._json_pertama(rusak) is None

    assert "JSON dari LLM gagal dibaca" in caplog.text
    assert "halo" in caplog.text, "jendela di sekitar titik gagal harus ikut"


def test_jejaknya_tidak_menumpahkan_seluruh_jawaban(caplog):
    """Jawaban LLM memuat transkrip wawancara dan isi CV orang. Yang dicatat
    cuma jendela di sekitar titik gagalnya, bukan seluruhnya."""
    jauh = "RAHASIA-" + "x" * 400
    rusak = '{"a": "' + jauh + '", "b": "dia bilang "halo""}'

    with caplog.at_level(logging.WARNING):
        assert structure._json_pertama(rusak) is None

    assert "RAHASIA" not in caplog.text
