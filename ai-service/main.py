import asyncio
import logging
import os
from contextlib import asynccontextmanager
from uuid import uuid4

import httpx
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, HttpUrl

import transkrip as trx
from chat import MAKS_TOKEN_CV, get_chat_provider, tanpa_kunci
from embeddings import get_provider
from extract import ekstrak_bytes
from nilai import nilai_dari_transkrip
from ocr import ocr_lengkapi
from sanitize import bersihkan
from scoring import BIDANG, Skor, hitung
from structure import _json_pertama, strukturkan_kontekstual

RETRY_ATTEMPTS = 4
RETRY_BASE_DELAY = float(os.environ.get("RETRY_BASE_DELAY", "2"))

# ponytail: job store in-memory + antrian asyncio, cukup untuk Fase 0-1 single-process;
# pindah ke tabel DB saat butuh persisten/multi-worker
jobs: dict[str, dict] = {}
job_queue: asyncio.Queue | None = None

# CV yang gagal ekstraksi masuk sini, TIDAK dibuang (A3.2: antrian proses ulang).
# Day 2 (fallback OCR) mengonsumsi daftar ini.
retry_extraction: list[str] = []


@asynccontextmanager
async def lifespan(app: FastAPI):
    global job_queue, TRANSKRIP_QUEUE
    job_queue = asyncio.Queue()
    TRANSKRIP_QUEUE = asyncio.Queue()
    # Antrian terpisah, bukan satu antrian bersama: transkripsi rekaman 30 menit
    # jauh lebih lama daripada screening CV, dan menaruh keduanya di satu antrian
    # membuat CV yang baru diunggah menunggu di belakang rekaman.
    pekerja = [asyncio.create_task(process_jobs()), asyncio.create_task(proses_interview_jobs())]
    yield
    for p in pekerja:
        p.cancel()


app = FastAPI(title="E-REQ AI Microservice", version="0.2.0", lifespan=lifespan)


# --- Kontrak API async (Blueprint A3.1) ---

class JobRequirement(BaseModel):
    skill: str
    pendidikan: str
    pengalaman: str
    deskripsi: str


class ScreeningRequest(BaseModel):
    job_id_internal: int
    cv_file_url: HttpUrl
    job_requirement: JobRequirement
    callback_url: HttpUrl
    # Tambahan additive terhadap kontrak A3.1: token bersama dari CI4, dikirim
    # balik sebagai header X-Token saat unduh CV dan saat POST callback.
    callback_token: str | None = None


class ScreeningAccepted(BaseModel):
    screening_job_id: str

# Model callback (Scores, ScreeningCallback) ditulis saat wiring minggu 5;
# kontraknya terdokumentasi di README.


# --- Pemrosesan background (Fase 0 Day 3: embedding terpanggil, ekstraksi CV menyusul Fase 1) ---

async def embed_with_retry(job: dict) -> list[list[float]]:
    provider = getattr(app.state, "provider", None) or get_provider()
    for attempt in range(RETRY_ATTEMPTS):
        job["attempts"] = attempt + 1
        try:
            return await asyncio.to_thread(provider.embed, job["texts"])
        except httpx.HTTPStatusError as e:
            retryable = e.response.status_code == 429 or e.response.status_code >= 500
            if not retryable or attempt == RETRY_ATTEMPTS - 1:
                raise
            await asyncio.sleep(RETRY_BASE_DELAY * 2**attempt)
    raise RuntimeError("unreachable")


def _unduh_cv(url: str, token: str | None) -> bytes:
    """Ambil berkas CV dari CI4. Module-level supaya bisa di-monkeypatch di test."""
    resp = httpx.get(url, headers={"X-Token": token or ""}, timeout=30, follow_redirects=True)
    resp.raise_for_status()

    return resp.content


def _kirim_callback(url: str, token: str | None, body: dict) -> None:
    """POST hasil ke CI4 (kontrak A3.1). Module-level supaya bisa di-monkeypatch."""
    httpx.post(url, json=body, headers={"X-Token": token or ""}, timeout=15).raise_for_status()


