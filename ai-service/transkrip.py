"""
Transkripsi rekaman wawancara (revisi 12 Agustus 2026).

Recruiter merekam wawancara Zoom ke komputernya lalu mengunggah berkasnya; di
sini rekaman itu diubah jadi teks, dan teks itulah yang kelak dinilai.

DUA MESIN, YANG LOKAL DULU (14 Agustus 2026)

Whisper di komputer sendiri yang dicoba pertama; Gemini jadi cadangan bila
faster-whisper belum terpasang atau hasilnya tidak layak. Urutannya begitu
karena transkripsi adalah langkah yang paling sering gagal di jalur Gemini -
kuota 20 panggilan sehari, 503 model sibuk, 400 berkas belum siap - padahal ia
juga satu-satunya langkah yang tidak butuh penalaran, cuma menyalin ucapan.
Memindahkannya ke mesin sendiri mengembalikan tiga dari empat panggilan harian
kepada pekerjaan yang memang butuh model bahasa.

Cadangannya tetap ada dan bukan basa-basi: Whisper bisa tersandung codec yang
tidak dikenalnya, dan menyerah pada percobaan pertama berarti recruiter
mengunggah ulang rekaman 8 MB tanpa tahu sebabnya.

KENAPA LEWAT FILES API, BUKAN DITEMPELKAN LANGSUNG

Gemini menerima audio langsung di dalam badan permintaan (inline_data), tapi
seluruh permintaan dibatasi sekitar 20 MB dan base64 membengkakkan berkas
sepertiga. Rekaman audio Zoom 30 menit saja sudah 15-30 MB, jadi jalur itu akan
gagal justru pada berkas yang sebenarnya, sementara berkas contoh yang kecil
lolos - kegagalan yang baru muncul di tangan pemakai.

Files API menerima sampai 2 GB dan berkasnya hidup 48 jam di sisi Google. Cukup
lama: transkripsi terjadi beberapa detik setelah unggah.
"""

import logging
import os
import time
from typing import NamedTuple

import httpx
from dotenv import load_dotenv

from chat import GEMINI_BASE_URL, tanpa_kunci, ulangi

try:
    import whisper_lokal
except ImportError:
    # faster-whisper sengaja opsional: pemasangannya ~2 GB, dan instalasi yang
    # belum sempat memasangnya harus tetap jalan lewat Gemini, bukan mati.
    whisper_lokal = None

load_dotenv()

GEMINI_UPLOAD_URL = "https://generativelanguage.googleapis.com/upload/v1beta/files"

# Transkrip 30 menit wawancara bisa 6.000-9.000 kata. Batas keluaran harus jauh
# lebih besar daripada percakapan biasa, kalau tidak transkripnya terpotong di
# tengah kalimat dan penilaian berikutnya membaca wawancara yang seolah berhenti
# mendadak. Pelajaran yang sama dengan MAKS_TOKEN_CV di chat.py.
MAKS_TOKEN_TRANSKRIP = 16384

# Unggah dan transkripsi sama-sama lambat untuk berkas puluhan MB.
TIMEOUT_UNGGAH = 180
TIMEOUT_TRANSKRIP = 300

# Menunggu berkas selesai diproses Gemini. Terukur pada rekaman 8,7 MB: ACTIVE
# dalam ~3 detik. Batas 120 detik memberi ruang lapang untuk berkas puluhan MB
# tanpa membuat pekerjaan yang benar-benar macet menggantung selamanya.
TIMEOUT_SIAP = 120
JEDA_PERIKSA = 2

# Di bawah ini transkrip dianggap tidak layak dinilai. Wawancara 30 menit yang
# menghasilkan kurang dari sekitar dua kalimat berarti audionya rusak, senyap,
# atau salah berkas - dan menilai kandidat dari itu jauh lebih berbahaya
# daripada mengaku gagal.
MIN_KARAKTER = 200

SYSTEM_TRANSKRIP = (
    "Kamu petugas transkripsi wawancara kerja. Tulis ulang isi rekaman menjadi "
    "teks, apa adanya.\n\n"
    "ATURAN:\n"
    "1. Tulis SETIAP ucapan yang terdengar, berurutan sesuai rekaman.\n"
    "2. Tandai pembicara dengan 'Pewawancara:' dan 'Kandidat:' di awal baris. "
    "Bila tidak bisa dibedakan, pakai 'Pembicara 1:' dan 'Pembicara 2:'.\n"
    "3. JANGAN merapikan, meringkas, menyimpulkan, atau memperbaiki tata bahasa "
    "kandidat. Jawaban yang berbelit ditulis berbelit. Isi transkrip inilah "
    "yang dipakai menilai orang, jadi merapikannya berarti menilai orang lain.\n"
    "4. Bagian yang tidak terdengar jelas tulis [tidak jelas]. JANGAN menebak "
    "kata yang tidak terdengar.\n"
    "5. Jangan menambahkan komentar, penilaian, atau kesimpulanmu sendiri.\n"
    "6. Bila rekaman tidak memuat suara orang berbicara sama sekali, jawab "
    "persis: [TIDAK ADA UCAPAN]"
)

