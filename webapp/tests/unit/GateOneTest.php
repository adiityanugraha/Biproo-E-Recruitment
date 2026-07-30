<?php

use App\Libraries\GateOne;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class GateOneTest extends TestCase
{
    public function testSkorTinggiLolosOtomatis(): void
    {
        $r = GateOne::evaluate(0.9, 0.9);
        $this->assertSame('passed', $r['decision']);
        $this->assertSame(0.9, $r['score']);
    }

    public function testSkorRendahGagalOtomatis(): void
    {
        $r = GateOne::evaluate(0.2, 0.3);
        $this->assertSame('failed', $r['decision']);
    }

    public function testZonaTengahKenaSoftFlag(): void
    {
        $r = GateOne::evaluate(0.6, 0.6);
        $this->assertSame('flagged', $r['decision']);
    }

    public function testTepatDiThresholdAtasLolos(): void
    {
        // 0.75 gabungan == upper default -> lolos (aturan >=)
        $this->assertSame('passed', GateOne::evaluate(0.75, 0.75)['decision']);
    }

    public function testTepatDiThresholdBawahMasihFlagged(): void
    {
        // 0.45 gabungan == lower default -> zona tengah, bukan gagal
        $this->assertSame('flagged', GateOne::evaluate(0.45, 0.45)['decision']);
    }

    public function testKonfigurasiPerPosisiMenimpaDefault(): void
    {
        $config = [
            'weights'   => ['cv' => 0.7, 'assessment' => 0.3],
            'threshold' => ['upper' => 0.8, 'lower' => 0.6],
        ];
        // 0.7*0.9 + 0.3*0.5 = 0.78 -> di bawah upper 0.8, di atas lower 0.6
        $r = GateOne::evaluate(0.9, 0.5, $config);
        $this->assertSame('flagged', $r['decision']);
        $this->assertSame(0.78, $r['score']);
    }

    public function testKonfigurasiParsialTetapPakaiDefaultSisanya(): void
    {
        // hanya override upper; bobot & lower tetap default
        $r = GateOne::evaluate(0.7, 0.7, ['threshold' => ['upper' => 0.7]]);
        $this->assertSame('passed', $r['decision']);
    }

    public function testSkorDiLuarRentangDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GateOne::evaluate(1.2, 0.5);
    }

    public function testThresholdTerbalikDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GateOne::evaluate(0.5, 0.5, ['threshold' => ['upper' => 0.4, 'lower' => 0.6]]);
    }

    public function testConfigFromJobMembacaKunciGate1(): void
    {
        $c = GateOne::configFromJob(
            '{"gate1":{"cv":0.7,"assessment":0.3}}',
            '{"gate1":{"upper":0.8,"lower":0.5}}',
        );

        $this->assertSame(['cv' => 0.7, 'assessment' => 0.3], $c['weights']);
        $this->assertSame(['upper' => 0.8, 'lower' => 0.5], $c['threshold']);
    }

    /** Kolom null / JSON rusak tidak boleh melempar - biarkan DEFAULTS berlaku. */
    public function testConfigFromJobTahanNullDanJsonRusak(): void
    {
        foreach ([[null, null], ['', ''], ['{bukan json', '[]']] as [$b, $t]) {
            $c = GateOne::configFromJob($b, $t);
            $this->assertSame([], $c['weights']);
            $this->assertSame([], $c['threshold']);
            // hasil akhirnya tetap sama dengan memanggil tanpa config
            $this->assertSame(GateOne::evaluate(0.8, 0.8), GateOne::evaluate(0.8, 0.8, $c));
        }
    }
}
