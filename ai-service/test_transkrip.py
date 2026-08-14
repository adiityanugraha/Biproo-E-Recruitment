"""Transkripsi rekaman wawancara (revisi 12 Agustus 2026)."""

from types import SimpleNamespace

import httpx
import pytest

import transkrip
from transkrip import transkripsikan, unggah

AUDIO = b"RIFFxxxxWAVEfmt "
MIME = "audio/wav"

UCAPAN = (
    "Pewawancara: Ceritakan pengalaman Anda di gudang.\n"
    "Kandidat: Saya di Indomarco tiga tahun, tugasnya menginput barang masuk dan keluar. "
    "Kalau ada selisih saya cek ulang dari surat jalan, lalu lapor supervisor.\n"
    "Pewawancara: Baik, terima kasih.\n"
)


def _klien(*balasan: httpx.Response) -> httpx.Client:
    """Klien yang membalas berurutan, dan merekam apa yang dikirim."""
    urut = iter(balasan)
    dikirim = []

    def handler(request: httpx.Request) -> httpx.Response:
        dikirim.append(request)
        return next(urut)

    k = httpx.Client(transport=httpx.MockTransport(handler))
    k.dikirim = dikirim

    return k


def _unggah_ok(uri="https://x/files/abc", state="ACTIVE"):
    return httpx.Response(200, json={"file": {"uri": uri, "state": state}})


def _periksa(state):
    """Balasan GET /files/{id} saat status berkasnya ditanya."""
    return httpx.Response(200, json={"uri": "https://x/files/abc", "state": state})


def _jawab(teks: str):
    return httpx.Response(200, json={"candidates": [{"content": {"parts": [{"text": teks}]}}]})


@pytest.fixture(autouse=True)
def _kunci(monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "kunci-uji")
    # Whisper dimatikan untuk sebagian besar berkas ini: yang diuji di sini
    # jalur Gemini, dan memuat model 460 MB cuma untuk menolak 16 byte sampah
    # membuat uji ini lambat tanpa menguji apa pun yang baru.
    monkeypatch.setattr(transkrip, "whisper_lokal", None)


def test_transkrip_terbaca():
    h = transkripsikan(AUDIO, MIME, _klien(_unggah_ok(), _jawab(UCAPAN)))

    assert h.berhasil
    assert "surat jalan" in h.teks


def test_berkas_dititipkan_lewat_files_api_bukan_ditempel_di_badan():
    """
    Gemini membatasi seluruh permintaan sekitar 20 MB dan base64 membengkakkan
    berkas sepertiga; rekaman audio Zoom 30 menit saja sudah 15-30 MB. Jalur
    inline akan gagal justru pada berkas yang sebenarnya, sementara berkas
    contoh yang kecil lolos - kegagalan yang baru muncul di tangan pemakai.
    """
    k = _klien(_unggah_ok("https://x/files/zzz"), _jawab(UCAPAN))

    transkripsikan(AUDIO, MIME, k)

    unggahan, panggilan = k.dikirim
    assert unggahan.url.path.startswith("/upload/")
    assert unggahan.content == AUDIO

    badan = panggilan.read().decode()
    assert "https://x/files/zzz" in badan
    assert "inline_data" not in badan


def test_nama_berkas_asli_tidak_ikut_ke_pihak_ketiga():
    """Nama berkas Zoom memuat nama peserta dan tanggalnya. Tidak ada gunanya
    menyerahkan itu hanya untuk mentranskripsi suara."""
    k = _klien(_unggah_ok(), _jawab(UCAPAN))

    transkripsikan(AUDIO, MIME, k)

    assert k.dikirim[0].headers["X-Goog-Upload-File-Name"] == "rekaman"


def test_rekaman_tanpa_ucapan_jadi_gagal():
    k = _klien(_unggah_ok(), _jawab(transkrip.PENANDA_KOSONG))

    h = transkripsikan(AUDIO, MIME, k)

    assert not h.berhasil
    assert h.teks == "", "penanda tidak boleh disimpan sebagai transkrip"
    assert "tidak memuat suara" in h.catatan


def test_transkrip_terlalu_pendek_jadi_gagal():
    """
    Wawancara 30 menit yang menghasilkan dua kalimat berarti audionya rusak,
    senyap, atau salah berkas. Menilai kandidat dari itu jauh lebih berbahaya
    daripada mengaku gagal.
    """
    h = transkripsikan(AUDIO, MIME, _klien(_unggah_ok(), _jawab("Halo. Ya.")))

    assert not h.berhasil
    assert "karakter" in h.catatan


