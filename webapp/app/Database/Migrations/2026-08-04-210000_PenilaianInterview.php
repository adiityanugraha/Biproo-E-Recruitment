<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Penilaian interview per kompetensi, satu baris per butir rubrik.
 *
 * Sebelumnya yang tersimpan cuma satu angka 0-100 di dalam kalimat catatan
 * riwayat, hasil geseran slider yang tidak punya dasar apa pun.
 *
 * Kenapa tabel sendiri dan bukan kolom JSON di interviews (yang lebih sesuai
 * kebiasaan proyek ini), dua alasan:
 *
 *   1. `interviews` memakai indeks unik TERFILTER untuk mengunci slot. SQLite
 *      membuang kolom dengan cara membangun ulang tabel, dan pembangunan ulang
 *      itu bertabrakan dengan indeks tersebut - migrasi turunnya gagal, dan
 *      seluruh uji basis data ikut gagal karena refresh menjalankan down().
 *   2. Gunanya justru menumpuk: nilai per kompetensi dari ratusan wawancara
 *      adalah satu-satunya cara kelak membuktikan penilaian otomatis benar atau
 *      mengarang. Satu baris per butir membuatnya bisa diagregasi langsung.
 *
 * Tanpa foreign key, mengikuti tabel lain di skema ini.
 */
class PenilaianInterview extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id' => ['type' => 'BIGINT'],
            'kompetensi'     => ['type' => 'VARCHAR', 'constraint' => 120],
            'kategori'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'bobot'          => ['type' => 'INT'],
            // kurang | cukup | baik - tiga tingkat, lihat PenilaianRubrik
            'tingkat'        => ['type' => 'VARCHAR', 'constraint' => 10],
            'catatan'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('application_id');
        $this->forge->createTable('interview_penilaian');
    }

    public function down(): void
    {
        $this->forge->dropTable('interview_penilaian');
    }
}
