<?php

namespace App\Models;

use CodeIgniter\Model;

class ApplicationModel extends Model
{
    protected $table         = 'applications';
    protected $allowedFields = ['candidate_id', 'job_id', 'cv_path'];

    public const MAX_LAMARAN = 3; // sesuai desain BIPROO "Max. 3"
}
