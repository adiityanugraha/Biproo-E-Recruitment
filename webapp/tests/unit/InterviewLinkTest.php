<?php

use App\Models\InterviewModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Jendela link Zoom kandidat: aktif 15 menit sebelum jadwal sampai 1 jam sesudahnya.
 * Fungsi murni, waktu "sekarang" disuntik - jadi batas jendela diuji tepat di
 * detiknya tanpa DB dan tanpa menunggu waktu nyata.
 *
 * @internal
 */
final class InterviewLinkTest extends CIUnitTestCase
{
    private const JADWAL = '2026-08-10 10:00:00';

    private function pada(string $waktu): bool
    {
        return InterviewModel::linkAktif(self::JADWAL, new DateTimeImmutable($waktu));
    }

    public function testSedetikSebelumJendelaBukaMasihMati(): void
    {
        $this->assertFalse($this->pada('2026-08-10 09:44:59'));
    }

    public function testTepatDiBatasBukaSudahAktif(): void
    {
        $this->assertTrue($this->pada('2026-08-10 09:45:00'));
    }

    public function testSaatJadwalDimulaiAktif(): void
    {
        $this->assertTrue($this->pada('2026-08-10 10:00:00'));
    }

    public function testTepatDiBatasTutupMasihAktif(): void
    {
        $this->assertTrue($this->pada('2026-08-10 11:00:00'));
    }

    public function testSedetikSetelahJendelaTutupSudahMati(): void
    {
        $this->assertFalse($this->pada('2026-08-10 11:00:01'));
    }
}
