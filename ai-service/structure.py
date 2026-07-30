"""
Strukturisasi teks CV menjadi tiga field yang di-embed (Blueprint A3.2,
Fase 4 Day 2): ringkasan pengalaman, skill, pendidikan.

Day 2 = versi berbasis heading (CV dengan section jelas). CV naratif tanpa
heading jatuh ke fallback: seluruh teks jadi field pengalaman + flag
'tanpa_heading', supaya embedding tetap punya bahan dan kegagalannya terukur.
Day 3 memperbaiki ke pemahaman konteks per kalimat (A3.2a), bukan keyword.

Atribut sensitif (gender, usia, agama, alamat, foto) TIDAK ikut ter-embed:
hanya isi tiga section ini yang diteruskan, sisanya (header/kontak/biodata)
tertinggal di bagian 'lain' dan berhenti di sini (fairness-by-design A3.2).
"""

import re
from typing import NamedTuple

# heading yang lazim di CV Indonesia + Inggris; dicocokkan ke SELURUH baris
# pendek (bukan substring bebas) supaya kalimat biasa tidak salah terdeteksi
HEADING = {
    "pengalaman": (
        "pengalaman kerja", "pengalaman organisasi", "pengalaman", "riwayat pekerjaan",
        "riwayat karir", "work experience", "working experience", "experience",
        "professional experience", "employment history", "organizational experience",
    ),
    "pendidikan": (
        "pendidikan", "riwayat pendidikan", "pendidikan terakhir", "education",
        "educational background", "academic background", "akademik",
    ),
    "skill": (
        "skill", "skills", "keahlian", "keterampilan", "kemampuan", "kompetensi",
        "technical skills", "hard skill", "soft skill", "hard & soft skill",
        "keahlian & minat", "kualifikasi",
    ),
}
MAX_PANJANG_HEADING = 45  # heading itu baris pendek; kalimat panjang bukan heading


class Terstruktur(NamedTuple):
    pengalaman: str
    skill: str
    pendidikan: str
    lain: str  # header/kontak/biodata - berhenti di sini, tidak di-embed
    flags: tuple[str, ...] = ()


def _jenis_heading(baris: str) -> str | None:
    b = re.sub(r"[^a-z& ]", " ", baris.lower()).strip()
    b = re.sub(r"\s+", " ", b)
    if not b or len(baris.strip()) > MAX_PANJANG_HEADING:
        return None
    for jenis, kandidat in HEADING.items():
        if b in kandidat:
            return jenis

    return None


def strukturkan(teks: str) -> Terstruktur:
    bagian: dict[str, list[str]] = {"pengalaman": [], "skill": [], "pendidikan": [], "lain": []}
    aktif = "lain"
    for baris in teks.splitlines():
        jenis = _jenis_heading(baris)
        if jenis is not None:
            aktif = jenis
            continue  # baris heading-nya sendiri tidak perlu ikut isi
        bagian[aktif].append(baris.rstrip())

    hasil = {k: "\n".join(v).strip() for k, v in bagian.items()}

    # CV naratif tanpa heading sama sekali: seluruh teks jadi pengalaman supaya
    # tetap bisa di-embed; flag dicatat sebagai baseline kegagalan (Day 2 item 5)
    if not hasil["pengalaman"] and not hasil["skill"] and not hasil["pendidikan"]:
        return Terstruktur(teks.strip(), "", "", "", ("tanpa_heading",))

    flags = tuple(
        f"{k}_kosong" for k in ("pengalaman", "skill", "pendidikan") if not hasil[k]
    )

    return Terstruktur(hasil["pengalaman"], hasil["skill"], hasil["pendidikan"], hasil["lain"], flags)
