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


# --- kekuatan & kelemahan (14 Agustus 2026) ---

RIWAYAT = [
    {"jabatan": "Operator Inventory", "perusahaan": "PT. Cipar Sukses Bersama",
     "periode": "2020 - 2021", "deskripsi": "Mencatat barang masuk dan keluar, stok opname berkala.",
     "gaji_terakhir": "Rp 4.500.000"},
]


def _jawaban_lengkap(kekuatan="", kelemahan=""):
    return json.dumps({
        "penilaian": [{"kompetensi": k, "nilai": 4, "alasan": "a"} for k in KOMPETENSI],
        "kekuatan": kekuatan,
        "kelemahan": kelemahan,
    }, ensure_ascii=False)


def test_kekuatan_dan_kelemahan_terbaca():
    llm = _LLM(_jawaban_lengkap("Terbiasa menelusuri dokumen.", "Belum pernah memakai WMS."))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert h.kekuatan == "Terbiasa menelusuri dokumen."
    assert h.kelemahan == "Belum pernah memakai WMS."


def test_riwayat_kerja_ikut_jadi_bahan():
    """
    Kekuatan dan kelemahan sebagiannya terbaca dari apa yang pernah DIKERJAKAN
    kandidat, bukan cuma dari apa yang sempat ia katakan dalam 30 menit.
    """
    llm = _LLM(_jawaban_lengkap("a", "b"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm, RIWAYAT, "Admin Gudang")

    q = llm.terakhir["question"]
    assert "RIWAYAT KERJA KANDIDAT" in q
    assert "Operator Inventory di PT. Cipar Sukses Bersama" in q
    assert "Admin Gudang" in q


def test_gaji_tidak_ikut_jadi_bahan_penilaian():
    llm = _LLM(_jawaban_lengkap("a", "b"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm, RIWAYAT)

    assert "4.500.000" not in llm.terakhir["question"]


def test_narasi_kosong_tetap_kosong():
    """'Tidak cukup bahan' jawaban yang sah - jangan dipaksa terisi."""
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_lengkap()))

    assert h.berhasil
    assert h.kekuatan == ""
    assert h.kelemahan == ""


def test_narasi_kepanjangan_dipotong_selebar_kolom():
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_lengkap("a" * 900, "b" * 900)))

    assert len(h.kekuatan) == nilai.MAKS_NARASI
    assert len(h.kelemahan) == nilai.MAKS_NARASI


def test_narasi_tidak_ikut_saat_tak_satu_pun_kompetensi_dinilai():
    """
    Kalau bahannya memang tidak ada, rangkuman yang tetap terisi akan terbaca
    sebagai penilaian yang sah - padahal ia lolos justru karena tidak dituntut
    angka.
    """
    llm = _LLM(json.dumps({
        "penilaian": [{"kompetensi": k, "nilai": None, "alasan": "tidak cukup bahan"} for k in KOMPETENSI],
        "kekuatan": "Terdengar percaya diri.",
        "kelemahan": "",
    }, ensure_ascii=False))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert not h.berhasil
    assert h.kekuatan == ""


def test_prompt_melarang_kelemahan_yang_dilunakkan():
    """Kelemahan karangan menyesatkan orang yang memutuskan nasib seseorang."""
    assert "terlalu teliti" in nilai.SYSTEM_NILAI.lower()


def test_prompt_menyuruh_menyebut_yang_belum_teruji():
    """
    Kandidat yang menjawab semuanya dengan baik tidak boleh dikarangkan
    kelemahan, tapi kotak kosong juga tidak berguna bagi pewawancara
    berikutnya. Jalan tengahnya: sebutkan apa yang belum tersentuh wawancara.
    """
    s = nilai.SYSTEM_NILAI.lower()
    assert "belum teruji" in s
    assert "jangan mengarang" in s


def test_prompt_melarang_nilai_tengah_sebagai_pengisi():
    """Angka karangan lebih berbahaya daripada kolom kosong: ia ikut menentukan
    kandidat lolos atau tidak."""
    assert "jangan menebak" in nilai.SYSTEM_NILAI.lower()
    assert "nilai tengah" in nilai.SYSTEM_NILAI.lower()


# --- rekomendasi diputuskan model (permintaan atasan, 14 Agustus 2026) ---


