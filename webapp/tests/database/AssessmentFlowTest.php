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

        // Gate 1 diputus assessment saja, jadi hasilnya pasti - bukan lagi
        // rentang skor gabungan dengan zona flagged
        $gate = (new StageHistoryModel())->where(['application_id' => $aid, 'stage' => 'gate_1'])->findAll();
        $this->assertCount(1, $gate);
        $this->assertSame('passed', $gate[0]['status']);
        $this->seeInDatabase('email_queue', ['to_email' => 'tes@example.com', 'template' => 'hasil_gate']);
    }

    public function testJawabanTidakMembuatGate1Failed(): void
    {
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Kandidat Tes'])
            ->post("assessment/{$aid}", ['jawaban' => 'tidak']);

        // jawaban "tidak" = tidak lulus assessment = gugur, tanpa perantara skor apa pun
        $gate = (new StageHistoryModel())->where(['application_id' => $aid, 'stage' => 'gate_1'])->first();
        $this->assertSame('failed', $gate['status']);
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

    /** Kelas keadaan (locked/current/done) langkah stepper yang menautkan ke $urlPart. */
    private function stateLangkah(string $html, string $urlPart): ?string
    {
        foreach (explode('class="step ', $html) as $blok) {
            if (str_contains($blok, $urlPart)) {
                return strtok($blok, '"');
            }
        }

        return null;
    }

    private function bukaDashboard(int $cid, int $aid): string
    {
        return (string) $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Kandidat Tes'])
            ->get('dashboard?app=' . $aid)->getBody();
    }

    public function testStepperAssessmentMenyalaBegituCvTerkirim(): void
    {
        [$cid, $aid] = $this->fixture();
        (new StageLogger())->log($aid, 'upload_cv', 'entered');

        // Screening CV sengaja belum jalan: assessment tidak bergantung padanya,
        // jadi tahapnya harus sudah menyala supaya kandidat tahu bisa mengerjakan.
        $this->assertSame('current', $this->stateLangkah($this->bukaDashboard($cid, $aid), "assessment/{$aid}"));
    }

    public function testStepperAssessmentTidakMenyalaLagiSetelahGate1Diputus(): void
    {
        [$cid, $aid] = $this->fixture();
        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Kandidat Tes'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->assertSame('done', $this->stateLangkah($this->bukaDashboard($cid, $aid), "assessment/{$aid}"));
    }
}
