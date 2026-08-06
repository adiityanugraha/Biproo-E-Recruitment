<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Pertanyaan interview yang dibuat AI, disimpan PER LOWONGAN (arahan atasan
 * 4 Agustus 2026).
 *
 * Kenapa menempel di jobs dan bukan tabel sendiri: satu lowongan punya tepat
 * satu set pertanyaan, tidak ada riwayat versi yang perlu disimpan, dan tidak
 * ada yang menanyakan "pertanyaan nomor 3 milik siapa". Tabel terpisah cuma
 * menambah join untuk hubungan satu-ke-satu.
 *
 * Kenapa per lowongan dan bukan per kandidat: tier gratis Gemini cuma memberi
 * 20 panggilan generateContent per hari, dan screening CV sudah memakai 1-2
 * panggilan per CV. Per lowongan berarti 3 panggilan sekali seumur hidup untuk
 * 3 lowongan, lalu dibaca dari basis data selamanya.
 *
 * Isi kolom: JSON array string, mis. ["Ceritakan ...", "Bagaimana Anda ..."].
 * NULL = belum pernah dibuat.
 */
class PertanyaanInterviewPerLowongan extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('jobs', [
            'pertanyaan_json' => ['type' => 'TEXT', 'null' => true, 'after' => 'threshold_json'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('jobs', 'pertanyaan_json');
    }
}
