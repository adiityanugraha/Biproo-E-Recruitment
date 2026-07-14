<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Rekomendasi Gate 2 (docs/gate-logic.md).
 *
 * Berbeda dengan Gate 1: sistem TIDAK pernah memutus otomatis di sini.
 * Fungsi ini hanya menghitung rekomendasi hire/no-hire; keputusan akhir
 * selalu recruiter (approve/reject manual di dashboard).
 */
class GateTwo
{
    public const DEFAULTS = [
        'weights'   => ['gate1' => 0.4, 'interview' => 0.6],
        'threshold' => ['rekomendasi' => 0.7],
    ];

    /**
     * @param float $gate1Score     skor gabungan Gate 1, 0.0 - 1.0
     * @param float $interviewScore skor interview ternormalkan, 0.0 - 1.0
     * @param array $config         override per posisi (key gate2 di jobs), bentuk = DEFAULTS
     *
     * @return array{recommendation: 'hire'|'no-hire', score: float}
     */
    public static function recommend(float $gate1Score, float $interviewScore, array $config = []): array
    {
        foreach (['gate1Score' => $gate1Score, 'interviewScore' => $interviewScore] as $name => $val) {
            if ($val < 0.0 || $val > 1.0) {
                throw new InvalidArgumentException("{$name} harus 0.0 - 1.0, diberi {$val}");
            }
        }

        $w = ($config['weights'] ?? []) + self::DEFAULTS['weights'];
        $t = ($config['threshold'] ?? []) + self::DEFAULTS['threshold'];

        // dibulatkan SEBELUM dibandingkan supaya rekomendasi konsisten dengan
        // skor yang disimpan/ditampilkan (hindari 0.6999999... di boundary)
        $score = round(($w['gate1'] * $gate1Score) + ($w['interview'] * $interviewScore), 4);

        return [
            'recommendation' => $score >= $t['rekomendasi'] ? 'hire' : 'no-hire',
            'score'          => $score,
        ];
    }
}
