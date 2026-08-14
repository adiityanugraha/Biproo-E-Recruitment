"""
Mengulang panggilan Gemini yang gagalnya sementara (14 Agustus 2026).

Terjadi sungguhan: transkripsi rekaman wawancara 8,7 MB dijawab
503 Service Unavailable - model sedang kelebihan beban di sisi Google. Kode
menyerah pada percobaan pertama, dan recruiter menghadapi "transkripsi gagal"
untuk sesuatu yang seharusnya cukup ditunggu beberapa detik.
"""

import httpx
import pytest

import chat
from chat import ulangi


@pytest.fixture(autouse=True)
def _tanpa_jeda(monkeypatch):
    """Uji tidak boleh benar-benar tidur 5-20 detik."""
    monkeypatch.setattr(chat, "JEDA_SIBUK", 0)
    monkeypatch.setattr(chat, "JEDA_KUOTA", 0)


def _galat(kode: int) -> httpx.HTTPStatusError:
    return httpx.HTTPStatusError(
        f"Server error '{kode}'", request=httpx.Request("POST", "https://x"),
        response=httpx.Response(kode),
    )


class _Panggilan:
    """Gagal `gagal_dulu` kali lebih dulu, lalu berhasil."""

    def __init__(self, kode: int, gagal_dulu: int):
        self.kode = kode
        self.sisa = gagal_dulu
        self.n = 0

    def __call__(self):
        self.n += 1
        if self.sisa > 0:
            self.sisa -= 1
            raise _galat(self.kode)

        return "berhasil"


def test_503_diulang_sampai_berhasil():
    p = _Panggilan(503, gagal_dulu=1)

    assert ulangi(p, "transkripsi") == "berhasil"
    assert p.n == 2


def test_429_diulang_juga():
    """Kuota gratis dihitung per menit, jadi menunggu memang bisa menolong."""
    p = _Panggilan(429, gagal_dulu=1)

    assert ulangi(p, "penilaian") == "berhasil"
    assert p.n == 2


def test_500_diulang():
    p = _Panggilan(500, gagal_dulu=2)

    assert ulangi(p, "transkripsi") == "berhasil"
    assert p.n == 3


def test_400_tidak_diulang():
    """
    Permintaan yang memang keliru akan tetap keliru pada percobaan kedua.
    Mengulangnya cuma menghabiskan waktu dan kuota untuk hasil yang sama.
    """
    p = _Panggilan(400, gagal_dulu=99)

    with pytest.raises(httpx.HTTPStatusError):
        ulangi(p, "transkripsi")

    assert p.n == 1


def test_403_tidak_diulang():
    p = _Panggilan(403, gagal_dulu=99)

    with pytest.raises(httpx.HTTPStatusError):
        ulangi(p, "transkripsi")

    assert p.n == 1


def test_putus_jaringan_diulang():
    n = {"x": 0}

    def panggil():
        n["x"] += 1
        if n["x"] == 1:
            raise httpx.ConnectError("connection refused")

        return "berhasil"

    assert ulangi(panggil, "transkripsi") == "berhasil"
    assert n["x"] == 2


def test_menyerah_setelah_batas_percobaan():
    p = _Panggilan(503, gagal_dulu=99)

    with pytest.raises(httpx.HTTPStatusError):
        ulangi(p, "transkripsi")

    assert p.n == chat.ULANG_MAKS


def test_berhasil_sekali_jalan_tidak_mengulang():
    n = {"x": 0}

    def panggil():
        n["x"] += 1

        return "berhasil"

    assert ulangi(panggil, "transkripsi") == "berhasil"
    assert n["x"] == 1


def test_api_key_tidak_bocor_ke_log(caplog):
    """Pesan httpx memuat URL lengkap, dan URL Gemini membawa ?key=API_KEY."""
    def panggil():
        raise httpx.ConnectError(
            "failed for url 'https://generativelanguage.googleapis.com/v1beta/x?key=RAHASIA123'"
        )

    with caplog.at_level("WARNING"), pytest.raises(httpx.ConnectError):
        ulangi(panggil, "transkripsi")

    assert "RAHASIA123" not in caplog.text
    assert "key=***" in caplog.text


def test_jeda_kuota_lebih_panjang_daripada_jeda_sibuk():
    """
    429 butuh menunggu jendela kuota per menit; 503 cuma butuh beberapa detik.
    Satu angka untuk keduanya berarti salah satunya selalu keliru.
    """
    import importlib

    segar = importlib.reload(chat)
    assert segar.JEDA_KUOTA > segar.JEDA_SIBUK
