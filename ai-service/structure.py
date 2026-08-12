"""
Strukturisasi teks CV menjadi tiga field yang di-embed (Blueprint A3.2):
ringkasan pengalaman, skill, pendidikan.

Dua jalur:
  strukturkan()            - berbasis heading (Day 2). Cepat, tanpa biaya API.
  strukturkan_kontekstual()- via LLM (Day 3). Memahami konteks per kalimat
                             sesuai A3.2a, jadi CV naratif tanpa heading dan CV
                             mixed (lampiran scan menempel ke section terakhir)
                             tidak lagi salah kelompok. LLM ERROR -> jatuh ke
                             jalur heading, bukan kehilangan CV.

Beda penting antara LLM error dan LLM menjawab kosong:

  - error (jaringan, kuota, JSON rusak) = kita tidak tahu apa isi CV-nya,
    jadi jalur heading dicoba sebagai cadangan.
  - ketiga bidang kosong = LLM sudah membaca dan menjawab bahwa dokumen ini
    TIDAK memuat isi CV (mis. berkas yang isinya cuma transkrip/sertifikat
    hasil scan). Itu JAWABAN, bukan kegagalan, dan tidak boleh dibatalkan
    oleh jalur heading.

Yang terakhir itu sempat salah: hasil "kosong" dianggap gagal, jatuh ke jalur
heading, dan karena dokumennya juga tanpa heading maka SELURUH teks mentah
masuk ke bidang pengalaman. Skornya lalu dihitung dari tumpahan teks itu dan
keluar 0,6567 - tak terbedakan dari CV yang terurai benar (0,6568). Itu skor
karangan, pola yang sama dengan bug "umur nan" tim DS. Sekarang kasus ini
mengembalikan bidang kosong supaya skornya null dan kandidat masuk antrian
review recruiter.

Atribut sensitif dibuang terpisah oleh sanitize.bersihkan() setelah
strukturisasi - jaminan deterministik, tidak bergantung kepatuhan LLM.

Kenapa riwayat ada
------------------
Skor kita = cosine similarity, dan itu mengukur tumpang tindih MAKNA, bukan
kompetensi. Terukur: CV yang isinya cuma menyalin kata dari iklan lowongan
("tertarik pada REST API, Backend Developer adalah cita-cita saya") mendapat
0,9592 - lebih tinggi daripada backend sungguhan berpengalaman 3 tahun (0,9042).
Embedding apa pun akan begitu, karena "menyebut Laravel" dan "memakai Laravel
3 tahun" memang mirip maknanya.

Yang membedakan keduanya bukan makna, tapi BUKTI: nama tempat kerja dan rentang
waktu. Itu tidak bisa dilihat cosine, tapi bisa dibaca LLM yang sudah membaca CV
ini untuk memisah tiga bidang - jadi diminta di panggilan yang SAMA, tanpa kuota
tambahan.

Sengaja TIDAK dilakukan: membandingkan periode dengan syarat "minimal N tahun"
secara otomatis. Format periode di CV asli liar ("2021-2022", "Maret 2022 -
Januari 2025", "Juli 2024, 1 bulan", sebagian tanpa tahun). Parser yang gagal
membaca akan menghasilkan 0 tahun dan menggugurkan kandidat berpengalaman karena
format tanggalnya aneh - persis bug "umur nan" tim DS yang menjatuhkan 1.888
orang. Riwayatnya ditampilkan apa adanya ke recruiter, biar manusia yang menilai.
"""

import json
import logging
import os
import re
import time
from typing import NamedTuple

from chat import tanpa_kunci

