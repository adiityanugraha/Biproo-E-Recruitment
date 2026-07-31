<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Keputusan Gate 2 (docs/gate-logic.md).
 *
 * Di sinilah skor CV dipakai: digabung berbobot dengan skor interview.
 * Gate 1 sudah diputus assessment (lihat GateOne), jadi Gate 2 adalah satu-satunya
 * tempat skor kecocokan CV mempengaruhi kelulusan kandidat.
 *
 * Bobot default memberi interview porsi lebih besar (60% versus 40%) karena
 * kalibrasi menunjukkan skor CV punya daya beda lemah, sementara interview
 * adalah penilaian manusia langsung.
 */
class GateTwo
{
    public const DEFAULTS = [
        'weights'   => ['cv' => 0.4, 'interview' => 0.6],
        'threshold' => ['rekomendasi' => 0.7],
    ];

    /**
     * Rakit config Gate 2 dari kolom jobs.bobot_json / jobs.threshold_json.
     * Toleran: kolom null atau JSON rusak jatuh ke DEFAULTS.
     */
    public static function configFromJob(?string $bobotJson, ?string $thresholdJson): array
    {
        $bobot     = json_decode((string) $bobotJson, true) ?? [];
        $threshold = json_decode((string) $thresholdJson, true) ?? [];

        return [
            'weights'   => $bobot['gate2'] ?? [],
            'threshold' => $threshold['gate2'] ?? [],
        ];
    }

    /**
     * @param float $cvScore        skor kecocokan CV, 0.0 - 1.0
     * @param float $interviewScore skor interview ternormalkan, 0.0 - 1.0
     * @param array $config         override per posisi (key gate2 di jobs), bentuk = DEFAULTS
     *
     * @return array{recommendation: 'hire'|'no-hire', score: float}
     */
    public static function recommend(float $cvScore, float $interviewScore, array $config = []): array
    {
        foreach (['cvScore' => $cvScore, 'interviewScore' => $interviewScore] as $name => $val) {
            if ($val < 0.0 || $val > 1.0) {
                throw new InvalidArgumentException("{$name} harus 0.0 - 1.0, diberi {$val}");
            }
        }

        $w = ($config['weights'] ?? []) + self::DEFAULTS['weights'];
        $t = ($config['threshold'] ?? []) + self::DEFAULTS['threshold'];

        // dibulatkan SEBELUM dibandingkan supaya rekomendasi konsisten dengan
        // skor yang disimpan/ditampilkan (hindari 0.6999999... di boundary)
        $score = round(($w['cv'] * $cvScore) + ($w['interview'] * $interviewScore), 4);

        return [
            'recommendation' => $score >= $t['rekomendasi'] ? 'hire' : 'no-hire',
            'score'          => $score,
        ];
    }
}
