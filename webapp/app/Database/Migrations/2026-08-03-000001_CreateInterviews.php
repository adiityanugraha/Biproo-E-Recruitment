<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Tabel interview (Fase 3 Day 1/2): satu baris per meeting Zoom yang dijadwalkan
 * untuk sebuah lamaran. meeting_id disimpan untuk penarikan rekaman/transkrip
 * di masa depan (recording_url menyusul saat auto-record aktif).
 */
class CreateInterviews extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id' => ['type' => 'BIGINT'],
            'meeting_id'     => ['type' => 'VARCHAR', 'constraint' => 50],
            'join_url'       => ['type' => 'VARCHAR', 'constraint' => 500],
            // start_url (host) panjang + memuat token ZAK yang kedaluwarsa; simpan opsional
            'start_url'      => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'scheduled_at'   => ['type' => 'DATETIME', 'null' => true],
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
