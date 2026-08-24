<?php

use App\Libraries\SaranPosisi as S;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Saran posisi untuk kandidat yang gugur karena salah posisi.
 *
 * Aritmetika murni, tanpa basis data dan tanpa satu pun panggilan API - jadi
 * seluruh perilakunya bisa dikunci di sini, termasuk yang paling sulit diuji
 * lewat halaman: daftar yang sengaja kosong.
 *
 * @internal
 */
final class SaranPosisiTest extends CIUnitTestCase
{
    private const CV = ['pengalaman' => [1.0, 0.0]];

    /**
     * @param array<string, list<float>> $vektor
     *
     * @return array<string, mixed>
     */
    private function lowongan(int $id, string $judul, array $vektor): array
    {
        return ['id' => $id, 'judul' => $judul, 'vektor_json' => json_encode($vektor)];
    }

    // --- cosine ---

    public function testCosineVektorIdentikSatu(): void
    {
        $this->assertSame(1.0, round(S::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 6));
    }

    public function testCosineVektorTegakLurusNol(): void
    {
        $this->assertSame(0.0, round(S::cosine([1.0, 0.0], [0.0, 1.0]), 6));
    }

    /**
     * Panjang beda mengembalikan 0, bukan meledak.
     *
     * Bisa benar-benar terjadi: vektor lowongan dihitung hari ini, vektor CV
     * bulan lalu, dan di antaranya model embedding diganti.
     */
    public function testPanjangBedaJadiNolBukanError(): void
    {
        $this->assertSame(0.0, S::cosine([1.0, 2.0], [1.0]));
        $this->assertSame(0.0, S::cosine([], [1.0]));
    }

    public function testVektorNolJadiNol(): void
    {
        $this->assertSame(0.0, S::cosine([0.0, 0.0], [1.0, 1.0]));
    }

    // --- skor satu bidang ---

    /**
     * Pemeringkatan memakai SATU bidang, dan bidangnya pengalaman.
     *
     * Bukan selera: diukur atas 195 kandidat berlabel, pengalaman-saja
     * mengalahkan rumus berbobot 50/30/20 pada teks produksi (hit@3 51,9%
     * lawan 38,5%), dan pendidikan justru lebih buruk daripada menebak (0,78x).
     * Rinciannya di docs/kalibrasi-saran-posisi.md. Kalau konstanta ini diubah,
     * jalankan kalibrasi/saran_posisi.py lebih dulu.
     */
    public function testMemeringkatDariBidangPengalaman(): void
    {
        $this->assertSame('pengalaman', S::BIDANG);
    }

    public function testSkorBidangIdentikSatu(): void
    {
        $v = ['pengalaman' => [1.0, 0.0]];

        $this->assertSame(1.0, S::skor($v, $v));
    }

    /**
     * Bidang LAIN tidak ikut menentukan apa pun.
     *
     * Pendidikan yang cocok sempurna tidak boleh mengangkat posisi yang
     * pengalamannya tidak nyambung - itu persis yang dulu menyeret hasil turun.
     */
    public function testBidangLainTidakIkutDihitung(): void
    {
        $cv  = ['pengalaman' => [1.0, 0.0], 'pendidikan' => [1.0, 0.0], 'skill' => [1.0, 0.0]];
        $job = ['pengalaman' => [0.0, 1.0], 'pendidikan' => [1.0, 0.0], 'skill' => [1.0, 0.0]];

        $this->assertSame(0.0, S::skor($cv, $job));
    }

    public function testTanpaBidangPengalamanNull(): void
    {
        $this->assertNull(S::skor(['skill' => [1.0]], ['pengalaman' => [1.0]]));
        $this->assertNull(S::skor(['pengalaman' => [1.0]], ['pendidikan' => [1.0]]));
        $this->assertNull(S::skor([], ['pengalaman' => [1.0]]));
    }

    /** Cosine negatif jadi 0, bukan setengah mirip. */
    public function testCosineNegatifDipangkasKeNol(): void
    {
        $this->assertSame(0.0, S::skor(['pengalaman' => [1.0, 0.0]], ['pengalaman' => [-1.0, 0.0]]));
    }

    // --- pemilihan saran ---

    public function testTigaTeratasBerurutan(): void
    {
        $lowongan = [
            $this->lowongan(1, 'Jauh', ['pengalaman' => [0.0, 1.0]]),
            $this->lowongan(2, 'Persis', ['pengalaman' => [1.0, 0.0]]),
            $this->lowongan(3, 'Dekat', ['pengalaman' => [0.9, 0.1]]),
            $this->lowongan(4, 'Agak', ['pengalaman' => [0.7, 0.3]]),
            $this->lowongan(5, 'Sedang', ['pengalaman' => [0.6, 0.4]]),
        ];

        $saran = S::untuk(self::CV, $lowongan, 0.0);

        $this->assertSame(['Persis', 'Dekat', 'Agak'], array_column($saran, 'judul'));
        $this->assertCount(S::JUMLAH, $saran);
    }

