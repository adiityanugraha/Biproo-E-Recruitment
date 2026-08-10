<?php

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Alur web Day 3: dashboard recruiter - review manual kandidat ber-flag
 * (human-in-the-loop Gate 1) + pemisahan role kandidat vs recruiter.
 *
 * @internal
 */
final class RecruiterFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    /** @return int applicationId dengan gate_1 flagged */
    private function fixtureFlagged(): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Kandidat Abu', 'email' => 'abu@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Posisi Tes', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        $logger = new StageLogger();
        $logger->log($aid, 'upload_cv', 'entered');
        $logger->log($aid, 'gate_1', 'flagged', 'system', 'skor_gabungan=0.7');

        return $aid;
    }

    private array $sesiRecruiter = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    public function testApproveKandidatFlagged(): void
    {
        $aid = $this->fixtureFlagged();

        $this->withSession($this->sesiRecruiter)->post("recruiter/review/{$aid}", ['keputusan' => 'approve']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'passed', 'actor' => 'recruiter:Irpan',
        ]);
        // keputusan final recruiter memicu email hasil_gate ke kandidat
        $this->seeInDatabase('email_queue', ['to_email' => 'abu@example.com', 'template' => 'hasil_gate']);
    }

    public function testRejectKandidatFlagged(): void
    {
        $aid = $this->fixtureFlagged();

        $this->withSession($this->sesiRecruiter)->post("recruiter/review/{$aid}", ['keputusan' => 'reject']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'failed', 'actor' => 'recruiter:Irpan',
        ]);
    }

    /**
     * Detail kandidat dibuka di jendela pratinjau di atas tabel, memakai
     * layout_bingkai: tanpa topbar dan tanpa sidebar, karena keduanya sudah ada
     * di halaman induk. Gayanya tetap ikut (partials/gaya_isi), kalau tidak
     * isinya tampil polos tanpa kartu maupun tabel.
     */
    public function testDetailDalamBingkaiTanpaCangkangTapiTetapBergaya(): void
    {
        $aid = $this->fixtureFlagged();

        $penuh   = (string) $this->withSession($this->sesiRecruiter)->get("recruiter/review/{$aid}")->getBody();
        $bingkai = (string) $this->withSession($this->sesiRecruiter)->get("recruiter/review/{$aid}?bingkai=1")->getBody();

        $this->assertStringContainsString('class="topbar"', $penuh, 'versi penuh tetap punya topbar');
        $this->assertStringNotContainsString('class="topbar"', $bingkai);
        $this->assertStringNotContainsString('class="sidebar"', $bingkai);
        $this->assertStringContainsString('Kandidat Abu', $bingkai);
        $this->assertStringContainsString('.kartu {', $bingkai, 'gaya isi harus ikut terbawa');
    }

    /**
     * Setelah keputusan disimpan dari dalam bingkai, tujuannya halaman ini
     * sendiri. Mengarahkan ke daftar kandidat berarti menampilkan daftar kedua
     * di dalam kotak kecil, sementara daftar aslinya ada di halaman induk.
     */
    public function testKeputusanDariDalamBingkaiKembaliKeHalamanYangSama(): void
    {
        $aid = $this->fixtureFlagged();

        $this->withSession($this->sesiRecruiter)
            ->post("recruiter/review/{$aid}?bingkai=1", ['keputusan' => 'approve'])
            ->assertRedirectTo(site_url("recruiter/review/{$aid}") . '?bingkai=1');
    }

    /** Versi halaman penuh tetap ke daftar kandidat seperti sebelumnya. */
    public function testKeputusanDariHalamanPenuhTetapKeDaftarKandidat(): void
    {
        $aid = $this->fixtureFlagged();

        $this->withSession($this->sesiRecruiter)
            ->post("recruiter/review/{$aid}", ['keputusan' => 'approve'])
            ->assertRedirectTo(site_url('recruiter/kandidat'));
    }

    public function testReviewLamaranNonFlaggedDitolak(): void
    {
        $aid = $this->fixtureFlagged();
        // putuskan sekali (flagged -> passed) ...
        $this->withSession($this->sesiRecruiter)->post("recruiter/review/{$aid}", ['keputusan' => 'approve']);
        // ... keputusan kedua tidak boleh menambah baris gate_1
        $this->withSession($this->sesiRecruiter)->post("recruiter/review/{$aid}", ['keputusan' => 'reject']);

        $this->assertSame(2, (new StageHistoryModel()) // flagged + passed, tanpa baris ketiga
            ->where(['application_id' => $aid, 'stage' => 'gate_1'])->countAllResults());
    }

    public function testKandidatTidakBisaMasukAreaRecruiter(): void
    {
        $respons = $this->withSession(['candidate_id' => 1, 'candidate_nama' => 'Budi'])->get('recruiter');
        $respons->assertRedirectTo(site_url('recruiter/login'));
    }

    public function testTahapUploadCvHanyaPunyaSatuTabUploaded(): void
    {
        $this->fixtureFlagged();

        $res = $this->withSession($this->sesiRecruiter)->get('recruiter/tahap/upload_cv');

        // upload_cv tidak pernah punya status passed/failed, jadi tab itu selalu
        // kosong dan cuma bikin recruiter mengira ada kandidat yang hilang
        $res->assertSee('Uploaded');
        $res->assertDontSee('On Progress');
        $res->assertDontSee('Passed');
        $res->assertDontSee('Failed');
    }

    public function testTahapUploadCvMenampilkanSemuaCvYangMasuk(): void
    {
        $aid = $this->fixtureFlagged();

        // ?status=passed dulu memfilter habis daftarnya; sekarang diabaikan
        foreach (['', '?status=passed', '?status=failed'] as $q) {
            $this->withSession($this->sesiRecruiter)->get('recruiter/tahap/upload_cv' . $q)
                ->assertSee('Kandidat Abu');
        }

        $this->assertSame(1, (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'upload_cv'])->countAllResults());
    }

    public function testTahapLainTetapPunyaTabLengkap(): void
    {
        $this->fixtureFlagged();

        $res = $this->withSession($this->sesiRecruiter)->get('recruiter/tahap/online_assessment');

        $res->assertSee('On Progress');
        $res->assertSee('Passed');
        $res->assertSee('Failed');
    }
}