async def _callback(job_id: str) -> None:
    """Kabari CI4; kegagalan callback dicatat di job, tidak menjatuhkan worker."""
    job = jobs[job_id]
    body = {
        "screening_job_id": job_id,
        "job_id_internal": job["job_id_internal"],  # echo, supaya CI4 tahu lamaran mana
        "status": "success" if job["status"] == "done" else job["status"],
        "scores": job.get("scores", Skor(None, None, None, None).as_dict()),
        "extracted_fields": job.get("extracted", {}),
        "flags": job.get("flags", []),
    }
    try:
        await asyncio.to_thread(_kirim_callback, job["callback_url"], job.get("token"), body)
        job["callback"] = "sent"
    except Exception as e:
        logging.getLogger("uvicorn.error").error("callback ke CI4 gagal: %s", tanpa_kunci(e))
        job["callback"] = f"failed: {e}"


async def process_jobs():
    while True:
        job_id = await job_queue.get()
        job = jobs[job_id]
        job["status"] = "processing"

        # 1. unduh CV dari CI4; gagal unduh = CV tak terbaca -> failed_extraction
        try:
            data = await asyncio.to_thread(_unduh_cv, job["cv_file_url"], job.get("token"))
        except Exception as e:
            job["status"], job["error"] = "failed_extraction", f"unduh gagal: {e}"
            retry_extraction.append(job_id)
            await _callback(job_id)
            continue

        # 2. ekstraksi layout-aware (A3.2a lapis 1); yang tak terbaca lanjut ke
        #    fallback OCR (lapis 2) - halaman scan di CV mixed ikut terisi
        hasil = await asyncio.to_thread(ekstrak_bytes, data)
        if not hasil.utuh:
            hasil = await asyncio.to_thread(ocr_lengkapi, data, hasil)
        job["extracted"] = {
            "metode": hasil.metode,
            "n_karakter": hasil.n_karakter,
            "n_halaman": hasil.n_halaman,
            "halaman_perlu_ocr": list(hasil.halaman_perlu_ocr),
            "catatan": hasil.catatan,
        }
        if not hasil.berhasil:
            job["status"], job["error"] = "failed_extraction", hasil.catatan
            retry_extraction.append(job_id)
            await _callback(job_id)
            continue

        job["cv_text"] = hasil.teks

        # 3. strukturisasi 3 bidang berbasis konteks (A3.2a), lalu buang atribut
        #    sensitif SEBELUM embedding (fairness-by-design A3.2)
        # Budget keluaran BESAR, bukan default chatbot: strukturisasi menyalin isi
        # CV ke JSON, jadi jawabannya sepanjang CV-nya (lihat MAKS_TOKEN_CV).
        llm = getattr(app.state, "chat_provider", None) or get_chat_provider(MAKS_TOKEN_CV)
        t   = await asyncio.to_thread(strukturkan_kontekstual, hasil.teks, llm)
        cv_bidang = {
            "skill":      bersihkan(t.skill),
            "pengalaman": bersihkan(t.pengalaman),
            "pendidikan": bersihkan(t.pendidikan),
        }
        job["flags"] = list(t.flags)
        job["extracted"]["bidang_karakter"] = {k: len(v) for k, v in cv_bidang.items()}
        # Riwayat kerja ikut ke CI4 untuk DITAMPILKAN ke recruiter, tidak di-embed
        # dan tidak mengubah skor (lihat "Kenapa riwayat ada" di structure.py).
        job["extracted"]["riwayat"] = [dict(r) for r in t.riwayat]
        # Biodata untuk lembar profil kandidat. Perlakuannya sama dengan riwayat:
        # ikut ke CI4 untuk ditampilkan, TIDAK di-embed, tidak menyentuh skor.
        job["extracted"]["data_pribadi"] = dict(t.data_pribadi)

        # 4. embedding CV vs job requirement, lalu cosine + skor agregat berbobot.
        #    Bidang yang salah satu sisinya kosong tidak di-embed (hemat kuota)
        #    dan tidak dinilai - bobotnya dinormalkan ulang di scoring.hitung().
        job_bidang = job["job_requirement"]
        pasangan   = [b for b in BIDANG if cv_bidang[b] and job_bidang[b]]
        job["texts"] = [cv_bidang[b] for b in pasangan] + [job_bidang[b] for b in pasangan]

        if not pasangan:
            job["status"] = "done"
            job["scores"] = Skor(None, None, None, None, ("tidak_dapat_dinilai",)).as_dict()
            job["flags"] += ["tidak_dapat_dinilai"]
            await _callback(job_id)
            continue

        try:
            vectors = await embed_with_retry(job)
        except Exception as e:
            # limit/error provider setelah retry habis → job ditunda, bukan hilang (A3.3)
            job["status"] = "failed_provider"
            job["error"] = str(e)
            await _callback(job_id)
            continue

        n = len(pasangan)
        vek_cv  = {b: vectors[i] for i, b in enumerate(pasangan)}
        vek_job = {b: vectors[n + i] for i, b in enumerate(pasangan)}
        skor    = hitung(vek_cv, vek_job)

        job["status"] = "done"
        job["embedding_dims"] = [len(v) for v in vectors]
        job["scores"] = skor.as_dict()
        job["flags"] += list(skor.flags)

        await _callback(job_id)


