<?php

namespace App\Libraries;

use App\Models\EmailQueueModel;
use Throwable;

/**
 * Pengirim antrian email (A2.5): dipanggil cron via `php spark email:send`.
 * Kegagalan SMTP tidak menghambat alur utama - baris gagal dicoba ulang
 * sampai MAX_ATTEMPTS lalu ditandai failed.
 *
 * Dry-run: bila kredensial SMTP belum diisi (email.SMTPUser kosong), email
 * dirender lalu dicatat ke log, baris tetap ditandai sent - supaya alur
 * bisa diuji end-to-end tanpa server email. Cek SMTPUser, bukan SMTPHost:
 * SMTPHost punya nilai default (smtp.gmail.com) meski kredensial belum diisi.
 */
class EmailQueueWorker
{
    public const MAX_ATTEMPTS = 3;

    private const SUBJECTS = [
        'konfirmasi_registrasi' => 'Pendaftaran E-REQ Anda Diterima',
        'hasil_gate'            => 'Hasil Tahap Seleksi E-REQ',
        'undangan_interview'    => 'Undangan Interview E-REQ',
        'jadwal_reschedule'     => 'Interview Anda Perlu Dijadwalkan Ulang',
        'akun_atasan'           => 'Akun Interview User E-REQ',
    ];

    private bool $dryRun;

    public function __construct(?bool $dryRun = null)
    {
        $this->dryRun = $dryRun ?? (env('email.SMTPUser', '') === '');
    }

    /**
     * ponytail: tanpa locking antar-run; dua cron tumpang tindih bisa kirim
     * ganda. Upgrade bila terjadi: claim baris via UPDATE status='sending'
     * WHERE status='pending' sebelum kirim.
     *
     * @param string|null $toEmail hanya proses baris utk alamat ini
     *                             (dipakai ereq:demo agar tidak menyeret antrian kandidat nyata)
     *
     * @return array{sent: int, failed: int}
     */
    public function process(int $limit = 20, ?string $toEmail = null): array
    {
        $model  = new EmailQueueModel();
        $result = ['sent' => 0, 'failed' => 0];

        $builder = $model->where('status', 'pending');
        if ($toEmail !== null) {
            $builder->where('to_email', $toEmail);
        }
        $pending = $builder->orderBy('created_at')->findAll($limit);

        foreach ($pending as $row) {
            $attempts = $row['attempts'] + 1;

            try {
                $payload = json_decode($row['payload_json'], true) ?? [];
                // saveData=false wajib: default CI4 menyimpan data view antar
                // pemanggilan dalam satu proses, payload email sebelumnya bocor
                // ke email berikutnya dalam batch
                $body = view("emails/{$row['template']}", $payload, ['saveData' => false]);
                $this->deliver($row['to_email'], self::SUBJECTS[$row['template']], $body);

                $model->update($row['id'], [
                    'status'   => 'sent',
                    'attempts' => $attempts,
                    'sent_at'  => date('Y-m-d H:i:s'),
                    // Rahasia dibuang begitu emailnya terkirim. Payload email
                    // akun atasan memuat sandi MENTAH - ia harus ada sampai
                    // badan emailnya dirakit, tapi sesudah itu tidak ada lagi
                    // gunanya, sementara barisnya tinggal di basis data
                    // selamanya dan terbaca siapa pun yang bisa membacanya.
                    'payload_json' => $this->tanpaRahasia($row['payload_json']),
                ]);
                $result['sent']++;
            } catch (Throwable $e) {
                $model->update($row['id'], [
                    'status'     => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'attempts'   => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    /** Kunci payload yang tidak boleh tertinggal di basis data setelah terkirim. */
    private const RAHASIA = ['sandi'];

    private function tanpaRahasia(string $payloadJson): string
    {
        $d = json_decode($payloadJson, true);
        if (! is_array($d)) {
            return $payloadJson;
        }
        foreach (self::RAHASIA as $kunci) {
            if (isset($d[$kunci])) {
                $d[$kunci] = '(dibuang setelah terkirim)';
            }
        }

        return json_encode($d);
    }

    protected function deliver(string $to, string $subject, string $body): void
    {
        if ($this->dryRun) {
            log_message('info', "[email dry-run] to={$to} subject={$subject}\n{$body}");

            return;
        }

        $email = service('email');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($body);
        $email->setMailType('html');

        if (! $email->send()) {
            throw new \RuntimeException('SMTP gagal: ' . strip_tags($email->printDebugger(['headers'])));
        }
    }
}
