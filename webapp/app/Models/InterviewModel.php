<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeInterface;

class InterviewModel extends Model
{
    /** Jendela link Zoom kandidat: aktif sejak 15 menit sebelum jadwal, mati 1 jam sesudahnya. */
    public const BUKA_MENIT  = 15;
    public const TUTUP_MENIT = 60;

    protected $table         = 'interviews';
    protected $allowedFields  = ['application_id', 'status', 'scheduled_at', 'meeting_id', 'join_url', 'start_url', 'recording_url'];

    protected $validationRules = [
        'application_id' => 'required|is_natural_no_zero',
        'status'         => 'required|in_list[requested,approved,rejected]',
        'scheduled_at'   => 'required|valid_date',
    ];

    /** Ajuan/jadwal terkini sebuah lamaran (satu baris per lamaran). */
    public function forApplication(int $applicationId): ?array
    {
        return $this->where('application_id', $applicationId)->orderBy('id', 'DESC')->first();
    }

    /**
     * Apakah link Zoom kandidat masih boleh dipakai pada waktu $now.
     * Fungsi murni (tanpa DB, waktu bisa disuntik) supaya langsung bisa dites.
     *
     * Zoom tidak punya mekanisme kedaluwarsa untuk join_url - hanya start_url
     * (token zak) yang mati sendiri setelah 2 jam. Jadi jendela ini kita hitung
     * sendiri dari scheduled_at, dan penjaganya Lamaran::masukInterview.
     */
    public static function linkAktif(string $scheduledAt, ?DateTimeInterface $now = null): bool
    {
        $jadwal = new DateTimeImmutable($scheduledAt);
        $now ??= new DateTimeImmutable();

        return $now >= $jadwal->modify('-' . self::BUKA_MENIT . ' minutes')
            && $now <= $jadwal->modify('+' . self::TUTUP_MENIT . ' minutes');
    }
}