# --- Transkripsi + penilaian wawancara (revisi 12 Agustus 2026) ---
#
# Async 202 + callback, sama seperti /screening, dan karena alasan yang sama:
# dua panggilan LLM berurutan atas berkas puluhan MB, jauh melewati batas sabar
# siapa pun yang menunggu di depan layar.
#
# SATU pekerjaan, DUA langkah, hasil TERPISAH. Transkrip disimpan sebagai
# transkrip dan penilaian sebagai penilaian. Menggabungkannya jadi satu
# panggilan memang menghemat satu jatah kuota, tapi transkripnya lalu tidak bisa
# diperiksa terpisah dari skornya - padahal justru itu satu-satunya cara
# membuktikan skornya masuk akal.

TRANSKRIP_QUEUE: asyncio.Queue | None = None


class RiwayatKerja(BaseModel):
    """
    Satu baris riwayat kerja kandidat dari hasil baca CV.

    Bidangnya DIDAFTAR satu per satu, bukan diterima sebagai dict apa adanya,
    dan itu penjaganya: hasil baca CV juga memuat gaji_terakhir dan alasan
    keluar. Keduanya tidak ada urusannya dengan menyusun pertanyaan, dan bidang
    yang tidak disebut di sini tidak akan pernah sampai ke penyedia LLM.
    """

    jabatan: str = ""
    perusahaan: str = ""
    periode: str = ""
    deskripsi: str = ""


class InterviewRequest(BaseModel):
    application_id: int
    # Baris interview_transkrip yang sedang dikerjakan, dikembalikan apa adanya
    # di callback. Tanpa ini CI4 harus menebak barisnya dari application_id, dan
    # satu lamaran bisa punya beberapa rekaman - unggah ulang menambah baris,
    # tidak menimpa. Hasil rekaman lama lalu mendarat di baris rekaman baru.
    transkrip_id: int = 0
    # URL internal CI4 untuk mengunduh rekamannya, dijaga X-Token yang sama
    audio_url: HttpUrl
    mime: str = "audio/mp4"
    # Kompetensi yang dinilai DITENTUKAN CI4. Sumber kebenarannya
    # LembarPenilaian di sisi PHP, yang juga dipakai lembar profil dan Gate 2.
    kompetensi: list[str] = []
    # Riwayat kerja dari hasil baca CV, untuk merangkum kekuatan dan kelemahan.
    # Biodata TIDAK ikut: usia, agama, jenis kelamin tidak boleh menyentuh
    # penilaian. RiwayatKerja mendaftar bidangnya satu per satu, jadi gaji dan
    # alasan keluar juga tidak akan pernah terkirim.
    riwayat: list[RiwayatKerja] = []
    posisi: str = ""
    # Skor kecocokan CV terhadap lowongan, 0.0-1.0. Ikut sejak rekomendasinya
    # diputuskan di sini (permintaan atasan, 14 Agustus 2026): tanpa angka ini
    # kecocokan CV hilang sama sekali dari keputusan - padahal di rumus lama ia
    # 40% bobotnya - dan kandidat dinilai semata dari 30 menit bicara.
    # None = screening belum menghasilkan skor.
    skor_cv: float | None = None
    # Syarat lowongan dan tiga pertanyaan yang benar-benar diajukan (18
    # Agustus 2026). Sebelumnya yang dikirim cuma JUDUL posisi, dan judul
    # tidak menerangkan apa pun tentang pekerjaannya - transkrip operator
    # gudang pun lolos dengan nilai bagus di posisi Security System.
    syarat: dict = {}
    pertanyaan: list[str] = []
    callback_url: HttpUrl
    callback_token: str | None = None


