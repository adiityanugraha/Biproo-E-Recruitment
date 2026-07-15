<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateJobs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'auto_increment' => true],
            'judul'           => ['type' => 'VARCHAR', 'constraint' => 160],
            'req_skill'       => ['type' => 'TEXT'],
            'req_pendidikan'  => ['type' => 'VARCHAR', 'constraint' => 160],
            'req_pengalaman'  => ['type' => 'VARCHAR', 'constraint' => 160],
            'deskripsi'       => ['type' => 'TEXT', 'null' => true],
            // konfigurasi gate per posisi (docs/gate-logic.md); NULL = default
            'bobot_json'      => ['type' => 'TEXT', 'null' => true],
            'threshold_json'  => ['type' => 'TEXT', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('jobs');
    }

    public function down()
    {
        $this->forge->dropTable('jobs');
    }
}
