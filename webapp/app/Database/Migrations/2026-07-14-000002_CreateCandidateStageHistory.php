<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateCandidateStageHistory extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id' => ['type' => 'BIGINT'],
            // stage & status: daftar nilai sah dijaga validasi model
            // (docs/skema-database.md), bukan CHECK constraint, agar portable
            'stage'          => ['type' => 'VARCHAR', 'constraint' => 30],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'actor'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'note'           => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['application_id', 'created_at']);
        $this->forge->addKey(['stage', 'created_at']);
        $this->forge->createTable('candidate_stage_history');
        // append-only ditegakkan di model (tanpa update/delete) +
        // DENY UPDATE,DELETE saat deploy produksi (db/001_tabel_inti_ereq.sql)
    }

    public function down()
    {
        $this->forge->dropTable('candidate_stage_history');
    }
}
