<?php

use App\Libraries\GateTwo;
use PHPUnit\Framework\TestCase;

/**
 * Gate 2: skor CV digabung skor interview. Ini SATU-SATUNYA tempat skor CV
 * mempengaruhi kelulusan kandidat - Gate 1 diputus assessment.
 *
 * @internal
 */
final class GateTwoTest extends TestCase
{
    public function testSkorTinggiDirekomendasikanHire(): void
    {
        // cv 0.8, interview 0.9 -> 0.4*0.8 + 0.6*0.9 = 0.86 >= 0.7
        $r = GateTwo::recommend(0.8, 0.9);
        $this->assertSame('hire', $r['recommendation']);
        $this->assertSame(0.86, $r['score']);
    }

    public function testInterviewBerbobotLebihBesarDaripadaSkorCv(): void
    {
        // CV bagus + interview buruk KALAH dari CV buruk + interview bagus,
        // karena bobot interview 60% versus CV 40% (kalibrasi: skor CV lemah)
        $cvBagus = GateTwo::recommend(0.9, 0.4)['score'];
        $ivBagus = GateTwo::recommend(0.4, 0.9)['score'];

        $this->assertGreaterThan($cvBagus, $ivBagus);
    }

    public function testBobotPerPosisiDariKolomJobs(): void
    {
        $c = GateTwo::configFromJob('{"gate2":{"cv":0.7,"interview":0.3}}', null);

        $this->assertSame(['cv' => 0.7, 'interview' => 0.3], $c['weights']);
        // cv jadi penentu utama: 0.7*0.9 + 0.3*0.4 = 0.75
        $this->assertSame(0.75, GateTwo::recommend(0.9, 0.4, $c)['score']);
    }

    public function testConfigFromJobTahanNullDanJsonRusak(): void
    {
        foreach ([[null, null], ['', ''], ['{bukan json', '[]']] as [$b, $t]) {
            $c = GateTwo::configFromJob($b, $t);
            $this->assertSame([], $c['weights']);
            $this->assertSame(GateTwo::recommend(0.8, 0.9), GateTwo::recommend(0.8, 0.9, $c));
        }
    }

    public function testSkorRendahDirekomendasikanNoHire(): void
    {
        // 0.4*0.7 + 0.6*0.5 = 0.58 < 0.7
        $this->assertSame('no-hire', GateTwo::recommend(0.7, 0.5)['recommendation']);
    }

    public function testTepatDiThresholdDirekomendasikanHire(): void
    {
        // 0.4*0.7 + 0.6*0.7 = 0.7 == threshold -> hire (aturan >=)
        $this->assertSame('hire', GateTwo::recommend(0.7, 0.7)['recommendation']);
    }

    public function testKonfigurasiPerPosisiMenimpaDefault(): void
    {
        $r = GateTwo::recommend(0.9, 0.6, ['threshold' => ['rekomendasi' => 0.8]]);
        // 0.4*0.9 + 0.6*0.6 = 0.72 < 0.8
        $this->assertSame('no-hire', $r['recommendation']);
    }

    public function testSkorDiLuarRentangDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GateTwo::recommend(-0.1, 0.5);
    }
}
