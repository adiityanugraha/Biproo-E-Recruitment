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
     * Lebar kolom candidate_stage_history.note.
     *
     * Dipotong DI SINI, bukan diserahkan ke basis data. SQL Server menolak
     * seluruh INSERT dengan galat 2628 kalau kepanjangan, sehingga perpindahan
     * tahapnya gagal tercatat sama sekali - dan pemanggilnya, misalnya callback
     * ai-service, membalas 500 lalu hasil yang sudah didapat hilang. Terjadi
     * sungguhan pada lamaran #72 saat catatan Gate 2 mulai memuat alasan yang
     * ditulis AI.
     *
     * TIDAK terlihat oleh satu pun uji: berkas uji memakai SQLite, yang tidak
     * menegakkan panjang VARCHAR sama sekali. Semua tesnya hijau sementara
     * produksi mati.
     *
     * Catatan yang terpotong jauh lebih baik daripada tahapan yang hilang.
     */
    public const MAKS_NOTE = 1000;

    /**
     * Lebar kolom actor. Dijaga karena sebagiannya datang dari nama recruiter
     * yang diketik orang - 'recruiter:' . nama - dan nama yang kepanjangan
     * menjatuhkan seluruh pencatatan tahapnya, persis seperti note.
     */
    public const MAKS_ACTOR = 100;

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
            'actor'          => mb_substr($actor, 0, self::MAKS_ACTOR),
            'note'           => $note === null ? null : mb_substr($note, 0, self::MAKS_NOTE),
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