def test_unggah_gagal_jadi_status_gagal_bukan_exception():
    k = _klien(httpx.Response(500, text="boom"))

    h = transkripsikan(AUDIO, MIME, k)

    assert not h.berhasil
    assert h.catatan != ""


def test_api_key_tidak_pernah_bocor_ke_catatan():
    """Pesan httpx memuat URL lengkap, dan URL Gemini membawa ?key=API_KEY.
    Catatan ini tampil di layar recruiter dan tersimpan di basis data."""
    def handler(request):
        raise httpx.ConnectError(
            "failed for url 'https://generativelanguage.googleapis.com/v1beta/x?key=RAHASIA123'"
        )

    h = transkripsikan(AUDIO, MIME, httpx.Client(transport=httpx.MockTransport(handler)))

    assert not h.berhasil
    assert "RAHASIA123" not in h.catatan
    assert "key=***" in h.catatan


def test_files_api_tanpa_uri_jadi_gagal():
    h = transkripsikan(AUDIO, MIME, _klien(httpx.Response(200, json={"file": {}})))

    assert not h.berhasil


def test_unggah_mengembalikan_uri():
    assert unggah(AUDIO, MIME, _klien(_unggah_ok("https://x/files/q"))) == "https://x/files/q"


# --- menunggu berkas siap dipakai (perbaikan 13 Agustus 2026) ---


def test_menunggu_sampai_berkas_aktif(monkeypatch):
    """
    Files API membalas seketika, tapi audio/video masih diproses di belakang
    layar dan berstatus PROCESSING. Memanggil generateContent dengan URI yang
    belum ACTIVE dijawab 400 Bad Request - pesan yang tidak menyebut sama sekali
    bahwa masalahnya cuma soal menunggu.

    Tidak pernah terlihat saat uji sebelumnya: berkas contoh beberapa ratus byte
    selesai diproses sebelum balasan unggahnya tiba. Rekaman sungguhan 8,7 MB
    butuh beberapa detik, dan di situlah ia gagal - di tangan pemakai.
    """
    monkeypatch.setattr(transkrip, "JEDA_PERIKSA", 0)
    k = _klien(
        _unggah_ok(state="PROCESSING"),
        _periksa("PROCESSING"),
        _periksa("ACTIVE"),
        _jawab(UCAPAN),
    )

    h = transkripsikan(AUDIO, MIME, k)

    assert h.berhasil
    # unggah, dua kali periksa, lalu baru transkripsi
    assert [r.method for r in k.dikirim] == ["POST", "GET", "GET", "POST"]


def test_berkas_yang_gagal_diproses_tidak_ditunggu_sampai_batas(monkeypatch):
    monkeypatch.setattr(transkrip, "JEDA_PERIKSA", 0)
    k = _klien(_unggah_ok(state="PROCESSING"), _periksa("FAILED"))

    h = transkripsikan(AUDIO, MIME, k)

    assert not h.berhasil
    assert "FAILED" in h.catatan


def test_menunggu_menyerah_setelah_batas_waktu(monkeypatch):
    """Pekerjaan yang benar-benar macet tidak boleh menggantung selamanya."""
    monkeypatch.setattr(transkrip, "JEDA_PERIKSA", 0)
    monkeypatch.setattr(transkrip, "TIMEOUT_SIAP", 0.05)

    def handler(request):
        if request.url.path.startswith("/upload/"):
            return _unggah_ok(state="PROCESSING")
        return _periksa("PROCESSING")

    h = transkripsikan(AUDIO, MIME, httpx.Client(transport=httpx.MockTransport(handler)))

    assert not h.berhasil
    assert "belum siap" in h.catatan


def test_berkas_yang_langsung_aktif_tidak_diperiksa_ulang():
    """Yang kecil selesai seketika - jangan menambah bolak-balik yang percuma."""
    k = _klien(_unggah_ok(state="ACTIVE"), _jawab(UCAPAN))

    assert transkripsikan(AUDIO, MIME, k).berhasil
    assert [r.method for r in k.dikirim] == ["POST", "POST"]


def test_prompt_melarang_merapikan_ucapan_kandidat():
    """
    Isi transkrip inilah yang dipakai menilai orang. Merapikan jawaban yang
    berbelit berarti menilai orang lain.
    """
    s = transkrip.SYSTEM_TRANSKRIP.lower()
    for dilarang in ("meringkas", "menyimpulkan", "tata bahasa"):
        assert dilarang in s