# heading yang lazim di CV Indonesia + Inggris; dicocokkan ke SELURUH baris
# pendek (bukan substring bebas) supaya kalimat biasa tidak salah terdeteksi
HEADING = {
    "pengalaman": (
        "pengalaman kerja", "pengalaman organisasi", "pengalaman", "riwayat pekerjaan",
        "riwayat karir", "work experience", "working experience", "experience",
        "professional experience", "employment history", "organizational experience",
    ),
    "pendidikan": (
        "pendidikan", "riwayat pendidikan", "pendidikan terakhir", "education",
        "educational background", "academic background", "akademik",
    ),
    "skill": (
        "skill", "skills", "keahlian", "keterampilan", "kemampuan", "kompetensi",
        "technical skills", "hard skill", "soft skill", "hard & soft skill",
        "keahlian & minat", "kualifikasi",
    ),
}
MAX_PANJANG_HEADING = 45  # heading itu baris pendek; kalimat panjang bukan heading


class Terstruktur(NamedTuple):
    pengalaman: str
    skill: str
    pendidikan: str
    lain: str  # header/kontak/biodata - berhenti di sini, tidak di-embed
    flags: tuple[str, ...] = ()
    # Riwayat kerja bertanda bukti (nama tempat / rentang waktu). TIDAK di-embed
    # dan TIDAK mengubah skor - hanya ditampilkan ke recruiter dan dipakai untuk
    # flag tanpa_riwayat_kerja. Lihat blok "Kenapa riwayat ada" di bawah.
    riwayat: tuple[dict[str, str], ...] = ()
    # Biodata untuk lembar profil kandidat (arahan atasan 12 Agustus 2026).
    # SAMA SEPERTI RIWAYAT: diekstrak untuk DITAMPILKAN, tidak pernah di-embed
    # dan tidak menyentuh skor. Pemisahan ini yang menjaga fairness-by-design
    # (A3.2) tetap berlaku walau biodatanya kini ikut dibaca: agama, usia, dan
    # status kawin ada di lembar profil, tapi tidak bisa menggeser skor
    # kemiripan sedikit pun karena tidak pernah masuk vektor.
    data_pribadi: dict[str, str] = {}

    def _replace_flags(self, *tambahan: str) -> "Terstruktur":
        """Salinan dengan flag tambahan di depan (jejak jalur yang dipakai)."""
        return self._replace(flags=(*tambahan, *self.flags))


def _jenis_heading(baris: str) -> str | None:
    b = re.sub(r"[^a-z& ]", " ", baris.lower()).strip()
    b = re.sub(r"\s+", " ", b)
    if not b or len(baris.strip()) > MAX_PANJANG_HEADING:
        return None
    for jenis, kandidat in HEADING.items():
        if b in kandidat:
            return jenis

    return None


def strukturkan(teks: str) -> Terstruktur:
    bagian: dict[str, list[str]] = {"pengalaman": [], "skill": [], "pendidikan": [], "lain": []}
    aktif = "lain"
    for baris in teks.splitlines():
        jenis = _jenis_heading(baris)
        if jenis is not None:
            aktif = jenis
            continue  # baris heading-nya sendiri tidak perlu ikut isi
        bagian[aktif].append(baris.rstrip())

    hasil = {k: "\n".join(v).strip() for k, v in bagian.items()}

    # CV naratif tanpa heading sama sekali: seluruh teks jadi pengalaman supaya
    # tetap bisa di-embed; flag dicatat sebagai baseline kegagalan (Day 2 item 5)
    if not hasil["pengalaman"] and not hasil["skill"] and not hasil["pendidikan"]:
        return Terstruktur(teks.strip(), "", "", "", ("tanpa_heading",))

    flags = tuple(
        f"{k}_kosong" for k in ("pengalaman", "skill", "pendidikan") if not hasil[k]
    )

    return Terstruktur(hasil["pengalaman"], hasil["skill"], hasil["pendidikan"], hasil["lain"], flags)


# --- Jalur kontekstual via LLM (Day 3, Blueprint A3.2a) ---

MAX_TEKS_LLM = 12000  # CV terpanjang di sampel ~15rb karakter; potong demi kuota

