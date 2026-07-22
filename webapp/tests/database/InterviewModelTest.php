<?php

use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Tabel interviews (Fase 3): skema tersimpan & terbaca, field wajib divalidasi.
 *
 * @internal
 */
final class InterviewModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private function appId(): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'A', 'email' => 'a@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'X', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C']);

        return (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);
    }

    public function testSimpanDanBacaInterviewApproved(): void
    {
        $m   = new InterviewModel();
        $app = $this->appId();
        $id  = $m->insert([
            'application_id' => $app,
            'status'         => 'approved',
            'scheduled_at'   => '2026-08-10 10:00:00',
            'meeting_id'     => '87654321099',
            'join_url'       => 'https://zoom.us/j/87654321099?pwd=abc',
            'start_url'      => 'https://zoom.us/s/87654321099?zak=' . str_repeat('t', 400),
        ]);

        $this->assertIsInt($id);
        $row = $m->find($id);
        $this->assertSame('approved', $row['status']);
        $this->assertSame('87654321099', $row['meeting_id']);
        $this->assertNull($row['recording_url']); // menyusul saat auto-record aktif
    }

    public function testAjuanRequestedBolehTanpaMeeting(): void
    {
        $m   = new InterviewModel();
        $app = $this->appId();
        // ajuan awal kandidat: belum ada meeting_id/join_url (di-acc dulu)
        $id = $m->insert(['application_id' => $app, 'status' => 'requested', 'scheduled_at' => '2026-08-10 10:00:00']);

        $this->assertIsInt($id);
        $this->assertNull($m->find($id)['meeting_id']);
    }

    public function testFieldWajibDivalidasi(): void
    {
        $m = new InterviewModel();
        // tanpa status & scheduled_at -> insert gagal
        $this->assertFalse($m->insert(['application_id' => $this->appId()]));
        $this->assertArrayHasKey('status', $m->errors());
        $this->assertArrayHasKey('scheduled_at', $m->errors());
    }
}
