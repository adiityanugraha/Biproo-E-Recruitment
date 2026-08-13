"""Transkripsi rekaman wawancara (revisi 12 Agustus 2026)."""

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


def _unggah_ok(uri="https://x/files/abc"):
    return httpx.Response(200, json={"file": {"uri": uri, "state": "ACTIVE"}})


def _jawab(teks: str):
    return httpx.Response(200, json={"candidates": [{"content": {"parts": [{"text": teks}]}}]})


@pytest.fixture(autouse=True)
def _kunci(monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "kunci-uji")


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


def test_suhu_nol_supaya_tidak_mengarang_kata():
    """Menyalin ucapan bukan pekerjaan yang butuh kreativitas, dan setiap
    variasi di sini adalah kata yang tidak diucapkan siapa pun."""
    k = _klien(_unggah_ok(), _jawab(UCAPAN))

    transkripsikan(AUDIO, MIME, k)

    import json
    badan = json.loads(k.dikirim[1].read())
    assert badan["generationConfig"]["temperature"] == 0
