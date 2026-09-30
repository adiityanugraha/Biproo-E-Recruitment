<?php

use App\Libraries\PemanduWawancara;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Aturan jalannya wawancara suara: butir mana yang ditanyakan, kapan AI
 * mendalami, dan kapan sesi ditutup.
 *
 * Diuji tanpa basis data, tanpa LLM, dan tanpa mikrofon - inilah alasan
 * logikanya dipisah ke library alih-alih ditulis di dalam controller.
 *
 * @internal
 */
final class PemanduWawancaraTest extends CIUnitTestCase
{
    /** Bank soal bergaya tim DS: berbobot, plus satu butir "Lainnya" tanpa bobot. */
    private function rubrik(): array
    {
        return [
            ['pertanyaan' => 'Ceritakan tentang diri Anda.', 'kompetensi' => 'Pembuka', 'kategori' => 'Lainnya'],
            ['pertanyaan' => 'Bagaimana Anda menghadapi penolakan?', 'kompetensi' => 'Ketahanan', 'bobot' => 5],
            ['pertanyaan' => 'Bandingkan dua produk ini.', 'kompetensi' => 'Perbandingan', 'bobot' => 4],
            ['pertanyaan' => 'Berapa ekspektasi gaji Anda?', 'kompetensi' => 'Gaji', 'kategori' => 'Lainnya'],
        ];
    }

    /** Satu baris interview_dialog yang sudah terjawab. */
    private function baris(int $urutan, string $sumber, ?int $butir, ?string $tingkat, ?string $jawaban = 'jawaban kandidat'): array
    {
        return [
            'urutan' => $urutan, 'sumber' => $sumber, 'butir_index' => $butir,
            'kompetensi' => 'K', 'bobot' => 5, 'pertanyaan' => 'T',
            'jawaban' => $jawaban, 'tingkat' => $tingkat, 'alasan' => '',
        ];
    }

    private function balasan(?string $tingkat, string $lanjutan = '', bool $nyambung = true): array
    {
        return ['tingkat' => $tingkat, 'lanjutan' => $lanjutan, 'nyambung' => $nyambung];
    }

    // --- rencana: butir mana yang ditanyakan ---------------------------------

    public function testRencanaMempertahankanUrutanAsliSaatSemuaMuat(): void
    {
        $r = PemanduWawancara::rencana($this->rubrik(), 8);

        $this->assertSame([0, 1, 2, 3], array_column($r, 'index'));
        $this->assertSame('Ketahanan', $r[1]['kompetensi']);
        $this->assertSame(5, $r[1]['bobot']);
    }

    public function testRencanaMendahulukanBobotTertinggiSaatHarusMemilih(): void
    {
        $r = PemanduWawancara::rencana($this->rubrik(), 2);

        // butir 1 (bobot 5) dan 2 (bobot 4) menang atas dua butir tanpa bobot
        $this->assertSame([1, 2], array_column($r, 'index'));
    }

    public function testYangTerpilihTetapDitanyakanMenurutUrutanAsli(): void
    {
        $rubrik = [
            ['pertanyaan' => 'A', 'bobot' => 1],
            ['pertanyaan' => 'B', 'bobot' => 9],
            ['pertanyaan' => 'C', 'bobot' => 5],
        ];
        // dipilih menurut bobot (B, C) tapi ditanyakan menurut urutan asli (B lalu C)
        $this->assertSame([1, 2], array_column(PemanduWawancara::rencana($rubrik, 2), 'index'));
    }

    public function testRencanaMenerimaPertanyaanBerupaStringBiasa(): void
    {
        // lowongan tanpa bank soal: pertanyaan_json cuma daftar string dari LLM
        $r = PemanduWawancara::rencana(['Ceritakan pengalaman Anda.', 'Kenapa melamar?'], 8);

        $this->assertCount(2, $r);
        $this->assertSame('Ceritakan pengalaman Anda.', $r[0]['pertanyaan']);
        $this->assertSame(0, $r[0]['bobot']);
    }

