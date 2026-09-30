<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Transkrip wawancara suara AI: satu baris per giliran (satu pertanyaan dan
 * jawabannya).
 *
 * Kenapa tabel sendiri, bukan kolom di `interviews`: alasannya sama persis
 * dengan PenilaianInterview - `interviews` memakai indeks unik terfilter untuk
 * mengunci slot, dan SQLite membuang kolom dengan membangun ulang tabel,
 * sehingga down() gagal dan seluruh uji basis data ikut gagal.
 *
 * Kenapa transkrip disimpan utuh, bukan cuma skornya: skor otomatis dari model
 * yang belum dikalibrasi tidak boleh jadi satu-satunya jejak. Recruiter harus
 * bisa membaca apa yang sebenarnya dikatakan kandidat, dan kelak butuh bahan
 * untuk membuktikan penilaian otomatisnya benar atau mengarang.
 *
 * Yang TIDAK disimpan: berkas audionya. Yang dinilai adalah isi jawaban, dan
 * rekaman suara satu orang adalah data pribadi dengan kewajiban retensi yang
 * belum ada aturannya di proyek ini.
 *
 * Tanpa foreign key, mengikuti tabel lain di skema ini.
 */
class DialogWawancaraAi extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id' => ['type' => 'BIGINT'],
            'urutan'         => ['type' => 'INT'],
            // bank     : butir rubrik lowongan
            // lanjutan : pendalaman yang dikarang AI dari jawaban sebelumnya
            // ulangi   : pertanyaan yang sama diulang (jawaban tak tertangkap)
            // penutup  : penanda sesi selesai, tidak punya jawaban
            'sumber'         => ['type' => 'VARCHAR', 'constraint' => 12],
            // indeks butir di jobs.pertanyaan_json - kunci yang memasangkan
            // hasil AI dengan baris form penilaian recruiter
            'butir_index'    => ['type' => 'INT', 'null' => true],
            'kompetensi'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'bobot'          => ['type' => 'INT', 'default' => 0],
            'pertanyaan'     => ['type' => 'VARCHAR', 'constraint' => 500],
            'jawaban'        => ['type' => 'TEXT', 'null' => true],
            // kurang | cukup | baik - kosakata PenilaianRubrik::TINGKAT.
            // NULL = belum/tidak dinilai (jawaban tak memadai, atau LLM gagal)
            'tingkat'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'alasan'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            // Bukti mutu tangkapan suara, dilaporkan browser. Dipakai recruiter
            // untuk menilai apakah "kurang" itu soal jawaban atau soal mikrofon.
            'keyakinan'      => ['type' => 'FLOAT', 'null' => true],
            'derau'          => ['type' => 'FLOAT', 'null' => true],
            'durasi_ms'      => ['type' => 'INT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['application_id', 'urutan']);
        $this->forge->createTable('interview_dialog');
    }

    public function down(): void
    {
        $this->forge->dropTable('interview_dialog');
    }
}
