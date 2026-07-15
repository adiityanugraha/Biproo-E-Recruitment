<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateRecruiters extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'auto_increment' => true],
            'nama'          => ['type' => 'VARCHAR', 'constraint' => 160],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at'    => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('recruiters');
    }

    public function down()
    {
        $this->forge->dropTable('recruiters');
    }
}
