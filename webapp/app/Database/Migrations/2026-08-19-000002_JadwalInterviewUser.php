<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * interviews.jenis: satu lamaran bisa punya DUA wawancara (19 Agustus 2026).
 *
 * Sejak Interview User ada, kandidat yang lolos wawancara HRD memilih jadwal
 * sekali lagi - kali ini dengan calon atasannya. Sebelum kolom ini, tabel
 * interviews satu baris per lamaran dan forApplication() mengambil yang
 * terbaru; jadwal Interview User akan menimpa jadwal HRD di layar recruiter,
 * dan tautan Zoom di ruang interview berpindah ke ruangan yang salah.
 *
 * 'hrd' jadi bawaan supaya seluruh baris yang sudah ada berarti sama persis
 * seperti sebelum migrasi ini - tidak ada jadwal berjalan yang berubah artinya.
 *
 * INDEKS SLOTNYA IKUT BERUBAH, dan ini bagian yang paling mudah terlewat.
 * Indeks lama menjamin satu jam hanya untuk satu kandidat, karena pewawancaranya
 * satu orang: recruiter. Interview User diwawancarai ATASAN, orang yang berbeda,
 * jadi wawancara HRD pukul 10.00 tidak menghalangi Interview User pukul 10.00.
 * Membiarkan indeks lama membuat slot saling menghabiskan tanpa sebab, dan
 * kandidat melihat jam kosong ditolak saat diklik.
 *
 * Yang TIDAK dibedakan: dua posisi berbeda dengan atasan berbeda tetap
 * berebut satu jam Interview User. Itu batasan yang disengaja - memisahkannya
 * per atasan menuntut indeks atas kolom yang tidak ada di tabel ini, dan
 * jumlah lowongan aktif belum sebanyak itu.
 */
class JadwalInterviewUser extends Migration
{
    private const NAMA = 'ux_interviews_slot_aktif';

    public function up(): void
    {
        $this->forge->addColumn('interviews', [
            'jenis' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => 'hrd',
                'after'      => 'application_id',
            ],
        ]);

        $this->buangIndeks();
        $this->db->query(
            'CREATE UNIQUE INDEX ' . self::NAMA . ' ON ' . $this->db->prefixTable('interviews')
            . " (scheduled_at, jenis) WHERE status IN ('requested', 'approved')"
        );
    }

    /**
     * CREATE-nya sama, DROP-nya beda: SQL Server butuh "ON <tabel>", SQLite
     * tidak. Aturan yang sama dengan migrasi SlotInterviewUnik yang membuat
     * indeks ini pertama kali - dan yang membuat migrasi lolos di produksi tapi
     * jatuh di seluruh uji database kalau dilupakan.
     */
    private function buangIndeks(): void
    {
        $this->db->query($this->db->DBDriver === 'SQLSRV'
            ? 'DROP INDEX ' . self::NAMA . ' ON ' . $this->db->prefixTable('interviews')
            : 'DROP INDEX ' . self::NAMA);
    }

    public function down(): void
    {
        // URUTANNYA PENTING: indeks dibuang DULU, baru kolomnya, baru indeks
        // lama dipasang kembali. SQLite membangun ulang seluruh tabel saat
        // sebuah kolom dibuang, dan indeks unik yang masih terpasang saat itu
        // ikut diterapkan ulang - tanpa klausa WHERE-nya. Baris jadwal lama yang
        // sah karena statusnya sudah tidak aktif lalu ditolak, dan migrate:refresh
        // gagal di seluruh berkas uji.
        $this->buangIndeks();
        $this->forge->dropColumn('interviews', 'jenis');
        $this->db->query(
            'CREATE UNIQUE INDEX ' . self::NAMA . ' ON ' . $this->db->prefixTable('interviews')
            . " (scheduled_at) WHERE status IN ('requested', 'approved')"
        );
    }
}
