"""
Strukturisasi teks CV menjadi tiga field yang di-embed (Blueprint A3.2):
ringkasan pengalaman, skill, pendidikan.

Dua jalur:
  strukturkan()            - berbasis heading (Day 2). Cepat, tanpa biaya API.
  strukturkan_kontekstual()- via LLM (Day 3). Memahami konteks per kalimat
                             sesuai A3.2a, jadi CV naratif tanpa heading dan CV
                             mixed (lampiran scan menempel ke section terakhir)
                             tidak lagi salah kelompok. Gagal/kosong -> otomatis
                             jatuh ke jalur heading, bukan kehilangan CV.

Atribut sensitif dibuang terpisah oleh sanitize.bersihkan() setelah
strukturisasi - jaminan deterministik, tidak bergantung kepatuhan LLM.
"""

import json
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

    def _replace_flags(self, *tambahan: str) -> "Terstruktur":
        """Salinan dengan flag tambahan di depan (jejak jalur yang dipakai)."""
        return self._replace(flags=(*tambahan, *self.flags))


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


# --- Jalur kontekstual via LLM (Day 3, Blueprint A3.2a) ---

MAX_TEKS_LLM = 12000  # CV terpanjang di sampel ~15rb karakter; potong demi kuota

SYSTEM_STRUKTUR = (
    "Kamu pengurai CV. Dari teks CV mentah (hasil ekstraksi PDF/OCR, urutannya "
    "bisa berantakan), kelompokkan ISI menjadi tiga bidang berdasarkan MAKNA "
    "kalimat, bukan judul section.\n"
    "- pengalaman: riwayat pekerjaan/organisasi, jabatan, nama perusahaan, "
    "uraian tanggung jawab, periode kerja.\n"
    "- skill: kemampuan, tools, bahasa pemrograman, sertifikasi keahlian.\n"
    "- pendidikan: jenjang, institusi, jurusan, tahun studi.\n\n"
    "ATURAN:\n"
    "1. Salin ulang isi aslinya, jangan mengarang dan jangan menyimpulkan.\n"
    "2. Teks lampiran hasil scan (transkrip nilai, sertifikat, surat) yang bukan "
    "riwayat kerja/pendidikan/skill: BUANG, jangan dipaksa masuk salah satu bidang.\n"
    "3. Data pribadi (nama, alamat, kontak, usia, gender, agama, status): BUANG.\n"
    "4. Bidang tanpa isi di CV ini: kembalikan string kosong.\n"
    "5. Jawab HANYA JSON: "
    '{"pengalaman": "...", "skill": "...", "pendidikan": "..."}'
)


def _json_pertama(s: str) -> dict | None:
    """Ambil objek JSON dari jawaban LLM (kadang terbungkus ```json)."""
    m = re.search(r"\{.*\}", s, re.S)
    if m is None:
        return None
    try:
        d = json.loads(m.group(0))
    except json.JSONDecodeError:
        return None

    return d if isinstance(d, dict) else None


def strukturkan_kontekstual(teks: str, provider) -> Terstruktur:
    """
    Strukturisasi berbasis pemahaman konteks. `provider` mengikuti ChatProvider
    (chat.py): generate(system, history, question) -> str.

    Selalu mengembalikan hasil yang bisa dipakai: LLM error, JSON tak terparse,
    atau ketiga bidang kosong -> jatuh ke strukturkan() berbasis heading.
    """
    if not teks.strip():
        return Terstruktur("", "", "", "", ("teks_kosong",))

    try:
        jawab = provider.generate(SYSTEM_STRUKTUR, [], teks[:MAX_TEKS_LLM])
    except Exception:
        return strukturkan(teks)._replace_flags("llm_gagal")

    d = _json_pertama(jawab)
    if d is None:
        return strukturkan(teks)._replace_flags("llm_json_invalid")

    ambil = lambda k: str(d.get(k) or "").strip()
    peng, skill, didik = ambil("pengalaman"), ambil("skill"), ambil("pendidikan")

    if not (peng or skill or didik):
        return strukturkan(teks)._replace_flags("llm_kosong")

    flags = tuple(
        f"{n}_kosong" for n, v in (("pengalaman", peng), ("skill", skill), ("pendidikan", didik)) if not v
    )

    return Terstruktur(peng, skill, didik, "", ("kontekstual", *flags))
