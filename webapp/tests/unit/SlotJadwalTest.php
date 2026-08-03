<?php

use App\Libraries\SlotJadwal;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Daftar slot interview. Waktu "sekarang" disuntik, jadi batasnya diuji tepat
 * di detiknya tanpa menunggu waktu nyata dan tanpa bergantung hari menjalankan.
 *
 * @internal
 */
final class SlotJadwalTest extends CIUnitTestCase
{
    // 2026-08-03 adalah hari Senin
    private const SENIN_PAGI = '2026-08-03 08:00:00';

    private function slot(string $now): array
    {
        return SlotJadwal::tersedia(new DateTimeImmutable($now));
    }

    public function testTujuhSlotPerHariDari10SampaiJam16(): void
    {
        $hariIni = array_filter($this->slot(self::SENIN_PAGI), static fn ($s) => str_starts_with($s, '2026-08-03'));

        $this->assertCount(7, $hariIni, 'harus 7 slot per hari');
        $this->assertSame([
            '2026-08-03 10:00:00', '2026-08-03 11:00:00', '2026-08-03 12:00:00',
            '2026-08-03 13:00:00', '2026-08-03 14:00:00', '2026-08-03 15:00:00',
            '2026-08-03 16:00:00',
        ], array_values($hariIni));
    }

    public function testSlotYangJamnyaSudahLewatTidakDitawarkan(): void
    {
        // pukul 12.30: slot 10, 11, dan 12 sudah lewat
        $hariIni = array_filter($this->slot('2026-08-03 12:30:00'), static fn ($s) => str_starts_with($s, '2026-08-03'));

        $this->assertSame(['2026-08-03 13:00:00', '2026-08-03 14:00:00', '2026-08-03 15:00:00', '2026-08-03 16:00:00'],
            array_values($hariIni));
    }

    public function testSlotYangBaruSajaDimulaiSudahTidakBisaDipilih(): void
    {
        // sedetik setelah 10.00 slotnya hangus; sedetik sebelumnya masih bisa
        $this->assertNotContains('2026-08-03 10:00:00', $this->slot('2026-08-03 10:00:01'));
        $this->assertContains('2026-08-03 10:00:00', $this->slot('2026-08-03 09:59:59'));
    }

    public function testAkhirPekanDilewati(): void
    {
        $tanggal = array_unique(array_map(static fn ($s) => substr($s, 0, 10), $this->slot(self::SENIN_PAGI)));

        foreach ($tanggal as $t) {
            $n = (new DateTimeImmutable($t))->format('N');
            $this->assertLessThanOrEqual(5, (int) $n, "{$t} jatuh di akhir pekan");
        }
    }

    public function testMenawarkanTujuhHariKerja(): void
    {
        $tanggal = array_unique(array_map(static fn ($s) => substr($s, 0, 10), $this->slot(self::SENIN_PAGI)));

        $this->assertCount(7, $tanggal);
        // Senin 3 Agu sampai Selasa 11 Agu (akhir pekan 8-9 Agu dilompati)
        $this->assertSame('2026-08-03', reset($tanggal));
        $this->assertSame('2026-08-11', end($tanggal));
    }

    public function testDijalankanSabtuMulaiDariSeninBerikutnya(): void
    {
        // 2026-08-08 = Sabtu
        $tanggal = array_unique(array_map(static fn ($s) => substr($s, 0, 10), $this->slot('2026-08-08 09:00:00')));

        $this->assertSame('2026-08-10', reset($tanggal), 'Sabtu harus melompat ke Senin');
    }

    public function testSahMenolakYangBukanSlot(): void
    {
        $now = new DateTimeImmutable(self::SENIN_PAGI);

        $this->assertTrue(SlotJadwal::sah('2026-08-03 10:00:00', $now));
        // jam di luar daftar
        $this->assertFalse(SlotJadwal::sah('2026-08-03 09:00:00', $now));
        $this->assertFalse(SlotJadwal::sah('2026-08-03 17:00:00', $now));
        // menit tidak bulat
        $this->assertFalse(SlotJadwal::sah('2026-08-03 10:30:00', $now));
        // akhir pekan
        $this->assertFalse(SlotJadwal::sah('2026-08-08 10:00:00', $now));
        // sudah lewat
        $this->assertFalse(SlotJadwal::sah('2026-08-02 10:00:00', $now));
        // di luar rentang 7 hari kerja
        $this->assertFalse(SlotJadwal::sah('2026-09-01 10:00:00', $now));
        // format ngawur
        $this->assertFalse(SlotJadwal::sah('besok pagi', $now));
    }

    public function testPerTanggalMenandaiSlotYangSudahDiambil(): void
    {
        $peta = SlotJadwal::perTanggal(['2026-08-03 11:00:00'], new DateTimeImmutable(self::SENIN_PAGI));

        $senin = $peta['2026-08-03'];
        $this->assertSame('10:00', $senin[0]['jam']);
        $this->assertFalse($senin[0]['terpakai']);
        $this->assertSame('11:00', $senin[1]['jam']);
        $this->assertTrue($senin[1]['terpakai'], 'slot yang sudah diambil harus ditandai');
    }
}