def _jawaban_rekomendasi(rek, alasan="Riwayat kerjanya cocok dengan posisinya."):
    # kecocokan 'tinggi' disebut eksplisit: sejak 18 Agustus 2026 kecocokan yang
    # tidak dijawab diperlakukan sebagai 'rendah' dan MEMBATALKAN rekomendasinya,
    # jadi tanpa baris ini uji-uji di bawah menguji jalur yang lain.
    return json.dumps({
        "penilaian": [{"kompetensi": k, "nilai": 4, "alasan": "a"} for k in KOMPETENSI],
        "kekuatan": "a", "kelemahan": "b",
        "kecocokan": "tinggi", "alasan_kecocokan": "Menjawab ketiga pertanyaannya.",
        "rekomendasi": rek, "alasan_rekomendasi": alasan,
    }, ensure_ascii=False)


def test_rekomendasi_dan_alasannya_terbaca():
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_rekomendasi("recommended")))

    assert h.rekomendasi == "recommended"
    assert "cocok dengan posisinya" in h.alasan_rekomendasi


def test_penolakan_terbaca():
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_rekomendasi("not_recommended")))

    assert h.rekomendasi == "not_recommended"


def test_nilai_rekomendasi_asing_jadi_none_bukan_ditebak_paling_dekat():
    """
    Menebak "Hire" jadi "recommended" berarti meloloskan - atau menolak -
    seseorang dari jawaban yang tidak dipahami modelnya. CI4 memperlakukan None
    sebagai "diserahkan ke perekrut", dan itu jawaban yang jauh lebih aman.
    """
    for asing in ("Hire", "RECOMMENDED", "ya", "", 1, True, None):
        h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_rekomendasi(asing)))

        assert h.rekomendasi is None, asing


def test_rekomendasi_tidak_ikut_saat_tak_satu_pun_kompetensi_dinilai():
    """Keputusan yang tetap terisi dari bahan yang tidak ada akan terbaca
    sebagai keputusan yang sah."""
    llm = _LLM(json.dumps({
        "penilaian": [{"kompetensi": k, "nilai": None, "alasan": "x"} for k in KOMPETENSI],
        "rekomendasi": "not_recommended", "alasan_rekomendasi": "Tidak meyakinkan.",
    }, ensure_ascii=False))

    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert not h.berhasil
    assert h.rekomendasi is None


