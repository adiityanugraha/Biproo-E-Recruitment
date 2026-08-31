<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Slot Interview HRD dan Interview User dipisah, dan slot User milik posisi.
 *
 * Sebelum ini satu daftar jam dipakai kedua wawancara sekaligus; yang dipisah
 * cuma penghitungan kuotanya. Akibatnya dua hal yang tidak benar:
 *
 * 1. Recruiter membuka satu jam, dan jam itu otomatis jadi jam Interview User
 *    juga - padahal yang mewawancarai orang lain, dengan kesibukan lain.
 * 2. Jam Interview User berlaku lintas posisi. Kandidat posisi A yang mengambil
 *    jam 10.00 ikut menutup jam 10.00 untuk kandidat posisi B, padahal atasan
 *    posisi B orang yang berbeda dan sedang senggang.
 *
 * Sekarang tiap baris punya jenisnya sendiri, dan baris Interview User punya
 * job_id pemiliknya.
 *
 * DIBONGKAR ULANG, BUKAN DITAMBAL KOLOM. Tabelnya sedang kosong (seluruh slot
 * dihapus 31 Agustus 2026 atas permintaan pemilik proyek), jadi tidak ada data
 * yang hilang - dan membangun ulang menghindari penghapusan indeks unik lama,
 * yang di SQLite tidak bisa dicabut tanpa menyusun ulang tabelnya.
 *
 * job_id 0, BUKAN NULL, untuk slot yang tidak terikat posisi. SQL Server
 * menganggap dua NULL sama di indeks unik, SQLite menganggapnya berbeda - jadi
 * baris HRD kembar akan ditolak di produksi tapi lolos di seluruh berkas uji.
 */
class SlotPerJenis extends Migration
{
    public function up(): void
    {
        $this->forge->dropTable('slot_interview', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'scheduled_at' => ['type' => 'DATETIME'],
            // 'hrd' atau 'user' - nilainya sama dengan interviews.jenis
            'jenis'        => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'hrd'],
            // 0 = tidak terikat posisi (dipakai seluruh slot HRD)
            'job_id'       => ['type' => 'BIGINT', 'default' => 0],
            'kuota'        => ['type' => 'INT', 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        // Satu baris per jam PER JENIS PER POSISI. Dua baris untuk kombinasi
        // yang sama berarti dua sumber kebenaran untuk "jam ini muat berapa".
        $this->forge->addUniqueKey(['scheduled_at', 'jenis', 'job_id']);
        $this->forge->createTable('slot_interview');
    }

    public function down(): void
    {
        $this->forge->dropTable('slot_interview', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'scheduled_at' => ['type' => 'DATETIME'],
            'kuota'        => ['type' => 'INT', 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('scheduled_at');
        $this->forge->createTable('slot_interview');
    }
}
