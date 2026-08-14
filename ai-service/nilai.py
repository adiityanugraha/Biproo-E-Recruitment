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

from chat import ChatProvider, tanpa_kunci, ulangi
from structure import _json_pertama

# Skala 1-5 mengikuti formulir BIPROO (LembarPenilaian::SKALA di sisi PHP).
SKALA_MIN = 1
SKALA_MAKS = 5

MAKS_ALASAN = 500   # sama dengan lebar kolom interview_penilaian.catatan

# Transkrip 30 menit bisa 40.000 karakter. Dipotong supaya permintaannya tidak
# membengkak, dan yang dipotong bagian AKHIR: wawancara dibuka dengan basa-basi
# lalu masuk ke pertanyaan inti, jadi awal transkrip justru yang paling penting.
MAKS_TRANSKRIP = 30000

# Riwayat kerja yang ikut dikirim, sama batasnya dengan penyusun pertanyaan:
# daftar panjang menenggelamkan pekerjaan yang paling relevan.
MAKS_RIWAYAT = 4
MAKS_DESKRIPSI = 300

# Dua kotak narasi lembar BIPROO. Dua kalimat, jadi 500 karakter lapang - dan
# angkanya sama dengan lebar kolom interview_penilaian.catatan.
MAKS_NARASI = 500

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
    "6. Bahasa Indonesia. Alasan ringkas, paling banyak dua kalimat.\n\n"
    "Setelah itu susun 'kekuatan' dan 'kelemahan' kandidat.\n"
    "7. Keduanya dirangkum dari RIWAYAT KERJA dan TRANSKRIP, dan HANYA dari "
    "keduanya. Sebutkan hal yang konkret - pekerjaan yang pernah dijalani, "
    "atau yang benar-benar dikatakan di wawancara.\n"
    "8. 'kelemahan' diisi apa adanya, bukan dilunakkan jadi pujian terselubung "
    'seperti "terlalu teliti". Kelemahan karangan menyesatkan orang yang membaca '
    "lembar ini untuk memutuskan nasib seseorang.\n"
    "   Bila kandidat menjawab semuanya dengan baik dan tidak ada kelemahan yang "
    "terlihat, JANGAN mengarang satu. Sebutkan hal yang BELUM TERUJI di "
    'wawancara ini, mis. "Wawancara tidak menyentuh pengalaman memakai sistem '
    'gudang berbasis aplikasi, jadi kesiapan teknisnya belum bisa dinilai." Itu '
    "keterangan yang berguna untuk pewawancara berikutnya. Kosongkan hanya bila "
    "transkripnya benar-benar tidak memberi bahan apa pun.\n"
    "9. Maksimal dua kalimat masing-masing. JANGAN menyinggung usia, agama, "
    "suku, jenis kelamin, status pernikahan, atau kondisi kesehatan.\n"
    '10. Jawab HANYA JSON: {"penilaian": [{"kompetensi": "...", "nilai": 1-5 '
    'atau null, "alasan": "..."}], "kekuatan": "...", "kelemahan": "..."}'
)


class Butir(NamedTuple):
    kompetensi: str
    nilai: int | None
    alasan: str


class Hasil(NamedTuple):
    butir: tuple[Butir, ...]
    status: str          # 'selesai' | 'gagal'
    catatan: str = ""
    # Dua kotak narasi lembar BIPROO, dirangkum dari riwayat kerja + transkrip.
    # '' = tidak cukup bahan, dan itu jawaban yang sah.
    kekuatan: str = ""
    kelemahan: str = ""

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


def _blok_riwayat(riwayat: list[dict]) -> str:
    """Riwayat kerja jadi daftar ringkas. '' bila tak satu pun layak."""
    baris = []
    for r in riwayat[:MAKS_RIWAYAT]:
        judul = " di ".join(x for x in (str(r.get("jabatan", "")).strip(),
                                        str(r.get("perusahaan", "")).strip()) if x)
        if not judul:
            continue
        periode = str(r.get("periode", "")).strip()
        teks = f"- {judul}" + (f" ({periode})" if periode else "")
        deskripsi = str(r.get("deskripsi", "")).strip()[:MAKS_DESKRIPSI]
        baris.append(f"{teks}: {deskripsi}" if deskripsi else teks)

    return "\n".join(baris)


def nilai_dari_transkrip(
    teks: str,
    kompetensi: list[str],
    provider: ChatProvider,
    riwayat: list[dict] | None = None,
    posisi: str = "",
) -> Hasil:
    """
    Nilai tiap kompetensi yang diminta CI4, plus rangkum kekuatan dan kelemahan.

    Kompetensi yang tidak dijawab LLM tetap dikembalikan dengan nilai None -
    bukan dihilangkan. Sisi CI4 dengan begitu bisa membedakan "tidak cukup
    bahan" dari "tidak pernah diminta", dan keduanya memang berbeda artinya.

    RIWAYAT KERJA ikut dikirim, bukan hanya transkrip: kekuatan dan kelemahan
    kandidat sebagiannya terbaca dari apa yang pernah ia kerjakan, bukan cuma
    dari apa yang sempat ia katakan dalam wawancara 30 menit. Yang TIDAK dikirim
    biodatanya - usia, agama, jenis kelamin tidak boleh menyentuh penilaian.
    """
    teks = teks.strip()
    if not teks or not kompetensi:
        return Hasil((), "gagal", "transkrip atau daftar kompetensi kosong")

    daftar = "\n".join(f"- {k}" for k in kompetensi)
    blok   = _blok_riwayat(riwayat or [])
    pesan  = (
        (f"POSISI YANG DILAMAR: {posisi}\n\n" if posisi.strip() else "")
        + (f"RIWAYAT KERJA KANDIDAT (dari CV):\n{blok}\n\n" if blok else "")
        + f"KOMPETENSI YANG DINILAI:\n{daftar}\n\n"
        + f"=== TRANSKRIP WAWANCARA ===\n{teks[:MAKS_TRANSKRIP]}\n=== AKHIR TRANSKRIP ==="
    )

    try:
        # Diulang bila gagalnya sementara (503 model sibuk, 429 kuota per menit).
        # Penilaian berjalan di latar dan tidak ada yang menunggu di layar, jadi
        # menunggu beberapa detik jauh lebih baik daripada membuang transkrip
        # yang sudah berhasil dibuat.
        jawab = ulangi(lambda: provider.generate(SYSTEM_NILAI, [], pesan), "penilaian")
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

    def narasi(kunci: str) -> str:
        return str(d.get(kunci, "")).strip()[:MAKS_NARASI]

    if all(b.nilai is None for b in hasil):
        # Kekuatan/kelemahan tidak ikut dikembalikan di sini. Kalau tak satu pun
        # kompetensi bisa dinilai, bahannya memang tidak ada - dan rangkuman
        # yang tetap terisi dari bahan yang sama akan terbaca sebagai penilaian
        # yang sah, padahal ia satu-satunya yang lolos justru karena tidak
        # dituntut angka.
        return Hasil(hasil, "gagal",
                     "Tak satu pun kompetensi bisa dinilai dari transkrip ini.")

    return Hasil(hasil, "selesai", "", narasi("kekuatan"), narasi("kelemahan"))