SYSTEM_STRUKTUR = (
    "Kamu pengurai CV. Dari teks CV mentah (hasil ekstraksi PDF/OCR, urutannya "
    "bisa berantakan), kelompokkan ISI menjadi tiga bidang berdasarkan MAKNA "
    "kalimat, bukan judul section.\n"
    "- pengalaman: riwayat pekerjaan/organisasi, jabatan, nama perusahaan, "
    "uraian tanggung jawab, periode kerja.\n"
    "- skill: kemampuan, tools, bahasa pemrograman, sertifikasi keahlian.\n"
    "- pendidikan: jenjang, institusi, jurusan, tahun studi.\n\n"
    "ATURAN:\n"
    "1. Salin ulang isi aslinya, jangan mengarang dan jangan menyimpulkan.\n"
    "2. Teks lampiran hasil scan (transkrip nilai, sertifikat, surat) yang bukan "
    "riwayat kerja/pendidikan/skill: BUANG, jangan dipaksa masuk salah satu bidang.\n"
    "3. Data pribadi (nama, alamat, kontak, usia, gender, agama, status): JANGAN "
    "masukkan ke tiga bidang di atas. Tempatnya di 'data_pribadi' (lihat bawah).\n"
    "4. Bidang tanpa isi di CV ini: kembalikan string kosong.\n\n"
    "Selain itu keluarkan 'riwayat': daftar posisi yang BENAR-BENAR pernah "
    "dijalani kandidat, satu objek per posisi.\n"
    "5. Masukkan hanya posisi yang punya BUKTI berupa nama tempat kerja atau "
    "rentang waktu. Magang dan pengalaman organisasi termasuk, asal ada tempat "
    "atau waktunya.\n"
    "6. Minat, cita-cita, tujuan karier, dan daftar kemampuan BUKAN riwayat "
    'kerja. Kalimat seperti "tertarik pada X" atau "menguasai Y" jangan '
    "dimasukkan. CV tanpa riwayat kerja: kembalikan daftar kosong.\n"
    "7. Jangan mengarang perusahaan, periode, alasan keluar, atau gaji yang "
    "tidak tertulis di CV. Tidak tertulis = string kosong.\n\n"
    "Terakhir keluarkan 'data_pribadi' untuk lembar profil kandidat. Isi APA "
    "ADANYA dari CV, jangan menyimpulkan dan jangan menebak.\n"
    "8. usia hanya diisi bila tertulis sebagai angka usia di CV. JANGAN "
    "menghitungnya sendiri dari tanggal lahir.\n"
    "9. agama, status_kawin, dan jumlah_anak diisi HANYA bila memang tertulis. "
    "Tidak tertulis = string kosong. Jangan menduga dari nama, foto, atau "
    "apa pun.\n"
    "10. Jawab HANYA JSON: "
    '{"pengalaman": "...", "skill": "...", "pendidikan": "...", '
    '"riwayat": [{"jabatan": "...", "perusahaan": "...", "periode": "...", '
    '"alasan_keluar": "...", "gaji_terakhir": "...", "deskripsi": "..."}], '
    '"data_pribadi": {"nama": "...", "alamat": "...", "tempat_lahir": "...", '
    '"tanggal_lahir": "...", "usia": "...", "jenis_kelamin": "...", '
    '"status_kawin": "...", "jumlah_anak": "...", "bahasa": "...", '
    '"agama": "...", "telepon": "...", "email": "..."}}'
)


def _json_pertama(s: str) -> dict | None:
    """Ambil objek JSON dari jawaban LLM (kadang terbungkus ```json)."""
    m = re.search(r"\{.*\}", s, re.S)
    if m is None:
        return None
    try:
        d = json.loads(m.group(0))
    except json.JSONDecodeError:
        return None

    return d if isinstance(d, dict) else None


# Pagar terhadap LLM kabur, BUKAN penyaring CV panjang. Batas 20 yang semula
# dipakai memotong CV nyata: satu mahasiswa dengan banyak kepanitiaan dan asisten
# praktikum menghasilkan 22 posisi berbukti, dua di antaranya hilang diam-diam.
# CV kandidat memang bisa sepanjang itu; yang tidak wajar adalah ratusan.
MAKS_RIWAYAT = 60

