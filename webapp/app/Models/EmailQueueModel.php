<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailQueueModel extends Model
{
    protected $table         = 'email_queue';
    protected $allowedFields = ['to_email', 'template', 'payload_json', 'status', 'attempts', 'last_error', 'sent_at'];

    protected $validationRules = [
        'to_email' => 'required|valid_email',
        'template' => 'required|in_list[konfirmasi_registrasi,undangan_interview,hasil_gate,pengingat_h1,jadwal_reschedule]',
    ];
}
