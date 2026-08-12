<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * Usia lembar profil dihitung dari tanggal lahir, bukan diambil dari CV.
 *
 * Semua uji memakai titik hitung TETAP (12 Agustus 2026). Tanpa itu uji ini
 * akan berubah hasilnya setiap tahun - jenis kerusakan yang muncul berbulan-
 * bulan setelah kodenya ditulis, saat tidak ada yang ingat kenapa.
 *
 * @internal
 */
final class UsiaDariDobTest extends CIUnitTestCase
{
    private DateTimeImmutable $kini;

    protected function setUp(): void
    {
        parent::setUp();
        helper('form');   // memuat app/Common.php
        $this->kini = new DateTimeImmutable('2026-08-12');
    }

    /** Bentuk yang benar-benar ada di CV Reza Rahmansyah. */
    public function testTanggalIndonesiaBerhurufTerbaca(): void
    {
        $this->assertSame(35, usia_dari_dob('01 Mei 1991', $this->kini));
    }

    /**
     * @dataProvider bentukTanggal
     */
    public function testBerbagaiBentukPenulisan(string $dob, int $harap): void
    {
        $this->assertSame($harap, usia_dari_dob($dob, $this->kini), $dob);
    }

    public static function bentukTanggal(): array
    {
        return [
            'nama bulan penuh'   => ['30 April 1999', 27],
            'nama bulan singkat' => ['5 Des 1990', 35],
            'huruf besar'        => ['01 MEI 1991', 35],
            'garis miring'       => ['01/05/1991', 35],
            'tanda hubung'       => ['01-05-1991', 35],
            'ISO tahun di depan' => ['1991-05-01', 35],
            'ada teks di depan'  => ['Medan, 01 Mei 1991', 35],
            'label ikut terbawa' => ['Tempat/Tanggal Lahir: Depok, 30 April 1999', 27],
        ];
    }

    /**
     * Ulang tahun belum lewat = usianya belum bertambah.
     *
     * Ini yang membuat menghitung usia tidak sesederhana mengurangi tahun.
     */
    public function testUlangTahunBelumLewatTidakDibulatkanNaik(): void
    {
        $this->assertSame(34, usia_dari_dob('13 Agustus 1991', $this->kini));
        $this->assertSame(35, usia_dari_dob('12 Agustus 1991', $this->kini), 'tepat hari ini sudah berulang tahun');
    }

    /**
     * @dataProvider tidakTerbaca
     */
    public function testYangTidakTerbacaJadiNull(?string $dob): void
    {
        $this->assertNull(usia_dari_dob($dob, $this->kini), var_export($dob, true));
    }

    public static function tidakTerbaca(): array
    {
        return [
            'kosong'           => [''],
            'null'             => [null],
            'strip'            => ['-'],
            'hanya tahun'      => ['1991'],
            'bulan tak dikenal' => ['01 Mayo 1991'],
            'tanggal mustahil' => ['31 Februari 1991'],
            'belum lahir'      => ['01 Mei 2030'],
            // Penyaring salah baca: tahun lulus atau periode kerja yang
            // tercomot ke kolom tanggal lahir menghasilkan usia di luar nalar.
            'terlalu muda'     => ['01 Mei 2020'],
            'terlalu tua'      => ['01 Mei 1930'],
        ];
    }
}
