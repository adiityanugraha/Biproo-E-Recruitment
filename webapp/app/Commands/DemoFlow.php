<?php

namespace App\Commands;

use App\Libraries\EmailQueueWorker;
use App\Libraries\GateOne;
use App\Libraries\StageLogger;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Demo alur satu kandidat: registrasi -> AI verification -> assessment ->
 * Gate 1 -> penjadwalan, email terpicu di tiap event, lalu antrian dikirim.
 *
 *   php spark ereq:demo            (data dibiarkan, bisa dilihat di DB)
 *   php spark ereq:demo --clean    (hapus data demo setelah selesai)
 */
class DemoFlow extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'ereq:demo';
    protected $description = 'Jalankan alur demo satu kandidat end-to-end (skor dummy).';
    protected $options     = ['--clean' => 'Hapus data demo setelah selesai'];

    public function run(array $params)
    {
        $logger = new StageLogger();
        $appId  = random_int(1000, 9999);
        $email  = ['to' => "kandidat{$appId}@example.com", 'nama' => 'Kandidat Demo', 'posisi' => 'Frontliner Retail Gadget'];

        CLI::write("Demo alur kandidat, application_id = {$appId}", 'yellow');

        $steps = [
            ['upload_cv', 'entered', 'system', null, $email],
            ['ai_verification', 'entered', 'system', null, null],
            ['ai_verification', 'passed', 'system', 'skor_cv=0.82 (dummy, skor nyata di minggu 4)', null],
            ['online_assessment', 'entered', 'system', null, null],
            ['online_assessment', 'passed', 'system', 'nilai=0.70', null],
        ];

        foreach ($steps as [$stage, $status, $actor, $note, $mail]) {
            $logger->log($appId, $stage, $status, $actor, $note, $mail);
            CLI::write("  {$stage} / {$status}" . ($mail ? '  [email terpicu]' : ''));
        }

        $gate = GateOne::evaluate(0.82, 0.70);
        $logger->log($appId, 'gate_1', $gate['decision'], 'system', "skor_gabungan={$gate['score']}", $email);
        CLI::write("  gate_1 / {$gate['decision']} (skor {$gate['score']})  [email terpicu]", 'green');

        $logger->log($appId, 'penjadwalan', 'entered', 'recruiter', null,
            $email + ['jadwal' => 'Senin, 27 Juli 2026 10:00 WIB', 'join_url' => 'https://zoom.us/j/contoh']);
        CLI::write('  penjadwalan / entered  [email terpicu]');

        $result = (new EmailQueueWorker())->process();
        CLI::write("Antrian email diproses: terkirim {$result['sent']}, gagal {$result['failed']}", 'green');

        if (array_key_exists('clean', $params) || CLI::getOption('clean')) {
            db_connect()->table('candidate_stage_history')->where('application_id', $appId)->delete();
            db_connect()->table('email_queue')->like('to_email', "kandidat{$appId}@")->delete();
            CLI::write('Data demo dibersihkan.');
        } else {
            CLI::write("Lihat hasilnya: SELECT * FROM candidate_stage_history WHERE application_id = {$appId}");
        }
    }
}
