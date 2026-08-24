<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Simpan vektor embedding yang selama ini dibuang begitu skornya jadi.
 *
 * Dipakai fitur saran posisi: kandidat yang digugurkan karena SALAH POSISI
 * ditawari lowongan lain yang lebih cocok dengan CV-nya. Menghitungnya ulang
 * dari nol menuntut 36 lowongan x 3 bidang = 108 teks embedding per kandidat,
 * dari jatah 1.000 teks sehari - cuma cukup untuk sembilan orang. Dengan
 * vektornya tersimpan, sarannya jadi aritmetika murni: nol panggilan API.
 *
 * Bentuknya JSON {bidang: [float, ...]} supaya bisa dibaca kedua sisi tanpa
 * tabel baru. Satu vektor 3.072 dimensi sekitar 40 KB; 36 lowongan sekitar
 * 4 MB. Besar untuk sebuah kolom, kecil untuk sebuah basis data.
 *
 * NULL berarti belum pernah dihitung - bukan kesalahan. Lowongan terisi saat
 * ada kandidat melamar ke sana, atau sekaligus lewat `php spark vektor:isi`.
 */
class SimpanVektorEmbedding extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('jobs', [
            'vektor_json' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addColumn('screening_results', [
            'vektor_json' => ['type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('jobs', 'vektor_json');
        $this->forge->dropColumn('screening_results', 'vektor_json');
    }
}