KUNCI_RIWAYAT = (
    "jabatan", "perusahaan", "periode",
    # Diminta lembar profil BIPROO. Sering tidak ada di CV kandidat, dan itu
    # wajar: yang tidak tertulis tetap string kosong, bukan ditebak.
    "alasan_keluar", "gaji_terakhir", "deskripsi",
)

# Kunci biodata yang boleh masuk. Daftar tertutup, bukan apa pun yang dikirim
# LLM: tanpa ini satu jawaban ngawur bisa menyelipkan kunci baru yang langsung
# ikut tampil di lembar profil kandidat.
KUNCI_PRIBADI = (
    "nama", "alamat", "tempat_lahir", "tanggal_lahir", "usia", "jenis_kelamin",
    "status_kawin", "jumlah_anak", "bahasa", "agama", "telepon", "email",
)

MAKS_PANJANG_PRIBADI = 120


def _data_pribadi(nilai: object) -> dict[str, str]:
    """
    Bersihkan biodata dari LLM: kunci tertutup, nilai dipotong, kosong dibuang.

    Nilai panjang dipotong karena bidang biodata seharusnya pendek. Kalau LLM
    menaruh satu paragraf di 'alamat', itu tanda ia menyalin blok CV yang salah,
    dan lembar profilnya jadi berantakan.
    """
    if not isinstance(nilai, dict):
        return {}

    hasil: dict[str, str] = {}
    for k in KUNCI_PRIBADI:
        v = str(nilai.get(k) or "").strip()[:MAKS_PANJANG_PRIBADI]
        if v:
            hasil[k] = v

    return hasil


def _riwayat(nilai: object) -> tuple[dict[str, str], ...]:
    """
    Bersihkan daftar riwayat dari LLM.

    Syarat "punya bukti" DITEGAKKAN DI SINI, bukan dititipkan ke kepatuhan LLM:
    entri tanpa perusahaan DAN tanpa periode dibuang, walau jabatannya terisi.
    Tanpa ini penyalin kata kunci cukup membuat LLM menulis
    {"jabatan": "Backend Developer", "perusahaan": "", "periode": ""} untuk lolos.
    Pola yang sama dengan sanitize.bersihkan(): jaminan deterministik.
    """
    if not isinstance(nilai, list):
        return ()

    hasil: list[dict[str, str]] = []
    for item in nilai[:MAKS_RIWAYAT]:
        if not isinstance(item, dict):
            continue
        entri = {k: str(item.get(k) or "").strip() for k in KUNCI_RIWAYAT}
        if entri["perusahaan"] or entri["periode"]:
            hasil.append(entri)

    return tuple(hasil)


RETRY_LLM = 2  # sekali coba + sekali ulang

# Jeda ulang 20 detik, bukan 1. Kegagalan yang benar-benar terjadi di lapangan
# adalah 429 Too Many Requests dari kuota Gemini gratis, dan kuota itu dihitung
# PER MENIT. Mengulang setelah 1 detik pasti kena 429 lagi - percobaan kedua
# terbuang percuma, persis yang terlihat di log app#37.
RETRY_JEDA = float(os.environ.get("RETRY_JEDA_LLM", "20"))