class InterviewAccepted(BaseModel):
    interview_job_id: str


async def _proses_interview(job_id: str) -> None:
    job = jobs[job_id]
    job["status"] = "processing"

    badan = {
        "interview_job_id": job_id,
        "application_id": job["application_id"],
        "transkrip_id": job["transkrip_id"],
        "status": "gagal",
        "teks": "",
        "penilaian": [],
        "kekuatan": "",
        "kelemahan": "",
        "catatan": "",
        "mesin": "",
        # null = model tidak memutuskan; CI4 menyerahkannya ke perekrut.
        "rekomendasi": None,
        "alasan_rekomendasi": "",
        "kecocokan": "",
        "alasan_kecocokan": "",
    }

    try:
        data = await asyncio.to_thread(_unduh_cv, job["audio_url"], job.get("token"))
    except Exception as e:
        badan["catatan"] = f"Rekaman tidak bisa diunduh: {tanpa_kunci(e)}"[:400]
        job["status"] = "gagal"
        await _kirim_hasil_interview(job, badan)

        return

    # Langkah 1: rekaman jadi teks.
    hasil = await asyncio.to_thread(trx.transkripsikan, data, job["mime"])
    badan["teks"] = hasil.teks
    badan["mesin"] = hasil.mesin
    if not hasil.berhasil:
        badan["catatan"] = hasil.catatan
        job["status"] = "gagal"
        await _kirim_hasil_interview(job, badan)

        return

    # Langkah 2: teks jadi penilaian. Transkrip TETAP dikirim walau penilaian
    # gagal - ia hasil yang sudah didapat, dan recruiter masih bisa membacanya
    # lalu menilai sendiri.
    llm = getattr(app.state, "chat_provider", None) or get_chat_provider(MAKS_TOKEN_CV)
    n = await asyncio.to_thread(
        nilai_dari_transkrip, hasil.teks, job["kompetensi"], llm,
        job.get("riwayat", []), job.get("posisi", ""), job.get("skor_cv"),
        job.get("syarat", {}), job.get("pertanyaan", []),
    )

    badan["penilaian"] = [
        {"kompetensi": b.kompetensi, "nilai": b.nilai, "alasan": b.alasan} for b in n.butir
    ]
    badan["kekuatan"] = n.kekuatan
    badan["kelemahan"] = n.kelemahan
    badan["rekomendasi"] = n.rekomendasi
    badan["alasan_rekomendasi"] = n.alasan_rekomendasi
    badan["kecocokan"] = n.kecocokan
    badan["alasan_kecocokan"] = n.alasan_kecocokan
    badan["status"] = "selesai" if n.berhasil else "gagal"
    badan["catatan"] = n.catatan
    job["status"] = badan["status"]

    await _kirim_hasil_interview(job, badan)


async def _kirim_hasil_interview(job: dict, badan: dict) -> None:
    try:
        await asyncio.to_thread(_kirim_callback, job["callback_url"], job.get("token"), badan)
        job["callback"] = "sent"
    except Exception as e:
        logging.getLogger("uvicorn.error").error("callback interview gagal: %s", tanpa_kunci(e))
        job["callback"] = f"failed: {tanpa_kunci(e)}"


async def proses_interview_jobs():
    while True:
        job_id = await TRANSKRIP_QUEUE.get()
        try:
            await _proses_interview(job_id)
        except Exception as e:
            # Antrian TIDAK boleh mati karena satu pekerjaan yang meledak: sekali
            # worker-nya tumbang, semua rekaman berikutnya menggantung di 'antre'
            # selamanya tanpa ada yang tahu.
            logging.getLogger("uvicorn.error").error("job interview tumbang: %s", tanpa_kunci(e))
            jobs[job_id]["status"] = "gagal"


# --- Endpoint ---

@app.post("/interview", status_code=202, response_model=InterviewAccepted)
def create_interview(req: InterviewRequest) -> InterviewAccepted:
    if not req.kompetensi:
        raise HTTPException(400, "daftar kompetensi kosong")

    job_id = uuid4().hex
    jobs[job_id] = {
        "status": "queued",
        "application_id": req.application_id,
        "transkrip_id": req.transkrip_id,
        "audio_url": str(req.audio_url),
        "mime": req.mime,
        "kompetensi": list(req.kompetensi),
        "riwayat": [r.model_dump() for r in req.riwayat],
        "posisi": req.posisi,
        "skor_cv": req.skor_cv,
        "syarat": dict(req.syarat),
        "pertanyaan": list(req.pertanyaan),
        "callback_url": str(req.callback_url),
        "token": req.callback_token,
    }
    TRANSKRIP_QUEUE.put_nowait(job_id)

    return InterviewAccepted(interview_job_id=job_id)

