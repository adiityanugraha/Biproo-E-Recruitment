<?php

namespace App\Models;

use CodeIgniter\Model;

class InterviewModel extends Model
{
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
}
