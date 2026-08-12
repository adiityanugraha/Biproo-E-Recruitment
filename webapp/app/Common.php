<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter.com/user_guide/extending/common.html
 */

if (! function_exists('badge_status')) {
    /** Badge status berwarna (tema BIPROO) utk status stage_history. */
    function badge_status(?string $status): string
    {
        if ($status === null || $status === '' || $status === '-') {
            return '<span class="badge badge-netral">-</span>';
        }

        $label = \App\Controllers\Lamaran::STATUS_LABEL[$status] ?? $status;
        $kelas = match ($status) {
            'passed'  => 'badge-lolos',
            'failed'  => 'badge-gagal',
            'flagged' => 'badge-flag',
            default   => 'badge-netral',
        };

        return '<span class="badge ' . $kelas . '">' . esc($label) . '</span>';
    }
}

if (! function_exists('skor_100')) {
    /**
     * Skor 0.0-1.0 jadi angka 0-100 untuk dibaca manusia.
     *
     * JANGAN dipakai untuk skor kemiripan CV. Skor itu cosine similarity yang
     * lantainya sekitar 0,54 (teks yang sama sekali tidak relevan pun sampai
     * situ), jadi "54 dari 100" dibaca orang sebagai "cocok 54 persen" padahal
     * artinya "tidak cocok sama sekali". Untuk CV pakai kemiripan_teks().
     *
     * Yang sah lewat sini: skor interview (memang 0-100 dari recruiter) dan
     * skor akhir Gate 2.
     */
    function skor_100(float|string|null $skor, int $desimal = 0): ?string
    {
        if ($skor === null || $skor === '') {
            return null;
        }

        return number_format((float) $skor * 100, $desimal, ',', '.');
    }
}

if (! function_exists('kemiripan_pita')) {
    /**
     * Pita kemiripan CV terhadap syarat lowongan: rendah / sedang / tinggi.
     *
     * Ambangnya dari pengukuran, bukan tebakan (docs/pipeline-screening-cv.md):
     *   0,5404  teks yang jelas tak relevan (resep masakan vs lowongan backend)
     *   0,6099  CV nyata tapi salah bidang (perawat vs lowongan backend)
     *   0,8896  CV yang cocok betul
     * Kandidat nyata di basis data ini mendarat di 0,58 sampai 0,66.
     *
     * Sengaja tiga pita, bukan angka dua digit. Jarak antar kandidat cuma
     * sekitar 0,06 sementara daya beda model di dalam satu posisi terukur
     * AUC ~0,50 (docs/kalibrasi-gate.md). Menampilkan "66" memberi kesan
     * ketelitian yang tidak dimiliki model, dan angka setepat itu mengundang
     * orang mengurutkan kandidat berdasarkan derau.
     */
    function kemiripan_pita(float $skor): string
    {
        // Dibulatkan DULU ke presisi yang ditampilkan, supaya pita selalu cocok
        // dengan angkanya. Tanpa ini skor 0,5956 tampil sebagai "rendah (0,60)"
        // padahal ambang sedang juga 0,60 - pembaca wajar mengira ada bug.
        $n = round($skor, 2);

        return match (true) {
            $n >= 0.75 => 'tinggi',
            $n >= 0.60 => 'sedang',
            default    => 'rendah',
        };
    }
}

if (! function_exists('kemiripan_teks')) {
    /** Kemiripan CV sebagai teks siap catat: "sedang (0,66)". null bila belum ada skor. */
    function kemiripan_teks(float|string|null $skor): ?string
    {
        if ($skor === null || $skor === '') {
            return null;
        }

        $n = (float) $skor;

        return kemiripan_pita($n) . ' (' . number_format($n, 2, ',', '.') . ')';
    }
}

