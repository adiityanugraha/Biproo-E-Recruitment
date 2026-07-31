<?php

namespace App\Libraries;

/**
 * Keputusan Gate 1 (docs/gate-logic.md).
 *
 * Gate 1 ditentukan MURNI oleh hasil assessment: lulus assessment berarti lolos,
 * tidak lulus berarti gugur. Skor CV TIDAK dipakai di sini - ia disimpan lalu
 * dipakai di Gate 2 bersama skor interview.
 *
 * Alasannya bukan penyederhanaan, tapi hasil kalibrasi pada 7.815 kandidat
 * berlabel historis (docs/kalibrasi-gate.md): di dalam satu posisi, skor
 * kecocokan CV nyaris tidak membedakan siapa yang akhirnya diterima - AUC 0,499
 * / 0,513 / 0,446 pada tiga posisi terbesar. Pencarian ambang gugur-otomatis
 * mengembalikan lower=0,0, artinya tidak ada titik potong yang menggugurkan
 * sebagian kandidat tanpa ikut menggugurkan kandidat yang sebenarnya diterima.
 * Memakai skor CV untuk menggugurkan orang di Gate 1 berarti menolak kandidat
 * berdasarkan angka yang tidak terbukti.
 */
class GateOne
{
    /**
     * @param bool $lulusAssessment hasil assessment kandidat
     *
     * @return 'passed'|'failed'
     */
    public static function dariAssessment(bool $lulusAssessment): string
    {
        return $lulusAssessment ? 'passed' : 'failed';
    }
}
