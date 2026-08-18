<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * jobs.alur_json: rangkaian tahap milik satu lowongan (18 Agustus 2026).
 *
 * Mengikuti web recruiter BIPROO, tiap posisi punya alurnya sendiri. Sebelum
 * ini E-REQ memakai satu rangkaian tetap untuk semua posisi, ditulis sebagai
 * konstanta di Lamaran::STEPPER.
 *
 * NULL berarti "pakai bawaan", dan itu disengaja: seluruh lowongan yang sudah
 * ada tetap berjalan dengan rangkaian yang sama persis seperti sebelum kolom
 * ini dipasang. Tidak ada lamaran berjalan yang berubah artinya karena migrasi.
 *
 * Bentuknya daftar kunci tahap berurutan, mis. ["upload_cv","disc",
 * "online_assessment","gate_1",...]. Yang menafsirkannya AlurRekrutmen, dan
 * kunci yang tidak dikenalnya diabaikan - halaman kandidat tidak boleh mati
 * gara-gara satu kolom konfigurasi yang salah ketik.
 *
 * TEXT, bukan VARCHAR, seragam dengan bobot_json dan pertanyaan_json di tabel
 * yang sama.
 */
class AlurRekrutmenPerLowongan extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('jobs', [
            'alur_json' => ['type' => 'TEXT', 'null' => true, 'after' => 'pertanyaan_json'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('jobs', 'alur_json');
    }
}