    public function testBarisKosongDibuangDariRencana(): void
    {
        $this->assertCount(1, PemanduWawancara::rencana(['', '   ', 'Pertanyaan nyata?'], 8));
    }

    // --- giliran berikutnya --------------------------------------------------

    public function testSesiBaruMulaiDariButirPertama(): void
    {
        $g = PemanduWawancara::berikutnya(PemanduWawancara::rencana($this->rubrik(), 8), [], null, 3, 12);

        $this->assertSame('bank', $g['sumber']);
        $this->assertSame(0, $g['butir_index']);
        $this->assertSame('Ceritakan tentang diri Anda.', $g['pertanyaan']);
    }

    public function testJawabanBerisiDidalamiDenganPertanyaanLanjutan(): void
    {
        $g = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($this->rubrik(), 8),
            [$this->baris(1, 'bank', 0, 'baik')],
            $this->balasan('baik', 'Kalau antreannya dua kali lipat, apa yang Anda lakukan?'),
            3,
            12,
        );

        $this->assertSame('lanjutan', $g['sumber']);
        $this->assertSame(0, $g['butir_index'], 'pendalaman menempel pada butir yang sama');
        $this->assertStringContainsString('dua kali lipat', $g['pertanyaan']);
    }

    public function testJawabanKurangTidakDidalamiTetapiLanjutKeButirBerikutnya(): void
    {
        // Menggali jawaban "kurang" membakar anggaran pada kandidat yang bahkan
        // belum menjawab pertanyaan pertamanya.
        $g = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($this->rubrik(), 8),
            [$this->baris(1, 'bank', 0, 'kurang')],
            $this->balasan('kurang', 'Boleh diperjelas?'),
            3,
            12,
        );

        $this->assertSame('bank', $g['sumber']);
        $this->assertSame(1, $g['butir_index']);
    }

    public function testSatuButirTidakDidalamiDuaKali(): void
    {
        $dialog = [$this->baris(1, 'bank', 0, 'baik'), $this->baris(2, 'lanjutan', 0, 'baik')];

        $g = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($this->rubrik(), 8),
            $dialog,
            $this->balasan('baik', 'Sekali lagi, bagaimana?'),
            3,
            12,
        );

        $this->assertSame('bank', $g['sumber']);
        $this->assertSame(1, $g['butir_index']);
    }

    public function testAnggaranPendalamanHabisMakaLanjutKeButirBerikutnya(): void
    {
        $dialog = [
            $this->baris(1, 'bank', 0, 'baik'), $this->baris(2, 'lanjutan', 0, 'baik'),
            $this->baris(3, 'bank', 1, 'baik'), $this->baris(4, 'lanjutan', 1, 'baik'),
        ];

        $g = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($this->rubrik(), 8),
            array_merge($dialog, [$this->baris(5, 'bank', 2, 'baik')]),
            $this->balasan('baik', 'Coba dalami lagi?'),
            2,   // anggaran 2, sudah terpakai 2
            12,
        );

        $this->assertSame('bank', $g['sumber']);
        $this->assertSame(3, $g['butir_index']);
    }

    public function testJawabanTidakNyambungMembuatPertanyaanDiulang(): void
    {
        $g = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($this->rubrik(), 8),
            [$this->baris(1, 'bank', 0, null)],
            $this->balasan(null, 'Maksud saya: coba ceritakan diri Anda singkat saja.', false),
            3,
            12,
        );

        $this->assertSame('ulangi', $g['sumber']);
        $this->assertSame(0, $g['butir_index']);
    }

    public function testPertanyaanYangSamaTidakDiulangDuaKali(): void
    {
        // Dua kali gagal biasanya mikrofonnya, bukan kandidatnya. Memaksa
        // mengulang terus cuma mempermalukan orang.
        $dialog = [$this->baris(1, 'bank', 0, null), $this->baris(2, 'ulangi', 0, null)];

        $g = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($this->rubrik(), 8),
            $dialog,
            $this->balasan(null, 'Ulangi sekali lagi?', false),
            3,
            12,
        );

        $this->assertSame('bank', $g['sumber']);
        $this->assertSame(1, $g['butir_index']);
    }

    public function testSemuaButirTerjawabMakaSesiDitutup(): void
    {
        $dialog = [
            $this->baris(1, 'bank', 0, 'baik'), $this->baris(2, 'bank', 1, 'baik'),
            $this->baris(3, 'bank', 2, 'baik'), $this->baris(4, 'bank', 3, 'baik'),
        ];

        $g = PemanduWawancara::berikutnya(PemanduWawancara::rencana($this->rubrik(), 8), $dialog, null, 3, 12);

        $this->assertSame('penutup', $g['sumber']);
        $this->assertSame(PemanduWawancara::PENUTUP, $g['pertanyaan']);
    }

    public function testPagarKuotaMenutupSesiWalauButirMasihSisa(): void
    {
        // Inilah yang mencegah satu kandidat menghabiskan kuota harian seluruh
        // sistem dengan memuat ulang halaman berkali-kali.
        $dialog = [$this->baris(1, 'bank', 0, 'baik'), $this->baris(2, 'bank', 1, 'baik')];

        $g = PemanduWawancara::berikutnya(PemanduWawancara::rencana($this->rubrik(), 8), $dialog, null, 3, 2);

        $this->assertSame('penutup', $g['sumber']);
    }

    public function testPertanyaanYangBelumDijawabTidakDihitungKeAnggaran(): void
    {
        $dialog = [$this->baris(1, 'bank', 0, 'baik'), $this->baris(2, 'bank', 1, null, null)];

        $g = PemanduWawancara::berikutnya(PemanduWawancara::rencana($this->rubrik(), 8), $dialog, null, 3, 2);

        $this->assertSame('bank', $g['sumber'], 'baru satu jawaban masuk, anggaran belum habis');
    }

    // --- penilaian -----------------------------------------------------------

    public function testPendalamanMenimpaPenilaianPertanyaanInduknya(): void
    {
        // Jawaban mengesankan yang runtuh saat digali tidak boleh tetap "baik".
        $dialog = [
            $this->baris(1, 'bank', 1, 'baik'),
            $this->baris(2, 'lanjutan', 1, 'kurang'),
        ];

        $this->assertSame('kurang', PemanduWawancara::tingkatPerButir($dialog)[1]['tingkat']);
    }

    public function testGiliranTanpaTingkatTidakMenghapusPenilaianSebelumnya(): void
    {
        // LLM gagal di giliran pendalaman -> tingkat null. Yang sudah dinilai tetap.
        $dialog = [$this->baris(1, 'bank', 1, 'baik'), $this->baris(2, 'lanjutan', 1, null)];

        $this->assertSame('baik', PemanduWawancara::tingkatPerButir($dialog)[1]['tingkat']);
    }

    public function testSkorSementaraMemakaiRumusRubrikYangSama(): void
    {
        $dialog = [
            $this->baris(1, 'bank', 0, 'baik'),      // tanpa bobot: tidak ikut
            $this->baris(2, 'bank', 1, 'baik'),      // bobot 5, nilai 2
            $this->baris(3, 'bank', 2, 'cukup'),     // bobot 4, nilai 1
        ];

        // (2*5 + 1*4) / (2*5 + 2*4) = 14/18 = 77,8 -> 78
        $this->assertSame(78, PemanduWawancara::skorSementara($this->rubrik(), $dialog));
    }

    public function testSkorSementaraNullSaatBelumAdaButirBerbobotDinilai(): void
    {
        $dialog = [$this->baris(1, 'bank', 0, 'baik')]; // butir "Lainnya", tanpa bobot

        $this->assertNull(PemanduWawancara::skorSementara($this->rubrik(), $dialog));
    }

    public function testSelesaiDitandaiOlehBarisPenutup(): void
    {
        $this->assertFalse(PemanduWawancara::selesai([$this->baris(1, 'bank', 0, 'baik')]));
        $this->assertTrue(PemanduWawancara::selesai([
            $this->baris(1, 'bank', 0, 'baik'),
            $this->baris(2, 'penutup', null, null, null),
        ]));
    }
}
