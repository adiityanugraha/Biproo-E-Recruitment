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

# Syarat lowongan diketik manusia dan panjangnya tak menentu; dipotong
# supaya uraian pekerjaan yang bertele-tele tidak menenggelamkan
# transkripnya sendiri.
MAKS_SYARAT = 600
MAKS_PERTANYAAN = 300
MAKS_JUMLAH_PERTANYAAN = 3

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
    "suku, jenis kelamin, status pernikahan, atau kondisi kesehatan.\n\n"
    "Sebelum memutuskan, nilai KECOCOKAN wawancara ini dengan posisinya.\n"
    "10. Isi 'kecocokan' dengan 'tinggi', 'sedang', atau 'rendah'. Yang "
    "ditimbang: apakah yang dibicarakan kandidat benar-benar menyangkut "
    "pekerjaan pada SYARAT POSISI di atas, dan apakah ia menjawab PERTANYAAN "
    "yang diajukan.\n"
    "11. Isi 'rendah' bila wawancaranya membahas jenis pekerjaan yang lain "
    "sama sekali - misalnya seluruh jawabannya tentang pencatatan stok gudang "
    "sementara posisi yang dilamar petugas keamanan. Jawaban yang bagus untuk "
    "pekerjaan lain tetap TIDAK menerangkan apa pun tentang kesiapan kandidat "
    "di posisi INI, dan menilainya tinggi berarti meloloskan orang atas dasar "
    "yang keliru. Pengalaman yang berbeda tapi tugasnya bersinggungan itu "
    "'sedang', bukan 'rendah'.\n"
    "12. 'alasan_kecocokan' WAJIB dan menyebut hal yang konkret: bagian mana "
    "dari syarat posisi yang tersentuh wawancara, dan mana yang tidak.\n\n"
    "Terakhir, putuskan REKOMENDASI.\n"
    "13. Isi 'rekomendasi' dengan 'recommended' atau 'not_recommended'. Ini "
    "keputusan sungguhan: kandidat yang not_recommended akan menerima surat "
    "penolakan, dan tidak ada orang yang memeriksa ulang sebelum surat itu "
    "terkirim.\n"
    "14. Timbang TIGA hal: jawaban di transkrip, kecocokan riwayat kerja dengan "
    "posisi yang dilamar, dan skor kecocokan CV bila diberikan. Kekurangan yang "
    "bisa dipelajari dalam beberapa minggu bukan alasan menolak; kekurangan pada "
    "hal inti pekerjaan adalah alasan yang sah.\n"
    "15. 'alasan_rekomendasi' WAJIB, paling banyak tiga kalimat, dan menyebut "
    "hal yang konkret dari transkrip atau riwayat kerja. Kalimat ini yang "
    "dibaca perekrut saat kandidat bertanya kenapa ia gugur, jadi 'kurang "
    "sesuai' saja tidak sah.\n"
    "16. Bila transkripnya terlalu tipis untuk memutuskan dengan yakin, isi "
    "'rekomendasi' dengan null. Itu bukan kegagalan - keputusannya lalu "
    "diserahkan ke perekrut, dan itu jauh lebih baik daripada menolak orang "
    "dari bahan yang tidak cukup.\n"
    "17. Bila 'kecocokan' bernilai 'rendah', isi 'rekomendasi' dengan null "
    "juga. Wawancara yang membahas pekerjaan lain tidak cukup untuk meloloskan "
    "MAUPUN menggugurkan seseorang - yang benar menyerahkannya ke perekrut.\n"
    "18. JANGAN memutuskan berdasarkan usia, agama, suku, jenis kelamin, status "
    "pernikahan, atau kondisi kesehatan. Ini larangan keras.\n\n"
    '19. Jawab HANYA JSON: {"penilaian": [{"kompetensi": "...", "nilai": 1-5 '
    'atau null, "alasan": "..."}], "kekuatan": "...", "kelemahan": "...", '
    '"kecocokan": "tinggi" | "sedang" | "rendah", "alasan_kecocokan": "...", '
    '"rekomendasi": "recommended" | "not_recommended" | null, '
    '"alasan_rekomendasi": "..."}'
)

# Nilai 'rekomendasi' yang diakui. Selain ini - termasuk variasi ejaan yang
# dikarang model - dianggap tidak menjawab, dan keputusannya jatuh ke perekrut.
REKOMENDASI = ("recommended", "not_recommended")

# Nilai kecocokan yang diakui. Selain ini dianggap tidak menjawab, dan tidak
# menjawab diperlakukan sama dengan 'rendah': meloloskan orang tanpa tahu
# wawancaranya nyambung dengan posisinya atau tidak justru keadaan yang mau
# dihindari.
KECOCOKAN = ("tinggi", "sedang", "rendah")


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
    # Keputusan rekomendasi (permintaan atasan, 14 Agustus 2026).
    # None = model tidak memutuskan; CI4 lalu menyerahkannya ke perekrut.
    rekomendasi: str | None = None
    alasan_rekomendasi: str = ""
    # Seberapa nyambung wawancaranya dengan posisi yang dilamar (18 Agustus
    # 2026). 'rendah' berarti yang dibicarakan pekerjaan lain sama sekali,
    # dan penilaian sebagus apa pun di atasnya tidak menerangkan kesiapan
    # kandidat DI POSISI INI.
    kecocokan: str = ""
    alasan_kecocokan: str = ""

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


