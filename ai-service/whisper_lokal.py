"""
Transkripsi di komputer sendiri dengan faster-whisper (14 Agustus 2026).

KENAPA ADA

Transkripsi dulunya satu dari empat panggilan Gemini per kandidat, dan
kuota gratisnya 20 panggilan sehari - empat kandidat lalu habis. Ia juga
langkah yang paling sering gagal: 429 kuota, 503 model sibuk, dan 400 karena
berkas belum selesai diproses, ketiganya terjadi sungguhan 14 Agustus 2026.
Di sini tidak ada satu pun dari itu: modelnya jalan di mesin sendiri, tanpa
jaringan, tanpa jatah, dan rekaman wawancaranya tidak ke mana-mana.

YANG HILANG: PENANDA PEMBICARA

Whisper tidak membedakan siapa yang bicara. Keluarannya teks mengalir tanpa
'Pewawancara:' dan 'Kandidat:' - itu pekerjaan diarisasi, pustaka lain lagi.
Yang bisa dilakukan murah: tiap segmen ditulis satu baris. Whisper memotong
segmen di jeda bicara, dan jeda bicara umumnya jatuh di pergantian giliran,
jadi batas gilirannya masih terbaca walau tidak berlabel.

UKURAN MODEL: 'medium', BUKAN 'small'

Diukur pada rekaman wawancara berbahasa Indonesia yang sama (14 Agustus 2026),
CPU i5-11400H int8:

    small  (~460 MB)  4,6x realtime   "Admin Gudang" -> "atming gudang"
    medium (~1,5 GB)  3,5x realtime   "Admin Gudang" benar

'small' merusak justru istilah yang paling penting - nama posisi, nama
perusahaan, 'inbound'. Penilaiannya memang tetap sama karena modelnya bisa
menebak dari konteks, tapi transkrip inilah yang ditunjukkan kepada orang yang
bertanya kenapa seorang kandidat gugur, dan dokumen semacam itu tidak boleh
berbunyi asal.

Selisih waktunya kecil: wawancara 30 menit jadi 8,5 menit dari 6,5 menit, dan
tidak ada yang menunggu di layar - transkripsinya berjalan di latar. Yang
bertambah nyata cuma unduhan pertamanya.

Mesin ber-GPU yang cuDNN dan cuBLAS-nya lengkap tinggal menyetel
WHISPER_DEVICE=cuda. Mesin yang kepayahan bisa turun lagi lewat
WHISPER_MODEL=small, dengan konsekuensi di atas.
"""

import logging
import os
import threading
from io import BytesIO

from dotenv import load_dotenv
from faster_whisper import WhisperModel

load_dotenv()

NAMA_MODEL = os.environ.get("WHISPER_MODEL", "medium")

# cpu/int8 bawaan: jalan di mesin mana pun tanpa pustaka tambahan. cuda/float16
# butuh cublas64_12.dll dan cudnn - WhisperModel tetap terbentuk tanpa keduanya
# dan baru gagal saat menyalin, jadi jangan disetel kalau belum yakin terpasang.
DEVICE = os.environ.get("WHISPER_DEVICE", "cpu")
COMPUTE = os.environ.get("WHISPER_COMPUTE", "int8")

MESIN = f"faster-whisper:{NAMA_MODEL}"

_model: WhisperModel | None = None

# Satu model dipakai bergantian. Antrian transkripsi memang berjalan satu per
# satu, tapi kuncinya tetap ada karena CTranslate2 tidak aman dipanggil dua
# utas sekaligus - dan yang menjaga urutan sekarang cuma kebetulan bentuk
# antriannya, bukan janji apa pun.
_kunci = threading.Lock()


def _muat() -> WhisperModel:
    """Muat sekali, pakai seterusnya. Unduhan pertama ~460 MB dari HuggingFace."""
    global _model
    if _model is None:
        logging.getLogger("uvicorn.error").info(
            "memuat whisper %s (%s/%s)", NAMA_MODEL, DEVICE, COMPUTE
        )
        _model = WhisperModel(NAMA_MODEL, device=DEVICE, compute_type=COMPUTE)

    return _model


def transkripsikan(data: bytes) -> str:
    """
    Rekaman jadi teks, satu baris per segmen.

    Melempar exception bila gagal - penanganannya di transkrip.py, yang lalu
    menjatuhkannya ke Gemini.
    """
    with _kunci:
        segmen, _ = _muat().transcribe(
            BytesIO(data),
            beam_size=5,
            # Membuang bagian senyap. Tanpa ini Whisper mengarang kalimat yang
            # diulang-ulang saat tidak ada yang bicara, dan karangan itu ikut
            # dinilai sebagai jawaban kandidat.
            vad_filter=True,
            # Tiap potongan berdiri sendiri. Menyuapkan hasil sebelumnya membuat
            # satu salah dengar menular ke sisa rekaman.
            condition_on_previous_text=False,
        )

        return "\n".join(s.text.strip() for s in segmen if s.text.strip())