@app.post("/screening", status_code=202, response_model=ScreeningAccepted)
def create_screening(req: ScreeningRequest) -> ScreeningAccepted:
    job_id = uuid4().hex
    r = req.job_requirement
    jobs[job_id] = {
        "status": "queued",
        "job_id_internal": req.job_id_internal,
        "cv_file_url": str(req.cv_file_url),
        "callback_url": str(req.callback_url),
        "token": req.callback_token,
        # sisi lowongan: deskripsi digabung ke pengalaman karena di situlah uraian
        # tanggung jawab berada, sehingga dibandingkan dengan bidang yang setara
        "job_requirement": {
            "skill": r.skill.strip(),
            "pengalaman": f"{r.pengalaman}\n{r.deskripsi}".strip(),
            "pendidikan": r.pendidikan.strip(),
        },
        "texts": [],  # diisi setelah strukturisasi CV (pasangan bidang yang ada)
        "attempts": 0,
    }
    job_queue.put_nowait(job_id)
    return ScreeningAccepted(screening_job_id=job_id)


@app.get("/screening/{job_id}")
def get_screening(job_id: str) -> dict:
    """Debug/monitoring. CI4 tidak lagi polling ke sini - hasil dikirim via callback.
    Whitelist: token, teks CV, dan URL callback tidak boleh ikut keluar."""
    if job_id not in jobs:
        raise HTTPException(404, "screening_job_id tidak dikenal")
    job = jobs[job_id]
    return {k: job[k] for k in ("status", "attempts", "embedding_dims", "error", "extracted", "callback") if k in job}


# --- Chatbot status kandidat (Fase 3 Day 3) ---
# Sinkron (bukan 202/callback): user menunggu jawaban. Grounding ketat - LLM
# hanya boleh menjawab dari konteks status yang dikirim CI4, tidak mengarang.

class ChatTurn(BaseModel):
    role: str  # 'user' | 'model'
    text: str


class ChatRequest(BaseModel):
    question: str
    context: str  # data status kandidat, dirakit CI4 dari candidate_stage_history
    history: list[ChatTurn] = []


class ChatReply(BaseModel):
    answer: str


SYSTEM_TEMPLATE = (
    "Kamu asisten status lamaran E-REQ BIPROO. Jawab HANYA berdasarkan DATA STATUS "
    "kandidat di bawah, dalam Bahasa Indonesia yang ramah dan ringkas. Bila pertanyaan "
    "tidak bisa dijawab dari data itu (di luar topik lamaran, atau menanyakan data "
    "kandidat lain), tolak dengan sopan dan sarankan menghubungi tim rekrutmen. Jangan "
    "mengarang tahap, tanggal, skor, atau keputusan yang tidak ada di data.\n\n"
    "=== DATA STATUS KANDIDAT ===\n{context}\n=== AKHIR DATA ==="
)


@app.post("/chat", response_model=ChatReply)
def chat(req: ChatRequest) -> ChatReply:
    if not req.question.strip():
        raise HTTPException(400, "pertanyaan kosong")

    provider = getattr(app.state, "chat_provider", None) or get_chat_provider()
    system = SYSTEM_TEMPLATE.format(context=req.context)
    history = [{"role": t.role, "text": t.text} for t in req.history]

    try:
        answer = provider.generate(system, history, req.question)
    except Exception as e:
        # JANGAN echo str(e): pesan httpx memuat URL Gemini + ?key=API_KEY.
        # Log sisi server, balas detail generik. CI4 tampilkan pesan ramah.
        logging.getLogger("uvicorn.error").error("chat LLM gagal: %s", tanpa_kunci(e))
        raise HTTPException(502, "LLM gagal")

    return ChatReply(answer=answer)


