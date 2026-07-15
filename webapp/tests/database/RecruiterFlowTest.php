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

    public function testRecruiterBisaBuatLowongan(): void
    {
        $this->withSession($this->sesiRecruiter)->post('recruiter/lowongan', [
            'judul' => 'Posisi Baru', 'req_skill' => 'X', 'req_pendidikan' => 'Y', 'req_pengalaman' => 'Z',
        ]);

        $this->seeInDatabase('jobs', ['judul' => 'Posisi Baru']);
    }
}
