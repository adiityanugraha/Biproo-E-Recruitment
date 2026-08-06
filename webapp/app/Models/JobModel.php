<?php

namespace App\Models;

use CodeIgniter\Model;

class JobModel extends Model
{
    protected $table         = 'jobs';
    protected $allowedFields = ['judul', 'kategori', 'req_skill', 'req_pendidikan', 'req_pengalaman', 'deskripsi', 'bobot_json', 'threshold_json', 'pertanyaan_json'];
}
