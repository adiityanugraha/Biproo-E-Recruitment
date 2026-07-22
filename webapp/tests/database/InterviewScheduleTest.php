<?php

use App\Libraries\StageLogger;
use App\Libraries\ZoomService;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Penjadwalan interview (Fase 3): buat meeting Zoom -> simpan interviews ->
 * log penjadwalan + email undangan. ZoomService di-mock (tak menyentuh Zoom nyata).
 *
 * @internal
 */
final class InterviewScheduleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesi = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private function fakeZoom(): void
    {
        Services::injectMock('zoomService', new class extends ZoomService {
            public function __construct() {} // lewati constructor (butuh config Zoom)

            public function createMeeting(string $topic, ?string $startAt = null): array
            {
                return [
                    'meeting_id' => '99999999999',
                    'join_url'   => 'https://zoom.us/j/99999999999',
                    'start_url'  => 'https://zoom.us/s/99999999999?zak=x',
                ];
            }
        });
    }

    private function fixture(string $gate1): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        (new StageLogger())->log($aid, 'gate_1', $gate1, 'system', 'skor=0.8');

        return $aid;
    }

    public function testJadwalkanBuatInterviewDanKirimUndangan(): void
    {
        $this->fakeZoom();
        $aid = $this->fixture('passed');

        $this->withSession($this->sesi)->post("recruiter/jadwalkan/{$aid}", ['jadwal' => '2026-08-15T09:30']);

        $this->seeInDatabase('interviews', ['application_id' => $aid, 'meeting_id' => '99999999999']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'penjadwalan', 'status' => 'entered']);
        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'undangan_interview']);
    }

    public function testTidakBisaJadwalkanBilaBelumLolosGate1(): void
    {
        $this->fakeZoom();
        $aid = $this->fixture('flagged');

        $this->withSession($this->sesi)->post("recruiter/jadwalkan/{$aid}", ['jadwal' => '2026-08-15T09:30']);

        $this->assertSame(0, (new InterviewModel())->where('application_id', $aid)->countAllResults());
    }

    public function testTidakBisaJadwalkanDuaKali(): void
    {
        $this->fakeZoom();
        $aid = $this->fixture('passed');

        $this->withSession($this->sesi)->post("recruiter/jadwalkan/{$aid}", ['jadwal' => '2026-08-15T09:30']);
        $this->withSession($this->sesi)->post("recruiter/jadwalkan/{$aid}", ['jadwal' => '2026-08-16T10:00']);

        $this->assertSame(1, (new InterviewModel())->where('application_id', $aid)->countAllResults());
    }
}
