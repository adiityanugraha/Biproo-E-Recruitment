<?php

namespace App\Models;

use CodeIgniter\Model;

class InterviewModel extends Model
{
    protected $table         = 'interviews';
    protected $allowedFields = ['application_id', 'meeting_id', 'join_url', 'start_url', 'scheduled_at', 'recording_url'];

    protected $validationRules = [
        'application_id' => 'required|is_natural_no_zero',
        'meeting_id'     => 'required|max_length[50]',
        'join_url'       => 'required|max_length[500]',
        'scheduled_at'   => 'permit_empty|valid_date',
    ];
}