# --- Pertanyaan interview (arahan atasan 4 Agustus 2026, direvisi 12 Agustus) ---
# Sinkron seperti /chat: recruiter menunggu hasilnya di layar.
#
# Semula dibuat PER LOWONGAN demi kuota: tier gratis cuma memberi 20 panggilan
# generateContent per hari dan screening CV sudah memakai 1-2 per CV. Revisi 12
# Agustus meminta pertanyaan disusun dari pengalaman kandidat sendiri, jadi
# mau tidak mau per KANDIDAT. Kuotanya memang jadi lebih sempit (sekitar empat
# kandidat sehari); yang menahan pemborosan sekarang bukan lagi bentuk endpoint
# ini, melainkan CI4 yang menyimpan hasilnya di applications.pertanyaan_json dan
# tidak memanggil ulang kecuali recruiter meminta.
#
# Endpoint ini tetap melayani pemakaian lama tanpa riwayat kandidat (bank soal
# per lowongan), yang kini berperan sebagai cadangan saat kuota habis.

MAKS_PERTANYAAN = 12

# Tepat tiga: satu menggali pengalaman nyata, satu berupa kasus, satu menguji
# kesiapan posisi. Wawancara 30 menit tidak muat lebih dari itu kalau tiap
# jawaban memang digali sampai dalam.
JUMLAH_PERTANYAAN = 3

# Riwayat kerja yang ikut dikirim dibatasi. Bukan cuma soal token: daftar
# panjang menenggelamkan pekerjaan yang paling relevan di antara pekerjaan
# sepuluh tahun lalu, dan pertanyaannya jadi menyasar yang tidak penting.
MAKS_RIWAYAT = 4
MAKS_PANJANG_DESKRIPSI = 300

SUMBER_PENGALAMAN = "pengalaman"
SUMBER_POSISI = "posisi"

SYSTEM_PERTANYAAN = (
    "Kamu perekrut senior di perusahaan retail gadget Indonesia. Susun daftar "
    "pertanyaan wawancara untuk SATU kandidat pada SATU posisi.\n\n"
    "ATURAN:\n"
    "1. Pertanyaan harus SPESIFIK, bukan pertanyaan umum yang cocok untuk semua "
    'orang ("Apa kelebihan Anda?" dilarang).\n'
    "2. Bila RIWAYAT KERJA KANDIDAT diberikan, pertanyaan pertama WAJIB menggali "
    "salah satu pekerjaan nyata di riwayat itu dan menyebut jabatan atau nama "
    "perusahaannya.\n"
    "3. Bila riwayat kerjanya TIDAK diberikan, susun seluruh pertanyaan dari "
    "uraian lowongan, dan JANGAN mengandaikan kandidat pernah memegang posisi "
    'itu. Bentuk seperti "di posisi X Anda sebelumnya" dilarang; kandidat tanpa '
    "riwayat kerja tidak bisa menjawabnya, dan pertanyaan yang mustahil dijawab "
    "menghukum kandidat atas kesalahan penyusunnya.\n"
    "4. Tiap pertanyaan harus menuntut jawaban bercerita, bukan ya/tidak, dan "
    "memancing kandidat menjelaskan situasinya (apa, kapan, di mana), perannya "
    "sendiri (siapa), alasan tindakannya (kenapa), serta langkah yang ditempuh "
    "(bagaimana).\n"
    "5. Susunannya berurutan: pertanyaan pertama menggali pengalaman nyata, "
    "kedua berupa kasus yang mungkin terjadi di posisi yang dilamar, ketiga "
    "menguji kesiapan kandidat untuk posisi itu.\n"
    "6. Bahasa Indonesia, sopan, satu kalimat per pertanyaan.\n"
    "7. JANGAN menanyakan usia, agama, suku, status pernikahan, rencana punya "
    "anak, kondisi kesehatan, atau hal pribadi lain yang tidak berkaitan dengan "
    "pekerjaan. Ini larangan keras.\n"
    "8. Jawab HANYA JSON: {\"pertanyaan\": [\"...\", \"...\"]}"
)


class PertanyaanRequest(BaseModel):
    judul: str
    skill: str = ""
    pendidikan: str = ""
    pengalaman: str = ""
    deskripsi: str = ""
    jumlah: int = JUMLAH_PERTANYAAN
    # Riwayat kerja kandidat. Kosong = pertanyaan disusun dari lowongan saja,
    # yang juga terjadi pada kandidat fresh graduate.
    riwayat: list[RiwayatKerja] = []


