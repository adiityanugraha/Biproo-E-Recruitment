import asyncio
import logging
import os
from contextlib import asynccontextmanager
from uuid import uuid4

import httpx
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, HttpUrl

from chat import MAKS_TOKEN_CV, get_chat_provider, tanpa_kunci
from embeddings import get_provider
from extract import ekstrak_bytes
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
    global job_queue
    job_queue = asyncio.Queue()
    worker = asyncio.create_task(process_jobs())
    yield
    worker.cancel()


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


# --- Endpoint ---

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


# --- Pertanyaan interview per lowongan (arahan atasan 4 Agustus 2026) ---
# Sinkron seperti /chat: recruiter menunggu hasilnya di layar.
#
# Dibuat PER LOWONGAN, bukan per kandidat. Tier gratis cuma memberi 20 panggilan
# generateContent per hari dan screening CV sudah memakai 1-2 per CV; per
# kandidat akan menghabiskannya dalam sehari. CI4 menyimpan hasilnya di
# jobs.pertanyaan_json dan tidak memanggil ulang kecuali diminta recruiter.

MAKS_PERTANYAAN = 12

SYSTEM_PERTANYAAN = (
    "Kamu perekrut senior di perusahaan retail gadget Indonesia. Susun daftar "
    "pertanyaan wawancara untuk SATU posisi, berdasarkan uraian lowongan yang "
    "diberikan.\n\n"
    "ATURAN:\n"
    "1. Pertanyaan harus SPESIFIK untuk posisi itu, bukan pertanyaan umum yang "
    'cocok untuk semua pekerjaan ("Apa kelebihan Anda?" dilarang).\n'
    "2. Gali pengalaman nyata dan cara kerja, bukan definisi. Utamakan bentuk "
    '"Ceritakan saat Anda ..." dan "Bagaimana Anda menangani ...".\n'
    "3. Bahasa Indonesia, sopan, satu kalimat per pertanyaan.\n"
    "4. JANGAN menanyakan usia, agama, suku, status pernikahan, rencana punya "
    "anak, kondisi kesehatan, atau hal pribadi lain yang tidak berkaitan dengan "
    "pekerjaan. Ini larangan keras.\n"
    "5. Jawab HANYA JSON: {\"pertanyaan\": [\"...\", \"...\"]}"
)


class PertanyaanRequest(BaseModel):
    judul: str
    skill: str = ""
    pendidikan: str = ""
    pengalaman: str = ""
    deskripsi: str = ""
    jumlah: int = 8


class PertanyaanReply(BaseModel):
    pertanyaan: list[str]


@app.post("/pertanyaan", response_model=PertanyaanReply)
def pertanyaan(req: PertanyaanRequest) -> PertanyaanReply:
    if not req.judul.strip():
        raise HTTPException(400, "judul lowongan kosong")

    jumlah = max(3, min(MAKS_PERTANYAAN, req.jumlah))
    uraian = (
        f"Posisi: {req.judul}\n"
        f"Keahlian yang dicari: {req.skill}\n"
        f"Pendidikan minimal: {req.pendidikan}\n"
        f"Pengalaman yang diminta: {req.pengalaman}\n"
        f"Uraian pekerjaan: {req.deskripsi}\n\n"
        f"Buat tepat {jumlah} pertanyaan."
    )

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

    return PertanyaanReply(pertanyaan=bersih[:MAKS_PERTANYAAN])


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}
