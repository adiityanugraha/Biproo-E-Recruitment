<?php

use App\Controllers\Chat;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Chatbot status kandidat: perakit konteks (grounding) + guard input.
 * Jalur LLM nyata diuji di ai-service (test_main.py) dan verifikasi manual.
 *
 * @internal
 */
final class ChatTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private function fixture(): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Budi', 'email' => 'budi@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        (new StageLogger())->log($aid, 'upload_cv', 'entered');
        (new StageLogger())->log($aid, 'ai_verification', 'entered');

        return (int) $cid;
    }

    public function testBuildContextBerisiLamaranDanTahapBerbahasaIndonesia(): void
    {
        $ctx = Chat::buildContext($this->fixture());

        $this->assertStringContainsString('Backend Developer', $ctx);
        $this->assertStringContainsString('CV Terkirim', $ctx);        // label ID utk upload_cv
        $this->assertStringContainsString('Screening CV (AI)', $ctx);  // label ID utk ai_verification
    }

    public function testBuildContextKandidatTanpaLamaran(): void
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Kosong', 'email' => 'k@example.com', 'password_hash' => 'x']);
        $this->assertStringContainsString('belum memiliki lamaran', Chat::buildContext((int) $cid));
    }

    public function testAskMenolakPertanyaanKosong(): void
    {
        $cid = $this->fixture();
        $res = $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Budi'])
            ->post('chat/ask', ['question' => '   ']);

        $res->assertStatus(400);
        $res->assertJSONFragment(['error' => 'Pertanyaan kosong.']);
    }

    public function testAskSaatAiServiceMatiBalas503Ramah(): void
    {
        $cid = $this->fixture();
        // arahkan client ke port mati -> connection refused -> AiServiceException
        $cfg                 = config('AiService');
        $cfg->baseURL        = 'http://127.0.0.1:59999';
        $cfg->connectTimeout = 1;
        $cfg->timeout        = 1;

        $res = $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Budi'])
            ->post('chat/ask', ['question' => 'sampai tahap mana?']);

        $res->assertStatus(503);
        $res->assertJSONFragment(['error' => 'Asisten sedang tidak tersedia. Coba lagi sebentar lagi ya.']);
    }
}
