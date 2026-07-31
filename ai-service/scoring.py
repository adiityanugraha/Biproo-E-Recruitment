"""
Skor kecocokan CV terhadap job requirement (Blueprint A3.2, Fase 4 Day 3).

Kemiripan tiap bidang = cosine similarity antara embedding bidang CV dan
embedding bidang padanan di lowongan. Skor akhir = rata-rata BERBOBOT.

Bobot default A3.2: skill 50%, pengalaman 30%, pendidikan 20%.

Skor bersifat ABSOLUT (kemiripan makna), bukan persentil dalam kolam pelamar.
Ini pembeda sadar dari pipeline tim DS: persentil berubah kalau pelamar lain
berubah, sehingga tidak bisa dibandingkan dengan ambang gate yang tetap.

Bidang yang KOSONG tidak dinilai 0 - bobotnya dipindahkan ke bidang yang ada
lalu dinormalkan ulang, dan kekosongannya di-flag untuk review recruiter.
Menilai 0 karena datanya tak terbaca adalah pola bug "umur nan" tim DS yang
menggugurkan 1.888 kandidat (terverifikasi, docs/pipeline-screening-cv.md);
di sini itu tidak diulang.
"""

import math
from typing import NamedTuple

BOBOT_DEFAULT = {"skill": 0.50, "pengalaman": 0.30, "pendidikan": 0.20}
BIDANG = ("skill", "pengalaman", "pendidikan")


class Skor(NamedTuple):
    overall: float | None  # None = tidak ada satu pun bidang yang bisa dinilai
    skill: float | None
    pendidikan: float | None
    pengalaman: float | None
    flags: tuple[str, ...] = ()

    def as_dict(self) -> dict:
        """Bentuk payload callback A3.1."""
        return {
            "overall": self.overall,
            "skill": self.skill,
            "pendidikan": self.pendidikan,
            "pengalaman": self.pengalaman,
        }


def cosine(a: list[float], b: list[float]) -> float:
    """Cosine similarity dua vektor. Panjang beda / vektor nol -> 0.0."""
    if not a or not b or len(a) != len(b):
        return 0.0

    dot = sum(x * y for x, y in zip(a, b))
    na  = math.sqrt(sum(x * x for x in a))
    nb  = math.sqrt(sum(y * y for y in b))
    if na == 0.0 or nb == 0.0:
        return 0.0

    return dot / (na * nb)


def ke_0_1(nilai: float) -> float:
    """
    Cosine bisa -1..1; gate memakai skala 0..1 (GateOne). Nilai negatif berarti
    tidak mirip sama sekali, jadi dipangkas ke 0 - bukan dipetakan ke 0,5 lewat
    (x+1)/2, karena itu akan memberi nilai tengah gratis pada CV yang benar-benar
    tak relevan.
    """
    return round(min(1.0, max(0.0, nilai)), 4)


def hitung(
    vektor_cv: dict[str, list[float] | None],
    vektor_job: dict[str, list[float] | None],
    bobot: dict[str, float] | None = None,
) -> Skor:
    """
    vektor_cv / vektor_job: {'skill': vec|None, 'pengalaman': ..., 'pendidikan': ...}
    None atau vektor kosong = bidang tidak tersedia -> tidak dinilai.
    """
    w = {**BOBOT_DEFAULT, **(bobot or {})}

    per_bidang: dict[str, float | None] = {}
    flags: list[str] = []
    for b in BIDANG:
        vc, vj = vektor_cv.get(b), vektor_job.get(b)
        if not vc or not vj:
            per_bidang[b] = None
            flags.append(f"{b}_tidak_dinilai")
            continue
        per_bidang[b] = ke_0_1(cosine(vc, vj))

    dinilai = {b: s for b, s in per_bidang.items() if s is not None}
    if not dinilai:
        return Skor(None, None, None, None, ("tidak_dapat_dinilai", *flags))

    # bobot dinormalkan ulang atas bidang yang ada saja
    total_w = sum(w[b] for b in dinilai) or 1.0
    overall = round(sum(dinilai[b] * w[b] for b in dinilai) / total_w, 4)

    if len(dinilai) < len(BIDANG):
        flags.append("bobot_dinormalkan_ulang")

    return Skor(overall, per_bidang["skill"], per_bidang["pendidikan"], per_bidang["pengalaman"], tuple(flags))
