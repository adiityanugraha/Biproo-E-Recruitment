<?php

use App\Libraries\GateOne;
use PHPUnit\Framework\TestCase;

/**
 * Gate 1 ditentukan MURNI oleh hasil assessment - skor CV tidak ikut.
 * Skor CV baru dipakai di Gate 2 (lihat GateTwoTest).
 *
 * @internal
 */
final class GateOneTest extends TestCase
{
    public function testLulusAssessmentBerartiLolos(): void
    {
        $this->assertSame('passed', GateOne::dariAssessment(true));
    }

    public function testTidakLulusAssessmentBerartiGugur(): void
    {
        $this->assertSame('failed', GateOne::dariAssessment(false));
    }

    /**
     * Regresi terhadap perilaku lama: Gate 1 pernah memakai skor CV berbobot
     * dengan zona flagged. Kalibrasi 7.815 kandidat menunjukkan skor CV nyaris
     * tidak membedakan siapa yang diterima (AUC ~0,50 di dalam posisi), jadi
     * skor CV TIDAK boleh lagi menggugurkan siapa pun di sini.
     */
    public function testTidakAdaLagiZonaFlaggedOtomatisDariSkor(): void
    {
        $this->assertFalse(method_exists(GateOne::class, 'evaluate'),
            'GateOne::evaluate() sudah dihapus - Gate 1 tidak lagi memakai skor CV');
        $this->assertContains(GateOne::dariAssessment(true), ['passed', 'failed']);
        $this->assertContains(GateOne::dariAssessment(false), ['passed', 'failed']);
    }
}
