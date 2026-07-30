"""
Penghapusan atribut sensitif sebelum embedding (Blueprint A3.2, Fase 4 Day 3).

Fairness-by-design: gender, usia/tanggal lahir, agama, status pernikahan,
kewarganegaraan, alamat, dan identitas kontak TIDAK boleh ikut menentukan skor
kecocokan. Dibuang di sini, bukan di prompt LLM - jaminan deterministik yang
bisa diuji, tidak bergantung pada kepatuhan model.

Yang dibuang adalah ATRIBUT, bukan kompetensi. "Bahasa Inggris" sebagai skill
tetap lolos; "Kewarganegaraan: Indonesia" dibuang.
"""

import re

GANTI = "[dihapus]"

# Baris "Label: nilai" yang labelnya atribut sensitif -> seluruh baris dibuang.
LABEL_SENSITIF = (
    "jenis kelamin", "gender", "sex", "kelamin",
    "tempat/tgl lahir", "tempat tanggal lahir", "tempat & tanggal lahir",
    "tanggal lahir", "tgl lahir", "tempat lahir", "date of birth", "dob", "birth",
    "umur", "usia", "age",
    "agama", "religion",
    "status", "status pernikahan", "status perkawinan", "marital status",
    "kewarganegaraan", "nationality", "citizenship", "suku",
    "alamat", "address", "domisili", "domicile", "tempat tinggal",
    "nik", "ktp", "npwp", "no ktp", "nomor induk",
    "telepon", "telp", "hp", "no hp", "phone", "mobile", "whatsapp", "wa",
    "email", "e-mail", "surel",
    "tinggi badan", "berat badan", "height", "weight", "golongan darah",
    "foto", "photo",
)

# Pola nilai yang sensitif di mana pun ia muncul (bukan cuma setelah label).
#
# Pemisah selalu [ \t], JANGAN \s: \s ikut menelan newline sehingga baris
# berikutnya tersambung ke hasil sensor (terbukti bikin "Usia 30\nSkill: Python"
# jadi satu baris).
#
# TIDAK disensor dengan sengaja: durasi seperti "3 tahun". Pola bare "N tahun"
# mustahil dibedakan dari lama pengalaman ("pengalaman 3 tahun") tanpa konteks,
# dan membuangnya menghapus bukti kompetensi. Usia yang berlabel sudah tertangkap
# LABEL_SENSITIF, jadi risikonya jauh lebih besar daripada manfaatnya.
POLA = (
    # kontak
    re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+", re.I),                       # email
    re.compile(r"(?:\+62|62|0)8\d{2}[ .-]?\d{3,4}[ .-]?\d{3,4}"),        # HP Indonesia
    re.compile(r"\b\d{16}\b"),                                           # NIK 16 digit
    # tanggal lahir eksplisit
    re.compile(r"\b\d{1,2}[/\-.]\d{1,2}[/\-.](?:19|20)\d{2}\b"),
    re.compile(
        r"\b\d{1,2}[ \t]+(?:januari|februari|maret|april|mei|juni|juli|agustus|"
        r"september|oktober|november|desember)[ \t]+(?:19|20)\d{2}\b", re.I),
    # pernyataan usia (berlabel di tengah kalimat)
    re.compile(r"\b(?:umur|usia|age)[ \t]*:?[ \t]*\d{1,2}[ \t]*(?:tahun|thn|years?)?", re.I),
    # atribut kategori berdiri sendiri
    re.compile(r"\b(?:laki-laki|perempuan|pria|wanita|male|female)\b", re.I),
    re.compile(r"\b(?:islam|kristen|katolik|katholik|protestan|hindu|buddha|budha|konghucu)\b", re.I),
    re.compile(r"\b(?:belum menikah|sudah menikah|menikah|lajang|single|married)\b", re.I),
)

_LABEL_RE = re.compile(
    r"^\s*(?:[-*•]\s*)?(" + "|".join(re.escape(x) for x in LABEL_SENSITIF) + r")\s*[:\-]",
    re.I,
)

# Label sensitif bisa muncul di TENGAH kalimat - lazim pada CV naratif dan pada
# hasil strukturisasi LLM yang menyalin ulang satu paragraf utuh. Di situ nilainya
# disensor sampai akhir kalimat/baris.
#
# Hanya label yang TIDAK ambigu didaftar di sini. "status" dan "foto" sengaja
# TIDAK ikut: "status: aktif" atau "foto produk" bisa bagian uraian pekerjaan,
# dan menyensornya akan memakan konteks kerja yang sah.
LABEL_INLINE = (
    "alamat", "address", "domisili", "domicile", "tempat tinggal",
    "agama", "religion",
    "jenis kelamin", "gender", "kelamin",
    "tempat/tgl lahir", "tempat tanggal lahir", "tanggal lahir", "tgl lahir",
    "tempat lahir", "date of birth", "dob",
    "umur", "usia", "age",
    "nik", "ktp", "npwp", "no ktp",
    "telepon", "telp", "no hp", "phone", "whatsapp",
    "email", "e-mail", "surel",
    "kewarganegaraan", "nationality", "citizenship",
    "status pernikahan", "status perkawinan", "marital status",
    "tinggi badan", "berat badan", "golongan darah",
)

_INLINE_RE = re.compile(
    r"\b(?:" + "|".join(re.escape(x) for x in LABEL_INLINE) + r")\s*:\s*[^.\n]*",
    re.I,
)


def baris_sensitif(baris: str) -> bool:
    """Baris berformat 'Label sensitif: nilai'."""
    return _LABEL_RE.match(baris) is not None


def bersihkan(teks: str) -> str:
    """Buang baris berlabel sensitif, lalu sensor pola sensitif yang tersisa."""
    if not teks:
        return teks

    simpan = [b for b in teks.splitlines() if not baris_sensitif(b)]
    hasil  = _INLINE_RE.sub(GANTI, "\n".join(simpan))
    for p in POLA:
        hasil = p.sub(GANTI, hasil)

    # rapikan: baris jadi kosong / hanya penanda, dan spasi berlebih
    bersih = [b for b in hasil.splitlines() if re.sub(r"[\s\[\]dihapus:.,-]", "", b, flags=re.I)]

    return re.sub(r"[ \t]{2,}", " ", "\n".join(bersih)).strip()
