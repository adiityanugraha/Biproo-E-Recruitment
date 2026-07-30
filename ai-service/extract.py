"""
Ekstraksi teks CV (Blueprint A3.2a, Fase 4 Day 1).

Lapis pertahanan pertama: ekstraktor LAYOUT-AWARE. PyMuPDF membaca posisi tiap
blok teks lalu kita minta urutan baca berdasarkan posisi (sort=True), bukan
urutan mentah di dalam file - inilah yang membedakannya dari ekstraktor teks
polos, dan yang membuat CV multi-kolom tidak terbaca meloncat antar kolom.

Lapis kedua (fallback OCR untuk CV hasil scan / CV gambar) menyusul di Day 2.
Di sini CV yang teksnya kosong/terlalu pendek hanya DITANDAI 'perlu_ocr' supaya
tidak ada CV yang hilang dari proses (kriteria selesai Fase 4).
"""

import os
import tempfile
from pathlib import Path
from typing import NamedTuple

import fitz  # PyMuPDF

# Di bawah ambang ini hasil ekstraksi seluruh dokumen dianggap tidak layak dan
# CV dilempar ke jalur OCR. Dikalibrasi dari 15 CV sampel historis: yang berhasil
# menghasilkan 1.318 karakter ke atas, yang gagal tepat 0 - jadi ambang di mana
# pun antara keduanya aman. 200 dipilih konservatif.
MIN_KARAKTER = 200

# Ambang PER HALAMAN. Perlu terpisah karena CV "mixed" (10% data historis) punya
# halaman pertama berupa teks lalu lampiran sertifikat hasil scan: dokumennya
# lolos ambang total, tapi 70-93% halamannya nol karakter dan isinya hilang
# tanpa jejak kalau hanya diperiksa di tingkat dokumen.
# Terukur: halaman teks 1.470-6.558 karakter, halaman gambar tepat 0.
MIN_KARAKTER_HALAMAN = 100

# Format yang tidak punya text layer sama sekali - langsung ke jalur OCR,
# tanpa perlu mencoba membaca teks lebih dulu.
EKSTENSI_GAMBAR = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}


class Hasil(NamedTuple):
    teks: str
    # lapis 1: 'text-layer' | 'perlu_ocr' | 'gagal_baca'
    # setelah lapis 2 (ocr.py): 'ocr' | 'mixed' | 'ocr_gagal'
    metode: str
    n_karakter: int
    n_halaman: int
    # Nomor halaman (mulai 1) yang teksnya di bawah MIN_KARAKTER_HALAMAN dan
    # masih menunggu OCR. ocr.py mengosongkannya setelah halaman itu diproses.
    halaman_perlu_ocr: tuple[int, ...] = ()
    catatan: str = ""

    @property
    def berhasil(self) -> bool:
        return self.metode in ("text-layer", "ocr", "mixed")

    @property
    def utuh(self) -> bool:
        """Berhasil DAN tidak ada halaman yang isinya terlewat."""
        return self.berhasil and not self.halaman_perlu_ocr


def _halaman_pdf(path: Path) -> list[str]:
    """Teks tiap halaman dalam urutan baca menurut posisi (bukan urutan mentah)."""
    with fitz.open(path) as doc:
        return [p.get_text("text", sort=True) for p in doc]


def ekstrak(path: str | Path) -> Hasil:
    """
    Baca teks dari satu file CV.

    Tidak pernah melempar exception karena file rusak: kegagalan apa pun jadi
    metode 'gagal_baca' supaya pemanggil bisa mengantrikannya, bukan tumbang.
    """
    path = Path(path)

    if not path.exists():
        return Hasil("", "gagal_baca", 0, 0, "file tidak ditemukan")

    if path.suffix.lower() in EKSTENSI_GAMBAR:
        return Hasil("", "perlu_ocr", 0, 1, (1,), f"berkas gambar ({path.suffix.lower()}), tidak punya text layer")

    try:
        halaman = _halaman_pdf(path)
    except Exception as e:  # PDF rusak, terenkripsi, atau bukan PDF sungguhan
        return Hasil("", "gagal_baca", 0, 0, (), f"{type(e).__name__}: {e}")

    teks   = "\n".join(halaman).strip()
    n      = len(teks)
    kurang = tuple(i for i, h in enumerate(halaman, 1) if len(h.strip()) < MIN_KARAKTER_HALAMAN)

    if n < MIN_KARAKTER:
        return Hasil(teks, "perlu_ocr", n, len(halaman), kurang,
                     f"teks hanya {n} karakter (< {MIN_KARAKTER}), diduga hasil scan")

    catatan = ""
    if kurang:
        catatan = f"{len(kurang)} dari {len(halaman)} halaman nyaris tanpa teks (lampiran hasil scan)"

    return Hasil(teks, "text-layer", n, len(halaman), kurang, catatan)


def _sniff_suffix(data: bytes) -> str:
    """Format dari magic bytes - URL unduhan internal tidak membawa ekstensi."""
    if data[:4] == b"%PDF":
        return ".pdf"
    if data[:3] == b"\xff\xd8\xff":
        return ".jpg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return ".png"

    return ".pdf"  # biarkan fitz mencoba; kalau bukan PDF sungguhan -> gagal_baca


def ekstrak_bytes(data: bytes) -> Hasil:
    """ekstrak() untuk isi berkas yang baru diunduh (belum ada di disk)."""
    f = tempfile.NamedTemporaryFile(suffix=_sniff_suffix(data), delete=False)
    try:
        f.write(data)
        f.close()  # Windows: file harus ditutup sebelum dibuka proses lain

        return ekstrak(f.name)
    finally:
        os.unlink(f.name)
