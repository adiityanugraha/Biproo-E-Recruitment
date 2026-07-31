<?php

namespace App\Commands;

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Models\ApplicationModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Kirim ulang job screening untuk lamaran yang belum punya skor.
 *
 * Kenapa perlu: Lamaran::kirim mengirim job ke ai-service saat kandidat upload,
 * dan sengaja TIDAK menggagalkan lamaran bila ai-service mati. Tapi akibatnya
 * job itu hilang - tidak ada yang mengirim ulang, dan kandidat berakhir tanpa
 * skor CV selamanya. Perintah ini menutup celah itu.
 *
 * Aman dijalankan berulang: lamaran yang sudah punya baris screening_results
 * dilewati, jadi tidak ada job ganda.
 *
 * Pemakaian:
 *   php spark screening:resend            kirim ulang semua yang belum berskor
 *   php spark screening:resend --dry      hanya tampilkan, tidak mengirim
 *   php spark screening:resend --id 37    satu lamaran saja
 */
class ScreeningResend extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'screening:resend';
    protected $description = 'Kirim ulang job screening CV untuk lamaran yang belum punya skor.';
    protected $usage       = 'screening:resend [--dry] [--id <appId>]';
    protected $options     = [
        '--dry' => 'Tampilkan daftar tanpa mengirim apa pun.',
        '--id'  => 'Batasi ke satu application_id.',
    ];

    /**
     * Opsi bisa datang dari dua tempat: CLI::getOption() saat dijalankan lewat
     * terminal, atau array $params saat dipanggil helper command() (dipakai test).
     * Membaca keduanya membuat perintah ini bisa diuji tanpa mengarang harness.
     */
    private function opsi(array $params, string $nama): string|bool|null
    {
        // Flag tanpa nilai (mis. --dry) masuk sebagai null, jadi KEBERADAAN kunci
        // yang berarti true - bukan nilainya. `$params[$nama] ?? ...` salah di sini.
        if (array_key_exists($nama, $params)) {
            return $params[$nama] ?? true;
        }

        return CLI::getOption($nama);
    }

    public function run(array $params)
    {
        $dry  = (bool) $this->opsi($params, 'dry');
        $satu = (int) ($this->opsi($params, 'id') ?? 0);

        $token = (string) config('AiService')->sharedToken;
        if ($token === '') {
            CLI::error('aiservice.sharedToken kosong di .env - jalur internal mati. Isi dulu.');

            return 1;
        }

        $apps = new ApplicationModel();
        $sr   = new ScreeningResultModel();

        $builder = $apps->select('applications.id, applications.cv_path, jobs.req_skill, jobs.req_pendidikan, jobs.req_pengalaman, jobs.deskripsi')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->orderBy('applications.id');
        if ($satu > 0) {
            $builder->where('applications.id', $satu);
        }

        $perlu = [];
        foreach ($builder->findAll() as $app) {
            // sudah punya skor -> lewati (idempoten)
            $ada = $sr->latestFor((int) $app['id']);
            if ($ada !== null && $ada['score_overall'] !== null) {
                continue;
            }
            // CV harus benar-benar ada, kalau tidak ai-service pasti gagal unduh
            if (! is_file(WRITEPATH . $app['cv_path'])) {
                CLI::write("  app#{$app['id']}  DILEWATI: berkas CV hilang ({$app['cv_path']})", 'yellow');
                continue;
            }
            $perlu[] = $app;
        }

        if ($perlu === []) {
            CLI::write('Tidak ada lamaran yang perlu dikirim ulang.', 'green');

            return 0;
        }

        CLI::write(count($perlu) . ' lamaran belum punya skor CV.' . ($dry ? ' (mode dry, tidak mengirim)' : ''));

        $ai = service('aiService');
        $ok = $gagal = 0;
        foreach ($perlu as $app) {
            $appId = (int) $app['id'];
            if ($dry) {
                CLI::write("  app#{$appId}  akan dikirim");
                continue;
            }

            try {
                $ai->post('/screening', [
                    'job_id_internal' => $appId,
                    'cv_file_url'     => site_url("internal/cv/{$appId}"),
                    'job_requirement' => [
                        'skill'      => (string) $app['req_skill'],
                        'pendidikan' => (string) $app['req_pendidikan'],
                        'pengalaman' => (string) $app['req_pengalaman'],
                        'deskripsi'  => (string) ($app['deskripsi'] ?? ''),
                    ],
                    'callback_url'   => site_url('screening/callback'),
                    'callback_token' => $token,
                ]);
                $ok++;
                CLI::write("  app#{$appId}  terkirim", 'green');
            } catch (AiServiceException $e) {
                $gagal++;
                CLI::write("  app#{$appId}  GAGAL: " . $e->getMessage(), 'red');
            }
        }

        if (! $dry) {
            CLI::write("Selesai: terkirim {$ok}, gagal {$gagal}.", $gagal ? 'yellow' : 'green');
            CLI::write('Skor tiba lewat callback beberapa detik kemudian - cek lagi dengan --dry.');
        }

        return 0;
    }
}
