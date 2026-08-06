<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rumpun pekerjaan pada lowongan, diisi saat impor bank pertanyaan tim DS
 * (nilai job_family apa adanya dari interview_softskill_hardskill.csv).
 *
 * Gunanya satu: lowongan yang TIDAK ada di bank bisa meminjam pertanyaan dari
 * lowongan serumpun, lewat tebakan kata kunci di KategoriPosisi. Tanpa kolom
 * ini tebakan itu tidak punya sasaran.
 *
 * NULL = di luar bank dan belum tertebak.
 *
 * Catatan buat yang membandingkan dengan kode tim DS: mereka memakai tabel
 * job_category_overrides terpisah untuk menyimpan kategori manual. Di sini satu
 * kolom sudah cukup - recruiter mengubahnya lewat halaman lowongan, bukan lewat
 * tabel penyesuaian tersendiri, dan tidak ada riwayat perubahan yang perlu
 * disimpan.
 */
class KategoriLowongan extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('jobs', [
            'kategori' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'after' => 'judul'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('jobs', 'kategori');
    }
}
