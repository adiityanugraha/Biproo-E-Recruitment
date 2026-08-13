<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Rekaman wawancara dan transkripnya (revisi 12 Agustus 2026).
 *
 * Satu baris per unggahan. Kalau recruiter mengunggah ulang karena berkasnya
 * salah, baris lama TIDAK ditimpa - transkrip adalah dasar penilaian, dan dasar
 * penilaian harus bisa ditelusuri ke belakang. Pembacanya selalu mengambil
 * baris terbaru, sama seperti screening_results.
 */
class InterviewTranskripModel extends Model
{
    protected $table         = 'interview_transkrip';
    protected $allowedFields = [
        'application_id', 'sumber', 'status', 'berkas', 'teks', 'catatan', 'model_version', 'updated_at',
    ];

    protected $validationRules = [
        'application_id' => 'required|is_natural_no_zero',
        'sumber'         => 'required|in_list[unggahan,zoom_cloud]',
        'status'         => 'required|in_list[antre,proses,selesai,gagal]',
    ];

    /** Transkrip terakhir sebuah lamaran (tabel tambah-saja, ambil baris terbaru). */
    public function terakhirUntuk(int $appId): ?array
    {
        return $this->where('application_id', $appId)->orderBy('id', 'DESC')->first();
    }

    /**
     * Transkrip yang siap dipakai menilai.
     *
     * Yang gagal atau masih diproses sengaja tidak dikembalikan: penilaian dari
     * transkrip separuh jadi lebih buruk daripada tidak menilai sama sekali,
     * karena hasilnya tetap berupa angka yang terlihat sah.
     */
    public function selesaiUntuk(int $appId): ?array
    {
        return $this->where(['application_id' => $appId, 'status' => 'selesai'])
            ->orderBy('id', 'DESC')->first();
    }
}
