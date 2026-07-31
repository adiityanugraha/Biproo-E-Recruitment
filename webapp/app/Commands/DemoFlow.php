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
    protected $options     = [
        '--clean' => 'Hapus data demo setelah selesai',
        '--email' => 'Alamat tujuan email demo (default: kandidat<id>@example.com)',
    ];

    public function run(array $params)
    {
        $logger = new StageLogger();
        $appId  = random_int(1000, 9999);
        $to     = $params['email'] ?? "kandidat{$appId}@example.com";
        $email  = ['to' => $to, 'nama' => 'Kandidat Demo', 'posisi' => 'Frontliner Retail Gadget'];

        $smtpAktif = env('email.SMTPUser', '') !== '';
        CLI::write("Demo alur kandidat, application_id = {$appId}", 'yellow');
        CLI::write('Mode email: ' . ($smtpAktif ? "SMTP NYATA -> {$to}" : 'dry-run (dicatat ke log, tidak terkirim)'), $smtpAktif ? 'green' : 'yellow');
        if ($smtpAktif && str_ends_with($to, '@example.com')) {
            CLI::write('Peringatan: SMTP aktif tapi tujuan @example.com akan bounce - pakai --email alamat_nyata untuk demo.', 'red');
        }

        $steps = [
            ['upload_cv', 'entered', 'system', null, $email],
            ['ai_verification', 'entered', 'system', null, null],
            ['ai_verification', 'passed', 'system', 'Kemiripan CV terhadap lowongan: tinggi (0,82) (demo)', null],
            ['online_assessment', 'entered', 'system', null, null],
            ['online_assessment', 'passed', 'system', 'Hasil assessment: lulus', null],
        ];

        foreach ($steps as [$stage, $status, $actor, $note, $mail]) {
            $logger->log($appId, $stage, $status, $actor, $note, $mail);
            CLI::write("  {$stage} / {$status}" . ($mail ? '  [email terpicu]' : ''));
        }

        // Gate 1 diputus assessment, bukan skor CV (lihat GateOne)
        $keputusan = GateOne::dariAssessment(true);
        $logger->log($appId, 'gate_1', $keputusan, 'system',
            'Keputusan dari hasil assessment. Kemiripan CV tinggi (0,82) dipakai di Tahap 2', $email);
        CLI::write("  gate_1 / {$keputusan} (dari assessment)  [email terpicu]", 'green');

        $logger->log($appId, 'penjadwalan', 'entered', 'recruiter', null,
            $email + ['jadwal' => 'Senin, 27 Juli 2026 10:00 WIB', 'join_url' => 'https://zoom.us/j/contoh']);
        CLI::write('  penjadwalan / entered  [email terpicu]');

        // filter ke alamat demo: antrian kandidat nyata tidak ikut terseret
        $result = (new EmailQueueWorker())->process(20, $to);
        CLI::write("Antrian email diproses: terkirim {$result['sent']}, gagal {$result['failed']}", 'green');

        if (array_key_exists('clean', $params)) {
            db_connect()->table('candidate_stage_history')->where('application_id', $appId)->delete();
            db_connect()->table('email_queue')->where('to_email', $to)->delete();
            CLI::write('Data demo dibersihkan.');
        } else {
            CLI::write("Lihat hasilnya: SELECT * FROM candidate_stage_history WHERE application_id = {$appId}");
        }
    }
}
