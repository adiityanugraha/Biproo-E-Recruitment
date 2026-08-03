<?php

namespace App\Libraries;

use App\Models\EmailQueueModel;
use App\Models\StageHistoryModel;
use RuntimeException;

/**
 * Satu pintu pencatatan perpindahan tahap (docs/skema-database.md,
 * "Titik Pencatatan Timestamp") sekaligus pemicu email otomatis:
 * event stage:status yang ada di EMAIL_MAP langsung masuk email_queue.
 */
class StageLogger
{
    /** stage:status => template email (A2.5, event-driven) */
    private const EMAIL_MAP = [
        'upload_cv:entered'   => 'konfirmasi_registrasi',
        'gate_1:passed'       => 'hasil_gate',
        'gate_1:failed'       => 'hasil_gate',
        'gate_2:passed'       => 'hasil_gate',
        'gate_2:failed'       => 'hasil_gate',
        'penjadwalan:entered' => 'undangan_interview',
        // recruiter melepas jadwal, kandidat diminta memilih slot lain
        'penjadwalan:failed'  => 'jadwal_reschedule',
    ];

    /**
     * @param array|null $email data kandidat utk notifikasi:
     *                          ['to' => email, 'nama' => ..., 'posisi' => ..., ...]
     *                          null = tidak ada email dikirim utk event ini
     *
     * @return int id baris stage_history
     */
    public function log(
        int $applicationId,
        string $stage,
        string $status,
        string $actor = 'system',
        ?string $note = null,
        ?array $email = null,
    ): int {
        $history = new StageHistoryModel();
        $id = $history->insert([
            'application_id' => $applicationId,
            'stage'          => $stage,
            'status'         => $status,
            'actor'          => $actor,
            'note'           => $note,
        ]);

        if ($id === false) {
            throw new RuntimeException('stage_history invalid: ' . json_encode($history->errors()));
        }

        $template = self::EMAIL_MAP["{$stage}:{$status}"] ?? null;
        if ($template !== null && $email !== null) {
            if (empty($email['to'])) {
                throw new RuntimeException("event {$stage}:{$status} memicu email tetapi key 'to' kosong");
            }
            $to      = $email['to'];
            $payload = ['stage' => $stage, 'status' => $status] + $email;
            unset($payload['to']);

            $queue = new EmailQueueModel();
            if ($queue->insert(['to_email' => $to, 'template' => $template, 'payload_json' => json_encode($payload)]) === false) {
                throw new RuntimeException('email_queue invalid: ' . json_encode($queue->errors()));
            }
        }

        return (int) $id;
    }
}
