<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Fondasi interview berbasis rekaman (arahan revisi 12 Agustus 2026).
 *
 * Wawancara tetap lisan lewat Zoom bersama recruiter. Yang berubah: pertanyaan
 * dibuat per KANDIDAT dari isi CV-nya, dan penilaian tidak lagi diketik dari
 * ingatan melainkan dibaca dari transkrip rekaman.
 *
 * Tiga perubahan dijadikan satu berkas karena memang satu fitur: dipasang atau
 * dicabut bersama-sama, tidak ada gunanya salah satunya berdiri sendiri.
 *
 * TABEL TRANSKRIP ADALAH SAMBUNGANNYA. Hari ini yang mengisinya berkas rekaman
 * lokal Zoom yang diunggah recruiter. Kalau kelak akun Zoom naik ke paket
 * berbayar dan transkrip cloud-nya bisa diambil lewat API, yang berubah cuma
 * pengisi kolom 'sumber' - seluruh penilaian, Gate 2, dan lembar profil di
 * belakangnya tidak perlu disentuh sama sekali.
 */
class InterviewBerbasisRekaman extends Migration
{
    /**
     * Sumber transkrip. 'unggahan' = rekaman lokal Zoom yang diunggah recruiter,
     * 'zoom_cloud' = transkrip otomatis Zoom (belum bisa: akun perlu paket
     * berbayar, scope perekaman, dan URL webhook publik).
     */
    public const SUMBER = ['unggahan', 'zoom_cloud'];

    /** Status pengerjaan transkrip. Sengaja seperti screening_results. */
    public const STATUS = ['antre', 'proses', 'selesai', 'gagal'];

    public function up(): void
    {
        // 1. Tiga pertanyaan milik SATU kandidat, bukan satu lowongan.
        //
        // Kolom di applications, bukan tabel sendiri: isinya paling banyak tiga
        // baris, selalu dibaca sekaligus, dan tidak pernah diagregasi lintas
        // kandidat. Bentuknya sengaja sama dengan jobs.pertanyaan_json supaya
        // pembaca yang sudah mengenal yang satu langsung mengenali yang lain.
        //
        // [{"pertanyaan": "...", "sumber": "pengalaman"|"posisi"}, ...]
        $this->forge->addColumn('applications', [
            'pertanyaan_json' => ['type' => 'TEXT', 'null' => true, 'after' => 'cv_path'],
        ]);

        // 2. Rekaman dan transkripnya.
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'auto_increment' => true],
            'application_id' => ['type' => 'BIGINT'],
            // sumber + status: lihat konstanta di atas, dijaga validasi model
            'sumber'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 20],
            // Berkas rekaman di writable/uploads/rekaman. NULL setelah dihapus:
            // rekaman wawancara memuat suara kandidat dan seluruh isi
            // pembicaraan, jauh lebih peka daripada CV. Transkripnya tetap
            // tersimpan sebagai dasar penilaian walau berkasnya sudah dibuang.
            'berkas'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'teks'           => ['type' => 'TEXT', 'null' => true],
            // Sebab kegagalan, dibaca recruiter di layar. Tanpa ini kegagalan
            // transkripsi hanya tampak sebagai kolom kosong tanpa keterangan.
            'catatan'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'model_version'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['application_id', 'created_at']);
        $this->forge->addKey('status');
        $this->forge->createTable('interview_transkrip');

        // 3. Siapa yang memberi nilai.
        //
        // Mulai sekarang satu lembar penilaian diisi DUA pihak: AI membaca
        // transkrip untuk kompetensi yang memang terbaca dari ucapan, recruiter
        // menilai yang cuma bisa dilihat mata (Appearance, Personal Grooming,
        // Self-Presentation Skills). Tanpa kolom ini keduanya tidak bisa
        // dibedakan lagi setelah tersimpan, dan itu justru yang paling perlu
        // diketahui saat kelak mengukur apakah penilaian AI-nya masuk akal.
        $this->forge->addColumn('interview_penilaian', [
            'sumber' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'kategori'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('interview_penilaian', 'sumber');
        $this->forge->dropTable('interview_transkrip');
        $this->forge->dropColumn('applications', 'pertanyaan_json');
    }
}
