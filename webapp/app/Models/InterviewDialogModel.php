<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Transkrip wawancara suara AI, satu baris per giliran.
 *
 * Pola tulisnya dua langkah: baris dibuat saat AI MENGAJUKAN pertanyaan (jawaban
 * masih null), lalu diperbarui saat kandidat selesai menjawab. Bukan sekali
 * tulis di akhir - kalau koneksi kandidat putus di tengah sesi, yang sudah
 * ditanyakan dan sudah dijawab tetap ada jejaknya, dan recruiter yang sedang
 * memantau di konsol melihat pertanyaannya muncul saat itu juga.
 */
class InterviewDialogModel extends Model
{
    protected $table         = 'interview_dialog';
    protected $allowedFields = [
        'application_id', 'urutan', 'sumber', 'butir_index', 'kompetensi', 'bobot',
        'pertanyaan', 'jawaban', 'tingkat', 'alasan', 'keyakinan', 'derau', 'durasi_ms',
    ];

    protected $validationRules = [
        'application_id' => 'required|is_natural_no_zero',
        'urutan'         => 'required|is_natural',
        'sumber'         => 'required|in_list[bank,lanjutan,ulangi,penutup]',
        'pertanyaan'     => 'required|max_length[500]',
        'tingkat'        => 'permit_empty|in_list[kurang,cukup,baik]',
    ];

    /**
     * Seluruh giliran satu lamaran, urut sebagaimana berlangsung.
     *
     * @return list<array<string, mixed>>
     */
    public function untukLamaran(int $applicationId): array
    {
        return $this->where('application_id', $applicationId)->orderBy('urutan')->orderBy('id')->findAll();
    }

    /** Giliran terakhir, atau null bila sesi belum dimulai. */
    public function terakhir(int $applicationId): ?array
    {
        return $this->where('application_id', $applicationId)
            ->orderBy('urutan', 'DESC')->orderBy('id', 'DESC')->first();
    }
}
