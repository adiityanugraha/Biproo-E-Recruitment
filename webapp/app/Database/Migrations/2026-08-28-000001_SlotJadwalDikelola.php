<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use DateTimeImmutable;

/**
 * Slot jadwal interview pindah dari kode ke basis data, dan punya kuota.
 *
 * Sebelum ini slot dihasilkan SlotJadwal: 10.00-16.00, hari kerja, 7 hari kerja
 * ke depan, satu slot satu orang. Recruiter tidak bisa mengubah apa pun -
 * libur nasional, hari ia berhalangan, atau jam yang ingin ditutup semuanya
 * menuntut perubahan kode.
 *
 * Sekarang recruiter mengelolanya sendiri lewat Settings, berikut kuota per
 * slot: jam 10.00 berkuota 2 berarti dua kandidat boleh mengambilnya.
 *
 * DUA HAL YANG DILAKUKAN MIGRASI INI SELAIN MEMBUAT TABEL
 * -------------------------------------------------------
 * 1. Indeks unik ux_interviews_slot_aktif DIBUANG. Ia menjamin satu slot satu
 *    orang, dan itulah yang sekarang justru dilarang. Batas jumlahnya pindah
 *    ke kolom kuota, ditegakkan aplikasi saat kandidat memilih.
 *
 * 2. Tabelnya DIISI dengan pola yang berlaku hari ini, dihitung dari saat
 *    migrasi dijalankan. Tanpa itu, begitu migrasi selesai kandidat melihat
 *    halaman jadwal yang kosong sama sekali sampai recruiter sempat mengisinya
 *    - dan pada sistem yang sudah berjalan, itu jam-jam yang hilang.
 */
class SlotJadwalDikelola extends Migration
{
    private const INDEKS_LAMA = 'ux_interviews_slot_aktif';

    /** Pola yang berlaku sebelum migrasi ini, dipakai untuk mengisi awal. */
    private const JAM_PERTAMA = 10;
    private const JAM_TERAKHIR = 16;
    private const HARI_KERJA = 7;

    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'scheduled_at' => ['type' => 'DATETIME'],
            // 1 = perilaku lama. Recruiter menaikkannya bila sanggup
            // mewawancarai lebih dari satu orang pada jam itu.
            'kuota'        => ['type' => 'INT', 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        // Satu baris per waktu. Recruiter mengubah KUOTA-nya, bukan membuat
        // dua baris untuk jam yang sama - dua baris berarti dua sumber
        // kebenaran untuk pertanyaan "jam ini muat berapa orang".
        $this->forge->addUniqueKey('scheduled_at');
        $this->forge->createTable('slot_interview');

        $this->buangIndeksLama();
        $this->isiPolaLama();
    }

    public function down(): void
    {
        $this->forge->dropTable('slot_interview', true);

        // Dipulihkan sebisanya. Ia bisa gagal wajar - misalnya indeksnya sudah
        // ada, atau tabel interviews sudah lebih dulu dibongkar migrasi lain
        // saat seluruh basis data digulung mundur - dan kegagalan seperti itu
        // tidak boleh menghentikan pemunduran migrasi berikutnya.
        try {
            $this->db->query(
                'CREATE UNIQUE INDEX ' . self::INDEKS_LAMA . ' ON ' . $this->db->prefixTable('interviews')
                . " (scheduled_at, jenis) WHERE status IN ('requested', 'approved')"
            );
        } catch (\Throwable $e) {
            // sudah ada, atau tabelnya memang tidak ada lagi
        }
    }

    /**
     * DROP-nya beda antar-driver: SQL Server butuh "ON <tabel>", SQLite tidak.
     * Aturan yang sama dengan migrasi yang membuat indeks ini.
     */
    private function buangIndeksLama(): void
    {
        $tabel = $this->db->prefixTable('interviews');

        try {
            $this->db->query($this->db->DBDriver === 'SQLSRV'
                ? 'DROP INDEX ' . self::INDEKS_LAMA . ' ON ' . $tabel
                : 'DROP INDEX ' . self::INDEKS_LAMA);
        } catch (\Throwable $e) {
            // Indeksnya memang belum ada pada pemasangan yang lebih baru.
        }
    }

    /** Slot 10.00-16.00 untuk 7 hari kerja ke depan, kuota 1, seperti dulu. */
    private function isiPolaLama(): void
    {
        $hari  = (new DateTimeImmutable())->setTime(0, 0);
        $baris = [];

        for ($terkumpul = 0; $terkumpul < self::HARI_KERJA;) {
            // 6 = Sabtu, 7 = Minggu (ISO-8601)
            if ((int) $hari->format('N') <= 5) {
                $terkumpul++;
                for ($jam = self::JAM_PERTAMA; $jam <= self::JAM_TERAKHIR; $jam++) {
                    $baris[] = [
                        'scheduled_at' => $hari->setTime($jam, 0)->format('Y-m-d H:i:s'),
                        'kuota'        => 1,
                        'created_at'   => date('Y-m-d H:i:s'),
                    ];
                }
            }
            $hari = $hari->modify('+1 day');
        }

        if ($baris !== []) {
            $this->db->table('slot_interview')->insertBatch($baris);
        }
    }
}
