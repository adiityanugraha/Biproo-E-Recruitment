import os
from typing import Protocol

import httpx
from dotenv import load_dotenv

load_dotenv()


class EmbeddingProvider(Protocol):
    """Layer swappable (Blueprint A3.3): ganti provider cukup lewat konfigurasi,
    pipeline tidak berubah. Model hasil training sendiri (A3.4) kelak masuk
    sebagai implementasi baru dari interface ini."""

    def embed(self, texts: list[str]) -> list[list[float]]:
        """Kembalikan satu vektor embedding per teks, urutan sama dengan input."""
        ...


GEMINI_BASE_URL = "https://generativelanguage.googleapis.com/v1beta"


class GeminiEmbeddingProvider:
    def __init__(
        self,
        api_key: str,
        model: str = "gemini-embedding-001",
        client: httpx.Client | None = None,
    ):
        self.api_key = api_key
        self.model = model
        self.client = client or httpx.Client(timeout=30)

    def embed(self, texts: list[str]) -> list[list[float]]:
        resp = self.client.post(
            f"{GEMINI_BASE_URL}/models/{self.model}:batchEmbedContents",
            params={"key": self.api_key},
            json={
                "requests": [
                    {
                        "model": f"models/{self.model}",
                        "content": {"parts": [{"text": t}]},
                    }
                    for t in texts
                ]
            },
        )
        resp.raise_for_status()
        return [e["values"] for e in resp.json()["embeddings"]]


def get_provider() -> EmbeddingProvider:
    # ponytail: satu provider dulu; tambah cabang pilihan saat provider kedua benar-benar dipakai
    return GeminiEmbeddingProvider(
        api_key=os.environ["GEMINI_API_KEY"],
        model=os.environ.get("EMBEDDING_MODEL", "gemini-embedding-001"),
    )
