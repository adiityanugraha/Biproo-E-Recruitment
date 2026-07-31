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

    public function testAssessmentTidakLagiMengarangSkorDummy(): void
    {
        // Dulu alur assessment menyisipkan skor acak ke screening_results supaya
        // Gate 1 punya angka. Gate 1 kini diputus assessment, jadi tidak ada lagi
        // alasan mengarang skor - dan angka karangan itu ikut ke keputusan akhir.
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->assertNull((new ScreeningResultModel())->latestFor($aid));
        $this->dontSeeInDatabase('screening_results', ['application_id' => $aid, 'provider' => 'dummy']);
    }

    public function testGate1DiputusAssessmentBukanSkorCv(): void
    {
        // skor CV sangat rendah, tapi assessment lulus -> Gate 1 LOLOS
        [$cid, $aid] = $this->fixture();
        $this->screening($aid, 0.05);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'passed',
        ]);
    }

    public function testAssessmentTidakLulusMenggugurkanWalauSkorCvTinggi(): void
    {
        [$cid, $aid] = $this->fixture();
        $this->screening($aid, 0.95);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'tidak']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'failed',
        ]);
    }

    public function testLatestForMengambilBarisTerbaru(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.40);
        $this->screening($aid, 0.85); // percobaan ulang -> yang dipakai

        $this->assertSame(0.85, round((float) (new ScreeningResultModel())->latestFor($aid)['score_overall'], 2));
    }

    public function testGate2SkorCvTinggiMenghasilkanLolos(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.90);
        $this->siapDiputus($aid, 'passed');

        // gate2 = 0.4*0.90 (CV) + 0.6*0.70 (interview) = 0.78 >= 0.7 -> LOLOS
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '70']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
        $this->assertStringContainsString('Skor akhir 78/100', $this->skorAkhir($aid));
        $this->assertStringContainsString('skor CV 90/100', $this->skorAkhir($aid));
    }

    public function testGate2SkorCvRendahMenggugurkanWalauInterviewSama(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.30);
        $this->siapDiputus($aid, 'passed');

        // gate2 = 0.4*0.30 + 0.6*0.70 = 0.54 < 0.7 -> TIDAK LOLOS
        // skor interview IDENTIK dengan test di atas; yang membedakan hanya skor CV
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '70']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->assertStringContainsString('Skor akhir 54/100', $this->skorAkhir($aid));
    }

    public function testGate2TanpaSkorCvMengalihkanBobotKeInterview(): void
    {
        // Tidak ada baris screening_results -> tidak ada skor CV. Bobot CV
        // dialihkan ke interview, BUKAN diisi angka karangan.
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed');

        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", ['skor' => '60']);

        $catatan = $this->skorAkhir($aid);
        $this->assertStringContainsString('skor CV belum tersedia', $catatan);
        $this->assertStringContainsString('Skor akhir 60/100', $catatan);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
    }

    public function testCatatanRiwayatTidakLagiMemuatTeksMentah(): void
    {
        // UI menampilkan catatan ini apa adanya, jadi tidak boleh berbentuk
        // "skor_gabungan=0.915" yang tak terbaca manusia.
        [$cid, $aid] = $this->fixture();
        $this->screening($aid, 0.68);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $r = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_1'])->orderBy('id', 'DESC')->first();
        $this->assertStringNotContainsString('skor_gabungan', (string) $r['note']);
        $this->assertStringNotContainsString('=0.', (string) $r['note']);
        $this->assertStringContainsString('assessment', (string) $r['note']);
    }
}
