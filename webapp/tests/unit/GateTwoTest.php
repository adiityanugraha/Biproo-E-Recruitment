<?php

use App\Libraries\GateTwo;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class GateTwoTest extends TestCase
{
    public function testSkorTinggiDirekomendasikanHire(): void
    {
        // 0.4*0.8 + 0.6*0.9 = 0.86 >= 0.7
        $r = GateTwo::recommend(0.8, 0.9);
        $this->assertSame('hire', $r['recommendation']);
        $this->assertSame(0.86, $r['score']);
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
