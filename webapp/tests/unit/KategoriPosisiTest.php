<?php

use App\Libraries\KategoriPosisi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tebakan rumpun pekerjaan dari judul lowongan.
 *
 * Rancangannya diadaptasi dari InterviewQuestionModel tim DS. Yang diuji di sini
 * terutama BUKAN ketepatan tebakannya, melainkan bahwa hasilnya tidak pernah
 * mengaku pasti - `cocok` selalu false, supaya UI wajib mengatakannya ke
 * recruiter alih-alih menyodorkan pertanyaan orang lain diam-diam.
 *
 * @internal
 */
final class KategoriPosisiTest extends CIUnitTestCase
{
    public static function judulProvider(): array
    {
        return [
            'gudang'            => ['Admin Gudang', 'Warehouse & Logistik'],
            'kurir'             => ['Kurir Gudang Jakarta', 'Warehouse & Logistik'],
            'security'          => ['Security Officer', 'Security'],
            'teknisi'           => ['Teknisi Toko Bandung', 'Teknisi & Service'],
            'customer service'  => ['Customer Service Officer', 'Admin & Customer Service'],
            'f&b'               => ['Paris Baguette Crew', 'F&B Retail'],
            'store'             => ['Store Staff Under Armour', 'Store Generalist'],
            'sales gadget'      => ['Sales Assistant Erafone', 'Retail Gadget Sales'],
        ];
    }

    /** @dataProvider judulProvider */
    public function testMenebakRumpunDariKataKunci(string $judul, string $harapan): void
    {
        $this->assertSame($harapan, KategoriPosisi::tebak($judul)['kategori']);
    }

    public function testTebakanTidakPernahMengakuPasti(): void
    {
        $this->assertFalse(KategoriPosisi::tebak('Kurir Gudang')['cocok']);
    }

    public function testTidakDikenaliMengembalikanNullBukanKategoriAsal(): void
    {
        // Tim DS memakai 'sales_retail' sebagai cadangan supaya layar tidak
        // kosong. Di sini kosong justru benar: halamannya menawarkan pembuatan
        // lewat AI, dan menebak "sales" untuk Backend Developer cuma memindahkan
        // kesalahan ke tempat yang lebih sulit terlihat.
        $this->assertNull(KategoriPosisi::tebak('Backend Developer')['kategori']);
        $this->assertNull(KategoriPosisi::tebak('Data Scientist')['kategori']);
    }

    public function testJudulKosongTidakMenebakApaPun(): void
    {
        $this->assertNull(KategoriPosisi::tebak('   ')['kategori']);
    }

    /** Judul bisa memuat dua kata kunci; yang lebih khusus harus menang. */
    public function testYangLebihKhususDidahulukan(): void
    {
        $this->assertSame('Admin & Customer Service', KategoriPosisi::tebak('Sales Administration XPENG')['kategori']);
        $this->assertSame('Warehouse & Logistik', KategoriPosisi::tebak('Operator Gudang Retail')['kategori']);
    }
}