class PertanyaanReply(BaseModel):
    pertanyaan: list[str]
    # 'pengalaman' | 'posisi' - dari mana pertanyaannya disusun. Ditentukan DI
    # SINI dari ada tidaknya riwayat, bukan ditanyakan ke LLM: ini fakta tentang
    # masukan, bukan penilaian, dan recruiter berhak tahu tanpa harus percaya
    # pada laporan model.
    sumber: str = SUMBER_POSISI


def _blok_riwayat(riwayat: list[RiwayatKerja]) -> str:
    """Riwayat kerja jadi daftar ringkas untuk prompt. '' bila tak satu pun layak."""
    baris = []
    for r in riwayat[:MAKS_RIWAYAT]:
        judul = " di ".join(x for x in (r.jabatan.strip(), r.perusahaan.strip()) if x)
        if not judul:
            continue   # entri tanpa jabatan DAN tanpa perusahaan tidak bisa ditanyakan
        periode = r.periode.strip()
        teks = f"- {judul}" + (f" ({periode})" if periode else "")
        deskripsi = r.deskripsi.strip()[:MAKS_PANJANG_DESKRIPSI]
        baris.append(f"{teks}: {deskripsi}" if deskripsi else teks)

    return "\n".join(baris)


@app.post("/pertanyaan", response_model=PertanyaanReply)
def pertanyaan(req: PertanyaanRequest) -> PertanyaanReply:
    if not req.judul.strip():
        raise HTTPException(400, "judul lowongan kosong")

    jumlah = max(JUMLAH_PERTANYAAN, min(MAKS_PERTANYAAN, req.jumlah))
    blok = _blok_riwayat(req.riwayat)
    sumber = SUMBER_PENGALAMAN if blok else SUMBER_POSISI

    uraian = (
        f"Posisi: {req.judul}\n"
        f"Keahlian yang dicari: {req.skill}\n"
        f"Pendidikan minimal: {req.pendidikan}\n"
        f"Pengalaman yang diminta: {req.pengalaman}\n"
        f"Uraian pekerjaan: {req.deskripsi}\n"
    )
    # Ketiadaan riwayat dinyatakan TERANG-TERANGAN, bukan dengan menghilangkan
    # bagiannya. Percobaan sungguhan 12 Agustus: dengan bagian riwayat sekadar
    # dihapus, LLM tetap bertanya "di posisi Admin Gudang Anda sebelumnya" pada
    # kandidat tanpa riwayat kerja - larangan di aturan sistem tidak menolongnya.
    # Wajar: yang dibacanya hanya "Pengalaman yang diminta: 1 tahun", dan bagian
    # yang absen tidak mengatakan apa-apa. Kalimat eksplisit di sini menang atas
    # aturan mana pun di prompt sistem.
    if blok:
        uraian += f"\nRIWAYAT KERJA KANDIDAT:\n{blok}\n"
    else:
        uraian += (
            "\nRIWAYAT KERJA KANDIDAT: TIDAK ADA. CV kandidat tidak mencantumkan "
            "satu pun pekerjaan, jadi anggap ia belum pernah bekerja. Pertanyaan "
            "TIDAK BOLEH mengandaikan ia punya pengalaman kerja atau pernah "
            "memegang posisi ini.\n"
        )
    uraian += f"\nBuat tepat {jumlah} pertanyaan."

    provider = getattr(app.state, "chat_provider", None) or get_chat_provider()
    try:
        jawab = provider.generate(SYSTEM_PERTANYAAN, [], uraian)
    except Exception as e:
        # JANGAN echo str(e): pesan httpx memuat URL Gemini + ?key=API_KEY
        logging.getLogger("uvicorn.error").error("pertanyaan LLM gagal: %s", tanpa_kunci(e))
        raise HTTPException(502, "LLM gagal")

    d = _json_pertama(jawab)
    daftar = d.get("pertanyaan") if isinstance(d, dict) else None
    if not isinstance(daftar, list):
        raise HTTPException(502, "jawaban LLM tidak bisa dibaca")

    bersih = [t for t in (str(x).strip() for x in daftar if isinstance(x, (str, int, float))) if t]
    if not bersih:
        raise HTTPException(502, "LLM tidak menghasilkan pertanyaan")

    return PertanyaanReply(pertanyaan=bersih[:jumlah], sumber=sumber)


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}
