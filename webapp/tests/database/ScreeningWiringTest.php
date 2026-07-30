<?php

use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Wiring CI4 <-> ai-service (Fase 4 Day 1, kontrak A3.1).
 * Jalur internal dijaga token X-Token; callback mendarat di screening_results;
 * lamaran tidak boleh gagal cuma karena ai-service mati.
 *
 * @internal
 */
final class ScreeningWiringTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private const TOKEN = 'token-uji-wiring';

    protected function setUp(): void
    {
        parent::setUp();
        config('AiService')->sharedToken = self::TOKEN;
    }

    /** @return array{0:int,1:int} [candidateId, applicationId] */
    private function fixture(): array
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/uji-wiring.pdf']);

        return [(int) $cid, $aid];
    }

    private function kirimCallback(array $body, ?string $token = self::TOKEN)
    {
        $req = $this->withBodyFormat('json');
        if ($token !== null) {
            $req = $req->withHeaders(['X-Token' => $token]);
        }

        return $req->post('screening/callback', $body);
    }

    private function bodySukses(int $appId): array
    {
        return [
            'screening_job_id' => 'job-abc',
            'job_id_internal'  => $appId,
            'status'           => 'success',
            'scores'           => ['overall' => null, 'skill' => null, 'pendidikan' => null, 'pengalaman' => null],
            'extracted_fields' => ['metode' => 'text-layer', 'n_karakter' => 2500, 'halaman_perlu_ocr' => []],
            'flags'            => [],
        ];
    }

    public function testCallbackSuksesMendaratDiScreeningResults(): void
    {
        [, $aid] = $this->fixture();

        $this->kirimCallback($this->bodySukses($aid))->assertStatus(200);

        $sr = (new ScreeningResultModel())->latestFor($aid);
        $this->assertSame('success', $sr['status']);
        $this->assertSame('ai-service', $sr['provider']);
        $this->assertStringContainsString('text-layer', $sr['extracted_json']);
        // sukses tidak menyentuh alur stage (masih digerakkan assessment sampai Day 3)
        $this->dontSeeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'ai_verification']);
    }

    public function testCallbackGagalEkstraksiTercatatSebagaiDiprosesUlang(): void
    {
        [, $aid] = $this->fixture();
        $body           = $this->bodySukses($aid);
        $body['status'] = 'failed_extraction';
        $body['extracted_fields']['catatan'] = 'berkas gambar (.jpg), tidak punya text layer';

        $this->kirimCallback($body)->assertStatus(200);

        // kandidat TIDAK gugur - masuk antrian ulang (pelajaran bug umur-nan DS)
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'ai_verification', 'status' => 'retry_queued']);
        $this->seeInDatabase('screening_results', ['application_id' => $aid, 'status' => 'failed_extraction']);
    }

    public function testCallbackTokenSalahDitolak(): void
    {
        [, $aid] = $this->fixture();

        $this->kirimCallback($this->bodySukses($aid), 'token-ngawur')->assertStatus(403);
        $this->kirimCallback($this->bodySukses($aid), null)->assertStatus(403);

        $this->assertSame(0, (new ScreeningResultModel())->where('application_id', $aid)->countAllResults());
    }

    public function testTokenKosongMenolakSemua(): void
    {
        // instalasi belum dikonfigurasi -> fail-closed, bukan terbuka diam-diam
        [, $aid] = $this->fixture();
        config('AiService')->sharedToken = '';

        $this->kirimCallback($this->bodySukses($aid), '')->assertStatus(403);
    }

    public function testCallbackPayloadTidakValidDitolak(): void
    {
        [, $aid] = $this->fixture();

        $b           = $this->bodySukses($aid);
        $b['status'] = 'status-aneh';
        $this->kirimCallback($b)->assertStatus(422);

        $b = $this->bodySukses(999999); // lamaran tidak ada
        $this->kirimCallback($b)->assertStatus(422);
    }

    public function testCvFileDenganTokenMenyajikanBerkas(): void
    {
        [, $aid] = $this->fixture();
        $path = WRITEPATH . 'uploads/cv/uji-wiring.pdf';
        file_put_contents($path, '%PDF-1.4 isi uji wiring');

        try {
            $res = $this->withHeaders(['X-Token' => self::TOKEN])->get("internal/cv/{$aid}");
            $res->assertStatus(200);

            $this->withHeaders(['X-Token' => 'salah'])->get("internal/cv/{$aid}")->assertStatus(403);
            $this->withHeaders(['X-Token' => self::TOKEN])->get('internal/cv/999999')->assertStatus(404);
        } finally {
            unlink($path);
        }
    }

    public function testSkorNyataDariCallbackDipakaiGate1BukanDummy(): void
    {
        [$cid, $aid] = $this->fixture();
        $body                      = $this->bodySukses($aid);
        $body['scores']['overall'] = 0.8123;
        $this->kirimCallback($body)->assertStatus(200);

        // kandidat mengerjakan assessment -> Gate 1 harus memakai 0.8123, bukan acak
        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid,
            'stage'          => 'ai_verification',
            'note'           => 'skor_cv=0.8123 (ai-service, fase4-day1-wiring)',
        ]);
        // gate1 = 0.5*0.8123 + 0.5*1.0 = 0.9062 (bobot default)
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid,
            'stage'          => 'gate_1',
            'note'           => 'skor_gabungan=0.9062',
        ]);
    }

    public function testSkorNullDariCallbackJatuhKeDummyDanDitandai(): void
    {
        [$cid, $aid] = $this->fixture();
        $this->kirimCallback($this->bodySukses($aid))->assertStatus(200); // overall null

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $r = (new \App\Models\StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'ai_verification', 'status' => 'passed'])->first();
        $this->assertStringContainsString('dummy', $r['note'], 'sumber dummy wajib jujur tertulis di riwayat');
    }

    // Jalur "upload tetap sukses saat ai-service mati" diverifikasi lewat e2e
    // HTTP nyata (upload multipart sungguhan dengan ai-service dimatikan) -
    // feature test CI4 tidak praktis untuk multipart, dan menguji mock yang
    // melempar exception buatan sendiri tidak membuktikan apa pun.
}