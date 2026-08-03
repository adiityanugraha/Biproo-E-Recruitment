import os
import re
from typing import Protocol

import httpx
from dotenv import load_dotenv

load_dotenv()

GEMINI_BASE_URL = "https://generativelanguage.googleapis.com/v1beta"


def tanpa_kunci(pesan: object) -> str:
    """
    Buang API key dari pesan sebelum masuk log.

    httpx menyertakan URL lengkap di pesan errornya, dan URL Gemini membawa
    ?key=<API KEY>. Meneruskannya mentah ke log berarti menulis kredensial ke
    berkas - pernah terjadi di uvicorn.log. Setiap logging exception yang
    menyentuh panggilan API WAJIB lewat sini.
    """
    return re.sub(r"key=[^&\s'\"\)]+", "key=***", str(pesan))

# ponytail: jawaban cadangan bila LLM tidak mengembalikan teks (mis. diblokir
# safety filter) - lebih baik pesan sopan daripada string kosong
_FALLBACK = "Maaf, saya belum bisa menjawab itu sekarang. Coba tanyakan hal lain seputar status lamaran Anda."


class ChatProvider(Protocol):
    """Layer swappable (sama seperti EmbeddingProvider): ganti provider LLM
    cukup lewat konfigurasi, endpoint /chat tidak berubah."""

    def generate(self, system: str, history: list[dict], question: str) -> str:
        """Balas satu jawaban teks. history: [{'role': 'user'|'model', 'text': str}]."""
        ...


# Chatbot status: jawabannya beberapa kalimat, 600 sudah lebih dari cukup dan
# menjaga latency. JANGAN dipakai untuk strukturisasi CV - lihat MAKS_TOKEN_CV.
MAKS_TOKEN_CHAT = 600

# Strukturisasi CV menyalin ulang isi dokumen ke dalam JSON, jadi keluarannya
# sebanding dengan panjang CV (sampai MAX_TEKS_LLM = 12.000 karakter). Dengan
# batas 600 jawabannya terpotong di tengah JSON, parser gagal, dan pipeline diam
# diam jatuh ke parser heading yang jauh lebih buruk. Terlihat pada CV asli
# 4.893 karakter: jawaban putus persis di tengah array riwayat.
MAKS_TOKEN_CV = 8192


class GeminiChatProvider:
    def __init__(
        self,
        api_key: str,
        model: str = "gemini-2.5-flash",
        client: httpx.Client | None = None,
        maks_token: int = MAKS_TOKEN_CHAT,
    ):
        self.api_key = api_key
        self.model = model
        self.client = client or httpx.Client(timeout=30)
        self.maks_token = maks_token

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
                    "maxOutputTokens": self.maks_token,
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


def get_chat_provider(maks_token: int = MAKS_TOKEN_CHAT) -> ChatProvider:
    # ponytail: satu provider dulu; tambah cabang saat provider LLM kedua benar dipakai
    return GeminiChatProvider(
        api_key=os.environ["GEMINI_API_KEY"],
        model=os.environ.get("GENERATION_MODEL", "gemini-2.5-flash"),
        maks_token=maks_token,
    )
