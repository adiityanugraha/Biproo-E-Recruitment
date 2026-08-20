"""Bentuk permintaan ke Gemini.

Yang diuji di sini bukan jawaban modelnya, melainkan apa yang kita minta -
bagian yang tidak terlihat di mana pun sampai ia salah.
"""

from chat import MAKS_TOKEN_CV, GeminiChatProvider


class _Balasan:
    def raise_for_status(self):
        pass

    def json(self):
        return {"candidates": [{"content": {"parts": [{"text": '{"ok": true}'}]}}]}


class _Klien:
    def __init__(self):
        self.badan = None

    def post(self, url, params=None, json=None):
        self.badan = json

        return _Balasan()


def _kirim(**kw) -> dict:
    k = _Klien()
    GeminiChatProvider("kunci-uji", client=k, **kw).generate("sistem", [], "tanya")

    return k.badan["generationConfig"]


def test_permintaan_json_memakai_mode_json_gemini():
    """20 Agustus 2026: 3 dari 20 panggilan penilaian nyata gagal dengan
    'jawaban LLM tidak bisa dibaca'. Bukan kuota, bukan jatah token - JSON-nya
    memang tidak sah, dan permintaan yang sama persis berhasil bila diulang.
    Yang menutupnya fitur bawaan Gemini, bukan penambal JSON buatan sendiri."""
    konfigurasi = _kirim(maks_token=MAKS_TOKEN_CV, minta_json=True)

    assert konfigurasi["responseMimeType"] == "application/json"


def test_chatbot_tetap_menjawab_prosa():
    """Bawaannya mati. Chatbot status menjawab kalimat untuk dibaca kandidat,
    dan memaksanya jadi JSON akan menampilkan tanda kurung kurawal di layar."""
    konfigurasi = _kirim()

    assert "responseMimeType" not in konfigurasi


def test_setelan_lama_tidak_ikut_hilang():
    """Pindah ke _konfigurasi() gampang menjatuhkan salah satu setelan lama;
    thinkingBudget yang hilang membuat jawaban terpotong lagi."""
    konfigurasi = _kirim(minta_json=True)

    assert konfigurasi["temperature"] == 0.2
    assert konfigurasi["thinkingConfig"] == {"thinkingBudget": 0}
    assert konfigurasi["maxOutputTokens"] > 0
