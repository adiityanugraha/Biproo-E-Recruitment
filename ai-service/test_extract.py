"""Ekstraksi CV: tiap cabang keputusan diuji dengan berkas sintetis (tanpa PII)."""

import fitz
import pytest

from extract import MIN_KARAKTER_HALAMAN, ekstrak, ekstrak_bytes

ISI = (
    "Pengalaman Kerja: Backend Developer di PT Contoh Sejahtera (2021-2024). "
    "Membangun REST API dengan PHP dan SQL Server, memimpin tim kecil, "
    "menangani deployment dan monitoring aplikasi internal perusahaan. "
    "Pendidikan: S1 Teknik Informatika. Skill: PHP, Python, SQL, Git."
)


def buat_pdf(path, teks_per_halaman):
    doc = fitz.open()
    for teks in teks_per_halaman:
        page = doc.new_page()
        # per baris ~60 karakter: teks satu baris panjang terpotong di tepi
        # halaman oleh MuPDF dan hilang dari hasil get_text
        for i in range(0, len(teks), 60):
            page.insert_text((72, 72 + 14 * (i // 60)), teks[i : i + 60])
    doc.save(path)
    doc.close()


def test_pdf_teks_normal_jadi_text_layer_utuh(tmp_path):
    f = tmp_path / "normal.pdf"
    buat_pdf(f, [ISI])

    h = ekstrak(f)

    assert h.berhasil and h.utuh
    assert h.metode == "text-layer"
    assert "Backend Developer" in h.teks
    assert h.halaman_perlu_ocr == ()


def test_pdf_scan_tanpa_teks_jadi_perlu_ocr(tmp_path):
    f = tmp_path / "scan.pdf"
    buat_pdf(f, [""])  # halaman ada, teks tidak

    h = ekstrak(f)

    assert h.metode == "perlu_ocr"
    assert not h.berhasil


def test_pdf_mixed_menandai_halaman_scan(tmp_path):
    """Halaman 1 teks, halaman 2-3 lampiran scan: teks terbaca TAPI halaman
    scan tercatat supaya Day 2 bisa OCR halaman itu saja (pola 10% data historis)."""
    f = tmp_path / "mixed.pdf"
    buat_pdf(f, [ISI, "", ""])

    h = ekstrak(f)

    assert h.berhasil
    assert not h.utuh
    assert h.halaman_perlu_ocr == (2, 3)


def test_berkas_gambar_langsung_perlu_ocr(tmp_path):
    f = tmp_path / "cv.jpg"
    f.write_bytes(b"\xff\xd8\xff\xe0isi-jpeg-palsu")

    h = ekstrak(f)

    assert h.metode == "perlu_ocr"
    assert h.halaman_perlu_ocr == (1,)


def test_berkas_rusak_jadi_gagal_baca_bukan_crash(tmp_path):
    f = tmp_path / "rusak.pdf"
    f.write_bytes(b"bukan pdf sama sekali")

    h = ekstrak(f)

    assert h.metode == "gagal_baca"
    assert h.catatan != ""


def test_file_tidak_ada_jadi_gagal_baca():
    h = ekstrak("tidak/ada/di/mana-pun.pdf")

    assert h.metode == "gagal_baca"


# 0 dan 50: jelas di bawah ambang 100. Nilai tepat di ambang tidak diuji lewat
# PDF karena pemisah baris ikut terhitung dan menggeser panjang 1-2 karakter.
@pytest.mark.parametrize("panjang", [0, MIN_KARAKTER_HALAMAN // 2])
def test_ambang_per_halaman(tmp_path, panjang):
    f = tmp_path / "tipis.pdf"
    buat_pdf(f, [ISI, "x" * panjang])

    assert ekstrak(f).halaman_perlu_ocr == (2,)


# --- ekstrak_bytes: jalur unduhan (format dari magic bytes, bukan nama file) ---

def test_bytes_pdf_terdeteksi_dan_terekstrak(tmp_path):
    f = tmp_path / "x.pdf"
    buat_pdf(f, [ISI])

    h = ekstrak_bytes(f.read_bytes())

    assert h.berhasil
    assert "Backend Developer" in h.teks


def test_bytes_jpeg_langsung_perlu_ocr():
    assert ekstrak_bytes(b"\xff\xd8\xff\xe0jpeg-unduhan").metode == "perlu_ocr"


def test_bytes_sampah_jadi_gagal_baca():
    assert ekstrak_bytes(b"halaman error 404 dari server").metode == "gagal_baca"
