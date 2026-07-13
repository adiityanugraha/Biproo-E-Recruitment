from typing import Literal
from uuid import uuid4

from fastapi import FastAPI
from pydantic import BaseModel, HttpUrl

app = FastAPI(title="E-REQ AI Microservice", version="0.1.0")


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


class Scores(BaseModel):
    overall: float
    skill: float
    pendidikan: float
    pengalaman: float


class ScreeningCallback(BaseModel):
    """Payload yang FastAPI POST-kan ke callback_url milik CI4 (dipakai mulai Fase 1)."""
    screening_job_id: str
    status: Literal["success", "failed_extraction", "failed_provider"]
    scores: Scores | None = None
    extracted_fields: dict | None = None
    flags: list[str] = []


# --- Endpoint ---

@app.post("/screening", status_code=202, response_model=ScreeningAccepted)
def create_screening(req: ScreeningRequest) -> ScreeningAccepted:
    # ponytail: skeleton Fase 0 — balas 202 + job_id dummy, pipeline asli dibangun di Fase 1
    return ScreeningAccepted(screening_job_id=uuid4().hex)


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}
