"""
Transkripsi rekaman wawancara (revisi 12 Agustus 2026).

Recruiter merekam wawancara Zoom ke komputernya lalu mengunggah berkasnya; di
sini rekaman itu diubah jadi teks, dan teks itulah yang kelak dinilai.

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
from typing import NamedTuple

import httpx
from dotenv import load_dotenv

from chat import GEMINI_BASE_URL, tanpa_kunci

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

    @property
    def berhasil(self) -> bool:
        return self.status == "selesai"


def _kunci() -> str:
    return os.environ["GEMINI_API_KEY"]


def _model() -> str:
    return os.environ.get("GENERATION_MODEL", "gemini-2.5-flash")


def unggah(data: bytes, mime: str, klien: httpx.Client | None = None) -> str:
    """
    Titipkan berkas ke Files API, kembalikan URI-nya.

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

    uri = (resp.json().get("file") or {}).get("uri")
    if not uri:
        raise RuntimeError("Files API tidak mengembalikan uri")

    return uri


def _teks_dari_jawaban(d: dict) -> str:
    kandidat = d.get("candidates", [])
    if not kandidat:
        return ""
    bagian = kandidat[0].get("content", {}).get("parts", [])

    return "".join(p.get("text", "") for p in bagian).strip()


def transkripsikan(data: bytes, mime: str, klien: httpx.Client | None = None) -> Hasil:
    """
    Rekaman jadi teks.

    Tidak pernah melempar exception: kegagalan apa pun jadi status 'gagal'
    beserta sebabnya, supaya recruiter membaca alasannya di layar alih-alih
    menghadapi kolom kosong tanpa keterangan.
    """
    klien = klien or httpx.Client(timeout=TIMEOUT_TRANSKRIP)
    try:
        uri = unggah(data, mime, klien)
        resp = klien.post(
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
        resp.raise_for_status()
        teks = _teks_dari_jawaban(resp.json())
    except Exception as e:
        # JANGAN echo str(e) apa adanya: pesan httpx memuat URL Gemini + ?key=
        logging.getLogger("uvicorn.error").error("transkripsi gagal: %s", tanpa_kunci(e))

        return Hasil("", "gagal", f"{type(e).__name__}: {tanpa_kunci(e)}"[:400])

    if PENANDA_KOSONG in teks:
        return Hasil("", "gagal", "Rekaman tidak memuat suara orang berbicara.")

    if len(teks) < MIN_KARAKTER:
        return Hasil(teks, "gagal",
                     f"Transkrip hanya {len(teks)} karakter (< {MIN_KARAKTER}). "
                     "Audionya kemungkinan rusak, senyap, atau salah berkas.")

    return Hasil(teks, "selesai")
