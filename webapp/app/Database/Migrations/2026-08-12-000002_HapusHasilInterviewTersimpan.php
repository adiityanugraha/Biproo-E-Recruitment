<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Buang baris kategori 'hasil' di interview_penilaian.
 *
 * Interview Result tidak lagi dipilih recruiter di form; ia diturunkan dari
 * keputusan Gate 2 (lolos = Recommended). Baris yang terlanjur tersimpan dari
 * form versi lama sudah tidak dibaca siapa pun, dan membiarkannya berarti
 * menyimpan nilai yang bisa bertentangan dengan keputusan yang sebenarnya.
 *
 * Membersihkannya lewat migrasi, bukan lewat kode tampilan yang menyaringnya:
 * tiap salinan basis data yang pernah memakai form lama punya baris yang sama,
 * dan penyaring di tampilan berarti kategori itu hidup selamanya di kode.
 */
class HapusHasilInterviewTersimpan extends Migration
{
    public function up(): void
    {
        $this->db->table('interview_penilaian')->where('kategori', 'hasil')->delete();
    }

    public function down(): void
    {
        // Tidak bisa dikembalikan: nilainya memang tidak ada lagi sumbernya.
    }
}
