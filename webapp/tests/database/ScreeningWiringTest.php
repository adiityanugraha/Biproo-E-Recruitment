<?php

use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;
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
        // sukses TANPA skor (bidang tak bisa dinilai) -> minta mata manusia,
        // bukan diberi angka karangan
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'ai_verification', 'status' => 'flagged',
        ]);
    }

    public function testCallbackBerskorLangsungMencatatAiVerificationPassed(): void
    {
        [, $aid] = $this->fixture();
        $body                      = $this->bodySukses($aid);
        $body['scores']['overall'] = 0.7412;

        $this->kirimCallback($body)->assertStatus(200);

        // dicatat saat callback tiba, tidak menunggu kandidat mengerjakan assessment
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid,
            'stage'          => 'ai_verification',
            'status'         => 'passed',
            'note'           => 'Kemiripan CV terhadap lowongan: sedang (0,74) (fase4-embedding-cosine-v1)',
        ]);
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

    public function testSkorNyataDariCallbackTercatatSebagaiAngkaTerbaca(): void
    {
        [$cid, $aid] = $this->fixture();
        $body                      = $this->bodySukses($aid);
        $body['scores']['overall'] = 0.8123;
        $this->kirimCallback($body)->assertStatus(200);

        // callback mencatat pita + angka terbaca, bukan "skor_cv=0.8123" mentah
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid,
            'stage'          => 'ai_verification',
            'status'         => 'passed',
            'note'           => 'Kemiripan CV terhadap lowongan: tinggi (0,81) (fase4-embedding-cosine-v1)',
        ]);

        // Gate 1 tetap diputus assessment, bukan skor 0.8123 itu
        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'tidak']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'failed',
        ]);
    }

    public function testTanpaSkorCvGate1TetapDiputusAssessment(): void
    {
        // Tidak ada callback sama sekali. Gate 1 tidak lagi bergantung skor CV,
        // jadi alur tetap jalan tanpa mengarang angka apa pun.
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $r = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_1'])->first();
        $this->assertSame('passed', $r['status']);

        $ai = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'ai_verification', 'status' => 'passed'])->first();
        $this->assertStringContainsString('belum menghasilkan skor', (string) $ai['note']);
        $this->dontSeeInDatabase('screening_results', ['application_id' => $aid, 'provider' => 'dummy']);
    }

    public function testCallbackSusulanMenambahBarisSkorTanpaMengulangEntered(): void
    {
        [$cid, $aid] = $this->fixture();
        // kandidat lebih cepat: assessment jalan sebelum callback tiba
        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $body                      = $this->bodySukses($aid);
        $body['scores']['overall'] = 0.9;
        $this->kirimCallback($body)->assertStatus(200);

        $rows = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'ai_verification'])->orderBy('id')->findAll();

        // 'entered' TIDAK diulang - tahapnya sudah dimulai
        $this->assertSame(1, count(array_filter($rows, fn ($r) => $r['status'] === 'entered')));
        // skor susulan MUNCUL sebagai baris baru, bukan menimpa yang lama.
        // Tanpa ini recruiter melihat "belum ada skor" padahal skornya sudah tiba.
        $this->assertStringContainsString('tinggi (0,90)', (string) end($rows)['note']);
    }

    // Jalur "upload tetap sukses saat ai-service mati" diverifikasi lewat e2e
    // HTTP nyata (upload multipart sungguhan dengan ai-service dimatikan) -
    // feature test CI4 tidak praktis untuk multipart, dan menguji mock yang
    // melempar exception buatan sendiri tidak membuktikan apa pun.

    public function testResendMencatatSkorBaruWalauRiwayatSudahAdaBarisTanpaSkor(): void
    {
        // Skenario nyata: ai-service mati saat upload, kandidat lanjut assessment
        // (riwayat memuat "belum ada skor"), lalu screening:resend berhasil.
        // Skor baru WAJIB muncul sebagai baris baru - kalau tidak, recruiter
        // melihat "belum ada skor" padahal skornya sudah ada.
        [$cid, $aid] = $this->fixture();
        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $b                      = $this->bodySukses($aid);
        $b['scores']['overall'] = 0.6551;
        $this->kirimCallback($b)->assertStatus(200);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid,
            'stage'          => 'ai_verification',
            'status'         => 'passed',
            'note'           => 'Kemiripan CV terhadap lowongan: sedang (0,66) (fase4-embedding-cosine-v1)',
        ]);
    }

    public function testPenilaianUlangDenganSkorBerbedaTercatatBesertaAngkaLamanya(): void
    {
        // screening:resend --paksa setelah pipeline diperbaiki: skor lama tetap
        // tersimpan, perubahannya muncul sebagai baris riwayat baru.
        [, $aid] = $this->fixture();

        $b                      = $this->bodySukses($aid);
        $b['scores']['overall'] = 0.6385;
        $this->kirimCallback($b)->assertStatus(200);

        $b['scores']['overall'] = 0.7120;
        $this->kirimCallback($b)->assertStatus(200);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid,
            'stage'          => 'ai_verification',
            'status'         => 'passed',
            'note'           => 'Kemiripan CV terhadap lowongan: sedang (0,71) (fase4-embedding-cosine-v1), dinilai ulang dari sedang (0,64)',
        ]);
        // baris lama tetap ada - riwayat append-only, bukan ditimpa
        $this->assertSame(2, (new ScreeningResultModel())->where('application_id', $aid)->countAllResults());
    }

    public function testSkorDitarikTercatatSebagaiFlaggedBukanDibiarkanDiamDiam(): void
    {
        // Skenario nyata: screening pertama memberi skor, screening ulang (kode
        // strukturisasi diperbaiki) menyimpulkan dokumennya tidak memuat isi CV.
        // Riwayat WAJIB menunjukkan skor lama tidak berlaku lagi.
        [, $aid] = $this->fixture();

        $b                      = $this->bodySukses($aid);
        $b['scores']['overall'] = 0.66;
        $this->kirimCallback($b)->assertStatus(200);

        $this->kirimCallback($this->bodySukses($aid))->assertStatus(200); // overall null

        $rows = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'ai_verification'])->orderBy('id')->findAll();

        $this->assertSame('flagged', end($rows)['status']);
        $this->assertStringContainsString('tidak berlaku lagi', (string) end($rows)['note']);
    }

    public function testPenarikanSkorTidakDiulangPadaCallbackBerikutnya(): void
    {
        [, $aid] = $this->fixture();
        $b                      = $this->bodySukses($aid);
        $b['scores']['overall'] = 0.66;
        $this->kirimCallback($b)->assertStatus(200);

        $this->kirimCallback($this->bodySukses($aid))->assertStatus(200);
        $this->kirimCallback($this->bodySukses($aid))->assertStatus(200);

        $n = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'ai_verification', 'status' => 'flagged'])
            ->countAllResults();
        $this->assertSame(1, $n);
    }

    public function testCallbackKeduaDenganSkorSamaTidakMenambahBarisLagi(): void
    {
        [, $aid] = $this->fixture();
        $b                      = $this->bodySukses($aid);
        $b['scores']['overall'] = 0.70;

        $this->kirimCallback($b)->assertStatus(200);
        $this->kirimCallback($b)->assertStatus(200);

        // entered + passed sekali saja, bukan dua kali
        $n = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'ai_verification'])->countAllResults();
        $this->assertSame(2, $n);
    }
}
