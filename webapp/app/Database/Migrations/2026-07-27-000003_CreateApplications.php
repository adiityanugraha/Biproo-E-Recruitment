<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateApplications extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'candidate_id' => ['type' => 'BIGINT'],
            'job_id'       => ['type' => 'BIGINT'],
            'cv_path'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at'   => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        // satu kandidat tidak bisa melamar posisi yang sama dua kali (constraint DB, bukan cek aplikasi)
        $this->forge->addUniqueKey(['candidate_id', 'job_id']);
        $this->forge->createTable('applications');
    }

    public function down()
    {
        $this->forge->dropTable('applications');
    }
}
