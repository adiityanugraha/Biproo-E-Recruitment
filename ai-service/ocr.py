"""
Fallback OCR (Blueprint A3.2a lapis 2, Fase 4 Day 2).

Dipanggil saat lapis 1 (extract.py) tidak mendapat teks: CV berupa gambar
(jpg/png), PDF hasil scan, atau PDF mixed yang sebagian halamannya lampiran
scan. Halaman PDF dirender ke PNG via PyMuPDF lalu dibaca Tesseract.

ponytail: subprocess ke tesseract.exe langsung, tanpa pytesseract - pytesseract
juga cuma wrapper subprocess. Ganti ke pytesseract bila butuh fitur lebih.
"""

import os
import shutil
import subprocess
import tempfile
from pathlib import Path

import fitz

from extract import MIN_KARAKTER, Hasil, _sniff_suffix

# render halaman PDF utk OCR; 300 dpi = rekomendasi Tesseract utk font kecil
DPI = 300
BAHASA = "ind+eng"  # CV Indonesia, istilah teknis sering Inggris
TIMEOUT_PER_HALAMAN = 60


def _tesseract_exe() -> str | None:
    """Cari tesseract: env TESSERACT_CMD -> lokasi instal standar -> PATH."""
    for c in (os.environ.get("TESSERACT_CMD"), r"C:\Program Files\Tesseract-OCR\tesseract.exe", "tesseract"):
        if c and shutil.which(c):
            return c

    return None


def _env_tessdata() -> dict:
    """tessdata bisa di folder user (instal tanpa admin tidak bisa menulis ke
    Program Files - kasus mesin dev ini). Hormati TESSDATA_PREFIX bila sudah ada."""
    env = os.environ.copy()
    if "TESSDATA_PREFIX" not in env:
        alt = Path(os.environ.get("LOCALAPPDATA", "")) / "tessdata"
        if (alt / "ind.traineddata").is_file():
            env["TESSDATA_PREFIX"] = str(alt)

    return env


def tersedia() -> bool:
    return _tesseract_exe() is not None


def _ocr_berkas_gambar(path: str) -> str:
    out = subprocess.run(
        [_tesseract_exe(), path, "stdout", "-l", BAHASA],
        capture_output=True, env=_env_tessdata(), timeout=TIMEOUT_PER_HALAMAN,
    )
    if out.returncode != 0:
        raise RuntimeError(f"tesseract exit {out.returncode}: {out.stderr.decode(errors='replace')[:200]}")

    return out.stdout.decode("utf-8", errors="replace").strip()


def _ocr_bytes_gambar(data: bytes, suffix: str) -> str:
    f = tempfile.NamedTemporaryFile(suffix=suffix, delete=False)
    try:
        f.write(data)
        f.close()  # Windows: tutup dulu sebelum dibaca proses tesseract

        return _ocr_berkas_gambar(f.name)
    finally:
        os.unlink(f.name)


def _ocr_halaman_pdf(data: bytes, nomor: tuple[int, ...]) -> dict[int, str]:
    """Render halaman terpilih (nomor mulai 1) ke PNG lalu OCR satu per satu."""
    hasil: dict[int, str] = {}
    with fitz.open(stream=data, filetype="pdf") as doc:
        for n in nomor:
            png = doc[n - 1].get_pixmap(dpi=DPI).tobytes("png")
            hasil[n] = _ocr_bytes_gambar(png, ".png")

    return hasil


def ocr_lengkapi(data: bytes, h: Hasil) -> Hasil:
    """
    Lapis 2: lengkapi hasil lapis 1 dengan OCR untuk bagian yang tak terbaca.

    text-layer utuh  -> dikembalikan apa adanya.
    gambar / scan    -> seluruh isi via OCR (metode 'ocr').
    mixed            -> hanya halaman kosong yang di-OCR, digabung urut halaman
                        dengan teks text-layer (metode 'mixed').
    OCR gagal / hasil tetap terlalu pendek -> metode 'ocr_gagal' (pemanggil
    memasukkannya ke antrian ulang, CV tidak pernah dibuang).
    """
    if h.utuh or h.metode == "gagal_baca" or not h.halaman_perlu_ocr:
        return h

    if not tersedia():
        return h._replace(catatan=(h.catatan + "; tesseract tidak tersedia").strip("; "))

    try:
        if _sniff_suffix(data) != ".pdf":  # CV berupa gambar langsung
            teks = _ocr_bytes_gambar(data, _sniff_suffix(data))
            per_halaman = {1: teks}
            halaman = [""]
        else:
            with fitz.open(stream=data, filetype="pdf") as doc:
                halaman = [p.get_text("text", sort=True) for p in doc]
            per_halaman = _ocr_halaman_pdf(data, h.halaman_perlu_ocr)
    except Exception as e:
        return h._replace(metode="ocr_gagal", catatan=f"OCR error: {type(e).__name__}: {e}"[:300])

    # gabung urut halaman: teks asli utk halaman ber-teks, hasil OCR utk sisanya
    gabung = [per_halaman.get(i, t) for i, t in enumerate(halaman, 1)]
    teks   = "\n".join(s.strip() for s in gabung if s.strip())
    n      = len(teks)

    if n < MIN_KARAKTER:
        return Hasil(teks, "ocr_gagal", n, h.n_halaman, (),
                     f"OCR hanya menghasilkan {n} karakter (< {MIN_KARAKTER})")

    metode = "ocr" if h.metode == "perlu_ocr" else "mixed"

    return Hasil(teks, metode, n, h.n_halaman, (),
                 f"halaman {','.join(map(str, h.halaman_perlu_ocr))} via OCR")
