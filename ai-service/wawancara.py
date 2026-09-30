"""
Satu giliran wawancara suara: menilai jawaban kandidat DAN menyusun pertanyaan
lanjutan yang mengait ke isi jawaban itu (arahan 12 Agustus 2026).

Kenapa dua pekerjaan itu disatukan dalam SATU panggilan LLM, bukan dua:
tier gratis Gemini memberi 20 panggilan generateContent per hari dan screening
CV sudah memakai 1-2 per CV. Memisah "nilai" dan "buat lanjutan" berarti dua kali
lipat panggilan untuk keluaran yang sumbernya sama persis - jawaban kandidat yang
baru saja diucapkan. Digabung, satu wawancara 8 butir memakai 8 panggilan, bukan
16. Rinciannya di docs/wawancara-suara.md.

Yang TIDAK dikerjakan di sini: keputusan lolos. Modul ini mengembalikan tingkat
per butir (kurang/cukup/baik) dengan kosakata yang sama persis dengan
PenilaianRubrik di sisi CI4, lalu CI4 yang menghitung skornya. Satu rubrik, satu
rumus, apa pun yang mengisinya - manusia atau model.
"""

import logging
import re
from typing import NamedTuple

from structure import _json_pertama

# Kosakata tingkat WAJIB sama dengan PenilaianRubrik::TINGKAT di CI4. Kalau
# suatu saat berubah di sana, ia harus berubah di sini juga - kalau tidak,
# seluruh penilaian otomatis diam-diam terbuang karena tingkatnya tak dikenal.
TINGKAT_SAH = ("kurang", "cukup", "baik")

MAKS_ALASAN = 300
MAKS_LANJUTAN = 300
MAKS_JAWABAN = 4000

# Di bawah ini jawaban tidak dikirim ke LLM sama sekali.
#
# Transkrip suara menghasilkan banyak potongan sampah: kandidat berdehem,
# mikrofon menangkap "hmm", atau pengenal suara memuntahkan satu kata dari
# kebisingan latar. Menilainya berarti membakar kuota harian untuk memberi
# "kurang" pada sesuatu yang bukan jawaban - dan itu tercatat sebagai penilaian
# kompetensi kandidat. Yang benar: minta ulangi.
MIN_KATA_JAWABAN = 5

# Isian yang kerap muncul sendirian dari pengenal suara saat kandidat diam atau
# ragu. Sendirian ia bukan jawaban; di tengah kalimat panjang ia tidak masalah.
PENGISI = {"hmm", "mmm", "ehm", "eh", "em", "anu", "apa", "ya", "oke", "hm", "mm"}

SYSTEM_WAWANCARA = (
    "Kamu pewawancara senior yang sedang mewawancarai satu kandidat lewat suara. "
    "Kamu menerima: butir penilaian yang sedang diuji, pertanyaan yang barusan kamu "
    "ajukan, dan jawaban kandidat.\n\n"
    "JAWABAN ITU HASIL TRANSKRIP SUARA. Ejaannya bisa salah, nama diri dan istilah "
    "teknis sering salah dengar, tanda baca tidak ada. Nilai ISI dan cara berpikirnya, "
    "jangan menghukum tata bahasa, kalimat terpotong, atau salah ketik.\n\n"
    "KERJAKAN DUA HAL:\n\n"
    "1. NILAI jawaban terhadap indikator butir ini, pilih satu:\n"
    '   "kurang" - tidak menjawab yang ditanya, atau hanya klaim tanpa isi\n'
    '   "cukup"  - menjawab yang ditanya tapi umum, tanpa contoh atau ukuran\n'
    '   "baik"   - menjawab dengan kejadian nyata, tindakan yang jelas, dan hasilnya\n'
    "   Bila butir ini punya daftar red flag dan jawabannya mengandung salah satu, "
    'nilainya "kurang" dan sebut red flag itu di alasan.\n\n'
    "2. SUSUN SATU pertanyaan lanjutan yang MENGAIT KE ISI JAWABAN BARUSAN. Bukan "
    "pertanyaan baru yang berdiri sendiri. Bentuknya kasus: ambil situasi, angka, "
    "atau keputusan yang kandidat sebut sendiri, lalu ubah keadaannya dan tanyakan "
    'apa yang akan ia lakukan. Contoh: kandidat bercerita menangani antrean panjang '
    'sendirian; lanjutannya "Tadi Anda bilang menanganinya sendirian. Kalau saat itu '
    'ada satu pelanggan yang komplain keras di depan antrean, mana yang Anda dahulukan '
    'dan kenapa?"\n'
    "   Kutip satu potong isi jawabannya supaya kandidat tahu kamu mendengarkan.\n"
    "   Satu kalimat, Bahasa Indonesia, sopan, langsung tanya.\n\n"
    "LARANGAN KERAS pada pertanyaan lanjutan: usia, agama, suku, ras, status "
    "pernikahan, rencana punya anak, kehamilan, kondisi kesehatan, orientasi seksual, "
    "afiliasi politik, dan keadaan keluarga. Semua itu tidak boleh ditanyakan dalam "
    "keadaan apa pun, sekalipun kandidat yang menyinggungnya lebih dulu.\n\n"
    "Bila jawaban kandidat sama sekali tidak nyambung dengan pertanyaan (mis. salah "
    'dengar, atau ia balik bertanya), set "nyambung": false dan isi "lanjutan" dengan '
    "pertanyaan yang sama diulang dengan kalimat lebih sederhana.\n\n"
    "Jawab HANYA JSON:\n"
    '{"tingkat":"kurang|cukup|baik","alasan":"...","lanjutan":"...","nyambung":true}'
)


