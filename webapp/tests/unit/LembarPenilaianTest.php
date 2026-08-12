<?php

use App\Database\Migrations\LebarkanTingkatPenilaian;
use App\Libraries\LembarPenilaian as L;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Lembar penilaian mengikuti formulir BIPROO (arahan atasan 12 Agustus 2026).
 *
 * @internal
 */
final class LembarPenilaianTest extends CIUnitTestCase
{
    public function testSembilanKompetensiHrdSesuaiLembarAsli(): void
    {
        $this->assertCount(9, L::HRD);
        $this->assertSame('Appearance', L::HRD[0]);
        $this->assertSame('Service Orientation', L::HRD[8]);
    }

    public function testTujuhButirInterviewUser(): void
    {
        $this->assertCount(7, L::USER);
        $this->assertSame(10, L::MAKS_USER);
    }

    public function testNilaiFormDirakitJadiBarisPenilaian(): void
    {
        $baris = L::rakitHrd([0 => 3, 1 => 4]);

        $this->assertCount(2, $baris);
        $this->assertSame('Appearance', $baris[0]['kompetensi']);
        $this->assertSame('3', $baris[0]['tingkat']);
        $this->assertSame(L::KAT_HRD, $baris[0]['kategori']);
        $this->assertSame('Communication Skills', $baris[1]['kompetensi']);
    }

    /**
     * Nama kompetensi datang dari konstanta, bukan dari browser. Kalau tidak,
     * siapa pun bisa mengirim kompetensi karangan lewat form.
     */
    public function testNamaKompetensiTidakBisaDatangDariForm(): void
    {
        $baris = L::rakitHrd([0 => 5, 'kompetensi' => 'Karangan', 99 => 5]);

        $this->assertCount(1, $baris);
        $this->assertSame('Appearance', $baris[0]['kompetensi']);
    }

    public function testButirBelumDiisiTidakDianggapNol(): void
    {
        $baris = L::rakitHrd([0 => 4, 1 => '', 2 => null]);

        $this->assertCount(1, $baris, 'yang kosong tidak ikut, bukan dinilai nol');
    }

    public function testNilaiDiLuarRentangDitolak(): void
    {
        $this->assertSame([], L::rakitHrd([0 => 0]));
        $this->assertSame([], L::rakitHrd([0 => 6]));
        $this->assertSame([], L::rakitHrd([0 => 'baik']));
    }

    public function testSkalaTerendahBernilaiNolDanTertinggiSeratus(): void
    {
        $semuaSatu = L::rakitHrd(array_fill(0, 9, 1));
        $semuaLima = L::rakitHrd(array_fill(0, 9, 5));

        $this->assertSame(0, L::skor($semuaSatu));
        $this->assertSame(100, L::skor($semuaLima));
    }

    public function testAverageDiTengahSkala(): void
    {
        $this->assertSame(50, L::skor(L::rakitHrd(array_fill(0, 9, 3))));
    }

    public function testTanpaPenilaianSkorNull(): void
    {
        $this->assertNull(L::skor([]));
        $this->assertNull(L::skor(L::rakitHrd([], ['strengths' => 'bagus'])));
    }

    /** Kotak narasi tersimpan, tapi tidak boleh ikut jadi angka. */
    public function testNarasiTersimpanTanpaMempengaruhiSkor(): void
    {
        $baris = L::rakitHrd(array_fill(0, 9, 4), ['strengths' => 'Komunikatif', 'remarks' => 'ok']);

        $narasi = array_values(array_filter($baris, static fn ($b) => $b['kategori'] === L::KAT_NARASI));
        $this->assertCount(2, $narasi);
        $this->assertSame(0, $narasi[0]['bobot']);
        $this->assertSame('Komunikatif', $narasi[0]['catatan']);
        $this->assertSame(75, L::skor($baris), 'narasi tidak menggeser skor');
    }

    public function testNarasiKosongTidakDisimpan(): void
    {
        $baris = L::rakitHrd([0 => 3], ['strengths' => '   ', 'notes' => '']);

        $this->assertCount(1, $baris);
    }

    /**
     * Interview Result mengikuti Gate 2, tidak bisa bertentangan dengannya.
     *
     * Inilah alasan pilihan Recommended / Not Recommended dihapus dari form:
     * dulu recruiter mengisinya SEBELUM kelulusan dihitung, jadi lembar profil
     * bisa berbunyi "Recommended" pada kandidat yang tidak lolos.
     */
    public function testHasilDiturunkanDariKeputusanGateDua(): void
    {
        $this->assertSame('Recommended', L::hasil('passed'));
        $this->assertSame('Not Recommended', L::hasil('failed'));
    }

    /** Belum diputus - termasuk 'flagged' - berarti belum ada hasil, bukan Not Recommended. */
    public function testBelumDiputusTidakPunyaHasil(): void
    {
        $this->assertSame('', L::hasil('flagged'));
        $this->assertSame('', L::hasil(null));
    }

    public function testHasilTidakLagiDisimpanSebagaiBaris(): void
    {
        $baris = L::rakitHrd([0 => 3], ['notes' => 'oke']);

        $this->assertSame([], array_filter(
            $baris,
            static fn ($b) => ! in_array($b['kategori'], [L::KAT_HRD, L::KAT_NARASI], true)
        ));
    }

    /** Terlemah hanya yang di BAWAH Average. */
    public function testTerlemahHanyaYangDiBawahAverage(): void
    {
        $baris = L::rakitHrd([0 => 1, 1 => 2, 2 => 3, 3 => 5]);

        $this->assertSame(['Appearance', 'Communication Skills'], L::terlemah($baris));
    }

    public function testKandidatBagusTidakPunyaTerlemah(): void
    {
        $this->assertSame([], L::terlemah(L::rakitHrd(array_fill(0, 9, 4))));
    }

    /**
     * Penjaga terhadap bug yang lolos uji dan tumbang di produksi.
     *
     * 'not_recommended' 15 karakter, sedangkan kolom interview_penilaian.tingkat
     * semula VARCHAR(10). SQL Server menolaknya ("String or binary data would be
     * truncated"), SQLite - yang dipakai basis data uji - menerimanya utuh. Jadi
     * seluruh uji hijau sementara halaman aslinya error.
     *
     * Nilai itu sendiri sudah tidak disimpan lagi (Interview Result diturunkan
     * dari Gate 2), tapi penjaganya tetap: yang dijaga bukan satu nilai tertentu,
     * melainkan kebiasaan menambah nilai baru tanpa melihat lebar kolomnya.
     *
     * Uji ini tidak menyentuh basis data sama sekali: ia membandingkan nilai
     * terpanjang yang BISA dihasilkan lembar dengan lebar kolom, jadi benar di
     * basis data mana pun.
     */
    public function testNilaiTingkatMuatDiLebarKolom(): void
    {
        $semua = array_merge(
            array_map('strval', array_keys(L::SKALA)),
            array_map('strval', range(1, L::MAKS_USER)),
            [''],
        );

        foreach ($semua as $nilai) {
            $this->assertLessThanOrEqual(
                LebarkanTingkatPenilaian::LEBAR,
                strlen($nilai),
                "'{$nilai}' tidak muat di kolom tingkat"
            );
        }
    }

    public function testCatatanPanjangDipotong(): void
    {
        $baris = L::rakitHrd([0 => 3], ['notes' => str_repeat('a', 900)]);

        $narasi = array_values(array_filter($baris, static fn ($b) => $b['kategori'] === L::KAT_NARASI));
        $this->assertSame(L::MAKS_CATATAN, mb_strlen($narasi[0]['catatan']));
    }
}
