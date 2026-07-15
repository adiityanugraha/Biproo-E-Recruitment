<?php

namespace App\Models;

use CodeIgniter\Model;

class CandidateModel extends Model
{
    protected $table         = 'candidates';
    protected $allowedFields = ['nama', 'email', 'password_hash'];

    protected $validationRules = [
        'nama'          => 'required|min_length[3]|max_length[160]',
        'email'         => 'required|valid_email|is_unique[candidates.email]',
        'password_hash' => 'required',
    ];

    protected $validationMessages = [
        'email' => ['is_unique' => 'Email sudah terdaftar - silakan login.'],
    ];
}
