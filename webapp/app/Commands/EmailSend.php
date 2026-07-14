<?php

namespace App\Commands;

use App\Libraries\EmailQueueWorker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Dipanggil cron/Task Scheduler tiap menit:
 *   php spark email:send
 */
class EmailSend extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'email:send';
    protected $description = 'Kirim email pending dari tabel email_queue.';

    public function run(array $params)
    {
        $result = (new EmailQueueWorker())->process();
        CLI::write("Terkirim: {$result['sent']}, gagal: {$result['failed']}");
    }
}
