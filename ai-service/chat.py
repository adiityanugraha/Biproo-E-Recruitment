import os
from typing import Protocol

import httpx
from dotenv import load_dotenv

load_dotenv()

GEMINI_BASE_URL = "https://generativelanguage.googleapis.com/v1beta"

# ponytail: jawaban cadangan bila LLM tidak mengembalikan teks (mis. diblokir
# safety filter) - lebih baik pesan sopan daripada string kosong
_FALLBACK = "Maaf, saya belum bisa menjawab itu sekarang. Coba tanyakan hal lain seputar status lamaran Anda."


class ChatProvider(Protocol):
    """Layer swappable (sama seperti EmbeddingProvider): ganti provider LLM
    cukup lewat konfigurasi, endpoint /chat tidak berubah."""

    def generate(self, system: str, history: list[dict], question: str) -> str:
        """Balas satu jawaban teks. history: [{'role': 'user'|'model', 'text': str}]."""
        ...


class GeminiChatProvider:
    def __init__(
        self,
        api_key: str,
        model: str = "gemini-2.5-flash",
        client: httpx.Client | None = None,
    ):
        self.api_key = api_key
        self.model = model
        self.client = client or httpx.Client(timeout=30)

    def generate(self, system: str, history: list[dict], question: str) -> str:
        contents = [{"role": h["role"], "parts": [{"text": h["text"]}]} for h in history]
        contents.append({"role": "user", "parts": [{"text": question}]})

        resp = self.client.post(
            f"{GEMINI_BASE_URL}/models/{self.model}:generateContent",
            params={"key": self.api_key},
            json={
                "system_instruction": {"parts": [{"text": system}]},
                "contents": contents,
                "generationConfig": {
                    # suhu rendah: jawaban patuh ke data status, bukan mengarang
                    "temperature": 0.2,
                    "maxOutputTokens": 600,
                    # matikan "thinking" 2.5-flash: chatbot lookup status tak butuh
                    # reasoning panjang; cegah budget output habis dipakai thinking
                    # (jawaban terpotong/kosong) + pangkas latency & biaya
                    "thinkingConfig": {"thinkingBudget": 0},
                },
            },
        )
        resp.raise_for_status()

        candidates = resp.json().get("candidates", [])
        if not candidates:
            return _FALLBACK
        parts = candidates[0].get("content", {}).get("parts", [])
        text = "".join(p.get("text", "") for p in parts).strip()
        return text or _FALLBACK


def get_chat_provider() -> ChatProvider:
    # ponytail: satu provider dulu; tambah cabang saat provider LLM kedua benar dipakai
    return GeminiChatProvider(
        api_key=os.environ["GEMINI_API_KEY"],
        model=os.environ.get("GENERATION_MODEL", "gemini-2.5-flash"),
    )
