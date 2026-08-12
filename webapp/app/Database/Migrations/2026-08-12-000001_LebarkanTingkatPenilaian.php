<?php

namespace App\Database\Migrations;

use App\Libraries\LembarPenilaian;
use CodeIgniter\Database\Migration;

/**
 * Lebarkan interview_penilaian.tingkat dari VARCHAR(10) jadi VARCHAR(20).
 *
 * Kolom itu dirancang untuk 'kurang' | 'cukup' | 'baik'. Sejak lembar penilaian
 * BIPROO dipakai (12 Agustus 2026), isinya bertambah: angka '1'-'5' untuk
 * kompetensi, dan hasil akhir 'recommended' | 'not_recommended'. Yang terakhir
 * 15 karakter, lewat dari batas.
 *
 * Gejalanya di SQL Server:
 *
 *   String or binary data would be truncated in table 'ereq.dbo.interview_penilaian',
 *   column 'tingkat'. Truncated value: 'recommende'.
 *
 * TIDAK tertangkap uji otomatis karena basis data uji memakai SQLite, dan SQLite
 * tidak menegakkan panjang VARCHAR sama sekali - nilainya masuk utuh di sana.
 * Penjaganya sekarang uji unit yang membandingkan panjang nilai terpanjang yang
 * bisa dihasilkan LembarPenilaian dengan lebar kolom ini, jadi tidak bergantung
 * pada basis data mana yang dipakai.
 */
class LebarkanTingkatPenilaian extends Migration
{
    public const LEBAR = 20;

    public function up(): void
    {
        $this->forge->modifyColumn('interview_penilaian', [
            'tingkat' => ['type' => 'VARCHAR', 'constraint' => self::LEBAR],
        ]);
    }

    public function down(): void
    {
        // Sengaja memotong dulu: baris lama yang lebih panjang dari 10 karakter
        // akan menggagalkan penyempitan kolom, dan migrasi turun yang macet lebih
        // menyusahkan daripada nilai yang terpotong di basis data pengembangan.
        $this->db->table('interview_penilaian')
            ->where('kategori', LembarPenilaian::KAT_HASIL)
            ->delete();

        $this->forge->modifyColumn('interview_penilaian', [
            'tingkat' => ['type' => 'VARCHAR', 'constraint' => 10],
        ]);
    }
}
