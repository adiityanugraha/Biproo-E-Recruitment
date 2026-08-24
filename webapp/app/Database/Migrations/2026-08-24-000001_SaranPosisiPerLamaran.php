<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Saran posisi yang DIBEKUKAN saat kandidat digugurkan karena salah posisi.
 *
 * Kenapa disimpan, bukan dihitung ulang tiap halaman dibuka: isinya ikut
 * terkirim di email penolakan. Kalau dihitung ulang, kandidat yang membuka
 * portal seminggu kemudian bisa melihat tiga posisi yang berbeda dari yang
 * tertulis di emailnya - lowongan baru masuk, syarat lowongan lama diubah, dan
 * daftarnya bergeser tanpa ada yang menyentuh apa pun.
 *
 * Bentuknya JSON [{id, judul, skor}, ...], paling banyak tiga entri.
 * NULL = belum pernah dihitung. Larik kosong = sudah dihitung dan memang tidak
 * ada posisi yang lebih cocok; keduanya berbeda arti dan tidak boleh disamakan.
 */
class SaranPosisiPerLamaran extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('applications', [
            'saran_json' => ['type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('applications', 'saran_json');
    }
}