def test_skor_cv_ikut_jadi_bahan_keputusan():
    """
    Tanpa angka ini kecocokan CV hilang sama sekali dari keputusan - padahal di
    rumus lama ia 40% bobotnya - dan kandidat dinilai semata dari 30 menit
    bicara.
    """
    llm = _LLM(_jawaban_rekomendasi("recommended"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm, RIWAYAT, "Admin Gudang", 0.74)

    assert "0.74" in llm.terakhir["question"]


def test_tanpa_skor_cv_tidak_ada_baris_karangan():
    """Screening bisa saja belum menghasilkan angka. Menuliskan 0,00 di situ
    membuat model mengira CV-nya sangat tidak cocok."""
    llm = _LLM(_jawaban_rekomendasi("recommended"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert "SKOR KECOCOKAN CV" not in llm.terakhir["question"]


def test_prompt_membolehkan_model_tidak_memutuskan():
    """
    Menolak orang dari bahan yang tidak cukup jauh lebih buruk daripada
    mengangkat tangan. Aturan ini yang membuat 'flagged' mungkin terjadi.
    """
    s = nilai.SYSTEM_NILAI.lower()
    assert "terlalu tipis" in s
    assert "diserahkan ke perekrut" in s


def test_prompt_menyebutkan_akibat_penolakan():
    """Model harus tahu keputusannya berakhir sebagai surat penolakan yang
    terkirim tanpa ada orang yang memeriksanya lebih dulu."""
    assert "surat penolakan" in nilai.SYSTEM_NILAI.lower()


def test_prompt_melarang_atribut_pribadi_menyentuh_keputusan():
    """Larangan yang sama dengan penilaian per kompetensi harus DIULANG untuk
    keputusannya - aturan yang jauh di atas mudah tidak terbawa."""
    aturan = nilai.SYSTEM_NILAI.lower().split("terakhir, putuskan rekomendasi")[1]
    for dilarang in ("usia", "agama", "suku", "jenis kelamin", "status pernikahan"):
        assert dilarang in aturan


# --- kecocokan dengan posisi (18 Agustus 2026) ---

SYARAT = {
    "skill": "Patroli area, pemantauan CCTV, penanganan gangguan keamanan",
    "pengalaman": "1 tahun sebagai petugas keamanan",
    "pendidikan": "SMA",
    "deskripsi": "Menjaga keamanan area toko dan menindaklanjuti kejadian.",
}
TANYA = ["Ceritakan pengalaman Anda menangani gangguan keamanan di area toko."]


def _jawaban_kecocokan(kecocokan, rekomendasi="recommended"):
    return json.dumps({
        "penilaian": [{"kompetensi": k, "nilai": 4, "alasan": "a"} for k in KOMPETENSI],
        "kekuatan": "a", "kelemahan": "b",
        "kecocokan": kecocokan, "alasan_kecocokan": "Seluruh jawabannya soal stok gudang.",
        "rekomendasi": rekomendasi, "alasan_rekomendasi": "jawabannya runtut",
    }, ensure_ascii=False)


def test_syarat_posisi_ikut_dikirim():
    """
    Sebelum 18 Agustus 2026 yang dikirim cuma JUDUL posisi. "Security System"
    tidak menerangkan apa pun tentang pekerjaannya, sehingga model tidak punya
    dasar menilai wawancaranya nyambung atau tidak - dan transkrip operator
    gudang lolos dengan nilai bagus di posisi itu.
    """
    llm = _LLM(_jawaban_kecocokan("rendah"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm, posisi="Security System", syarat=SYARAT)

    q = llm.terakhir["question"]
    assert "SYARAT POSISI" in q
    assert "pemantauan CCTV" in q
    assert "Menjaga keamanan area toko" in q


def test_pertanyaan_yang_diajukan_ikut_dikirim():
    """Petunjuk paling terang bahwa transkripnya dari wawancara yang lain:
    jawabannya tidak menjawab apa pun yang ditanyakan."""
    llm = _LLM(_jawaban_kecocokan("rendah"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm, pertanyaan=TANYA)

    assert "PERTANYAAN YANG DIAJUKAN" in llm.terakhir["question"]
    assert "gangguan keamanan" in llm.terakhir["question"]


def test_kecocokan_rendah_membatalkan_rekomendasi():
    """
    INI perbaikan intinya. Transkrip wawancara gudang yang dimasukkan ke posisi
    Security System dulu tetap diloloskan dengan nilai bagus: jawabannya memang
    runtut, cuma tentang pekerjaan yang lain sama sekali.

    Ditegakkan di kode, bukan cuma diminta lewat aturan prompt - model bisa saja
    mengisi 'rendah' lalu tetap merekomendasikan, dan itu persis yang terjadi.
    """
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_kecocokan("rendah", "recommended")))

    assert h.kecocokan == "rendah"
    assert h.rekomendasi is None, "kecocokan rendah tidak boleh meloloskan"
    assert h.berhasil, "penilaian per kompetensinya tetap tersimpan"


def test_kecocokan_rendah_juga_membatalkan_penolakan():
    """Wawancara yang membahas pekerjaan lain tidak cukup untuk meloloskan
    MAUPUN menggugurkan - keputusannya milik perekrut."""
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_kecocokan("rendah", "not_recommended")))

    assert h.rekomendasi is None


def test_kecocokan_tinggi_tidak_mengganggu_rekomendasi():
    h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_kecocokan("tinggi", "recommended")))

    assert h.kecocokan == "tinggi"
    assert h.rekomendasi == "recommended"


def test_kecocokan_yang_tidak_dijawab_dianggap_rendah():
    """Tanpa jawaban itu tidak ada yang tahu wawancaranya nyambung atau tidak,
    dan justru itu keadaan yang mau dihindari."""
    for asing in ("", "lumayan", None, 3):
        h = nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, _LLM(_jawaban_kecocokan(asing)))

        assert h.kecocokan == "rendah", asing
        assert h.rekomendasi is None, asing


def test_tanpa_syarat_tidak_ada_blok_karangan():
    llm = _LLM(_jawaban_kecocokan("tinggi"))

    nilai_dari_transkrip(TRANSKRIP, KOMPETENSI, llm)

    assert "SYARAT POSISI" not in llm.terakhir["question"]
    assert "PERTANYAAN YANG DIAJUKAN" not in llm.terakhir["question"]


def test_prompt_mencontohkan_kasus_yang_terjadi():
    """Contoh yang konkret jauh lebih menempel daripada aturan abstrak."""
    s = nilai.SYSTEM_NILAI.lower()
    assert "stok gudang" in s
    assert "petugas keamanan" in s
