<?php

use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Helper baca "status terkini" pada candidate_stage_history (append-only):
 * baris terakhir per (application, stage) yang menang.
 *
 * @internal
 */
final class StageHistoryModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private function appId(string $email = 'a@example.com'): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'A', 'email' => $email, 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'X', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C']);

        return (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);
    }

    public function testLatestStatusAmbilBarisTerakhir(): void
    {
        $h   = new StageHistoryModel();
        $app = $this->appId();
        $h->insert(['application_id' => $app, 'stage' => 'gate_1', 'status' => 'flagged', 'actor' => 'system']);
        $h->insert(['application_id' => $app, 'stage' => 'gate_1', 'status' => 'passed', 'actor' => 'recruiter:X']);

        $this->assertSame('passed', $h->latestStatus($app, 'gate_1')); // baris kedua menang
        $this->assertNull($h->latestStatus($app, 'gate_2'));            // tak ada -> null
    }

    public function testLatestStatusMapSatuStatusPerStage(): void
    {
        $h   = new StageHistoryModel();
        $app = $this->appId('b@example.com');
        $h->insert(['application_id' => $app, 'stage' => 'upload_cv', 'status' => 'entered', 'actor' => 'system']);
        $h->insert(['application_id' => $app, 'stage' => 'ai_verification', 'status' => 'entered', 'actor' => 'system']);
        $h->insert(['application_id' => $app, 'stage' => 'ai_verification', 'status' => 'passed', 'actor' => 'system']);

        $map = $h->latestStatusMap($app);
        $this->assertSame('entered', $map['upload_cv']);
        $this->assertSame('passed', $map['ai_verification']); // baris terakhir menang
        $this->assertArrayNotHasKey('gate_1', $map);
    }
}
