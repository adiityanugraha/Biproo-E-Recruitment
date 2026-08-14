<?php

namespace App\Models;

use CodeIgniter\Model;
use LogicException;

/**
 * candidate_stage_history: append-only (Blueprint A7).
 * update() dan delete() sengaja dimatikan; hanya INSERT yang sah.
 */
class StageHistoryModel extends Model
{
    protected $table         = 'candidate_stage_history';
    protected $allowedFields = ['application_id', 'stage', 'status', 'actor', 'note'];

    protected $validationRules = [
        'application_id' => 'required|is_natural_no_zero',
        'stage'          => 'required|in_list[upload_cv,ai_verification,online_assessment,gate_1,penjadwalan,interview_online,gate_2,berkas_kontrak]',
        'status'         => 'required|in_list[entered,passed,failed,flagged,retry_queued]',
        'actor'          => 'required|max_length[100]',
    ];

    public function update($id = null, $row = null): bool
    {
        throw new LogicException('candidate_stage_history append-only: UPDATE tidak diizinkan');
    }

    public function delete($id = null, bool $purge = false)
    {
        throw new LogicException('candidate_stage_history append-only: DELETE tidak diizinkan');
    }

    /** Status terkini sebuah (application, stage): baris terakhir yang berlaku. */
    public function latestStatus(int $applicationId, string $stage): ?string
    {
        $row = $this->where(['application_id' => $applicationId, 'stage' => $stage])
            ->orderBy('id', 'DESC')->first();

        return $row['status'] ?? null;
    }

    /** Catatan pada baris terkini sebuah (application, stage). '' bila tidak ada. */
    public function latestNote(int $applicationId, string $stage): string
    {
        $row = $this->where(['application_id' => $applicationId, 'stage' => $stage])
            ->orderBy('id', 'DESC')->first();

        return (string) ($row['note'] ?? '');
    }

    /**
     * Peta stage => status terkini untuk satu lamaran.
     * Urut ASC lalu ditimpa -> baris terakhir per stage yang menang.
     *
     * @return array<string, string>
     */
    public function latestStatusMap(int $applicationId): array
    {
        $map = [];
        foreach ($this->where('application_id', $applicationId)->orderBy('id')->findAll() as $r) {
            $map[$r['stage']] = $r['status'];
        }

        return $map;
    }
}
