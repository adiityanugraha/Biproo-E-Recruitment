<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailQueueModel extends Model
{
    protected $table         = 'email_queue';
    protected $allowedFields = ['to_email', 'template', 'payload_json', 'status', 'attempts', 'last_error', 'sent_at'];

    protected $validationRules = [
        'to_email' => 'required|valid_email',
        // akun_atasan ditambahkan 19 Agustus 2026. Daftar ini harus sejalan
        // dengan EmailQueueWorker::SUBJECTS dan berkas view di Views/emails -
        // template yang lolos di sini tapi tidak punya subjek akan menjatuhkan
        // pengiriman seluruh batch, bukan cuma satu barisnya.
        'template' => 'required|in_list[konfirmasi_registrasi,undangan_interview,hasil_gate,jadwal_reschedule,akun_atasan]',
    ];
}
