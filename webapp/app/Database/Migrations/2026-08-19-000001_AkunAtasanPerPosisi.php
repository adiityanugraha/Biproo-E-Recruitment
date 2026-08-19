<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Akun atasan untuk Interview User (19 Agustus 2026).
 *
 * ALURNYA. Head Developer meminta karyawan tambahan, HRD menyaring pelamarnya,
 * dan kandidat yang lolos wawancara HRD diteruskan ke Interview User - wawancara
 * dengan Head Developer itu sendiri. Akun di tabel ini yang dipakainya masuk.
 *
 * SATU AKUN PER POSISI, bukan per kandidat. Atasan yang sama mewawancarai semua
 * pelamar lowongannya, jadi kredensial baru untuk tiap kandidat cuma memperbanyak
 * email yang harus ia simpan. job_id dibuat UNIK supaya satu lowongan tidak
 * pernah punya dua akun yang sama-sama berlaku - kalau atasannya berganti, yang
 * ada diperbarui, bukan ditambah.
 *
 * TERPISAH DARI TABEL recruiters, dan itu disengaja. Akun ini hanya boleh
 * melihat kandidat lowongannya sendiri, tidak boleh menyentuh CV pelamar lain,
 * daftar lowongan, atau pengaturan. Menaruhnya di tabel yang sama lalu
 * membedakannya lewat kolom peran membuat satu kekeliruan pada satu kueri cukup
 * untuk membuka seluruh data rekrutmen kepada orang luar HRD.
 *
 * SANDINYA TIDAK PERNAH DISIMPAN, hanya hash-nya - sama dengan candidates dan
 * recruiters. Yang dikirim ke atasan sandi acak sekali pakai lewat email, dan
 * HRD sendiri tidak pernah melihatnya.
 */
class AkunAtasanPerPosisi extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'auto_increment' => true],
            'job_id'        => ['type' => 'BIGINT'],
            'nama'          => ['type' => 'VARCHAR', 'constraint' => 160],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            // Jejak kapan kredensialnya terakhir dibuat ulang. Dipakai layar HRD
            // untuk menjawab "atasannya bilang belum menerima email" tanpa harus
            // menebak-nebak.
            'dikirim_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('job_id');
        $this->forge->addForeignKey('job_id', 'jobs', 'id', '', 'CASCADE');
        $this->forge->createTable('akun_atasan');
    }

    public function down(): void
    {
        $this->forge->dropTable('akun_atasan');
    }
}