# Jawaban persis yang diminta aturan 6. Diperiksa CI4 lewat status 'gagal',
# bukan disimpan sebagai transkrip - kalau tidak, penilaian berikutnya akan
# menilai kandidat berdasarkan kalimat penanda ini.
PENANDA_KOSONG = "[TIDAK ADA UCAPAN]"


class Hasil(NamedTuple):
    teks: str
    # 'selesai' | 'gagal'
    status: str
    catatan: str = ""
    # Mesin yang menghasilkan teksnya, disimpan CI4 di interview_transkrip.
    # Bukan hiasan: keduanya berbeda mutu - yang lokal tidak memberi penanda
    # pembicara - dan tanpa catatan ini tidak ada yang tahu transkrip lama
    # dibuat siapa saat membandingkannya dengan yang baru.
    mesin: str = ""

    @property
    def berhasil(self) -> bool:
        return self.status == "selesai"


def _kunci() -> str:
    return os.environ["GEMINI_API_KEY"]


def _model() -> str:
    return os.environ.get("GENERATION_MODEL", "gemini-2.5-flash")


def _mesin() -> str:
    return f"gemini:{_model()}"


def unggah(data: bytes, mime: str, klien: httpx.Client | None = None) -> str:
    """
    Titipkan berkas ke Files API, kembalikan URI-nya SETELAH siap dipakai.

    Tidak menghitung kuota generateContent - yang dijatah 20 panggilan sehari
    hanyalah pemanggilan modelnya, bukan penitipan berkasnya.
    """
    klien = klien or httpx.Client(timeout=TIMEOUT_UNGGAH)
    resp = klien.post(
        GEMINI_UPLOAD_URL,
        params={"key": _kunci()},
        headers={
            "X-Goog-Upload-Protocol": "raw",
            # Nama berkas sengaja tetap dan tanpa arti. Nama asli dari Zoom
            # memuat nama peserta dan tanggalnya, dan tidak ada gunanya
            # menyerahkan itu ke pihak ketiga hanya untuk mentranskripsi suara.
            "X-Goog-Upload-File-Name": "rekaman",
            "Content-Type": mime,
        },
        content=data,
    )
    resp.raise_for_status()

    berkas = resp.json().get("file") or {}
    uri = berkas.get("uri")
    if not uri:
        raise RuntimeError("Files API tidak mengembalikan uri")

    return _tunggu_siap(uri, berkas.get("state", ""), klien)


def _tunggu_siap(uri: str, state: str, klien: httpx.Client) -> str:
    """
    Tunggu sampai berkasnya berstatus ACTIVE.

    KENAPA HARUS DITUNGGU

    Files API membalas seketika, tapi berkas audio/video masih diproses di
    belakang layar dan berstatus PROCESSING. Memanggil generateContent dengan
    URI yang belum ACTIVE dijawab 400 Bad Request - pesan yang tidak menyebut
    sama sekali bahwa masalahnya cuma soal menunggu.

    TIDAK terlihat saat uji: berkas contoh beberapa ratus byte selesai diproses
    sebelum balasan unggahnya tiba, jadi selalu langsung ACTIVE. Rekaman
    wawancara sungguhan 8,7 MB butuh beberapa detik, dan di situlah ia gagal -
    persis di tangan pemakai, tidak pernah di meja pengembang.
    """
    if state == "ACTIVE":
        return uri

    batas = time.monotonic() + TIMEOUT_SIAP
    while time.monotonic() < batas:
        time.sleep(JEDA_PERIKSA)
        d = klien.get(f"{GEMINI_BASE_URL}/files/{uri.rsplit('/', 1)[-1]}", params={"key": _kunci()})
        d.raise_for_status()
        state = d.json().get("state", "")

        if state == "ACTIVE":
            return uri
        if state == "FAILED":
            raise RuntimeError("Gemini gagal memproses berkasnya (state FAILED)")

    raise RuntimeError(
        f"Berkas belum siap setelah {TIMEOUT_SIAP} detik (state {state or 'tidak diketahui'})"
    )


