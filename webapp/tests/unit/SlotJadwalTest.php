<?php

use App\Libraries\SlotJadwal;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Urusan tanggal untuk slot interview.
 *
 * Sampai 28 Agustus 2026 kelas ini yang MENENTUKAN slot apa saja yang ada, dan
 * tes ini mengunci aturannya: 7 slot per hari, hari kerja, 7 hari kerja ke
 * depan. Aturan itu sekarang jadi DATA - recruiter mengelolanya lewat Settings,
 * dan yang menegakkan "belum lewat" adalah SlotInterviewModel::tersedia().
 *
 * Yang tersisa untuk diuji di sini dua perhitungan murni: hari kerja untuk
 * tombol "ulangi", dan pengelompokan per tanggal berikut kuotanya.
 *
 * @internal
 */
final class SlotJadwalTest extends CIUnitTestCase
{
    // 2026-08-03 adalah hari Senin
    private const SENIN = '2026-08-03';

    // --- hari kerja ---

    public function testMenghitungHariKerjaBerturutTurut(): void
    {
        $this->assertSame(
            ['2026-08-03', '2026-08-04', '2026-08-05'],
            SlotJadwal::hariKerja(3, new DateTimeImmutable(self::SENIN . ' 08:00:00')),
        );
    }

    public function testAkhirPekanDilewati(): void
    {
        // Kamis 6 Agu: dua hari berikutnya melompati Sabtu-Minggu
        $this->assertSame(
            ['2026-08-06', '2026-08-07', '2026-08-10'],
            SlotJadwal::hariKerja(3, new DateTimeImmutable('2026-08-06 08:00:00')),
        );
    }

    public function testDimulaiSabtuMelompatKeSenin(): void
    {
        // 2026-08-08 = Sabtu
        $this->assertSame(
            ['2026-08-10'],
            SlotJadwal::hariKerja(1, new DateTimeImmutable('2026-08-08 09:00:00')),
        );
    }

    /** Tujuh hari kerja dari Senin berakhir Selasa pekan berikutnya. */
    public function testTujuhHariKerjaMelewatiSatuAkhirPekan(): void
    {
        $tanggal = SlotJadwal::hariKerja(SlotJadwal::HARI_KERJA, new DateTimeImmutable(self::SENIN . ' 08:00:00'));

        $this->assertCount(7, $tanggal);
        $this->assertSame('2026-08-03', reset($tanggal));
        $this->assertSame('2026-08-11', end($tanggal));
    }

    public function testJumlahNolTidakMenghasilkanApaPun(): void
    {
        $this->assertSame([], SlotJadwal::hariKerja(0, new DateTimeImmutable(self::SENIN . ' 08:00:00')));
    }

    // --- pengelompokan per tanggal ---

    private function slot(string $waktu, int $kuota = 1): array
    {
        return ['scheduled_at' => $waktu, 'kuota' => $kuota];
    }

    public function testDikelompokkanPerTanggal(): void
    {
        $peta = SlotJadwal::perTanggal([
            $this->slot('2026-08-03 10:00:00'),
            $this->slot('2026-08-03 11:00:00'),
            $this->slot('2026-08-04 10:00:00'),
        ]);

        $this->assertSame(['2026-08-03', '2026-08-04'], array_keys($peta));
        $this->assertSame(['10:00', '11:00'], array_column($peta['2026-08-03'], 'jam'));
    }

    /**
     * Kuota dibandingkan dengan JUMLAH pemakai, bukan sekadar ada atau tidak.
     *
     * Inilah inti perubahan 28 Agustus 2026: slot berkuota 2 yang baru diambil
     * satu orang masih boleh dipilih.
     */
    public function testPenuhDitentukanKuotaBukanKeterisian(): void
    {
        $peta = SlotJadwal::perTanggal(
            [$this->slot('2026-08-03 10:00:00', 2), $this->slot('2026-08-03 11:00:00', 2)],
            ['2026-08-03 10:00:00' => 1, '2026-08-03 11:00:00' => 2],
        );

        $sepuluh = $peta['2026-08-03'][0];
        $sebelas = $peta['2026-08-03'][1];

        $this->assertSame(1, $sepuluh['terpakai']);
        $this->assertFalse($sepuluh['penuh'], 'kuota 2 baru terisi 1, masih boleh dipilih');
        $this->assertSame(2, $sebelas['terpakai']);
        $this->assertTrue($sebelas['penuh']);
    }

    public function testSlotKuotaSatuLangsungPenuhSetelahDiambil(): void
    {
        $peta = SlotJadwal::perTanggal(
            [$this->slot('2026-08-03 10:00:00')],
            ['2026-08-03 10:00:00' => 1],
        );

        $this->assertTrue($peta['2026-08-03'][0]['penuh']);
    }

    /**
     * Kuota nol berarti slot ditutup recruiter tanpa menghapusnya.
     *
     * Menghapus slot yang sudah dipegang kandidat akan menghilangkan jejak
     * jadwal yang dijanjikan kepadanya; menutupnya cukup untuk mencegah orang
     * baru masuk.
     */
    public function testKuotaNolLangsungPenuh(): void
    {
        $peta = SlotJadwal::perTanggal([$this->slot('2026-08-03 10:00:00', 0)]);

        $this->assertTrue($peta['2026-08-03'][0]['penuh']);
    }
}