def _panggil_llm(provider, teks: str) -> str | None:
    """
    Panggil LLM dengan satu kali coba ulang. None = tetap gagal setelah diulang.

    Errornya DICATAT, bukan ditelan. Versi sebelumnya memakai `except Exception`
    telanjang, sehingga kegagalan nyata tidak meninggalkan jejak apa pun: satu CV
    gagal dalam 0,2 detik padahal teksnya identik dengan CV lain yang sukses, dan
    penyebabnya tidak bisa dikejar sama sekali. Sekali coba ulang karena pola
    kegagalannya sporadis (429/5xx sesaat), sama seperti embed_with_retry.
    """
    log = logging.getLogger("uvicorn.error")

    for percobaan in range(RETRY_LLM):
        try:
            return provider.generate(SYSTEM_STRUKTUR, [], teks[:MAX_TEKS_LLM])
        except Exception as e:
            log.warning(
                "strukturisasi LLM gagal (percobaan %d/%d, %d karakter): %s: %s",
                percobaan + 1, RETRY_LLM, len(teks), type(e).__name__, tanpa_kunci(e),
            )
            if percobaan == RETRY_LLM - 1:
                return None
            time.sleep(RETRY_JEDA)

    return None


def strukturkan_kontekstual(teks: str, provider) -> Terstruktur:
    """
    Strukturisasi berbasis pemahaman konteks. `provider` mengikuti ChatProvider
    (chat.py): generate(system, history, question) -> str.

    LLM error atau JSON tak terparse -> jatuh ke strukturkan() berbasis heading.
    LLM menjawab ketiga bidang kosong -> bidang kosong, TIDAK jatuh ke heading
    (lihat penjelasan di docstring modul).
    """
    if not teks.strip():
        return Terstruktur("", "", "", "", ("teks_kosong",))

    jawab = _panggil_llm(provider, teks)
    if jawab is None:
        return strukturkan(teks)._replace_flags("llm_gagal")

    d = _json_pertama(jawab)
    if d is None:
        return strukturkan(teks)._replace_flags("llm_json_invalid")

    ambil = lambda k: str(d.get(k) or "").strip()
    peng, skill, didik = ambil("pengalaman"), ambil("skill"), ambil("pendidikan")
    riwayat = _riwayat(d.get("riwayat"))
    pribadi = _data_pribadi(d.get("data_pribadi"))

    if not (peng or skill or didik):
        # LLM sudah membaca dan memutuskan dokumen ini tidak memuat isi CV.
        # Dihormati: bidang dikosongkan -> skor null -> recruiter meninjau.
        # Jangan diganti tebakan heading, itu menghasilkan skor karangan.
        logging.getLogger("uvicorn.error").info(
            "LLM menyatakan dokumen tidak memuat isi CV (%d karakter) - skor dikosongkan", len(teks)
        )
        return Terstruktur("", "", "", "", ("tanpa_isi_cv",))

    flags = tuple(
        f"{n}_kosong" for n, v in (("pengalaman", peng), ("skill", skill), ("pendidikan", didik)) if not v
    )

    # Tak satu pun posisi punya tempat kerja atau rentang waktu. Sampai di sini
    # berarti CV-nya ADA isinya (kasus kosong total sudah keluar lewat
    # tanpa_isi_cv di atas), jadi ini pantas ditandai.
    #
    # Syaratnya sengaja TIDAK "peng and not riwayat". Diuji dengan LLM sungguhan,
    # CV penyalin kata kunci justru membuat bidang pengalaman KOSONG - LLM benar
    # menilai "Backend Developer adalah cita-cita saya" bukan pengalaman. Syarat
    # lama tidak pernah menyala di kasus yang paling perlu ditangkap, dan skornya
    # malah melonjak ke 1,0000 (bobot pengalaman yang kosong dinormalkan ke skill
    # dan pendidikan, dua bidang yang persis disalin dari iklan) sementara backend
    # sungguhan dapat 0,8917.
    #
    # Fresh graduate jujur ikut tertandai, dan itu memang benar: "CV ini tidak
    # memuat riwayat kerja" adalah fakta yang perlu dilihat recruiter, bukan
    # tuduhan. Skor TIDAK diturunkan - menghukum lewat angka berarti menebak
    # besar hukumannya.
    if not riwayat:
        flags += ("tanpa_riwayat_kerja",)

    return Terstruktur(peng, skill, didik, "", ("kontekstual", *flags), riwayat, pribadi)
