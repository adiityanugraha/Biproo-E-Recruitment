<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Batas-batas wawancara suara AI (arahan 12 Agustus 2026).
 *
 * Semuanya bermuara pada satu kenyataan: tier gratis Gemini memberi 20
 * panggilan generateContent PER HARI, dipakai bersama screening CV. Satu
 * jawaban kandidat = satu panggilan (penilaian dan pertanyaan lanjutan
 * digabung, lihat ai-service/wawancara.py). Jadi angka di bawah ini bukan
 * selera desain, melainkan anggaran.
 *
 * Dengan bawaan di bawah, satu wawancara memakai paling banyak 12 panggilan -
 * satu kandidat per hari pada tier gratis. Naikkan hanya bila kunci berbayar
 * sudah dipasang. Override lewat .env: wawancara.maksButir, dst.
 */
class Wawancara extends BaseConfig
{
    /**
     * Berapa butir rubrik yang ditanyakan AI.
     *
     * Bank soal tim DS memuat 15-24 butir per posisi. Membacakan semuanya lewat
     * suara memakan lebih dari satu jam, sementara satu sesi interview dijatah
     * 30 menit (InterviewModel::TUTUP_MENIT). Yang diambil adalah butir berbobot
     * tertinggi; sisanya tetap muncul di form penilaian recruiter, kosong dan
     * menunggu diisi manusia.
     */
    public int $maksButir = 8;

    /** Anggaran pertanyaan pendalaman (yang mengait ke isi jawaban) satu sesi. */
    public int $maksLanjutan = 3;

    /**
     * Pagar keras jumlah panggilan LLM satu lamaran, melintasi berapa pun kali
     * halaman dibuka ulang. Tanpa ini, satu kandidat yang memuat ulang ruang
     * wawancara berkali-kali bisa menghabiskan kuota harian seluruh sistem.
     */
    public int $maksGiliran = 12;

    /** Berapa giliran terakhir yang ikut dikirim sebagai konteks ke LLM. */
    public int $riwayatKonteks = 2;

    /** Batas panjang transkrip satu jawaban yang disimpan (karakter). */
    public int $maksJawaban = 4000;
}
