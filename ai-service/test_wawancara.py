import pytest

from wawancara import (
    MIN_KATA_JAWABAN,
    Giliran,
    baca_balasan,
    jawaban_memadai,
    nilai_giliran,
    rakit_uraian,
)

BUTIR = {
    "kompetensi": "Pelayanan pelanggan",
    "indikator": ["menyebut kejadian nyata", "menyebut tindakan yang diambil"],
    "red_flag": "menyalahkan pelanggan",
}

JAWABAN_PANJANG = (
    "waktu itu antreannya panjang sekali sampai keluar toko jadi saya bagi dua "
    "yang cuma bayar saya arahkan ke kasir sebelah"
)


class LlmPalsu:
    """Merekam prompt yang diterima dan mengembalikan balasan yang ditentukan."""

    def __init__(self, balasan: str):
        self.balasan = balasan
        self.dipanggil = 0
        self.uraian_terakhir = ""

    def generate(self, system, history, question):
        self.dipanggil += 1
        self.uraian_terakhir = question
        return self.balasan


# --- penjaga kuota: jawaban tak memadai tidak menyentuh LLM ---

@pytest.mark.parametrize("jawaban", ["", "   ", "hmm", "eh anu apa ya", "iya"])
def test_jawaban_pendek_atau_pengisi_tidak_memadai(jawaban):
    assert jawaban_memadai(jawaban) is False


def test_jawaban_bermakna_memadai():
    assert jawaban_memadai(JAWABAN_PANJANG) is True


def test_pengisi_di_tengah_kalimat_tidak_menggugurkan():
    """'hmm' di awal kalimat panjang itu wajar bicara, bukan tanda tak menjawab."""
    assert jawaban_memadai("hmm ya saya pernah menangani keluhan pelanggan di gerai") is True


def test_ambang_tepat_di_batas():
    assert jawaban_memadai(" ".join(["kata"] * MIN_KATA_JAWABAN)) is True
    assert jawaban_memadai(" ".join(["kata"] * (MIN_KATA_JAWABAN - 1))) is False


def test_jawaban_tak_memadai_tidak_memanggil_llm():
    llm = LlmPalsu("{}")
    hasil = nilai_giliran(llm, "Frontliner", BUTIR, "Ceritakan ...", "hmm")

    assert llm.dipanggil == 0
    assert hasil.tingkat is None
    assert hasil.nyambung is False
    assert "ulangi" in hasil.lanjutan.lower()


# --- membaca balasan LLM ---

def test_baca_balasan_lengkap():
    g = baca_balasan(
        '```json\n{"tingkat":"baik","alasan":"Menyebut kejadian nyata dan tindakannya",'
        '"lanjutan":"Kalau kasir sebelah juga penuh, apa yang Anda lakukan?",'
        '"nyambung":true}\n```'
    )

    assert g == Giliran(
        tingkat="baik",
        alasan="Menyebut kejadian nyata dan tindakannya",
        lanjutan="Kalau kasir sebelah juga penuh, apa yang Anda lakukan?",
        nyambung=True,
    )


def test_tingkat_di_luar_kosakata_jadi_tidak_dinilai():
    """'sangat baik' / '4' bukan kosakata rubrik - jangan ditebak maksudnya."""
    g = baca_balasan('{"tingkat":"sangat baik","alasan":"a","lanjutan":"b"}')

    assert g.tingkat is None
    assert g.alasan == "a"  # sisa balasan tetap dipakai


def test_tingkat_beda_kapital_tetap_diterima():
    assert baca_balasan('{"tingkat":"Baik"}').tingkat == "baik"


def test_nyambung_hanya_false_bila_model_menegaskan():
    assert baca_balasan('{"tingkat":"cukup"}').nyambung is True
    assert baca_balasan('{"tingkat":"cukup","nyambung":false}').nyambung is False


def test_balasan_bukan_json_ditolak():
    assert baca_balasan("Maaf saya tidak bisa menilai ini.") is None


def test_llm_ngawur_jadi_runtimeerror():
    with pytest.raises(RuntimeError):
        nilai_giliran(LlmPalsu("bukan json"), "Frontliner", BUTIR, "Ceritakan ...", JAWABAN_PANJANG)


def test_teks_panjang_dipotong():
    g = baca_balasan('{"tingkat":"baik","alasan":"' + "a" * 900 + '","lanjutan":"' + "b" * 900 + '"}')

    assert len(g.alasan) <= 300
    assert len(g.lanjutan) <= 300


# --- prompt ---

def test_uraian_memuat_indikator_red_flag_dan_jawaban():
    u = rakit_uraian("Frontliner Retail", BUTIR, "Ceritakan saat antrean panjang.", JAWABAN_PANJANG)

    assert "Frontliner Retail" in u
    assert "Pelayanan pelanggan" in u
    assert "menyebut kejadian nyata" in u
    assert "menyalahkan pelanggan" in u
    assert JAWABAN_PANJANG in u


def test_butir_tanpa_rubrik_tidak_menulis_baris_kosong():
    u = rakit_uraian("Admin Gudang", {}, "Ceritakan ...", JAWABAN_PANJANG)

    assert "Indikator" not in u
    assert "Red flag" not in u


def test_riwayat_ikut_masuk_prompt():
    u = rakit_uraian(
        "Frontliner",
        BUTIR,
        "Kalau kasir sebelah penuh?",
        JAWABAN_PANJANG,
        riwayat=[{"pertanyaan": "Ceritakan antrean panjang.", "jawaban": "saya bagi dua"}],
    )

    assert "Ceritakan antrean panjang." in u
    assert "saya bagi dua" in u


def test_jawaban_sangat_panjang_dipotong_sebelum_ke_llm():
    llm = LlmPalsu('{"tingkat":"cukup","alasan":"a","lanjutan":"b"}')
    nilai_giliran(llm, "Frontliner", BUTIR, "Ceritakan ...", "kata " * 5000)

    assert len(llm.uraian_terakhir) < 6000
