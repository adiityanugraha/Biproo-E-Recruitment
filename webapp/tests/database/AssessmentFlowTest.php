<?php

use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Alur web Day 2: assessment placeholder (ya/tidak) memicu rangkaian
 * ai_verification (dummy) -> online_assessment -> Gate 1 + email.
 *
 * @internal
 */
final class AssessmentFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    /** @return array{0:int,1:int} [candidateId, applicationId] */
    private function fixture(): array
    {
        $cid = (new CandidateModel())->insert([
            'nama' => 'Kandidat Tes', 'email' => 'tes@example.com', 'password_hash' => 'x',
        ]);
        $jid = (new JobModel())->insert([
            'judul' => 'Posisi Tes', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C',
        ]);
        $aid = (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/tes.pdf',
        ]);

        return [(int) $cid, (int) $aid];
    }

    public function testJawabanYaMenghasilkanKeputusanGate1DanEmail(): void
    {
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Kandidat Tes'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'online_assessment', 'status' => 'passed']);

        // jawaban "ya" (1.0) + cv dummy 0.30-0.90 -> gabungan 0.65-0.95: passed atau flagged, tak pernah failed
        $gate = (new StageHistoryModel())->where(['application_id' => $aid, 'stage' => 'gate_1'])->findAll();
        $this->assertCount(1, $gate);
        $this->assertContains($gate[0]['status'], ['passed', 'flagged']);

        // email hasil_gate hanya utk keputusan final; flagged = review internal tanpa email
        if ($gate[0]['status'] === 'passed') {
            $this->seeInDatabase('email_queue', ['to_email' => 'tes@example.com', 'template' => 'hasil_gate']);
        }
    }

    public function testJawabanTidakMembuatGate1Failed(): void
    {
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Kandidat Tes'])
            ->post("assessment/{$aid}", ['jawaban' => 'tidak']);

        // "tidak" (0.0) + cv maks 0.90 -> gabungan maks 0.45 -> selalu failed (< lower 0.45? tepat 0.45=flagged)
        $gate = (new StageHistoryModel())->where(['application_id' => $aid, 'stage' => 'gate_1'])->first();
        $this->assertContains($gate['status'], ['failed', 'flagged']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'online_assessment', 'status' => 'failed']);
    }

    public function testSubmitDobelDitolak(): void
    {
        [$cid, $aid] = $this->fixture();
        $sesi = ['candidate_id' => $cid, 'candidate_nama' => 'Kandidat Tes'];

        $this->withSession($sesi)->post("assessment/{$aid}", ['jawaban' => 'ya']);
        $this->withSession($sesi)->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->assertSame(1, (new StageHistoryModel())->where(['application_id' => $aid, 'stage' => 'gate_1'])->countAllResults());
    }

    public function testLamaranOrangLainTidakBisaDiakses(): void
    {
        [, $aid] = $this->fixture();
        $lain = (new CandidateModel())->insert(['nama' => 'Orang Lain', 'email' => 'lain@example.com', 'password_hash' => 'x']);

        $this->withSession(['candidate_id' => (int) $lain, 'candidate_nama' => 'Orang Lain'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->assertSame(0, (new StageHistoryModel())->where('application_id', $aid)->countAllResults());
    }
}
