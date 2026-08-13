"""
Penilaian kompetensi dari transkrip wawancara (revisi 12 Agustus 2026).

Menggantikan sembilan angka yang dulu diketik recruiter dari ingatan setelah
wawancara. Yang dinilai di sini HANYA kompetensi yang benar-benar terbaca dari
ucapan; yang butuh mata (penampilan, kerapian, cara membawa diri) tetap dinilai
recruiter yang menonton videonya.

DAFTAR KOMPETENSINYA DATANG DARI CI4, TIDAK DITULIS DI SINI. Sumbernya satu:
LembarPenilaian di sisi PHP, yang juga dipakai lembar profil dan Gate 2. Kalau
daftarnya ditulis dua kali, suatu hari keduanya berbeda dan tidak ada yang tahu
mana yang benar.

ALASAN WAJIB DITULIS. Setiap angka harus disertai kutipan dari transkrip yang
mendasarinya. Tanpa itu, penilaian otomatis cuma angka yang tidak bisa dibantah
siapa pun - termasuk oleh kandidat yang bertanya kenapa ia gugur.
"""

import logging
from typing import NamedTuple

from chat import ChatProvider, tanpa_kunci
from structure import _json_pertama

# Skala 1-5 mengikuti formulir BIPROO (LembarPenilaian::SKALA di sisi PHP).
SKALA_MIN = 1
SKALA_MAKS = 5

MAKS_ALASAN = 500   # sama dengan lebar kolom interview_penilaian.catatan

# Transkrip 30 menit bisa 40.000 karakter. Dipotong supaya permintaannya tidak
# membengkak, dan yang dipotong bagian AKHIR: wawancara dibuka dengan basa-basi
# lalu masuk ke pertanyaan inti, jadi awal transkrip justru yang paling penting.
MAKS_TRANSKRIP = 30000

SYSTEM_NILAI = (
    "Kamu perekrut senior di perusahaan retail gadget Indonesia. Nilai kandidat "
    "dari TRANSKRIP wawancara di bawah, pada kompetensi yang diminta saja.\n\n"
    "ATURAN:\n"
    f"1. Nilai {SKALA_MIN}-{SKALA_MAKS}: 1 Poor, 2 Below Average, 3 Average, "
    "4 Above Average, 5 Excellent.\n"
    "2. Setiap nilai WAJIB disertai alasan yang MENGUTIP transkrip. Sebutkan apa "
    "yang benar-benar dikatakan kandidat. Alasan tanpa kutipan tidak sah.\n"
    "3. Nilai HANYA dari apa yang terucap di transkrip. JANGAN menilai dari "
    "penampilan, cara berpakaian, suara, aksen, atau apa pun yang tidak "
    "tertulis - kamu tidak melihat dan tidak mendengar kandidat ini.\n"
    "4. Bila transkrip tidak memuat cukup bahan untuk sebuah kompetensi, beri "
    "nilai null dan tulis alasannya. JANGAN menebak, dan JANGAN memberi nilai "
    "tengah (3) sekadar supaya terisi - angka karangan lebih berbahaya daripada "
    "kolom kosong, karena ia ikut menentukan kandidat lolos atau tidak.\n"
    "5. JANGAN menilai berdasarkan usia, agama, suku, jenis kelamin, status "
    "pernikahan, atau kondisi kesehatan, walaupun hal itu ikut tersebut di "
    "transkrip. Ini larangan keras.\n"
    "6. Bahasa Indonesia. Alasan ringkas, paling banyak dua kalimat.\n"
    '7. Jawab HANYA JSON: {"penilaian": [{"kompetensi": "...", "nilai": 1-5 atau '
    'null, "alasan": "..."}]}'
)


class Butir(NamedTuple):
    kompetensi: str
    nilai: int | None
    alasan: str


class Hasil(NamedTuple):
    butir: tuple[Butir, ...]
    status: str          # 'selesai' | 'gagal'
    catatan: str = ""

    @property
    def berhasil(self) -> bool:
        return self.status == "selesai"


def _angka(v: object) -> int | None:
    """Nilai skala yang sah, atau None. Di luar rentang = None, bukan dijepit.

    Menjepit 9 jadi 5 berarti diam-diam mengarang penilaian tertinggi dari
    jawaban yang jelas tidak dipahami modelnya.
    """
    if isinstance(v, bool) or not isinstance(v, (int, float)):
        return None
    n = int(v)

    return n if SKALA_MIN <= n <= SKALA_MAKS else None


def nilai_dari_transkrip(
    teks: str,
    kompetensi: list[str],
    provider: ChatProvider,
) -> Hasil:
    """
    Nilai tiap kompetensi yang diminta CI4.

    Kompetensi yang tidak dijawab LLM tetap dikembalikan dengan nilai None -
    bukan dihilangkan. Sisi CI4 dengan begitu bisa membedakan "tidak cukup
    bahan" dari "tidak pernah diminta", dan keduanya memang berbeda artinya.
    """
    teks = teks.strip()
    if not teks or not kompetensi:
        return Hasil((), "gagal", "transkrip atau daftar kompetensi kosong")

    daftar = "\n".join(f"- {k}" for k in kompetensi)
    pesan = (
        f"KOMPETENSI YANG DINILAI:\n{daftar}\n\n"
        f"=== TRANSKRIP WAWANCARA ===\n{teks[:MAKS_TRANSKRIP]}\n=== AKHIR TRANSKRIP ==="
    )

    try:
        jawab = provider.generate(SYSTEM_NILAI, [], pesan)
    except Exception as e:
        logging.getLogger("uvicorn.error").error("penilaian LLM gagal: %s", tanpa_kunci(e))

        return Hasil((), "gagal", f"{type(e).__name__}: {tanpa_kunci(e)}"[:400])

    d = _json_pertama(jawab)
    baris = d.get("penilaian") if isinstance(d, dict) else None
    if not isinstance(baris, list):
        return Hasil((), "gagal", "jawaban LLM tidak bisa dibaca")

    # Dipetakan menurut NAMA kompetensi, bukan urutan: LLM kadang menukar urutan
    # atau menambah baris yang tidak diminta, dan mencocokkan per indeks membuat
    # nilai kompetensi A menempel pada kompetensi B tanpa jejak apa pun.
    terjawab: dict[str, Butir] = {}
    for b in baris:
        if not isinstance(b, dict):
            continue
        nama = str(b.get("kompetensi", "")).strip()
        if nama in kompetensi and nama not in terjawab:
            terjawab[nama] = Butir(
                nama,
                _angka(b.get("nilai")),
                str(b.get("alasan", "")).strip()[:MAKS_ALASAN],
            )

    hasil = tuple(
        terjawab.get(k, Butir(k, None, "Tidak dinilai: model tidak mengembalikan butir ini."))
        for k in kompetensi
    )

    if all(b.nilai is None for b in hasil):
        return Hasil(hasil, "gagal",
                     "Tak satu pun kompetensi bisa dinilai dari transkrip ini.")

    return Hasil(hasil, "selesai")