class Giliran(NamedTuple):
    """Hasil satu giliran. tingkat None = tidak dinilai (jawaban tak memadai)."""

    tingkat: str | None
    alasan: str
    lanjutan: str
    nyambung: bool


def _potong(nilai: object, batas: int) -> str:
    """Rapikan teks dari LLM: satu spasi, tanpa kutip pembungkus, terbatas."""
    t = re.sub(r"\s+", " ", str(nilai or "")).strip().strip('"')

    return t[:batas]


def jawaban_memadai(jawaban: str) -> bool:
    """
    Apakah jawaban ini layak dikirim ke LLM?

    Penjaga kuota sekaligus penjaga keadilan: potongan transkrip sepanjang dua
    kata bukan bahan yang cukup untuk menilai kompetensi seseorang.
    """
    kata = [k for k in re.findall(r"[\w']+", jawaban.lower()) if k not in PENGISI]

    return len(kata) >= MIN_KATA_JAWABAN


def rakit_uraian(
    posisi: str,
    butir: dict,
    pertanyaan: str,
    jawaban: str,
    riwayat: list[dict] | None = None,
) -> str:
    """Susun bagian user dari prompt. Dipisah supaya bisa diuji tanpa LLM."""
    baris = [
        f"Posisi yang dilamar: {posisi}",
        f"Kompetensi yang sedang diuji: {butir.get('kompetensi') or '(tidak disebut)'}",
    ]

    indikator = butir.get("indikator")
    if isinstance(indikator, (list, tuple)):
        indikator = "; ".join(str(x) for x in indikator if str(x).strip())
    if str(indikator or "").strip():
        baris.append(f"Indikator jawaban yang baik: {indikator}")

    red = butir.get("red_flag")
    if isinstance(red, (list, tuple)):
        red = "; ".join(str(x) for x in red if str(x).strip())
    if str(red or "").strip():
        baris.append(f"Red flag: {red}")

    for r in riwayat or []:
        baris.append(f"[sebelumnya] Tanya: {r.get('pertanyaan', '')}")
        baris.append(f"[sebelumnya] Jawab: {r.get('jawaban', '')}")

    baris.append(f"\nPertanyaan yang barusan diajukan: {pertanyaan}")
    baris.append(f"Jawaban kandidat (transkrip suara): {jawaban[:MAKS_JAWABAN]}")

    return "\n".join(baris)


def baca_balasan(teks: str) -> Giliran | None:
    """
    Terjemahkan balasan LLM jadi Giliran. None = tidak bisa dibaca.

    Tingkat di luar kosakata dianggap TIDAK ADA, bukan dipetakan paksa ke nilai
    terdekat: model yang menjawab "sangat baik" atau "4" sedang tidak mengikuti
    rubrik, dan menebak maksudnya berarti mengarang penilaian orang.
    """
    d = _json_pertama(teks)
    if d is None:
        return None

    tingkat = str(d.get("tingkat") or "").strip().lower()

    return Giliran(
        tingkat=tingkat if tingkat in TINGKAT_SAH else None,
        alasan=_potong(d.get("alasan"), MAKS_ALASAN),
        lanjutan=_potong(d.get("lanjutan"), MAKS_LANJUTAN),
        # default True: hanya penolakan tegas dari model yang dianggap tak nyambung
        nyambung=d.get("nyambung") is not False,
    )


def nilai_giliran(
    llm,
    posisi: str,
    butir: dict,
    pertanyaan: str,
    jawaban: str,
    riwayat: list[dict] | None = None,
) -> Giliran:
    """
    Satu panggilan LLM: nilai jawaban + susun pertanyaan lanjutan.

    Jawaban tak memadai dikembalikan tanpa memanggil LLM sama sekali.

    :raises RuntimeError: LLM gagal dijangkau atau balasannya tidak terbaca -
                          pemanggil yang memutuskan cara turun kelasnya.
    """
    if not jawaban_memadai(jawaban):
        return Giliran(
            tingkat=None,
            alasan="Jawaban terlalu pendek atau tidak tertangkap jelas.",
            lanjutan="Maaf, suara Anda kurang tertangkap. Boleh diulangi jawabannya?",
            nyambung=False,
        )

    uraian = rakit_uraian(posisi, butir, pertanyaan, jawaban, riwayat)
    balasan = llm.generate(SYSTEM_WAWANCARA, [], uraian)

    hasil = baca_balasan(balasan)
    if hasil is None:
        logging.getLogger("uvicorn.error").error("wawancara: balasan LLM tidak bisa dibaca")
        raise RuntimeError("balasan LLM tidak bisa dibaca")

    return hasil
