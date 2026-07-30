<?php

use App\Libraries\StageLogger;
use App\Libraries\ZoomService;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Alur interview (Fase 3): kandidat ajukan jadwal -> recruiter acc/tolak.
 * Acc -> meeting Zoom (di-mock) + email undangan. Tolak -> kandidat ajukan ulang.
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

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];
    private const JADWAL   = '2027-01-15T10:00'; // jauh di depan (lolos cek min 1 jam)

    private function fakeZoom(): void
    {
        Services::injectMock('zoomService', new class extends ZoomService {
            public function __construct() {}

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

    /** @return array{0:int,1:int} [candidateId, applicationId] */
    private function fixture(string $gate1): array
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        (new StageLogger())->log($aid, 'gate_1', $gate1, 'system', 'skor=0.8');

        return [(int) $cid, $aid];
    }

    private function sesiKandidat(int $cid): array
    {
        return ['candidate_id' => $cid, 'candidate_nama' => 'Sinta'];
    }

    public function testKandidatAjukanMembuatRequested(): void
    {
        [$cid, $aid] = $this->fixture('passed');

        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'requested', 'meeting_id' => null]);
    }

    public function testKandidatBelumLolosTidakBisaAjukan(): void
    {
        [$cid, $aid] = $this->fixture('flagged');

        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        $this->assertSame(0, (new InterviewModel())->where('application_id', $aid)->countAllResults());
    }

    public function testRecruiterAccBuatMeetingDanKirimUndangan(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        $this->withSession($this->sesiRec)->post("recruiter/interview/acc/{$aid}");

        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'approved', 'meeting_id' => '99999999999']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'penjadwalan', 'status' => 'entered']);
        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'undangan_interview']);
    }

    /** interview yang sudah di-acc & jadwalnya lewat (siap dinilai di tab Completed). */
    private function pastApprovedInterview(int $aid): void
    {
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'approved',
            'scheduled_at'   => '2020-01-01 10:00:00',
            'meeting_id'     => '111',
            'join_url'       => 'https://zoom.us/j/111',
        ]);
    }

    public function testTabCompletedTampilkanInterviewLewatWaktu(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')->assertSee('Sinta');
    }

    public function testSkorTinggiDihitungLolosCatatGate2BerkasDanEmail(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        // skor 90 (+ gate1 default 0.7) -> gabungan di atas ambang -> LOLOS otomatis
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '90']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'berkas_kontrak', 'status' => 'entered']);
        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'hasil_gate']);
    }

    public function testSkorRendahDihitungTidakLolos(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        // skor 20 -> gabungan di bawah ambang -> TIDAK LOLOS otomatis
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '20']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->dontSeeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'berkas_kontrak']);
    }

    public function testTabPassedTampilkanApprovedDenganLinkZoom(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);
        $this->withSession($this->sesiRec)->post("recruiter/interview/acc/{$aid}");

        $res = $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=passed');
        $res->assertSee('Sinta');      // masuk tab Passed
        $res->assertSee('Link Zoom');  // link Zoom tercantum
    }

    public function testTabFailedTampilkanRejected(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);
        $this->withSession($this->sesiRec)->post("recruiter/interview/tolak/{$aid}");

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=failed')->assertSee('Sinta');
    }

    public function testTolakMengirimEmailKeKandidat(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        $this->withSession($this->sesiRec)->post("recruiter/interview/tolak/{$aid}");

        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'jadwal_ditolak']);
    }

    public function testHalamanJadwalTampilUntukKandidatLolos(): void
    {
        [$cid, $aid] = $this->fixture('passed');

        $res = $this->withSession($this->sesiKandidat($cid))->get('jadwal');

        $res->assertStatus(200);
        $res->assertSee('Backend Developer'); // posisi yang lolos muncul
        $res->assertSee('Ajukan Jadwal');     // form pengajuan tersedia
    }

    public function testHanyaAjuanPendingMunculDiInterviewHrd(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        // masih requested -> muncul di halaman Interview HRD
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->assertSee('Sinta');

        // setelah di-acc (approved) -> tak lagi di daftar "menunggu acc"
        $this->withSession($this->sesiRec)->post("recruiter/interview/acc/{$aid}");
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->assertDontSee('Sinta');
    }

    public function testAccDariInterviewHrdBuatMeetingDanKembaliKeSana(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        $res = $this->withSession($this->sesiRec)->post("recruiter/interview/acc/{$aid}", ['kembali' => 'interview_hrd']);

        $res->assertRedirectTo(site_url('recruiter/tahap/interview_online'));
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'approved']);
    }

    public function testTolakLaluKandidatAjukanUlang(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        // recruiter tolak -> rejected
        $this->withSession($this->sesiRec)->post("recruiter/interview/tolak/{$aid}");
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'rejected']);

        // kandidat ajukan ulang -> requested lagi (baris sama, tak menumpuk)
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => '2027-02-20T14:00']);
        $this->assertSame(1, (new InterviewModel())->where('application_id', $aid)->countAllResults());
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'requested']);
    }

    /** interview approved dengan jadwal relatif ke sekarang, untuk uji gerbang link. */
    private function approvedInterviewPada(int $aid, string $offset): void
    {
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'approved',
            'scheduled_at'   => (new DateTime($offset))->format('Y-m-d H:i:s'),
            'meeting_id'     => '222',
            'join_url'       => 'https://zoom.us/j/222',
        ]);
    }

    public function testLinkDalamJendelaRedirectKeZoom(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');

        $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}")
            ->assertRedirectTo('https://zoom.us/j/222');
    }

    public function testLinkSetelahJendelaTutupTampilKedaluwarsaTanpaBocorkanUrlZoom(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '-3 hours');

        $res = $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}");

        $res->assertStatus(200);
        $res->assertSee('kedaluwarsa');
        $res->assertDontSee('zoom.us'); // URL Zoom asli tidak pernah sampai ke browser
    }

    public function testLinkSebelumJendelaBukaBelumBisaDipakai(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '+2 days');

        $res = $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}");

        $res->assertStatus(200);
        $res->assertSee('Belum waktunya');
        $res->assertDontSee('zoom.us');
    }

    public function testKandidatLainTidakBisaPakaiLinkOrang(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');
        $lain = (new CandidateModel())->insert(['nama' => 'Budi', 'email' => 'budi@example.com', 'password_hash' => 'x']);

        $this->withSession($this->sesiKandidat((int) $lain))->get("interview/masuk/{$aid}")
            ->assertRedirectTo(site_url('jadwal'));
    }

    public function testUndanganEmailMemuatGerbangBukanUrlZoomMentah(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->withSession($this->sesiKandidat($cid))->post("interview/ajukan/{$aid}", ['jadwal' => self::JADWAL]);

        $this->withSession($this->sesiRec)->post("recruiter/interview/acc/{$aid}");

        $baris   = (new EmailQueueModel())->where('template', 'undangan_interview')->first();
        $payload = json_decode($baris['payload_json'], true);
        $this->assertStringContainsString("interview/masuk/{$aid}", $payload['join_url']);
        $this->assertStringNotContainsString('zoom.us', $payload['join_url']);
        // join_url asli tetap tersimpan di DB untuk dipakai redirect
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'join_url' => 'https://zoom.us/j/99999999999']);
    }
}
