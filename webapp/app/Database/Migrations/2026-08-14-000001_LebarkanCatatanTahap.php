<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * candidate_stage_history.note dari 500 jadi 1000 karakter (14 Agustus 2026).
 *
 * KENAPA
 *
 * Sejak keputusan Gate 2 dipindahkan ke AI, catatannya memuat alasan yang
 * ditulis model - sampai tiga kalimat - DITAMBAH angka rumus sebagai
 * pembanding. Gabungannya menembus 600 karakter, dan SQL Server menolak
 * seluruh INSERT dengan galat 2628 "String or binary data would be truncated".
 *
 * Akibatnya bukan catatan yang terpotong, melainkan callback yang membalas 500:
 * keputusan AI yang sudah jadi hilang, kandidat tersangkut tanpa Gate 2, dan
 * tidak ada email yang terkirim. Terjadi sungguhan pada lamaran #72.
 *
 * KENAPA TIDAK DIPOTONG SAJA
 *
 * Kalimat itu alasan seseorang ditolak bekerja. Memotongnya di tengah
 * menyisakan dokumen yang tidak bisa dipertanggungjawabkan kepada orang yang
 * bertanya kenapa ia gugur. Melebarkan kolom varchar di SQL Server cuma
 * perubahan metadata - tabelnya tidak ditulis ulang.
 *
 * StageLogger tetap memotong di 1000 sebagai jaring pengaman: pemanggil lain
 * bisa saja mengirim catatan panjang, dan catatan yang terpotong jauh lebih
 * baik daripada tahapan yang gagal tercatat sama sekali.
 */
class LebarkanCatatanTahap extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('candidate_stage_history', [
            'note' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('candidate_stage_history', [
            'note' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ]);
    }
}
