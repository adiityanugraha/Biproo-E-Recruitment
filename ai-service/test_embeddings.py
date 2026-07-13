import os

import httpx
import pytest

from embeddings import GeminiEmbeddingProvider, get_provider


def test_embed_builds_request_and_parses_response():
    def handler(request: httpx.Request) -> httpx.Response:
        assert "batchEmbedContents" in str(request.url)
        assert "key=dummy" in str(request.url)
        return httpx.Response(
            200,
            json={"embeddings": [{"values": [0.1, 0.2]}, {"values": [0.3, 0.4]}]},
        )

    provider = GeminiEmbeddingProvider(
        api_key="dummy", client=httpx.Client(transport=httpx.MockTransport(handler))
    )
    assert provider.embed(["teks a", "teks b"]) == [[0.1, 0.2], [0.3, 0.4]]


@pytest.mark.skipif("GEMINI_API_KEY" not in os.environ, reason="butuh GEMINI_API_KEY")
def test_embed_live_smoke():
    # Tugas Day 2 no.3: kirim teks contoh ke API asli, pastikan response terparse
    vecs = get_provider().embed(
        ["Berpengalaman mengelola tim penjualan selama 3 tahun"]
    )
    assert len(vecs) == 1
    assert len(vecs[0]) > 100  # vektor embedding nyata, bukan respons kosong
