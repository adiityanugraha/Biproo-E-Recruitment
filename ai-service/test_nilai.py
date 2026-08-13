"""Penilaian kompetensi dari transkrip wawancara (revisi 12 Agustus 2026)."""

import json

import nilai
from nilai import nilai_dari_transkrip

KOMPETENSI = ["Communication Skills", "Adaptability", "Problem-Solving Ability"]

TRANSKRIP = (
    "Pewawancara: Ceritakan saat Anda menemukan selisih stok.\n"
    "Kandidat: Waktu di Indomarco, saya cek ulang dari surat jalan satu per satu, "
    "ternyata ada dua dus yang belum diinput. Saya lapor supervisor lalu perbaiki datanya.\n"
)


class _LLM:
    def __init__(self, jawab):
        self.jawab = jawab
        self.terakhir = None

    def generate(self, system, history, question):
        self.terakhir = {"system": system, "question": question}
        if isinstance(self.jawab, Exception):
            raise self.jawab
        return self.jawab


def _jawaban(*butir) -> str:
    # json.dumps, bukan rangkaian string: alasan yang bagus justru MENGUTIP
    # kandidat, jadi hampir selalu memuat tanda kutip di dalamnya.
    return json.dumps({"penilaian": [
        {"kompetensi": k, "nilai": n, "alasan": a} for k, n, a in butir
    ]}, ensure_ascii=False)


def test_nilai_dan_alasan_terbaca():
    llm = _LLM(_jawaban(
        ("Communication Skills", 4, "Menjelaskan runtut."),
        ("Adaptability", 3, "Cukup."),
        ("Problem-Solving Ability", 5, 'Berkata "saya cek ulang dari surat jalan".'),
    ))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert h.berhasil
    assert [b.nilai for b in h.butir] == [4, 3, 5]
    assert "surat jalan" in h.butir[2].alasan


def test_transkrip_ikut_dikirim_ke_llm():
    llm = _LLM(_jawaban(("Communication Skills", 4, "a")))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    q = llm.terakhir["question"]
    assert "selisih stok" in q
    for k in KOMPETENSI:
        assert k in q


def test_urutan_llm_yang_tertukar_tidak_menggeser_nilai():
    """
    Dipetakan menurut NAMA, bukan urutan.

    LLM kadang menukar urutan atau menambah baris yang tidak diminta, dan
    mencocokkan per indeks membuat nilai kompetensi A menempel pada kompetensi B
    tanpa jejak apa pun - kandidat lalu dinilai atas hal yang salah.
    """
    llm = _LLM(_jawaban(
        ("Problem-Solving Ability", 5, "c"),
        ("Communication Skills", 2, "a"),
        ("Adaptability", 3, "b"),
    ))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert {b.kompetensi: b.nilai for b in h.butir} == {
        "Communication Skills": 2,
        "Adaptability": 3,
        "Problem-Solving Ability": 5,
    }


def test_kompetensi_yang_tidak_dijawab_tetap_dikembalikan_bernilai_none():
    """
    Bukan dihilangkan: CI4 harus bisa membedakan "tidak cukup bahan" dari
    "tidak pernah diminta", dan keduanya memang berbeda artinya.
    """
    llm = _LLM(_jawaban(("Communication Skills", 4, "a")))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert [b.kompetensi for b in h.butir] == KOMPETENSI
    assert h.butir[1].nilai is None
    assert "tidak mengembalikan butir ini" in h.butir[1].alasan


def test_kompetensi_asing_dibuang():
    llm = _LLM(_jawaban(
        ("Communication Skills", 4, "a"),
        ("Kesetiaan Pada Perusahaan", 5, "karangan"),
    ))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert [b.kompetensi for b in h.butir] == KOMPETENSI


def test_nilai_di_luar_skala_jadi_none_bukan_dijepit():
    """
    Menjepit 9 jadi 5 berarti diam-diam mengarang penilaian TERTINGGI dari
    jawaban yang jelas tidak dipahami modelnya.
    """
    llm = _LLM(_jawaban(
        ("Communication Skills", 9, "a"),
        ("Adaptability", 0, "b"),
        ("Problem-Solving Ability", 3, "c"),
    ))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert [b.nilai for b in h.butir] == [None, None, 3]


def test_semua_null_dianggap_gagal():
    llm = _LLM(_jawaban(*[(k, None, "tidak cukup bahan") for k in KOMPETENSI]))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert not h.berhasil
    assert "Tak satu pun" in h.catatan


def test_transkrip_kosong_ditolak_tanpa_memanggil_llm():
    llm = _LLM(_jawaban(("Communication Skills", 4, "a")))

    h = nilai_dari_transkrip("   ", KOMPETENSI, llm)

    assert not h.berhasil
    assert llm.terakhir is None, "LLM tidak boleh dipanggil untuk transkrip kosong"


def test_llm_gagal_tidak_membocorkan_api_key():
    bocor = RuntimeError(
        "Client error '429' for url 'https://generativelanguage.googleapis.com/v1beta/x?key=RAHASIA123'"
    )

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(bocor))

    assert not h.berhasil
    assert "RAHASIA123" not in h.catatan


def test_jawaban_bukan_json_jadi_gagal():
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM("maaf, saya tidak bisa"))

    assert not h.berhasil


def test_alasan_kepanjangan_dipotong_selebar_kolom():
    llm = _LLM(_jawaban(("Communication Skills", 4, "a" * 900)))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert len(h.butir[0].alasan) == nilai.MAKS_ALASAN


def test_transkrip_panjang_dipotong_di_akhir():
    """
    Yang dipotong bagian AKHIR: wawancara dibuka dengan basa-basi lalu masuk ke
    pertanyaan inti, jadi awal transkrip justru yang paling penting.
    """
    llm = _LLM(_jawaban(("Communication Skills", 4, "a")))
    panjang = "AWAL. " + "x" * (nilai.MAKS_TRANSKRIP + 5000) + " AKHIR."

    nilai_dari_transkrip(panjang, KOMPETENSI, llm)

    q = llm.terakhir["question"]
    assert "AWAL." in q
    assert "AKHIR." not in q


def test_larangan_menilai_dari_penampilan_ada_di_prompt():
    """
    Model tidak melihat dan tidak mendengar kandidat - yang sampai kepadanya
    cuma teks. Menilai penampilan dari situ berarti mengarang.
    """
    s = nilai.SYSTEM_NILAI.lower()
    for dilarang in ("penampilan", "suara", "aksen"):
        assert dilarang in s


def test_larangan_atribut_pribadi_ada_di_prompt():
    s = nilai.SYSTEM_NILAI.lower()
    for dilarang in ("usia", "agama", "suku", "jenis kelamin", "status pernikahan"):
        assert dilarang in s


def test_prompt_melarang_nilai_tengah_sebagai_pengisi():
    """Angka karangan lebih berbahaya daripada kolom kosong: ia ikut menentukan
    kandidat lolos atau tidak."""
    assert "jangan menebak" in nilai.SYSTEM_NILAI.lower()
    assert "nilai tengah" in nilai.SYSTEM_NILAI.lower()