def test_prompt_melarang_menebak_yang_tidak_terdengar():
    assert "[tidak jelas]" in transkrip.SYSTEM_TRANSKRIP.lower()
    assert "jangan menebak" in transkrip.SYSTEM_TRANSKRIP.lower()


# --- whisper lokal dulu, gemini cadangan (14 Agustus 2026) ---


def _whisper(jawab):
    """Pengganti modul whisper_lokal. jawab boleh teks atau Exception."""
    def transkripsikan(data):
        if isinstance(jawab, Exception):
            raise jawab
        return jawab

    return SimpleNamespace(MESIN="whisper-uji", transkripsikan=transkripsikan)


def test_whisper_dipakai_duluan_dan_gemini_tidak_disentuh(monkeypatch):
    """
    Inti perubahannya: kuota Gemini 20 panggilan sehari, dan transkripsi tidak
    butuh penalaran sama sekali - cuma menyalin ucapan.
    """
    monkeypatch.setattr(transkrip, "whisper_lokal", _whisper(UCAPAN))
    k = _klien()  # tanpa balasan: dipanggil sekali pun langsung StopIteration

    h = transkripsikan(AUDIO, MIME, k)

    assert h.berhasil
    assert "surat jalan" in h.teks
    assert h.mesin == "whisper-uji"
    assert k.dikirim == []


def test_whisper_gagal_jatuh_ke_gemini(monkeypatch):
    """Cadangannya bukan basa-basi: Whisper bisa tersandung codec yang tidak
    dikenalnya, dan menyerah berarti recruiter mengunggah ulang 8 MB."""
    monkeypatch.setattr(transkrip, "whisper_lokal", _whisper(RuntimeError("codec asing")))

    h = transkripsikan(AUDIO, MIME, _klien(_unggah_ok(), _jawab(UCAPAN)))

    assert h.berhasil
    assert h.mesin.startswith("gemini:")


def test_transkrip_whisper_terlalu_pendek_juga_jatuh_ke_gemini(monkeypatch):
    """
    Syarat kelayakannya sama untuk kedua mesin. Transkrip sepotong dari mesin
    lokal tidak lebih layak dinilai daripada dari Gemini - dan justru di situ
    pendapat kedua paling berguna.
    """
    monkeypatch.setattr(transkrip, "whisper_lokal", _whisper("Halo. Ya."))

    h = transkripsikan(AUDIO, MIME, _klien(_unggah_ok(), _jawab(UCAPAN)))

    assert h.berhasil
    assert h.teks == UCAPAN.strip()


def test_tanpa_faster_whisper_langsung_ke_gemini(monkeypatch):
    """Pemasangannya ~2 GB dan sengaja opsional: instalasi yang belum sempat
    memasangnya harus tetap jalan, bukan mati."""
    monkeypatch.setattr(transkrip, "whisper_lokal", None)

    assert transkripsikan(AUDIO, MIME, _klien(_unggah_ok(), _jawab(UCAPAN))).berhasil


def test_kedua_mesin_gagal_menyimpan_sebab_keduanya(monkeypatch):
    """
    Sebab Gemini saja menyembunyikan bahwa jalur lokal sudah dicoba lebih
    dulu, dan orang yang membacanya besok akan mengira ia tidak pernah jalan.
    """
    monkeypatch.setattr(transkrip, "whisper_lokal", _whisper(RuntimeError("codec asing")))

    h = transkripsikan(AUDIO, MIME, _klien(httpx.Response(500, text="boom")))

    assert not h.berhasil
    assert "codec asing" in h.catatan
    assert "whisper-uji" in h.catatan


def test_rekaman_senyap_tetap_dikenali_lewat_cadangan(monkeypatch):
    """Whisper mengembalikan teks kosong untuk rekaman senyap; yang menyebut
    sebabnya dengan jelas kepada recruiter tetap Gemini."""
    monkeypatch.setattr(transkrip, "whisper_lokal", _whisper(""))

    h = transkripsikan(AUDIO, MIME, _klien(_unggah_ok(), _jawab(transkrip.PENANDA_KOSONG)))

    assert not h.berhasil
    assert "tidak memuat suara" in h.catatan


def test_suhu_nol_supaya_tidak_mengarang_kata():
    """Menyalin ucapan bukan pekerjaan yang butuh kreativitas, dan setiap
    variasi di sini adalah kata yang tidak diucapkan siapa pun."""
    k = _klien(_unggah_ok(), _jawab(UCAPAN))

    transkripsikan(AUDIO, MIME, k)

    import json
    badan = json.loads(k.dikirim[1].read())
    assert badan["generationConfig"]["temperature"] == 0
