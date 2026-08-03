<?php

use App\Models\InterviewModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Jendela link Zoom kandidat: aktif 15 menit sebelum jadwal sampai 30 menit sesudahnya
 * (satu sesi interview 30 menit, arahan atasan 3 Agustus 2026).
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
        $this->assertTrue($this->pada('2026-08-10 10:30:00'));
    }

    public function testSedetikSetelahJendelaTutupSudahMati(): void
    {
        $this->assertFalse($this->pada('2026-08-10 10:30:01'));
    }

    /**
     * Uji jendela di atas menyuntik "sekarang", jadi ia benar di zona mana pun.
     * Justru itu yang membuat bug zona waktu lolos: linkAktif tanpa suntikan
     * memakai jam default aplikasi, sementara scheduled_at adalah jam dinding
     * WIB milik SQL Server. Saat appTimezone masih UTC, PHP tertinggal 7 jam
     * dan link Zoom kandidat tidak pernah terbuka pada jadwalnya.
     *
     * Ini penguncian konfigurasi, bukan bukti perilaku: selisih dua sistem tidak
     * bisa dibuktikan dari dalam PHPUnit yang basis datanya SQLite. Yang bisa
     * dijaga adalah nilainya tidak berubah tanpa sengaja.
     */
    public function testZonaWaktuAplikasiIkutJamDatabaseWIB(): void
    {
        $this->assertSame(
            'Asia/Jakarta',
            date_default_timezone_get(),
            'scheduled_at disimpan sebagai jam dinding WIB (diketik recruiter, default kolom GETDATE()). '
            . 'Zona lain membuat perbandingan waktu meleset diam-diam, bukan error.'
        );
    }
}
