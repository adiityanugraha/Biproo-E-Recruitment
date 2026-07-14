<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateScreeningResults extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id'   => ['type' => 'BIGINT'],
            'screening_job_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            // status: success | failed_extraction | failed_provider (dijaga validasi model)
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20],
            'score_overall'    => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'score_skill'      => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'score_pendidikan' => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'score_pengalaman' => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'extracted_json'   => ['type' => 'TEXT', 'null' => true],
            'flags_json'       => ['type' => 'TEXT', 'null' => true],
            'provider'         => ['type' => 'VARCHAR', 'constraint' => 50],
            'model_version'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at'       => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['application_id', 'created_at']);
        $this->forge->addKey('status');
        $this->forge->createTable('screening_results');
    }

    public function down()
    {
        $this->forge->dropTable('screening_results');
    }
}
