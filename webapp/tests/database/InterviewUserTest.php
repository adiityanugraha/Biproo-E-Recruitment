<?php

use App\Libraries\AlurRekrutmen as A;
use App\Libraries\LembarPenilaian as L;
use App\Libraries\StageLogger;
use App\Models\AkunAtasanModel;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewPenilaianModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Interview User: akun atasan dan keputusan akhirnya (19 Agustus 2026).
 *
 * Alurnya: Head Developer meminta karyawan tambahan, HRD menyaring pelamarnya,
 * kandidat yang lolos wawancara HRD diteruskan ke atasan, dan ATASAN yang
 * memutuskan terakhir.
 *
 * @internal
 */
final class InterviewUserTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];
    private int $urut      = 0;

    private function lowongan(bool $pakaiUser = true): int
    {
        return (int) (new JobModel())->insert([
            'judul'          => 'Backend Developer ' . ++$this->urut,
            'req_skill'      => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th',
            'alur_json'      => $pakaiUser ? A::keJson(['interview_user']) : null,
        ]);
    }

    private function kandidat(int $jobId): int
    {
        $cid = (new CandidateModel())->insert([
            'nama' => 'Sinta ' . ++$this->urut, 'email' => "sinta{$this->urut}@example.com",
            'password_hash' => 'x',
        ]);

        return (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jobId, 'cv_path' => 'uploads/cv/x.pdf',
        ]);
    }

    /** Akun atasan siap pakai, beserta sesinya. */
    private function sesiAtasan(int $jobId, string $email = 'head@example.com'): array
    {
        $model = new AkunAtasanModel();
        $model->terbitkan($jobId, 'Head Developer', $email);
        $akun = $model->untukLowongan($jobId);

        return [
            'atasan_id' => $akun['id'], 'atasan_nama' => $akun['nama'],
            'atasan_job_id' => $jobId, 'atasan_posisi' => 'Backend Developer',
        ];
    }

    /** Kandidat yang sudah lolos tahap HRD dan menunggu atasan. */
    private function menungguAtasan(int $jobId): int
    {
        $aid = $this->kandidat($jobId);
        (new StageLogger())->log($aid, 'interview_online', 'passed', 'system:transkrip');
        (new StageLogger())->log($aid, 'interview_user', 'entered', 'system:transkrip');

        return $aid;
    }

    /** @return array<string, mixed> tujuh nilai penuh */
    private function nilaiPenuh(int $n = 8): array
    {
        return ['nilai' => array_fill(0, count(L::USER), $n)];
    }

    // --- akun ---

    public function testSandiTidakPernahDisimpanTerbaca(): void
    {
        $jobId = $this->lowongan();
        $sandi = (new AkunAtasanModel())->terbitkan($jobId, 'Head Developer', 'head@example.com');

        $akun = (new AkunAtasanModel())->untukLowongan($jobId);

        $this->assertNotSame($sandi, $akun['password_hash']);
        $this->assertTrue(password_verify($sandi, $akun['password_hash']));
    }

    /** Satu lowongan tidak boleh punya dua akun yang sama-sama berlaku. */
    public function testMenerbitkanUlangMenggantiBukanMenambah(): void
    {
        $jobId = $this->lowongan();
        $model = new AkunAtasanModel();

        $lama = $model->terbitkan($jobId, 'Head Lama', 'lama@example.com');
        $baru = $model->terbitkan($jobId, 'Head Baru', 'baru@example.com');

        $this->assertSame(1, $model->where('job_id', $jobId)->countAllResults());
        $akun = $model->untukLowongan($jobId);
        $this->assertSame('baru@example.com', $akun['email']);
        $this->assertFalse(password_verify($lama, $akun['password_hash']), 'sandi lama harus mati');
        $this->assertTrue(password_verify($baru, $akun['password_hash']));
    }

    /**
     * Menyimpan alur dengan Interview User menerbitkan akun dan mengirim
     * sandinya - dan sandinya TIDAK ikut ke layar HRD.
     */
    public function testMenyimpanAlurMenerbitkanAkunDanMengirimEmail(): void
    {
        $jobId = $this->lowongan(false);

        $res = $this->withSession($this->sesiRec)->post('recruiter/pengaturan/alur/' . $jobId, [
            'tahap'        => ['interview_user'],
            'atasan_nama'  => 'Head Developer',
            'atasan_email' => 'head@example.com',
        ]);

        $akun = (new AkunAtasanModel())->untukLowongan($jobId);
        $this->assertNotNull($akun);

        $antre = (new EmailQueueModel())->where('to_email', 'head@example.com')->first();
        $this->assertSame('akun_atasan', $antre['template']);

        $sandi = json_decode($antre['payload_json'], true)['sandi'];
        $this->assertTrue(password_verify($sandi, $akun['password_hash']));
        $this->assertStringNotContainsString($sandi, (string) $res->getBody(),
            'sandi tidak boleh tampil di layar HRD');
    }

    /** Posisi tanpa Interview User tidak menerbitkan akun apa pun. */
    public function testTanpaInterviewUserTidakAdaAkun(): void
    {
        $jobId = $this->lowongan(false);

        $this->withSession($this->sesiRec)->post('recruiter/pengaturan/alur/' . $jobId, [
            'tahap'        => ['disc'],
            'atasan_nama'  => 'Head Developer',
            'atasan_email' => 'head@example.com',
        ]);

        $this->assertNull((new AkunAtasanModel())->untukLowongan($jobId));
    }

    /**
     * Isian nama dan email atasan SELALU tampil di jendela sunting alur.
     *
     * Versi pertama menyembunyikannya sampai Interview User dipakai, dan itu
     * keliru: recruiter yang hendak MENYIAPKAN Interview User membuka jendela
     * ini lalu tidak menemukan tempat mengisi nama atasannya, sehingga fiturnya
     * seolah tidak ada. Yang tersembunyi tidak bisa ditemukan orang yang belum
     * tahu ia ada.
     */
    public function testIsianAtasanTampilWalauInterviewUserBelumDipakai(): void
    {
        $jobId = $this->lowongan(false);   // alurnya bawaan, tanpa Interview User

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $jobId)->getBody();

        $this->assertStringContainsString('name="atasan_nama"', $html);
        $this->assertStringContainsString('name="atasan_email"', $html);
        $this->assertStringContainsString('belum memakai', $html, 'keterangannya menyebut belum berlaku');
    }

    /** Yang sudah tersimpan ikut terisi kembali, bukan kotak kosong. */
    public function testIsianAtasanTerisiDariYangTersimpan(): void
    {
        $jobId = $this->lowongan();
        (new AkunAtasanModel())->terbitkan($jobId, 'Head Developer', 'head@example.com');

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $jobId)->getBody();

        $this->assertStringContainsString('value="Head Developer"', $html);
        $this->assertStringContainsString('value="head@example.com"', $html);
        $this->assertStringContainsString('Terakhir dikirim', $html);
    }

    /**
     * Halaman masuknya memakai layout bersama, bukan tata letak sendiri.
     *
     * Versi pertama menulis HTML-nya sendiri, dan itu membuat BIPROO punya dua
     * wajah halaman masuk yang berbeda - serta membuat perbaikan pada yang
     * bersama tidak pernah sampai ke sini.
     */
    public function testHalamanMasukMemakaiLayoutAuthYangSama(): void
    {
        $html = (string) $this->get('atasan/login')->getBody();

        // Penanda khas layout_auth: panel oranye BIPROO di sebelah kiri.
        $this->assertStringContainsString('auth-wrap', $html);
        $this->assertStringContainsString('Welcome to', $html);
        $this->assertStringContainsString('Sign In - Interview User', $html);
        $this->assertStringContainsString('atasan/login', $html);
    }

    /** Galatnya pun ikut tampilan bersama, bukan kotak buatan sendiri. */
    public function testGalatMasukMemakaiGayaBersama(): void
    {
        $html = (string) $this->post('atasan/login', [
            'email' => 'bukan@siapa.com', 'password' => 'salah',
        ])->getBody();

        $this->assertStringContainsString('pesan-error', $html);
        $this->assertStringContainsString('auth-wrap', $html);
    }

    // --- Gate 2 berhenti jadi keputusan akhir ---

    /**
     * INI perubahan intinya.
     *
     * Kandidat yang lolos wawancara HRD pada posisi ber-Interview User TIDAK
     * boleh dikabari "Anda diterima" - orang yang akan jadi atasannya belum
     * menemuinya, dan kalau ia lalu menolak, kandidat menerima dua surat yang
     * bertentangan.
     */
    public function testLolosHrdBelumMenutupGateDuaDanBelumMengirimEmail(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->kandidat($jobId);
        $this->kirimCallback($aid, 'recommended');

        $sh = new StageHistoryModel();
        $this->assertNull($sh->latestStatus($aid, 'gate_2'), 'keputusan akhir belum boleh jatuh');
        $this->assertSame('entered', $sh->latestStatus($aid, 'interview_user'));
        $this->assertNull($sh->latestStatus($aid, 'berkas_kontrak'));
        $this->assertSame(0, (new EmailQueueModel())->where('template', 'hasil_gate')->countAllResults());
    }

    /**
     * Gugur di tahap HRD tetap diputus dan dikabari saat itu juga.
     *
     * Kandidatnya memang tidak diteruskan ke atasan, dan menahan kabarnya cuma
     * membuat orang menunggu jawaban yang sebenarnya sudah ada.
     */
    public function testGugurDiHrdLangsungDiputusTanpaMenungguAtasan(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->kandidat($jobId);
        $this->kirimCallback($aid, 'not_recommended');

        $this->assertSame('failed', (new StageHistoryModel())->latestStatus($aid, 'gate_2'));
        $this->assertSame(1, (new EmailQueueModel())->where('template', 'hasil_gate')->countAllResults());
    }

    /** Posisi TANPA Interview User tetap seperti sebelumnya. */
    public function testPosisiTanpaInterviewUserTetapDiputusSetelahHrd(): void
    {
        $jobId = $this->lowongan(false);
        $aid   = $this->kandidat($jobId);
        $this->kirimCallback($aid, 'recommended');

        $sh = new StageHistoryModel();
        $this->assertSame('passed', $sh->latestStatus($aid, 'gate_2'));
        $this->assertSame('entered', $sh->latestStatus($aid, 'berkas_kontrak'));
    }

    // --- halaman atasan ---

    public function testDaftarHanyaMemuatKandidatLowonganSendiri(): void
    {
        $punyaSaya = $this->lowongan();
        $punyaOrang = $this->lowongan();
        $milikSaya = $this->menungguAtasan($punyaSaya);
        $milikOrang = $this->menungguAtasan($punyaOrang);

        $html = (string) $this->withSession($this->sesiAtasan($punyaSaya))->get('atasan')->getBody();

        $this->assertStringContainsString('atasan/nilai/' . $milikSaya, $html);
        $this->assertStringNotContainsString('atasan/nilai/' . $milikOrang, $html);
    }

    /**
     * Membatasinya bukan tautan, melainkan job_id dari SESI.
     *
     * Atasan yang mengetik id lamaran orang lain di alamat peramban tetap
     * ditolak - kalau tidak, satu akun untuk satu posisi cuma janji di tampilan.
     */
    public function testTidakBisaMembukaKandidatLowonganLain(): void
    {
        $punyaSaya  = $this->lowongan();
        $punyaOrang = $this->lowongan();
        $milikOrang = $this->menungguAtasan($punyaOrang);

        $this->withSession($this->sesiAtasan($punyaSaya))
            ->get('atasan/nilai/' . $milikOrang)->assertRedirect();
    }

    public function testKandidatYangBelumLolosHrdTidakMuncul(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->kandidat($jobId);   // belum ada interview_user

        $html = (string) $this->withSession($this->sesiAtasan($jobId))->get('atasan')->getBody();

        $this->assertStringNotContainsString('atasan/nilai/' . $aid, $html);
    }

    public function testTanpaLoginDitolak(): void
    {
        $this->get('atasan')->assertRedirectTo(site_url('atasan/login'));
    }

    // --- keputusan atasan ---

    public function testKeputusanAtasanMenutupGateDuaDanMengabariKandidat(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->menungguAtasan($jobId);

        $this->withSession($this->sesiAtasan($jobId))
            ->post('atasan/nilai/' . $aid, $this->nilaiPenuh(9) + ['keputusan' => 'lolos']);

        $sh = new StageHistoryModel();
        $this->assertSame('passed', $sh->latestStatus($aid, 'gate_2'));
        $this->assertSame('passed', $sh->latestStatus($aid, 'interview_user'));
        $this->assertSame('entered', $sh->latestStatus($aid, 'berkas_kontrak'));
        $this->assertSame(1, (new EmailQueueModel())->where('template', 'hasil_gate')->countAllResults());
    }

    public function testPenolakanAtasanTersimpanDanTidakMasukBerkasKontrak(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->menungguAtasan($jobId);

        $this->withSession($this->sesiAtasan($jobId))
            ->post('atasan/nilai/' . $aid, $this->nilaiPenuh(3) + ['keputusan' => 'gagal']);

        $sh = new StageHistoryModel();
        $this->assertSame('failed', $sh->latestStatus($aid, 'gate_2'));
        $this->assertNull($sh->latestStatus($aid, 'berkas_kontrak'));
    }

    public function testNilaiTersimpanSebagaiKategoriUserDariAtasan(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->menungguAtasan($jobId);

        $this->withSession($this->sesiAtasan($jobId))
            ->post('atasan/nilai/' . $aid, $this->nilaiPenuh(7) + ['keputusan' => 'lolos']);

        $baris = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_ATASAN])->findAll();

        $this->assertCount(count(L::USER), $baris);
        $this->assertSame(L::KAT_USER, $baris[0]['kategori']);
        $this->assertSame('7', $baris[0]['tingkat']);
    }

    /**
     * Lembar yang separuh terisi bukan penilaian, dan keputusan yang berdiri di
     * atasnya tidak bisa dipertanggungjawabkan kepada kandidat yang bertanya.
     */
    public function testNilaiTidakLengkapDitolakDanTidakMemutuskan(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->menungguAtasan($jobId);

        $this->withSession($this->sesiAtasan($jobId))
            ->post('atasan/nilai/' . $aid, ['nilai' => [8, 8], 'keputusan' => 'lolos']);

        $this->assertNull((new StageHistoryModel())->latestStatus($aid, 'gate_2'));
        $this->assertCount(0, (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_ATASAN])->findAll());
    }

    /** Nilai di luar skala 1-10 diperlakukan sama dengan kosong. */
    public function testNilaiDiLuarSkalaDitolak(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->menungguAtasan($jobId);

        $this->withSession($this->sesiAtasan($jobId))
            ->post('atasan/nilai/' . $aid, $this->nilaiPenuh(11) + ['keputusan' => 'lolos']);

        $this->assertNull((new StageHistoryModel())->latestStatus($aid, 'gate_2'));
    }

    /** Keputusan yang sudah dikirim ke kandidat tidak punya jalur pembatalan. */
    public function testKandidatYangSudahDiputusTidakBisaDinilaiUlang(): void
    {
        $jobId = $this->lowongan();
        $aid   = $this->menungguAtasan($jobId);
        $sesi  = $this->sesiAtasan($jobId);

        $this->withSession($sesi)->post('atasan/nilai/' . $aid, $this->nilaiPenuh(9) + ['keputusan' => 'lolos']);
        $this->withSession($sesi)->post('atasan/nilai/' . $aid, $this->nilaiPenuh(2) + ['keputusan' => 'gagal']);

        $this->assertSame('passed', (new StageHistoryModel())->latestStatus($aid, 'gate_2'));
        $this->assertSame(1, (new EmailQueueModel())->where('template', 'hasil_gate')->countAllResults());
    }

    /**
     * Tiga daftar template email harus sejalan.
     *
     * Nama template hidup di TIGA tempat: daftar putih EmailQueueModel, peta
     * subjek EmailQueueWorker, dan berkas view. Yang lolos daftar putih tapi
     * tidak punya subjek menjatuhkan SELURUH batch pengiriman, bukan cuma satu
     * barisnya - dan gagalnya baru terlihat saat cron berjalan, jauh dari tempat
     * template itu ditambahkan.
     */
    public function testDaftarTemplateEmailSejalan(): void
    {
        $aturan = (new EmailQueueModel())->getValidationRules()['template'];
        preg_match('/in_list\[([^\]]+)\]/', $aturan, $m);
        $daftar = explode(',', $m[1]);

        $subjek = (new ReflectionClass(\App\Libraries\EmailQueueWorker::class))
            ->getConstant('SUBJECTS');

        foreach ($daftar as $template) {
            $this->assertArrayHasKey($template, $subjek, $template . ' tidak punya subjek');
            $this->assertFileExists(APPPATH . 'Views/emails/' . $template . '.php',
                $template . ' tidak punya berkas view');
        }
    }

    /** Callback ai-service yang menutup tahap HRD. */
    private function kirimCallback(int $aid, string $rekomendasi): void
    {
        (new \App\Models\InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'proses',
            'berkas' => 'uploads/rekaman/x.wav',
        ]);
        (new \App\Models\ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'uji-' . $aid, 'status' => 'success',
            'score_overall'  => 0.8, 'provider' => 'dummy', 'model_version' => 'uji',
        ]);
        $model = new InterviewPenilaianModel();
        foreach (L::MATA_MANUSIA as $kompetensi) {
            $model->insert([
                'application_id' => $aid, 'kompetensi' => $kompetensi, 'kategori' => L::KAT_HRD,
                'sumber' => L::DARI_RECRUITER, 'bobot' => 1, 'tingkat' => '4', 'catatan' => '',
            ]);
        }

        config('AiService')->sharedToken = 'token-uji';
        $this->withHeaders(['X-Token' => 'token-uji'])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid,
                'status'         => 'selesai',
                'teks'           => 'Kandidat: saya pernah membangun API pembayaran.',
                'penilaian'      => array_map(
                    static fn (string $k): array => ['kompetensi' => $k, 'nilai' => 4, 'alasan' => 'a'],
                    L::dariTranskrip()
                ),
                'rekomendasi'        => $rekomendasi,
                'alasan_rekomendasi' => 'Pengalamannya relevan.',
                'kecocokan'          => 'tinggi',
                'alasan_kecocokan'   => 'Menjawab ketiga pertanyaan.',
            ]);
    }
}
