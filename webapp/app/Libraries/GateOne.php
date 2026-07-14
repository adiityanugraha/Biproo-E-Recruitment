<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Logika keputusan Gate 1 (Blueprint A4 + docs/gate-logic.md).
 *
 * Fungsi murni: skor CV + skor assessment + konfigurasi per posisi
 * menghasilkan keputusan passed/failed/flagged. Pencatatan ke
 * stage_history dan antrian email dilakukan pemanggil (controller/service),
 * bukan di sini, supaya logika ini bisa diuji tanpa database.
 */
class GateOne
{
    public const DEFAULTS = [
        'weights'   => ['cv' => 0.5, 'assessment' => 0.5],
        'threshold' => ['upper' => 0.75, 'lower' => 0.45],
    ];

    /**
     * @param float $cvScore         skor screening CV, 0.0 - 1.0
     * @param float $assessmentScore skor assessment ternormalkan, 0.0 - 1.0
     * @param array $config          override per posisi dari jobs.bobot_json/threshold_json,
     *                               bentuk sama dengan self::DEFAULTS (boleh parsial)
     *
     * @return array{decision: 'passed'|'failed'|'flagged', score: float}
     */
    public static function evaluate(float $cvScore, float $assessmentScore, array $config = []): array
    {
        foreach (['cvScore' => $cvScore, 'assessmentScore' => $assessmentScore] as $name => $val) {
            if ($val < 0.0 || $val > 1.0) {
                throw new InvalidArgumentException("{$name} harus 0.0 - 1.0, diberi {$val}");
            }
        }

        $w = ($config['weights'] ?? []) + self::DEFAULTS['weights'];
        $t = ($config['threshold'] ?? []) + self::DEFAULTS['threshold'];

        if ($t['lower'] > $t['upper']) {
            throw new InvalidArgumentException('threshold lower tidak boleh melebihi upper');
        }

        $score = ($w['cv'] * $cvScore) + ($w['assessment'] * $assessmentScore);

        // Tabel keputusan gate-logic.md: >= upper lolos, < lower gagal,
        // zona tengah (termasuk tepat di lower) soft-flag utk review manusia.
        $decision = match (true) {
            $score >= $t['upper'] => 'passed',
            $score < $t['lower']  => 'failed',
            default               => 'flagged',
        };

        return ['decision' => $decision, 'score' => round($score, 4)];
    }
}
