<?php

use App\Controllers\Recruiter;
use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Ruang interview recruiter (revisi 12 Agustus 2026).
 *
 * Satu halaman untuk satu kandidat, dibuka berdampingan dengan jendela Zoom:
 * tautan ruangannya, tiga pertanyaan dari CV kandidat itu, dan tempat
 * mengunggah rekaman setelah wawancara selesai.
 *
 * Menggantikan tombol "Pertanyaan" yang dulu membuka daftar milik LOWONGAN dan
 * sama untuk semua pelamarnya.
 *
 * @internal
 */
final class RuangInterviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private const RIWAYAT = [[
        'jabatan' => 'Clerk Distribution Center', 'perusahaan' => 'PT. Indomarco Prismatama',
        'periode' => '2012 - 2015', 'deskripsi' => 'Menginput data barang masuk dan keluar.',
    ]];

    /** Berkas sementara yang dibuat uji ini, dibersihkan di tearDown. */
    private array $sampah = [];

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        foreach ($this->sampah as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    /** Nomor urut kandidat: email dan slot jadwal keduanya wajib unik. */
    private int $urut = 0;

    private function fixture(array $riwayat = self::RIWAYAT): int
    {
        $n   = ++$this->urut;
        $cid = (new CandidateModel())->insert([
            'nama' => 'Reza ' . $n, 'email' => "reza{$n}@example.com", 'password_hash' => 'x',
        ]);
        $jid = (int) (new JobModel())->insert([
            'judul'          => 'Admin Gudang',
            'req_skill'      => 'Administrasi stok, Excel',
            'req_pendidikan' => 'D3 semua jurusan',
            'req_pengalaman' => '1 tahun logistik',
            'deskripsi'      => 'Mengelola pencatatan stok masuk-keluar gudang.',
        ]);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        // Jam diturunkan dari $aid: interviews punya indeks unik terfilter pada
        // scheduled_at, jadi dua kandidat dengan jam yang sama menabraknya.
        (new InterviewModel())->insert([
            'application_id' => $aid, 'status' => 'approved',
            'scheduled_at'   => sprintf('2030-08-20 %02d:00:00', 8 + $aid % 10),
            'meeting_id'     => '9988', 'join_url' => 'https://us04web.zoom.us/j/9988',
        ]);

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

    private function mockAi(array $balasan): void
    {
        Services::injectMock('aiService', new class ($balasan) extends AiService {
            public function __construct(private array $balasan)
            {
            }

            public function post(string $path, array $payload): array
            {
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

    private function tigaPertanyaan(string $sumber = 'pengalaman'): void
    {
        $this->mockAi(['sumber' => $sumber, 'pertanyaan' => [
            'Saat Anda menjabat Clerk Distribution Center di PT. Indomarco Prismatama, ceritakan ...',
            'Bayangkan ada selisih stok, apa yang Anda lakukan?',
            'Bagaimana Anda memastikan ketelitian data sehari-hari?',
        ]]);
    }

    /**
     * Siapkan $_FILES seolah ada unggahan.
     *
     * Lewat superglobal, bukan lewat helper uji: FeatureTestTrait CI4 tidak
     * punya cara mengirim berkas. Yang BISA diuji begini hanya aturan
     * validasinya - CI4 memberi FileRules cabang khusus lingkungan uji. Langkah
     * memindahkan berkas tidak bisa: UploadedFile::move() memanggil
     * is_uploaded_file(), yang menurut definisinya palsu kecuali berkasnya
     * benar-benar datang lewat POST HTTP. Bagian itu diperiksa manual.
     */
    private function siapkanBerkas(string $namaAsli, int $ukuran = 1024): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rek');
        file_put_contents($tmp, str_repeat('a', $ukuran));
        $this->sampah[] = $tmp;

        // Lewat layanan superglobals, bukan menulis $_FILES langsung: CI4
        // memotret superglobal itu saat layanannya dibuat, jadi penulisan
        // langsung tidak terlihat oleh FileCollection.
        service('superglobals')->setFilesArray(['rekaman' => [
            'name'     => $namaAsli,
            'type'     => 'audio/mp4',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => $ukuran,
        ]]);
    }

    // --- halaman ---

    public function testHalamanMemuatZoomPertanyaanDanUnggahan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();

        $res = $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $res->assertStatus(200);
        $html = (string) $res->getBody();
        $this->assertStringContainsString('us04web.zoom.us/j/9988', $html);
        $this->assertStringContainsString('PT. Indomarco Prismatama', $html);
        $this->assertStringContainsString('name="rekaman"', $html);
        $this->assertSame(3, substr_count($html, 'name="pertanyaan[]"'));
    }

    /**
     * Pertanyaan disusun saat halaman DIBUKA, bukan saat Gate 1 lolos.
     *
     * Kalau kuota habis di detik Gate 1, kandidat tersangkut tanpa pertanyaan
     * dan tanpa jalan keluar. Di sini kegagalan masih bisa jatuh ke bank soal.
     */
    public function testPertanyaanDisusunSaatHalamanDibukaLaluTersimpan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();

        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $json = (new ApplicationModel())->find($aid)['pertanyaan_json'];
        $this->assertCount(3, json_decode($json, true));
    }

    /**
     * 'posisi' dan 'bank' sama-sama berisi pertanyaan umum, tapi yang satu
     * memang seharusnya begitu dan yang satu lagi tanda kuota LLM habis. Kalau
     * keduanya terlihat sama di layar, kegagalan tidak akan pernah ketahuan.
     */
    public function testSumberBankDitandaiBerbedaDariSumberPosisi(): void
    {
        $aid = $this->fixture([]);
        $this->tigaPertanyaan('posisi');
        $posisi = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('dari uraian lowongan', $posisi);
        $this->assertStringNotContainsString('kuota hariannya habis', $posisi);

        $lain = $this->fixture([]);
        (new JobModel())->update((new ApplicationModel())->find($lain)['job_id'], [
            'pertanyaan_json' => json_encode(['Pertanyaan cadangan dari bank soal.']),
        ]);
        $this->mockAiMati();
        $bank = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $lain)->getBody();

        $this->assertStringContainsString('cadangan: bank soal lowongan', $bank);
        $this->assertStringContainsString('kuota hariannya habis', $bank);
    }

    public function testLamaranTidakDikenalDitolak(): void
    {
        $this->withSession($this->sesiRec)->get('recruiter/ruang/9999')->assertRedirect();
    }

    // --- sunting pertanyaan ---

    public function testRecruiterBisaMenyuntingPertanyaan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/pertanyaan', [
            'pertanyaan' => ['Sudah disunting', 'Kedua', 'Ketiga'],
        ]);

        $tersimpan = json_decode((new ApplicationModel())->find($aid)['pertanyaan_json'], true);
        $this->assertSame('Sudah disunting', $tersimpan[0]['pertanyaan']);
        $this->assertSame('pengalaman', $tersimpan[0]['sumber'], 'sumber tidak ikut berubah');
    }

    public function testSusunUlangMenggantiPertanyaan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $this->mockAi(['sumber' => 'pengalaman', 'pertanyaan' => ['Versi baru']]);
        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/pertanyaan', ['aksi' => 'buat']);

        $tersimpan = json_decode((new ApplicationModel())->find($aid)['pertanyaan_json'], true);
        $this->assertSame('Versi baru', $tersimpan[0]['pertanyaan']);
    }

    /**
     * Sesudah kandidat diputus, pertanyaannya jadi catatan: ia dasar penilaian
     * yang sudah terlanjur dipakai. Mengubahnya di belakang membuat lembar
     * profil bercerita tentang wawancara yang tidak pernah terjadi.
     */
    public function testPertanyaanTidakBisaDiubahSetelahKandidatDiputus(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);
        (new StageLogger())->log($aid, 'gate_2', 'passed', 'uji');

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/pertanyaan', [
            'pertanyaan' => ['Diselundupkan'],
        ]);

        $tersimpan = json_decode((new ApplicationModel())->find($aid)['pertanyaan_json'], true);
        $this->assertCount(3, $tersimpan);
        $this->assertStringNotContainsString('Diselundupkan', json_encode($tersimpan));
    }

    public function testHalamanKandidatYangSudahDiputusTanpaKotakIsian(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);
        (new StageLogger())->log($aid, 'gate_2', 'failed', 'uji');

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('PT. Indomarco Prismatama', $html, 'pertanyaannya tetap terbaca');
        $this->assertStringNotContainsString('name="pertanyaan[]"', $html);
        $this->assertStringNotContainsString('name="rekaman"', $html);
    }

    // --- unggah rekaman ---

    public function testBerkasBukanRekamanDitolak(): void
    {
        $aid = $this->fixture();
        $this->siapkanBerkas('virus.exe');

        $res = $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('rekaman audio atau video', session('error'));
    }

    public function testRekamanTerlaluBesarDitolak(): void
    {
        $aid = $this->fixture();
        $this->siapkanBerkas('panjang.m4a', (Recruiter::REKAMAN_MAKS_KB + 1024) * 1024);

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('maksimal', session('error'));
    }

    public function testTanpaBerkasDitolak(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
    }

    /**
     * Penjaga ini berjalan SEBELUM berkasnya diperiksa, jadi bisa diuji utuh.
     *
     * Form-nya memang disembunyikan setelah kandidat diputus, tapi
     * menyembunyikan form bukan penjagaan: kiriman ulang dari riwayat browser
     * tetap sampai ke controller.
     */
    public function testRekamanTidakBisaDiunggahSetelahKandidatDiputus(): void
    {
        $aid = $this->fixture();
        (new StageLogger())->log($aid, 'gate_2', 'passed', 'uji');
        $this->siapkanBerkas('telat.m4a');

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('sudah diputuskan', session('error'));
    }

    /**
     * Unggah ulang TIDAK menimpa baris lama.
     *
     * Transkrip adalah dasar penilaian, dan dasar penilaian harus bisa
     * ditelusuri ke belakang. Yang dipakai menilai selalu yang terbaru.
     *
     * Diuji lewat model, bukan lewat HTTP: langkah memindahkan berkas tidak
     * bisa dijalankan di lingkungan uji (lihat siapkanBerkas), sedangkan yang
     * dijaga di sini justru perilaku tabelnya.
     */
    public function testUnggahUlangMenambahBarisBaruBukanMenimpa(): void
    {
        $aid   = $this->fixture();
        $model = new InterviewTranskripModel();
        foreach (['uploads/rekaman/satu.m4a', 'uploads/rekaman/dua.m4a'] as $berkas) {
            $model->insert([
                'application_id' => $aid, 'sumber' => 'unggahan',
                'status' => 'antre', 'berkas' => $berkas,
            ]);
        }

        $this->assertCount(2, $model->where('application_id', $aid)->findAll());
        $this->assertSame('uploads/rekaman/dua.m4a', $model->terakhirUntuk($aid)['berkas']);
    }

    /**
     * Yang gagal atau masih diproses tidak boleh dipakai menilai.
     *
     * Penilaian dari transkrip separuh jadi lebih buruk daripada tidak menilai
     * sama sekali: hasilnya tetap berupa angka yang terlihat sah.
     */
    public function testHanyaTranskripSelesaiYangDipakaiMenilai(): void
    {
        $aid   = $this->fixture();
        $model = new InterviewTranskripModel();
        $model->insert(['application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'selesai',
            'berkas' => 'uploads/rekaman/lama.m4a', 'teks' => 'Halo, saya Reza.']);
        $model->insert(['application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'gagal',
            'berkas' => 'uploads/rekaman/baru.m4a', 'catatan' => 'audio tidak terbaca']);

        $this->assertSame('gagal', $model->terakhirUntuk($aid)['status']);
        $this->assertSame('Halo, saya Reza.', $model->selesaiUntuk($aid)['teks']);
    }

    public function testStatusRekamanTampilDiHalaman(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'gagal',
            'berkas' => 'uploads/rekaman/x.m4a', 'catatan' => 'audio tidak terbaca',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('transkripsi gagal', $html);
        $this->assertStringContainsString('audio tidak terbaca', $html);
    }

    // --- pintu masuknya ---

    /**
     * Tombolnya ada di DUA tab, dan itu bukan kelebihan.
     *
     * On Progress: recruiter membuka pertanyaan sebelum wawancara.
     * Completed:   recruiter mengunggah rekaman SESUDAH wawancara, dan saat itu
     *              kandidat sudah berpindah tab sendiri karena sesinya berakhir.
     */
    public function testTabelInterviewPunyaTombolRuangDiKeduaTab(): void
    {
        $akanDatang = $this->fixture();
        $sudah      = $this->fixture([]);
        (new InterviewModel())
            ->where('application_id', $sudah)
            ->set('scheduled_at', '2020-01-01 10:00:00')->update();

        $progress = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online')->getBody();
        $completed = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringContainsString('recruiter/ruang/' . $akanDatang, $progress);
        $this->assertStringContainsString('recruiter/ruang/' . $sudah, $completed);
    }
}
