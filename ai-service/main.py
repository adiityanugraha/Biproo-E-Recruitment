import asyncio
import os
from contextlib import asynccontextmanager
from uuid import uuid4

import httpx
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, HttpUrl

from chat import get_chat_provider
from embeddings import get_provider

RETRY_ATTEMPTS = 4
RETRY_BASE_DELAY = float(os.environ.get("RETRY_BASE_DELAY", "2"))

# ponytail: job store in-memory + antrian asyncio, cukup untuk Fase 0-1 single-process;
# pindah ke tabel DB saat butuh persisten/multi-worker
jobs: dict[str, dict] = {}
job_queue: asyncio.Queue | None = None


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


async def process_jobs():
    while True:
        job_id = await job_queue.get()
        job = jobs[job_id]
        job["status"] = "processing"
        try:
            vectors = await embed_with_retry(job)
            job["status"] = "done"
            job["embedding_dims"] = [len(v) for v in vectors]
        except Exception as e:
            # limit/error provider setelah retry habis → job ditunda, bukan hilang (A3.3)
            job["status"] = "failed_provider"
            job["error"] = str(e)


# --- Endpoint ---

@app.post("/screening", status_code=202, response_model=ScreeningAccepted)
def create_screening(req: ScreeningRequest) -> ScreeningAccepted:
    job_id = uuid4().hex
    r = req.job_requirement
    jobs[job_id] = {
        "status": "queued",
        # dummy CV: yang di-embed dulu 3 field job requirement; teks CV asli menyusul di Fase 1
        "texts": [r.skill, r.pengalaman, r.pendidikan],
        "attempts": 0,
    }
    job_queue.put_nowait(job_id)
    return ScreeningAccepted(screening_job_id=job_id)


@app.get("/screening/{job_id}")
def get_screening(job_id: str) -> dict:
    if job_id not in jobs:
        raise HTTPException(404, "screening_job_id tidak dikenal")
    job = jobs[job_id]
    return {k: v for k, v in job.items() if k != "texts"}


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
        # LLM/provider gagal setelah dipanggil -> 502, CI4 tampilkan pesan ramah
        raise HTTPException(502, f"LLM gagal: {e}")

    return ChatReply(answer=answer)


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}
