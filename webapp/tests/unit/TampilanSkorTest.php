<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Cara skor kemiripan CV ditampilkan ke manusia.
 *
 * Dulu ditampilkan sebagai "54 dari 100" dan itu berbohong: cosine similarity
 * punya lantai sekitar 0,54, jadi teks yang sama sekali tidak relevan pun
 * sampai ke sana. Angka-angka jangkar di bawah terukur, bukan tebakan
 * (docs/pipeline-screening-cv.md).
 *
 * @internal
 */
final class TampilanSkorTest extends CIUnitTestCase
{
    // jangkar hasil pengukuran terhadap lowongan Backend Developer
    private const RESEP_RENDANG = 0.5404;  // teks jelas tidak relevan
    private const CV_PERAWAT    = 0.6099;  // CV nyata, salah bidang
    private const CV_COCOK      = 0.8896;  // CV yang memang cocok

    public function testTeksTakRelevanTidakDitampilkanSebagaiSetengahCocok(): void
    {
        $badge = badge_skor(self::RESEP_RENDANG);

        // inti perbaikan: tidak ada lagi "/100" atau "dari 100" yang dibaca
        // orang sebagai persentase kecocokan
        $this->assertStringNotContainsString('/100', $badge);
        $this->assertStringNotContainsString('dari 100', $badge);
        $this->assertStringContainsString('rendah', $badge);
    }

    public function testPitaMengikutiJangkarYangTerukur(): void
    {
        $this->assertSame('rendah', kemiripan_pita(self::RESEP_RENDANG));
        $this->assertSame('tinggi', kemiripan_pita(self::CV_COCOK));
        // CV salah bidang tetap masuk "sedang" - model memang tidak bisa
        // membedakannya, dan tooltip harus mengakui itu
        $this->assertSame('sedang', kemiripan_pita(self::CV_PERAWAT));
    }

    public function testTooltipMengakuiLantaiSkor(): void
    {
        $badge = badge_skor(self::CV_PERAWAT);

        $this->assertStringContainsString('0,54', $badge, 'tooltip wajib menyebut lantai skor');
        $this->assertStringContainsString('bukan penilaian kualitas kandidat', $badge);
    }

    public function testAngkaMentahTetapIkutUntukAudit(): void
    {
        // pita saja tidak cukup: recruiter harus bisa menelusuri angka aslinya
        $this->assertSame('sedang (0,66)', kemiripan_teks(0.6568));
        $this->assertSame('tinggi (0,89)', kemiripan_teks(self::CV_COCOK));
    }

    public function testPitaSelaluCocokDenganAngkaYangDitampilkan(): void
    {
        // kasus nyata app#41: 0,5956 dibulatkan tampil "0,60", jadi tidak boleh
        // disebut "rendah" sementara ambang sedang juga 0,60
        $this->assertSame('sedang (0,60)', kemiripan_teks(0.5956));
        // sisi sebaliknya: yang benar-benar di bawah ambang tetap rendah
        $this->assertSame('rendah (0,59)', kemiripan_teks(0.5944));
    }

    public function testBelumAdaSkorTidakDikarang(): void
    {
        $this->assertNull(kemiripan_teks(null));
        $this->assertStringContainsString('belum ada', badge_skor(null));
    }

    public function testSkor100MasihDipakaiUntukSkalaYangMemangNol100(): void
    {
        // skor interview memang 0-100 dari slider recruiter, bukan cosine
        $this->assertSame('80', skor_100(0.80));
        $this->assertNull(skor_100(null));
    }
}
