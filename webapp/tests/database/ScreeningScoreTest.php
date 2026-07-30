<?php

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Skor CV tinggal di screening_results, bukan di teks note.
 *
 * Rekomendasi Gate 2 memakai skor gabungan Gate 1 yang DIHITUNG ULANG dari
 * kolom (screening_results.score_overall + status online_assessment), bukan
 * ditarik dari kalimat catatan pakai regex seperti sebelumnya.
 *
 * Angka acuan (bobot default): gate1 = 0.5*cv + 0.5*assessment,
 * gate2 = 0.4*gate1 + 0.6*interview, ambang hire 0.7.
 *
 * @internal
 */
final class ScreeningScoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    /** @return array{0:int,1:int} [candidateId, applicationId] */
    private function fixture(): array
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        // bobot_json/threshold_json dibiarkan null -> GateOne::DEFAULTS berlaku
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        return [(int) $cid, $aid];
    }

    private function screening(int $aid, float $skorCv): void
    {
        (new ScreeningResultModel())->insert([
            'application_id'   => $aid,
            'screening_job_id' => 'uji-' . $aid,
            'status'           => 'success',
            'score_overall'    => $skorCv,
            'provider'         => 'dummy',
            'model_version'    => 'uji',
        ]);
    }

    /** Siapkan lamaran yang siap diputus di Gate 2, dengan hasil assessment tertentu. */
    private function siapDiputus(int $aid, string $assessment, ?string $noteGate1 = null): void
    {
        $logger = new StageLogger();
        $logger->log($aid, 'online_assessment', $assessment, 'system');
        $logger->log($aid, 'gate_1', 'passed', 'system', $noteGate1);
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'approved',
            'scheduled_at'   => '2020-01-01 10:00:00',
            'meeting_id'     => '333',
            'join_url'       => 'https://zoom.us/j/333',
        ]);
    }

    private function skorAkhir(int $aid): string
    {
        $r = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])->orderBy('id', 'DESC')->first();

        return (string) $r['note'];
    }

    public function testAlurAssessmentMenyimpanSkorKeScreeningResults(): void
    {
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $sr = (new ScreeningResultModel())->latestFor($aid);
        $this->assertNotNull($sr, 'alur assessment wajib membuat baris screening_results');
        $this->assertSame('success', $sr['status']);
        $this->assertSame('dummy', $sr['provider']);
        $this->assertGreaterThanOrEqual(0.30, (float) $sr['score_overall']);
        $this->assertLessThanOrEqual(0.90, (float) $sr['score_overall']);
    }

    public function testLatestForMengambilBarisTerbaru(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.40);
        $this->screening($aid, 0.85); // percobaan ulang -> yang dipakai

        $this->assertSame(0.85, round((float) (new ScreeningResultModel())->latestFor($aid)['score_overall'], 2));
    }

    public function testSkorCvTinggiMenghasilkanRekomendasiLolos(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.90);
        $this->siapDiputus($aid, 'passed');

        // gate1 = 0.5*0.90 + 0.5*1.0 = 0.95 ; gate2 = 0.4*0.95 + 0.6*0.70 = 0.80 -> hire
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '70']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
        $this->assertStringContainsString('skor_akhir=0.8', $this->skorAkhir($aid));
    }

    public function testSkorCvRendahMenghasilkanTidakLolosWalauInterviewSama(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.30);
        $this->siapDiputus($aid, 'failed');

        // gate1 = 0.5*0.30 + 0.5*0.0 = 0.15 ; gate2 = 0.4*0.15 + 0.6*0.70 = 0.48 -> no-hire
        // skor interview IDENTIK dengan test di atas -> yang membedakan hanya skor CV
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '70']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->assertStringContainsString('skor_akhir=0.48', $this->skorAkhir($aid));
    }

    /**
     * Regresi jalur lama: note yang mengaku skor tinggi TIDAK boleh dipercaya lagi.
     * Tanpa baris screening_results, sistem memakai default konservatif 0.7.
     * Jalur regex lama akan membaca 0.99 dari note dan memutus LOLOS di sini.
     */
    public function testNoteYangMengakuSkorTinggiDiabaikan(): void
    {
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed', 'skor_gabungan=0.99');

        // default 0.7 -> gate2 = 0.4*0.7 + 0.6*0.6 = 0.64 -> no-hire
        // regex lama (0.99) -> 0.4*0.99 + 0.36 = 0.756 -> LOLOS (perilaku yang dibuang)
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '60']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->assertStringContainsString('skor_akhir=0.64', $this->skorAkhir($aid));
    }
}
