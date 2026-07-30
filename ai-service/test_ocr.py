"""Fallback OCR: berkas sintetis (teks dirender jadi gambar, tanpa PII).
Butuh tesseract terpasang - di-skip otomatis bila tidak ada."""

import fitz
import pytest

import ocr
from extract import ekstrak_bytes
from ocr import ocr_lengkapi

pytestmark = pytest.mark.skipif(not ocr.tersedia(), reason="tesseract tidak terpasang")

KALIMAT = "Pengalaman kerja sebagai Backend Developer di PT Contoh Sejahtera"


def _halaman_teks(doc):
    page = doc.new_page()
    page.insert_text((72, 90), KALIMAT, fontsize=14)
    page.insert_text((72, 115), "Pendidikan S1 Teknik Informatika Universitas Contoh", fontsize=14)
    page.insert_text((72, 140), "Skill utama PHP SQL Server dan Python untuk backend", fontsize=14)
    return page


def _png_teks() -> bytes:
    """Render teks ke PNG - simulasi CV difoto/discan."""
    doc = fitz.open()
    page = _halaman_teks(doc)
    png = page.get_pixmap(dpi=200).tobytes("png")
    doc.close()
    return png


def _pdf_scan() -> bytes:
    """PDF berisi GAMBAR teks (tanpa text layer) - simulasi CV hasil scan."""
    png = _png_teks()
    doc = fitz.open()
    page = doc.new_page()
    page.insert_image(page.rect, stream=png)
    data = doc.tobytes()
    doc.close()
    return data


def _pdf_mixed() -> bytes:
    """Halaman 1 text layer asli, halaman 2 gambar teks (lampiran scan)."""
    doc = fitz.open()
    _halaman_teks(doc)
    page2 = doc.new_page()
    page2.insert_image(page2.rect, stream=_png_teks())
    data = doc.tobytes()
    doc.close()
    return data


def test_cv_gambar_terbaca_via_ocr():
    data = _png_teks()
    h = ocr_lengkapi(data, ekstrak_bytes(data))

    assert h.metode == "ocr"
    assert h.berhasil and h.utuh
    assert "Backend Developer" in h.teks


def test_pdf_scan_terbaca_via_ocr():
    data = _pdf_scan()
    lapis1 = ekstrak_bytes(data)
    assert lapis1.metode == "perlu_ocr"  # lapis 1 memang tidak menemukan teks

    h = ocr_lengkapi(data, lapis1)

    assert h.metode == "ocr"
    assert "Backend Developer" in h.teks


def test_pdf_mixed_gabungkan_text_layer_dan_ocr():
    data = _pdf_mixed()
    lapis1 = ekstrak_bytes(data)
    assert lapis1.halaman_perlu_ocr == (2,)

    h = ocr_lengkapi(data, lapis1)

    assert h.metode == "mixed"
    assert h.utuh  # tidak ada halaman menunggu OCR lagi
    assert h.teks.count("Backend Developer") == 2  # dari kedua halaman
    assert "via OCR" in h.catatan


def test_text_layer_utuh_tidak_disentuh():
    doc = fitz.open()
    _halaman_teks(doc)
    data = doc.tobytes()
    doc.close()

    lapis1 = ekstrak_bytes(data)
    assert ocr_lengkapi(data, lapis1) == lapis1


def test_gambar_kosong_jadi_ocr_gagal():
    doc = fitz.open()
    doc.new_page()  # halaman putih polos
    png = doc[0].get_pixmap(dpi=100).tobytes("png")
    doc.close()

    h = ocr_lengkapi(png, ekstrak_bytes(png))

    assert h.metode == "ocr_gagal"
    assert not h.berhasil  # pemanggil mengantre-ulangkan, bukan membuang
