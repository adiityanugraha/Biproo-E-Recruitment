<?php

namespace App\Models;

use CodeIgniter\Model;

class ApplicationModel extends Model
{
    protected $table = 'applications';

    // pertanyaan_json: tiga pertanyaan interview milik kandidat ini, lihat
    // PertanyaanKandidat. Tanpa didaftarkan di sini update()-nya dibuang diam
    // diam oleh model, tanpa error dan tanpa jejak.
    protected $allowedFields = ['candidate_id', 'job_id', 'cv_path', 'pertanyaan_json'];

    public const MAX_LAMARAN = 3; // sesuai desain BIPROO "Max. 3"
}
