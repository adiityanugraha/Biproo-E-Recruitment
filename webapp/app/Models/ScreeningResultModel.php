<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Hasil screening CV (Blueprint A7): audit trail skor per lamaran, satu baris
 * per percobaan screening. Kolomnya sengaja sama dengan payload callback
 * ai-service (Blueprint A3.1) supaya Fase 4 tinggal mengisi dari callback nyata
 * tanpa mengubah pembacanya.
 */
class ScreeningResultModel extends Model
{
    protected $table         = 'screening_results';
    protected $allowedFields = [
        'application_id', 'screening_job_id', 'status',
        'score_overall', 'score_skill', 'score_pendidikan', 'score_pengalaman',
        'extracted_json', 'flags_json', 'provider', 'model_version',
    ];

    protected $validationRules = [
        'application_id'   => 'required|is_natural_no_zero',
        'screening_job_id' => 'required|max_length[64]',
        'status'           => 'required|in_list[success,failed_extraction,failed_provider]',
    ];

    /** Hasil screening terakhir sebuah lamaran (tabel append-only, ambil baris terbaru). */
    public function latestFor(int $applicationId): ?array
    {
        return $this->where('application_id', $applicationId)->orderBy('id', 'DESC')->first();
    }
}
