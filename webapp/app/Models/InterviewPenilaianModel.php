<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Penilaian interview per kompetensi. Satu baris per butir rubrik yang dinilai.
 *
 * Bersifat tambah-saja seperti candidate_stage_history: keputusan Gate 2 hanya
 * boleh sekali, jadi tidak ada jalur memperbarui baris lama.
 */
class InterviewPenilaianModel extends Model
{
    protected $table         = 'interview_penilaian';
    protected $allowedFields = ['application_id', 'kompetensi', 'kategori', 'bobot', 'tingkat', 'catatan'];

    /** @return list<array<string, mixed>> */
    public function untukLamaran(int $appId): array
    {
        return $this->where('application_id', $appId)->orderBy('id')->findAll();
    }
}
