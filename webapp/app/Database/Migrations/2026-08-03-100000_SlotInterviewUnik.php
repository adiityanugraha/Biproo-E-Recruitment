<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Satu slot interview hanya boleh dipegang satu kandidat (arahan atasan
 * 3 Agustus 2026).
 *
 * Penyaring di tampilan dan pengecekan di controller sudah menutup jalur normal,
 * tapi keduanya punya celah yang sama: antara "dicek masih kosong" dan
 * "disimpan" ada jeda, dan dua kandidat yang menekan tombol bersamaan bisa
 * lolos berdua. Yang benar-benar menjamin cuma database.
 *
 * Indeks BERSYARAT: hanya ajuan yang masih hidup (requested/approved) yang
 * mengunci slot. Ajuan yang ditolak melepas slotnya kembali ke daftar, dan
 * baris rejected lama tidak boleh menghalangi kandidat berikutnya.
 *
 * Ditulis sebagai SQL mentah karena Forge CI4 tidak punya API untuk filtered
 * index. Sintaksnya sama di SQL Server (produksi) dan SQLite (uji).
 *
 * WAJIB lewat prefixTable(): basis data uji memakai prefiks 'db_' sedangkan
 * produksi tanpa prefiks. Menulis nama tabel mentah membuat migrasi ini lolos
 * di produksi tapi gagal "no such table" di seluruh uji database.
 */
class SlotInterviewUnik extends Migration
{
    private const NAMA = 'ux_interviews_slot_aktif';

    public function up(): void
    {
        $tabel = $this->db->prefixTable('interviews');

        $this->db->query(
            'CREATE UNIQUE INDEX ' . self::NAMA . ' ON ' . $tabel . ' (scheduled_at) '
            . "WHERE status IN ('requested', 'approved')"
        );
    }

    public function down(): void
    {
        // CREATE-nya sama, DROP-nya beda: SQL Server butuh "ON <tabel>", SQLite tidak
        $this->db->query($this->db->DBDriver === 'SQLSRV'
            ? 'DROP INDEX ' . self::NAMA . ' ON ' . $this->db->prefixTable('interviews')
            : 'DROP INDEX ' . self::NAMA);
    }
}