def _teks_dari_jawaban(d: dict) -> str:
    kandidat = d.get("candidates", [])
    if not kandidat:
        return ""
    bagian = kandidat[0].get("content", {}).get("parts", [])

    return "".join(p.get("text", "") for p in bagian).strip()


def _layak(teks: str, mesin: str) -> Hasil:
    """Teks mentah jadi Hasil, dengan syarat kelayakan yang sama untuk kedua mesin."""
    if PENANDA_KOSONG in teks:
        return Hasil("", "gagal", "Rekaman tidak memuat suara orang berbicara.", mesin)

    if len(teks) < MIN_KARAKTER:
        return Hasil(teks, "gagal",
                     f"Transkrip hanya {len(teks)} karakter (< {MIN_KARAKTER}). "
                     "Audionya kemungkinan rusak, senyap, atau salah berkas.", mesin)

    return Hasil(teks, "selesai", "", mesin)


def _lewat_whisper(data: bytes) -> Hasil | None:
    """None bila faster-whisper memang tidak terpasang - bukan kegagalan."""
    if whisper_lokal is None:
        return None

    try:
        return _layak(whisper_lokal.transkripsikan(data), whisper_lokal.MESIN)
    except Exception as e:
        return Hasil("", "gagal", f"{type(e).__name__}: {e}"[:400], whisper_lokal.MESIN)


def transkripsikan(data: bytes, mime: str, klien: httpx.Client | None = None) -> Hasil:
    """
    Rekaman jadi teks: Whisper lokal dulu, Gemini bila hasilnya tidak layak.

    Tidak pernah melempar exception: kegagalan apa pun jadi status 'gagal'
    beserta sebabnya, supaya recruiter membaca alasannya di layar alih-alih
    menghadapi kolom kosong tanpa keterangan.
    """
    lokal = _lewat_whisper(data)
    if lokal is not None and lokal.berhasil:
        return lokal

    if lokal is not None:
        logging.getLogger("uvicorn.error").warning(
            "whisper lokal gagal, beralih ke Gemini: %s", lokal.catatan)

    hasil = _lewat_gemini(data, mime, klien)
    if hasil.berhasil or lokal is None:
        return hasil

    # Kedua-duanya gagal: sebab keduanya ikut disimpan. Sebab Gemini saja
    # menyembunyikan bahwa yang lokal sudah dicoba lebih dulu, dan orang yang
    # membacanya besok akan mengira jalur lokalnya tidak pernah jalan.
    return hasil._replace(catatan=f"{whisper_lokal.MESIN}: {lokal.catatan} | {hasil.catatan}"[:400])


def _lewat_gemini(data: bytes, mime: str, klien: httpx.Client | None = None) -> Hasil:
    klien = klien or httpx.Client(timeout=TIMEOUT_TRANSKRIP)

    def panggil(uri: str) -> httpx.Response:
        r = klien.post(
            f"{GEMINI_BASE_URL}/models/{_model()}:generateContent",
            params={"key": _kunci()},
            json={
                "system_instruction": {"parts": [{"text": SYSTEM_TRANSKRIP}]},
                "contents": [{"role": "user", "parts": [
                    {"file_data": {"mime_type": mime, "file_uri": uri}},
                    {"text": "Transkripsikan rekaman wawancara ini."},
                ]}],
                "generationConfig": {
                    # 0: menyalin ucapan bukan pekerjaan yang butuh kreativitas,
                    # dan setiap variasi di sini adalah kata yang tidak diucapkan
                    # siapa pun.
                    "temperature": 0,
                    "maxOutputTokens": MAKS_TOKEN_TRANSKRIP,
                },
            },
        )
        r.raise_for_status()

        return r

    try:
        uri = unggah(data, mime, klien)
        # Diulang bila gagalnya sementara. 503 dari model yang sedang kelebihan
        # beban terjadi sungguhan (14 Agustus 2026), dan menyerah pada percobaan
        # pertama berarti recruiter harus mengunggah ulang rekaman 8 MB karena
        # kesibukan sesaat di sisi Google.
        teks = _teks_dari_jawaban(ulangi(lambda: panggil(uri), "transkripsi").json())
    except Exception as e:
        # JANGAN echo str(e) apa adanya: pesan httpx memuat URL Gemini + ?key=
        logging.getLogger("uvicorn.error").error("transkripsi gagal: %s", tanpa_kunci(e))

        return Hasil("", "gagal", f"{type(e).__name__}: {tanpa_kunci(e)}"[:400], _mesin())

    return _layak(teks, _mesin())