if (! function_exists('usia_dari_dob')) {
    /**
     * Usia dalam tahun penuh dari tanggal lahir yang tertulis di CV.
     *
     * Dihitung DI SINI, bukan oleh model bahasa (structure.py aturan 8 melarangnya
     * secara eksplisit). Dua alasan, keduanya penting:
     *
     * 1. Model bahasa salah berhitung tanpa memberi tanda apa pun. Aritmetika
     *    tanggal adalah hal yang paling tidak layak diserahkan kepadanya, dan
     *    hasilnya tidak bisa dibedakan dari hasil yang benar.
     * 2. Usia yang DISIMPAN pasti basi. Kandidat yang CV-nya dibaca hari ini
     *    berulang tahun bulan depan, sementara angka di basis data tidak ikut
     *    berubah. Yang disimpan cukup tanggal lahirnya; usianya dihitung ulang
     *    tiap kali lembar profil dibuka.
     *
     * Tanggal Indonesia ditulis hari-bulan-tahun, dan itu yang diasumsikan untuk
     * bentuk berangka ("01/05/1991" = 1 Mei). Bentuk ISO (1991-05-01) dikenali
     * terpisah karena tahun empat digit di depan tidak mungkin salah baca.
     *
     * @param string|null            $dob teks apa adanya dari CV, mis. "01 Mei 1991"
     * @param DateTimeImmutable|null $per titik hitung, untuk uji yang tidak boleh ikut menua
     *
     * @return int|null null bila tanggalnya tidak terbaca atau hasilnya tidak masuk akal
     */
    function usia_dari_dob(?string $dob, ?DateTimeImmutable $per = null): ?int
    {
        $teks = mb_strtolower(trim((string) $dob));
        if ($teks === '') {
            return null;
        }

        $bulan = [
            'januari' => 1, 'jan' => 1, 'january' => 1,
            'februari' => 2, 'pebruari' => 2, 'feb' => 2, 'february' => 2,
            'maret' => 3, 'mar' => 3, 'march' => 3,
            'april' => 4, 'apr' => 4,
            'mei' => 5, 'may' => 5,
            'juni' => 6, 'jun' => 6, 'june' => 6,
            'juli' => 7, 'jul' => 7, 'july' => 7,
            'agustus' => 8, 'agt' => 8, 'agu' => 8, 'aug' => 8, 'august' => 8,
            'september' => 9, 'sept' => 9, 'sep' => 9,
            'oktober' => 10, 'okt' => 10, 'oct' => 10, 'october' => 10,
            'november' => 11, 'nopember' => 11, 'nov' => 11,
            'desember' => 12, 'des' => 12, 'dec' => 12, 'december' => 12,
        ];

        // Nama bulan jadi angka. Kata yang tidak dikenal sengaja DIBIARKAN utuh
        // supaya pencocokan di bawah gagal - lebih baik kosong daripada tanggal
        // hasil tebakan dari kalimat yang kebetulan memuat angka.
        $teks = preg_replace_callback(
            '/[a-z]+/u',
            static fn (array $m): string => isset($bulan[$m[0]]) ? ' ' . $bulan[$m[0]] . ' ' : $m[0],
            $teks
        );

        if (preg_match('/\b(\d{1,2})\D{1,3}(\d{1,2})\D{1,3}(\d{4})\b/', $teks, $m)) {
            [, $hari, $bln, $tahun] = $m;
        } elseif (preg_match('/\b(\d{4})\D{1,3}(\d{1,2})\D{1,3}(\d{1,2})\b/', $teks, $m)) {
            [, $tahun, $bln, $hari] = $m;
        } else {
            return null;
        }

        if (! checkdate((int) $bln, (int) $hari, (int) $tahun)) {
            return null;
        }

        $lahir = DateTimeImmutable::createFromFormat('!Y-n-j', $tahun . '-' . (int) $bln . '-' . (int) $hari);
        $per ??= new DateTimeImmutable();
        if ($lahir === false || $lahir > $per) {
            return null;
        }

        // Batas kewajaran. Bukan aturan perusahaan, melainkan penyaring salah
        // baca: tanggal yang tercomot dari bagian lain CV (tahun lulus, periode
        // kerja) hampir selalu jatuh di luar rentang ini, dan "Age: 6" di lembar
        // profil lebih merusak kepercayaan daripada kolom yang dikosongkan.
        $usia = $lahir->diff($per)->y;

        return $usia >= 15 && $usia <= 80 ? $usia : null;
    }
}

if (! function_exists('badge_skor')) {
    /**
     * Badge kemiripan CV. Warnanya bantuan visual untuk urutan prioritas review,
     * BUKAN penilaian lolos/gagal - Gate 1 diputus assessment, dan skor ini cuma
     * salah satu masukan Gate 2.
     */
    function badge_skor(float|string|null $skor): string
    {
        $tampil = kemiripan_teks($skor);
        if ($tampil === null) {
            return '<span class="badge badge-netral" title="Screening CV belum menghasilkan skor">belum ada</span>';
        }

        $kelas = match (kemiripan_pita((float) $skor)) {
            'tinggi' => 'badge-lolos',
            'sedang' => 'badge-flag',
            default  => 'badge-netral',
        };

        $judul = 'Kemiripan teks CV terhadap syarat lowongan, bukan penilaian kualitas kandidat. '
            . 'Teks yang sama sekali tidak relevan pun mendapat sekitar 0,54, jadi "rendah" '
            . 'tidak berarti kandidat tidak memenuhi syarat.';

        // esc() konteks html, bukan 'attr': $judul teks statis tanpa masukan
        // pengguna, dan 'attr' mengubah tiap spasi jadi &#x20; sehingga HTML-nya
        // sulit dibaca tanpa menambah keamanan apa pun
        return '<span class="badge ' . $kelas . '" title="' . esc($judul) . '">'
            . esc($tampil) . '</span>';
    }
}
