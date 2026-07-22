<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Tabel interview (Fase 3): satu ajuan per lamaran. Alur kandidat-ajukan ->
 * recruiter-acc:
 *   requested : kandidat mengajukan jadwal (belum ada meeting)
 *   approved  : recruiter setuju -> meeting Zoom dibuat (meeting_id/join_url terisi)
 *   rejected  : recruiter tolak -> kandidat boleh mengajukan ulang
 * meeting_id/join_url null sampai di-acc. recording_url menyusul saat auto-record.
 */
class CreateInterviews extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id' => ['type' => 'BIGINT'],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'requested'],
            'scheduled_at'   => ['type' => 'DATETIME'],
            'meeting_id'     => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'join_url'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            // start_url (host) panjang + memuat token ZAK yang kedaluwarsa; simpan opsional
            'start_url'      => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'recording_url'  => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('application_id');
        $this->forge->createTable('interviews');
    }

    public function down()
    {
        $this->forge->dropTable('interviews');
    }
}
