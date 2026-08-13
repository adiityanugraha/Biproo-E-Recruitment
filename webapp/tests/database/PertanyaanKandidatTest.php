<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Libraries\PertanyaanKandidat;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Tiga pertanyaan interview per KANDIDAT (revisi 12 Agustus 2026).
 *
 * Menggantikan pertanyaan per lowongan sebagai sumber utama. Bank soal lowongan
 * tetap ada, tapi turun peran jadi cadangan saat ai-service tidak menjawab.
 *
 * @internal
 */
final class PertanyaanKandidatTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    /** Riwayat asli dari CV Reza Rahmansyah di folder unggahan. */
    private const RIWAYAT = [
        ['jabatan' => 'Clerk Distribution Center', 'perusahaan' => 'PT. Indomarco Prismatama',
            'periode' => '2012 - 2015', 'deskripsi' => 'Menginput data barang masuk dan keluar.',
            'gaji_terakhir' => 'Rp 4.500.000', 'alasan_keluar' => 'Kontrak habis'],
        ['jabatan' => 'Assistant Chief Store', 'perusahaan' => 'PT. Sumber Alfaria Trijaya, Tbk',
            'periode' => '2015 - 2017', 'deskripsi' => 'Mengawasi operasional harian toko.'],
    ];

    private function lamaran(array $riwayat = [], ?string $bank = null): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Reza', 'email' => 'reza@example.com', 'password_hash' => 'x']);
        $jid = (int) (new JobModel())->insert([
            'judul'          => 'Admin Gudang',
            'req_skill'      => 'Administrasi stok, Excel',
            'req_pendidikan' => 'D3 semua jurusan',
            'req_pengalaman' => '1 tahun logistik',
            'deskripsi'      => 'Mengelola pencatatan stok masuk-keluar gudang.',
        ] + ($bank === null ? [] : ['pertanyaan_json' => $bank]));

        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        if ($riwayat !== []) {
            (new ScreeningResultModel())->insert([
                'application_id'   => $aid,
                'screening_job_id' => 'uji-' . $aid,
                'status'           => 'success',
                'score_overall'    => 0.7,
                'extracted_json'   => json_encode(['riwayat' => $riwayat]),
                'provider'         => 'dummy',
                'model_version'    => 'uji',
            ]);
        }

        return $aid;
    }

    /** @param array<string, mixed> $balasan */
    private function mockAi(array $balasan, ?array &$rekam = null): void
    {
        Services::injectMock('aiService', new class ($balasan, $rekam) extends AiService {
            public function __construct(private array $balasan, private ?array &$rekam)
            {
            }

            public function post(string $path, array $payload): array
            {
                $this->rekam = $payload;

                return $this->balasan;
            }
        });
    }

    private function mockAiMati(): void
    {
        Services::injectMock('aiService', new class () extends AiService {
            public function __construct()
            {
            }

            public function post(string $path, array $payload): array
            {
                throw new AiServiceException('ai-service tidak terjangkau');
            }
        });
    }

    public function testRiwayatKerjaKandidatIkutDikirimKeAiService(): void
    {
        $aid = $this->lamaran(self::RIWAYAT);
        $this->mockAi(['pertanyaan' => ['a', 'b', 'c'], 'sumber' => 'pengalaman'], $rekam);

        (new PertanyaanKandidat())->untukLamaran($aid);

        $this->assertCount(2, $rekam['riwayat']);
        $this->assertSame('Clerk Distribution Center', $rekam['riwayat'][0]['jabatan']);
        $this->assertSame(PertanyaanKandidat::JUMLAH, $rekam['jumlah']);
    }

    /**
     * Gaji terakhir dan alasan keluar TIDAK ikut terkirim.
     *
     * Keduanya ada di hasil baca CV dan tidak ada urusannya dengan menyusun
     * pertanyaan. Disaring dua kali - di sini dan sekali lagi di ai-service -
     * karena kebocoran data ke penyedia LLM tidak bisa ditarik kembali.
     */
    public function testGajiDanAlasanKeluarTidakIkutTerkirim(): void
    {
        $aid = $this->lamaran(self::RIWAYAT);
        $this->mockAi(['pertanyaan' => ['a'], 'sumber' => 'pengalaman'], $rekam);

        (new PertanyaanKandidat())->untukLamaran($aid);

        $terkirim = json_encode($rekam['riwayat']);
        $this->assertStringNotContainsString('4.500.000', $terkirim);
        $this->assertStringNotContainsString('Kontrak habis', $terkirim);
    }

    public function testHasilTersimpanDanTidakDibuatUlang(): void
    {
        $aid = $this->lamaran(self::RIWAYAT);
        $this->mockAi(['pertanyaan' => ['Pertama', 'Kedua', 'Ketiga'], 'sumber' => 'pengalaman']);

        $lib = new PertanyaanKandidat();
        $lib->untukLamaran($aid);

        // Panggilan kedua tidak boleh menyentuh ai-service lagi: kuota gratis
        // 20 panggilan sehari habis sebelum siang kalau tiap buka halaman.
        $this->mockAiMati();
        $kedua = $lib->untukLamaran($aid);

        $this->assertCount(3, $kedua);
        $this->assertSame('Pertama', $kedua[0]['pertanyaan']);
        $this->assertSame('pengalaman', $kedua[0]['sumber']);
    }

    public function testKandidatTanpaRiwayatDapatSumberPosisi(): void
    {
        $aid = $this->lamaran();
        $this->mockAi(['pertanyaan' => ['a', 'b', 'c'], 'sumber' => 'posisi'], $rekam);

        $hasil = (new PertanyaanKandidat())->untukLamaran($aid);

        $this->assertSame([], $rekam['riwayat']);
        $this->assertSame('posisi', $hasil[0]['sumber']);
    }

    /**
     * ai-service mati saat recruiter sudah duduk di ruang Zoom bersama kandidat.
     *
     * Wawancara tidak boleh terhenti karenanya. Bank soal lowongan dipinjam,
     * dan sumbernya ditandai 'bank' - bukan 'posisi' - supaya terlihat ini
     * jalan darurat, bukan pertanyaan yang memang disusun untuk lowongan itu.
     */
    public function testAiMatiJatuhKeBankSoalLowongan(): void
    {
        $aid = $this->lamaran(self::RIWAYAT, json_encode([
            'Ceritakan pengalaman Anda mengelola stok.',
            ['pertanyaan' => 'Bagaimana Anda menangani selisih stok?', 'kompetensi' => 'Ketelitian'],
        ]));
        $this->mockAiMati();

        $hasil = (new PertanyaanKandidat())->untukLamaran($aid);

        $this->assertCount(2, $hasil);
        $this->assertSame('bank', $hasil[0]['sumber']);
        $this->assertSame('Bagaimana Anda menangani selisih stok?', $hasil[1]['pertanyaan'],
            'rubrik bank soal ditanggalkan, teksnya saja yang dipakai');
    }

    /** ai-service mati DAN lowongan tidak punya bank soal: jangan simpan apa pun. */
    public function testTanpaBankSoalTidakMenyimpanDaftarKosong(): void
    {
        $aid = $this->lamaran();
        $this->mockAiMati();

        $lib = new PertanyaanKandidat();
        $this->assertSame([], $lib->untukLamaran($aid));

        // Karena tidak ada yang tersimpan, percobaan berikutnya mencoba lagi
        // alih-alih mengembalikan kekosongan selamanya.
        $this->mockAi(['pertanyaan' => ['Akhirnya jadi'], 'sumber' => 'posisi']);
        $this->assertCount(1, $lib->untukLamaran($aid));
    }

    public function testBuatUlangMenimpaYangTersimpan(): void
    {
        $aid = $this->lamaran(self::RIWAYAT);
        $lib = new PertanyaanKandidat();

        $this->mockAi(['pertanyaan' => ['Versi lama'], 'sumber' => 'pengalaman']);
        $lib->untukLamaran($aid);

        $this->mockAi(['pertanyaan' => ['Versi baru'], 'sumber' => 'pengalaman']);
        $hasil = $lib->buatUlang($aid);

        $this->assertSame('Versi baru', $hasil[0]['pertanyaan']);
        $this->assertSame('Versi baru', $lib->untukLamaran($aid)[0]['pertanyaan']);
    }

    /**
     * Recruiter menyunting teksnya, tapi TIDAK bisa memalsukan asal-usulnya.
     *
     * Sumber diambil dari yang tersimpan menurut urutan baris, bukan dari
     * kiriman browser: justru keterangan itu yang dibaca orang saat menimbang
     * apakah pertanyaannya wajar.
     */
    public function testSuntinganRecruiterTidakBisaMengubahSumber(): void
    {
        $aid = $this->lamaran(self::RIWAYAT);
        $lib = new PertanyaanKandidat();
        $this->mockAi(['pertanyaan' => ['Asli', 'Kedua'], 'sumber' => 'bank']);
        $lib->untukLamaran($aid);

        $hasil = $lib->simpanTeks($aid, ['Sudah disunting', 'Kedua']);

        $this->assertSame('Sudah disunting', $hasil[0]['pertanyaan']);
        $this->assertSame('bank', $hasil[0]['sumber']);
    }

    public function testPertanyaanKosongDanKepanjanganDirapikan(): void
    {
        $aid = $this->lamaran();
        $this->mockAi(['pertanyaan' => ['  ', str_repeat('a', 400), "dua   spasi\nberlebih"], 'sumber' => 'posisi']);

        $hasil = (new PertanyaanKandidat())->untukLamaran($aid);

        $this->assertCount(2, $hasil, 'yang kosong dibuang');
        $this->assertSame(PertanyaanKandidat::MAKS_PANJANG, mb_strlen($hasil[0]['pertanyaan']));
        $this->assertSame('dua spasi berlebih', $hasil[1]['pertanyaan']);
    }

    public function testLebihDariTigaDipotong(): void
    {
        $aid = $this->lamaran();
        $this->mockAi(['pertanyaan' => ['a', 'b', 'c', 'd', 'e'], 'sumber' => 'posisi']);

        $this->assertCount(3, (new PertanyaanKandidat())->untukLamaran($aid));
    }
}