    /** Posisi yang sudah pernah dilamar tidak boleh diusulkan lagi. */
    public function testYangSudahDilamarDikecualikan(): void
    {
        $lowongan = [
            $this->lowongan(1, 'Sudah dilamar', ['pengalaman' => [1.0, 0.0]]),
            $this->lowongan(2, 'Belum', ['pengalaman' => [0.9, 0.1]]),
        ];

        $saran = S::untuk(self::CV, $lowongan, 0.0, [1]);

        $this->assertSame(['Belum'], array_column($saran, 'judul'));
    }

    /**
     * INI yang membuat sarannya jujur: ambangnya kecocokan posisi yang baru
     * saja menolak kandidat. Yang tidak lebih baik dari itu tidak ditawarkan.
     */
    public function testHanyaYangTidakLebihRendahDariAmbang(): void
    {
        $lowongan = [
            $this->lowongan(1, 'Lebih baik', ['pengalaman' => [1.0, 0.0]]),
            $this->lowongan(2, 'Lebih buruk', ['pengalaman' => [0.5, 0.5]]),
        ];

        $saran = S::untuk(self::CV, $lowongan, 0.9);

        $this->assertSame(['Lebih baik'], array_column($saran, 'judul'));
    }

    /**
     * Daftar kosong adalah jawaban yang SAH, bukan kegagalan.
     *
     * Memaksakan tiga posisi asal supaya kotaknya terisi berarti mengirim orang
     * ke tempat yang tidak lebih cocok daripada yang baru saja menolaknya.
     */
    public function testBolehKosongSaatTidakAdaYangLebihCocok(): void
    {
        $lowongan = [$this->lowongan(1, 'Jauh', ['pengalaman' => [0.0, 1.0]])];

        $this->assertSame([], S::untuk(self::CV, $lowongan, 0.5));
    }

    /**
     * Skor 0 tidak pernah diusulkan, bahkan saat ambangnya juga 0.
     *
     * Tegak lurus berarti tidak ada satu pun kesamaan terukur. Tanpa penjagaan
     * ini, kandidat yang CV-nya tidak nyambung dengan posisi penolaknya akan
     * ditawari posisi yang sama tidak nyambungnya.
     */
    public function testSkorNolTidakPernahDiusulkan(): void
    {
        $lowongan = [$this->lowongan(1, 'Tegak lurus', ['pengalaman' => [0.0, 1.0]])];

        $this->assertSame([], S::untuk(self::CV, $lowongan, 0.0));
    }

    public function testTanpaVektorCvTidakMenyaranApaPun(): void
    {
        $lowongan = [$this->lowongan(1, 'Apa saja', ['pengalaman' => [1.0, 0.0]])];

        $this->assertSame([], S::untuk([], $lowongan, 0.0));
    }

    /** Lowongan yang belum punya vektor dilewati, bukan dianggap skor 0. */
    public function testLowonganTanpaVektorDilewati(): void
    {
        $lowongan = [
            ['id' => 1, 'judul' => 'Belum dihitung', 'vektor_json' => null],
            ['id' => 2, 'judul' => 'Rusak', 'vektor_json' => 'bukan json'],
            $this->lowongan(3, 'Siap', ['pengalaman' => [1.0, 0.0]]),
        ];

        $this->assertSame(['Siap'], array_column(S::untuk(self::CV, $lowongan, 0.0), 'judul'));
    }

    /**
     * Dua posisi berskor sama persis selalu tampil dalam urutan yang sama.
     *
     * Bukan kerapian: lowongan kembar seperti "Retail Gadget Ibox" dan
     * "Retail Gadget Erafone" memang berskor identik, dan urutan yang berubah
     * tiap halaman dimuat membuat saran di email berbeda dari saran di layar.
     */
    public function testUrutanStabilSaatSkorSama(): void
    {
        $sama     = ['pengalaman' => [1.0, 0.0]];
        $lowongan = [
            $this->lowongan(9, 'Sembilan', $sama),
            $this->lowongan(2, 'Dua', $sama),
            $this->lowongan(5, 'Lima', $sama),
        ];

        $this->assertSame(['Dua', 'Lima', 'Sembilan'], array_column(S::untuk(self::CV, $lowongan, 0.0), 'judul'));
        $this->assertSame(
            ['Dua', 'Lima', 'Sembilan'],
            array_column(S::untuk(self::CV, array_reverse($lowongan), 0.0), 'judul'),
            'urutan masukan tidak boleh mengubah hasil',
        );
    }
}
