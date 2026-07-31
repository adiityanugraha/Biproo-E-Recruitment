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
     * Skor 0.0-1.0 di database jadi angka 0-100 untuk dibaca manusia.
     * Recruiter membaca "68", bukan "0.6798". Satu tempat supaya skalanya
     * konsisten di seluruh halaman.
     */
    function skor_100(float|string|null $skor, int $desimal = 0): ?string
    {
        if ($skor === null || $skor === '') {
            return null;
        }

        return number_format((float) $skor * 100, $desimal, ',', '.');
    }
}

if (! function_exists('badge_skor')) {
    /**
     * Badge skor CV 0-100 berwarna. Ambang warna SENGAJA lebar dan tidak
     * dipakai untuk memutus apa pun - kalibrasi menunjukkan skor CV punya daya
     * beda lemah, jadi warna ini cuma bantuan visual untuk mengurutkan
     * prioritas review, bukan penilaian lolos/gagal.
     */
    function badge_skor(float|string|null $skor): string
    {
        $tampil = skor_100($skor);
        if ($tampil === null) {
            return '<span class="badge badge-netral" title="Screening CV belum selesai">belum ada</span>';
        }

        $n     = (float) $skor * 100;
        $kelas = match (true) {
            $n >= 70.0 => 'badge-lolos',
            $n >= 50.0 => 'badge-flag',
            default    => 'badge-netral',
        };

        return '<span class="badge ' . $kelas . '" title="Skor kecocokan CV terhadap lowongan (0-100)">'
            . esc($tampil) . '</span>';
    }
}
