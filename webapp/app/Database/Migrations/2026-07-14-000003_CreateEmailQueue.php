<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEmailQueue extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'to_email'     => ['type' => 'VARCHAR', 'constraint' => 255],
            // template: konfirmasi_registrasi | undangan_interview | hasil_gate | jadwal_reschedule
            'template'     => ['type' => 'VARCHAR', 'constraint' => 50],
            'payload_json' => ['type' => 'TEXT'],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts'     => ['type' => 'INT', 'default' => 0],
            'last_error'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'sent_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('email_queue');
    }

    public function down()
    {
        $this->forge->dropTable('email_queue');
    }
}
