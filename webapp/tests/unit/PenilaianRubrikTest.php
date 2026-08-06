<?php

use App\Libraries\PenilaianRubrik;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Perhitungan skor interview dari rubrik per kompetensi.
 *
 * Menggantikan slider 0-100 yang digeser recruiter sesuka hati lalu ikut
 * menentukan Gate 2 - angka tanpa dasar yang tidak bisa dijelaskan ke kandidat.
 *
 * @internal
 */
final class PenilaianRubrikTest extends CIUnitTestCase
{
    /** @return list<mixed> */
    private function rubrik(): array
    {
        return [
            ['pertanyaan' => 'Pembuka', 'kompetensi' => 'Pembuka', 'kategori' => 'Lainnya'],
            ['pertanyaan' => 'A', 'kompetensi' => 'Komunikasi', 'kategori' => 'Soft Skill', 'bobot' => 5],
            ['pertanyaan' => 'B', 'kompetensi' => 'Ketelitian', 'kategori' => 'Hard Skill', 'bobot' => 4],
        ];
    }

    public function testButirTanpaBobotTidakIkutDinilai(): void
    {
        // "Lainnya" (gaji, ketersediaan, pembuka) memang ditanyakan tapi bukan
        // penilaian kemampuan - gagasan pemisahan ini dari tim DS.
        $this->assertSame(2, PenilaianRubrik::jumlahDinilai($this->rubrik()));
    }

    public function testSemuaBaikMenghasilkanSeratus(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'baik', 2 => 'baik']);

        $this->assertSame(100, PenilaianRubrik::skor($p));
    }

    public function testSemuaKurangMenghasilkanNol(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'kurang', 2 => 'kurang']);

        $this->assertSame(0, PenilaianRubrik::skor($p));
    }

    public function testSemuaCukupMenghasilkanLimaPuluh(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'cukup', 2 => 'cukup']);

        $this->assertSame(50, PenilaianRubrik::skor($p));
    }

    /** Rata-rata BERBOBOT: butir bobot 5 berpengaruh lebih besar dari bobot 4. */
    public function testBobotBenarBenarBerpengaruh(): void
    {
        $bobotBesarBaik = PenilaianRubrik::rakit($this->rubrik(), [1 => 'baik', 2 => 'kurang']);
        $bobotKecilBaik = PenilaianRubrik::rakit($this->rubrik(), [1 => 'kurang', 2 => 'baik']);

        $this->assertGreaterThan(
            PenilaianRubrik::skor($bobotKecilBaik),
            PenilaianRubrik::skor($bobotBesarBaik)
        );
        $this->assertSame(56, PenilaianRubrik::skor($bobotBesarBaik));   // 10/18
        $this->assertSame(44, PenilaianRubrik::skor($bobotKecilBaik));   // 8/18
    }

    /**
     * Rata-rata, bukan penjumlahan: posisi dengan 9 soal dan 10 soal harus
     * sebanding. Penjumlahan mentah membuat skor bergantung banyaknya pertanyaan.
     */
    public function testPosisiDenganJumlahSoalBerbedaTetapSebanding(): void
    {
        $tiga  = [['kompetensi' => 'a', 'bobot' => 5], ['kompetensi' => 'b', 'bobot' => 5], ['kompetensi' => 'c', 'bobot' => 5]];
        $enam  = array_merge($tiga, $tiga);
        $semua = static fn (array $r): array => PenilaianRubrik::rakit($r, array_fill(0, count($r), 'baik'));

        $this->assertSame(PenilaianRubrik::skor($semua($tiga)), PenilaianRubrik::skor($semua($enam)));
    }

    /** Butir yang belum diisi tidak dihitung nol - itu menggugurkan orang karena recruiter belum selesai. */
    public function testButirBelumDiisiTidakDianggapNol(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'baik']);

        $this->assertCount(1, $p);
        $this->assertSame(100, PenilaianRubrik::skor($p));
    }

    public function testTingkatKaranganDiabaikan(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'sempurna', 2 => 'baik']);

        $this->assertCount(1, $p);
        $this->assertSame('Ketelitian', $p[0]['kompetensi']);
    }

    /** Bobot dan kompetensi datang dari RUBRIK, bukan dari kiriman browser. */
    public function testBobotTidakBisaDititipkanLewatForm(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'baik', 2 => 'baik']);

        $this->assertSame(5, $p[0]['bobot']);
        $this->assertSame('Komunikasi', $p[0]['kompetensi']);
    }

    public function testTanpaButirDinilaiSkornyaNullBukanNol(): void
    {
        $this->assertNull(PenilaianRubrik::skor([]));
    }

    /** Yang membuat keputusan bisa dijelaskan: butir mana yang kurang, bukan sekadar angka. */
    public function testKompetensiTerlemahDiurutkanDariBobotTerbesar(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'kurang', 2 => 'kurang']);

        $this->assertSame(['Komunikasi', 'Ketelitian'], PenilaianRubrik::terlemah($p));
    }

    public function testTerlemahHanyaMemuatYangBernilaiKurang(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'baik', 2 => 'kurang']);

        $this->assertSame(['Ketelitian'], PenilaianRubrik::terlemah($p));
    }

    public function testCatatanDipangkasDanDirapikan(): void
    {
        $p = PenilaianRubrik::rakit($this->rubrik(), [1 => 'baik'], [1 => "  banyak   spasi\n\nberlebih  "]);

        $this->assertSame('banyak spasi berlebih', $p[0]['catatan']);
    }
}