def _blok_syarat(syarat: dict) -> str:
    """
    Syarat lowongan, apa adanya dari kolom jobs.

    KENAPA INI ADA. Sebelum 18 Agustus 2026 yang dikirim ke penilai cuma JUDUL
    posisi. "Security System" tidak menerangkan apa pun tentang pekerjaannya,
    sehingga model tidak punya dasar menilai wawancaranya nyambung atau tidak -
    dan transkrip operator gudang pun lolos dengan nilai bagus di posisi itu.
    Terlihat sungguhan saat dicoba pemakainya, bukan oleh satu pun uji.
    """
    baris = []
    for kunci, label in (("skill", "Keahlian yang dibutuhkan"),
                         ("pengalaman", "Pengalaman yang dibutuhkan"),
                         ("pendidikan", "Pendidikan"),
                         ("deskripsi", "Uraian pekerjaan")):
        isi = str(syarat.get(kunci, "")).strip()[:MAKS_SYARAT]
        if isi:
            baris.append(f"- {label}: {isi}")

    return "SYARAT POSISI:\n" + "\n".join(baris) + "\n\n" if baris else ""


def _blok_pertanyaan(pertanyaan: list) -> str:
    """
    Tiga pertanyaan yang BENAR-BENAR diajukan ke kandidat.

    Tanpa ini model tidak bisa melihat bahwa jawabannya tidak menjawab apa pun
    yang ditanyakan - petunjuk paling terang bahwa transkripnya berasal dari
    wawancara yang lain.
    """
    baris = [
        f"{i}. {str(p).strip()[:MAKS_PERTANYAAN]}"
        for i, p in enumerate(pertanyaan[:MAKS_JUMLAH_PERTANYAAN], 1)
        if str(p).strip()
    ]

    return "PERTANYAAN YANG DIAJUKAN:\n" + "\n".join(baris) + "\n\n" if baris else ""


def nilai_dari_transkrip(
    teks: str,
    kompetensi: list[str],
    provider: ChatProvider,
    riwayat: list[dict] | None = None,
    posisi: str = "",
    skor_cv: float | None = None,
    syarat: dict | None = None,
    pertanyaan: list | None = None,
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
        # Ikut dikirim sejak rekomendasinya diputuskan di sini. Tanpa angka ini
        # kecocokan CV hilang sama sekali dari keputusan - padahal dulu ia 40%
        # bobotnya - dan kandidat dinilai semata dari 30 menit bicara.
        + (f"SKOR KECOCOKAN CV TERHADAP LOWONGAN: {skor_cv:.2f} dari 1,00\n\n"
           if skor_cv is not None else "")
        + _blok_syarat(syarat or {})
        + _blok_pertanyaan(pertanyaan or [])
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
        # Kekuatan/kelemahan DAN rekomendasi tidak ikut dikembalikan di sini.
        # Kalau tak satu pun kompetensi bisa dinilai, bahannya memang tidak ada
        # - dan keputusan yang tetap terisi dari bahan yang sama akan terbaca
        # sebagai penilaian yang sah, padahal ia satu-satunya yang lolos justru
        # karena tidak dituntut angka.
        return Hasil(hasil, "gagal",
                     "Tak satu pun kompetensi bisa dinilai dari transkrip ini.")

    # Nilai di luar dua yang diakui - termasuk ejaan karangan seperti "Hire"
    # atau "recommend" - jadi None, bukan ditebak paling dekat. Menebak di sini
    # berarti menolak orang dari jawaban yang tidak dipahami.
    rek = d.get("rekomendasi")
    rek = rek if rek in REKOMENDASI else None

    # Kecocokan yang tidak dijawab diperlakukan sama dengan 'rendah': tanpa
    # jawaban itu tidak ada yang tahu wawancaranya nyambung atau tidak, dan
    # justru itu keadaan yang mau dihindari.
    kec = d.get("kecocokan")
    kec = kec if kec in KECOCOKAN else "rendah"

    # DITEGAKKAN DI KODE, bukan cuma diminta lewat aturan 17. Transkrip yang
    # membahas pekerjaan lain tidak cukup untuk meloloskan MAUPUN
    # menggugurkan seseorang; keputusannya milik perekrut. Sisi CI4 sudah
    # memperlakukan rekomendasi kosong sebagai 'flagged', jadi jalurnya ada
    # dan tidak perlu aturan baru di sana.
    if kec == "rendah":
        rek = None

    return Hasil(hasil, "selesai", "", narasi("kekuatan"), narasi("kelemahan"),
                 rek, narasi("alasan_rekomendasi"), kec, narasi("alasan_kecocokan"))
