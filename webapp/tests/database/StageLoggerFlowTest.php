<?php

use App\Libraries\EmailQueueWorker;
use App\Libraries\GateOne;
use App\Libraries\StageLogger;
use App\Models\EmailQueueModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Uji Day 3 tugas 5: skenario lolos dan tidak lolos end-to-end -
 * pencatatan stage_history memicu email, worker mengirim (dry-run).
 *
 * @internal
 */
final class StageLoggerFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    public function testRegistrasiMemicuEmailKonfirmasi(): void
    {
        (new StageLogger())->log(1, 'upload_cv', 'entered', 'system', null, [
            'to' => 'kandidat@example.com', 'nama' => 'Budi', 'posisi' => 'Frontliner',
        ]);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => 1, 'stage' => 'upload_cv']);
        $this->seeInDatabase('email_queue', ['to_email' => 'kandidat@example.com', 'template' => 'konfirmasi_registrasi', 'status' => 'pending']);
    }

    public function testSkenarioLolosDanTidakLolosTerkirim(): void
    {
        $logger = new StageLogger();

        // kandidat A lolos Gate 1, kandidat B tidak lolos
        $logger->log(10, 'gate_1', 'passed', 'system', 'skor=0.81', ['to' => 'a@example.com', 'nama' => 'Ani', 'posisi' => 'Frontliner']);
        $logger->log(11, 'gate_1', 'failed', 'system', 'skor=0.32', ['to' => 'b@example.com', 'nama' => 'Beni', 'posisi' => 'Frontliner']);

        $result = (new EmailQueueWorker(dryRun: true))->process();

        $this->assertSame(['sent' => 2, 'failed' => 0], $result);
        $this->seeInDatabase('email_queue', ['to_email' => 'a@example.com', 'template' => 'hasil_gate', 'status' => 'sent']);
        $this->seeInDatabase('email_queue', ['to_email' => 'b@example.com', 'template' => 'hasil_gate', 'status' => 'sent']);
    }

    public function testEventTanpaEmailTidakMengantriApapun(): void
    {
        // flagged = review internal, kandidat tidak dikirimi email
        (new StageLogger())->log(20, 'gate_1', 'flagged', 'system', 'skor=0.6', [
            'to' => 'c@example.com', 'nama' => 'Cici', 'posisi' => 'Frontliner',
        ]);

        $this->assertSame(0, (new EmailQueueModel())->where('to_email', 'c@example.com')->countAllResults());
    }

    public function testAlurSatuKandidatRegistrasiSampaiPenjadwalan(): void
    {
        // Kriteria selesai minggu 2: satu kandidat berjalan registrasi ->
        // keputusan Gate 1 (skor dummy), email terkirim di tiap perubahan status.
        $logger = new StageLogger();
        $appId  = 50;
        $email  = ['to' => 'dina@example.com', 'nama' => 'Dina', 'posisi' => 'Frontliner'];

        $logger->log($appId, 'upload_cv', 'entered', 'system', null, $email);
        $logger->log($appId, 'ai_verification', 'entered');
        $logger->log($appId, 'ai_verification', 'passed', 'system', 'skor_cv=0.82');
        $logger->log($appId, 'online_assessment', 'entered');
        $logger->log($appId, 'online_assessment', 'passed', 'system', 'nilai=0.70');

        $gate = GateOne::evaluate(0.82, 0.70); // dummy score utk minggu 2
        $logger->log($appId, 'gate_1', $gate['decision'], 'system', "skor_gabungan={$gate['score']}", $email);

        $logger->log($appId, 'penjadwalan', 'entered', 'recruiter', null,
            $email + ['jadwal' => 'Senin, 27 Juli 2026 10:00 WIB', 'join_url' => 'https://zoom.us/j/123']);

        // riwayat lengkap & berurutan
        $rows = (new StageHistoryModel())->where('application_id', $appId)->orderBy('id')->findAll();
        $this->assertSame(
            ['upload_cv', 'ai_verification', 'ai_verification', 'online_assessment', 'online_assessment', 'gate_1', 'penjadwalan'],
            array_column($rows, 'stage')
        );
        $this->assertSame('passed', $gate['decision']);

        // 3 email terpicu (konfirmasi, hasil gate, undangan) dan semuanya terkirim
        $sent = (new EmailQueueWorker(dryRun: true))->process();
        $this->assertSame(['sent' => 3, 'failed' => 0], $sent);

        $templates = array_column(
            (new EmailQueueModel())->where('to_email', 'dina@example.com')->orderBy('id')->findAll(),
            'template'
        );
        $this->assertSame(['konfirmasi_registrasi', 'hasil_gate', 'undangan_interview'], $templates);
    }

    public function testPayloadTidakBocorAntarEmailDalamSatuBatch(): void
    {
        $queue = new EmailQueueModel();
        // email 1: payload lengkap; email 2: payload TANPA nama/posisi
        $queue->insert(['to_email' => 'a@example.com', 'template' => 'hasil_gate',
            'payload_json' => json_encode(['nama' => 'Ani', 'posisi' => 'Frontliner', 'status' => 'passed'])]);
        $queue->insert(['to_email' => 'b@example.com', 'template' => 'hasil_gate',
            'payload_json' => json_encode(['status' => 'failed'])]);

        $worker = new class (dryRun: true) extends EmailQueueWorker {
            public array $bodies = [];

            protected function deliver(string $to, string $subject, string $body): void
            {
                $this->bodies[$to] = $body;
            }
        };
        $worker->process();

        // regresi: data kandidat A tidak boleh muncul di email kandidat B
        $this->assertStringContainsString('Ani', $worker->bodies['a@example.com']);
        $this->assertStringNotContainsString('Ani', $worker->bodies['b@example.com']);
        $this->assertStringNotContainsString('Frontliner', $worker->bodies['b@example.com']);
    }

    public function testFilterToEmailTidakMenyeretAntrianLain(): void
    {
        $queue = new EmailQueueModel();
        $queue->insert(['to_email' => 'demo@example.com', 'template' => 'hasil_gate',
            'payload_json' => json_encode(['status' => 'passed'])]);
        $queue->insert(['to_email' => 'kandidat-nyata@example.com', 'template' => 'hasil_gate',
            'payload_json' => json_encode(['status' => 'passed'])]);

        $result = (new EmailQueueWorker(dryRun: true))->process(20, 'demo@example.com');

        // hanya email demo terkirim; antrian kandidat nyata tidak tersentuh
        $this->assertSame(['sent' => 1, 'failed' => 0], $result);
        $this->seeInDatabase('email_queue', ['to_email' => 'kandidat-nyata@example.com', 'status' => 'pending']);
    }

    public function testStageHistoryMenolakUpdateDanDelete(): void
    {
        $id = (new StageLogger())->log(30, 'upload_cv', 'entered');

        $model = new StageHistoryModel();
        $this->expectException(LogicException::class);
        $model->update($id, ['status' => 'passed']);
    }
}
