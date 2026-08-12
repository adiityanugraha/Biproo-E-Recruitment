<?php

namespace App\Commands;

use App\Libraries\ZoomException;
use App\Models\ApplicationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Hapus seluruh lamaran milik satu alamat email, beserta jejaknya:
 *
 *     php spark lamaran:hapus --email orang@contoh.com --kering
 *     php spark lamaran:hapus --email orang@contoh.com
 *
 * AKUNNYA TIDAK DISENTUH. Yang dihapus lamaran dan turunannya, supaya kandidat
 * masih bisa masuk dan melamar lagi.
 *
 * Tiga hal yang tidak akan beres kalau dikerjakan dengan DELETE manual, dan
 * karena itu perintah ini ada:
 *
 *   1. Urutan hapus harus dari anak ke induk, dan daftar id-nya diambil SEBELUM
 *      apa pun dihapus - kalau dicari ulang lewat JOIN ke candidates, begitu
 *      applications terhapus sisanya tidak menemukan apa-apa.
 *   2. Ruang Zoom hidup di server Zoom, bukan di basis data. Menghapus barisnya
 *      saja meninggalkan ruangan yang masih bisa dimasuki siapa pun yang
 *      menyimpan tautannya.
 *   3. Berkas CV ada di disk. Baris hilang, berkasnya menumpuk jadi yatim.
 */
class LamaranHapus extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'lamaran:hapus';
    protected $description = 'Hapus semua lamaran milik satu email (akun tetap ada).';
    protected $usage       = 'lamaran:hapus --email ALAMAT [--kering]';
    protected $options     = [
        '--email'  => 'Alamat email pemilik lamaran (wajib)',
        '--kering' => 'Tampilkan yang akan dihapus, tanpa mengubah apa pun',
    ];

    /** Anak lebih dulu, applications paling akhir. */
    private const TABEL_TURUNAN = [
        'interview_penilaian',
        'interviews',
        'screening_results',
        'candidate_stage_history',
    ];

    public function run(array $params)
    {
        $email  = (string) ($this->opsi($params, 'email') ?? '');
        $kering = (bool) $this->opsi($params, 'kering');

        if ($email === '') {
            CLI::error('Wajib: --email alamat@contoh.com');

            return EXIT_ERROR;
        }

        $db      = db_connect();
        $lamaran = (new ApplicationModel())
            ->select('applications.id, applications.cv_path, jobs.judul')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id', 'left')
            ->where('candidates.email', $email)
            ->orderBy('applications.id')
            ->findAll();

        if ($lamaran === []) {
            CLI::write("Tidak ada lamaran untuk {$email}.", 'yellow');

            return EXIT_SUCCESS;
        }

        // Diambil SEKARANG, selagi barisnya masih ada.
        $ids    = array_column($lamaran, 'id');
        $zoom   = $this->meetingIds($db, $ids);
        $berkas = array_filter(array_column($lamaran, 'cv_path'));

        CLI::write(count($lamaran) . " lamaran milik {$email}:", 'yellow');
        foreach ($lamaran as $l) {
            CLI::write('  #' . $l['id'] . '  ' . ($l['judul'] ?? '(lowongan terhapus)'));
        }
        foreach (self::TABEL_TURUNAN as $t) {
            CLI::write('  ' . $t . ': ' . $this->hitung($db, $t, $ids) . ' baris');
        }
        CLI::write('  ruang Zoom: ' . (count($zoom) ?: 'tidak ada'));
        CLI::write('  berkas CV: ' . count($berkas));

        if ($kering) {
            CLI::write('Kering: tidak ada yang diubah.', 'green');

            return EXIT_SUCCESS;
        }

        $this->cadangkan($db);

        // Zoom lebih dulu: setelah barisnya hilang, meeting_id-nya tidak bisa
        // dicari lagi dan ruangannya jadi yatim selamanya.
        foreach ($zoom as $id) {
            try {
                service('zoomService')->hapusMeeting($id);
                CLI::write("  ruang Zoom {$id} dicabut", 'green');
            } catch (ZoomException $e) {
                // Bukan alasan membatalkan penghapusan - tapi harus terbaca,
                // supaya bisa dibereskan manual lewat portal Zoom.
                CLI::error("  ruang Zoom {$id} GAGAL dicabut: " . $e->getMessage());
            }
        }

        foreach (self::TABEL_TURUNAN as $t) {
            $db->table($t)->whereIn('application_id', $ids)->delete();
        }
        $db->table('applications')->whereIn('id', $ids)->delete();
        CLI::write('  ' . count($ids) . ' lamaran terhapus dari basis data', 'green');

        foreach ($berkas as $rel) {
            $path = WRITEPATH . $rel;
            if (is_file($path) && unlink($path)) {
                CLI::write('  berkas ' . $rel . ' dihapus', 'green');
            }
        }

        CLI::write("Selesai. Akun {$email} tidak disentuh.", 'green');

        return EXIT_SUCCESS;
    }

    /**
     * Flag tanpa nilai (mis. --kering) masuk sebagai null, jadi KEBERADAAN
     * kuncinya yang berarti true - bukan nilainya.
     */
    private function opsi(array $params, string $nama): string|bool|null
    {
        if (array_key_exists($nama, $params)) {
            return $params[$nama] ?? true;
        }

        return CLI::getOption($nama);
    }

    /** @param list<int> $ids */
    private function hitung(object $db, string $tabel, array $ids): int
    {
        return $db->table($tabel)->whereIn('application_id', $ids)->countAllResults();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string>
     */
    private function meetingIds(object $db, array $ids): array
    {
        $baris = $db->table('interviews')->select('meeting_id')
            ->whereIn('application_id', $ids)->where('meeting_id IS NOT NULL', null, false)
            ->get()->getResultArray();

        return array_values(array_filter(array_column($baris, 'meeting_id')));
    }

    /**
     * Cadangan sebelum menghapus. Penghapusan ini tidak bisa dibatalkan, dan
     * satu salah ketik alamat email menghapus lamaran orang lain.
     *
     * Khusus SQL Server. Basis data uji memakai SQLite yang tidak punya BACKUP,
     * dan itu bukan kegagalan - cuma tidak berlaku.
     *
     * TANPA "WITH COMPRESSION": kompresi backup tidak ada di edisi Express
     * (Msg 1844), dan itu menggagalkan seluruh perintahnya.
     */
    private function cadangkan(object $db): void
    {
        if ($db->DBDriver !== 'SQLSRV') {
            return;
        }

        $tujuan = 'C:\\temp\\ereq-sebelum-hapus.bak';

        try {
            $db->query("BACKUP DATABASE [{$db->getDatabase()}] TO DISK = '{$tujuan}' WITH INIT");
            CLI::write('  cadangan: ' . $tujuan, 'green');
        } catch (Throwable $e) {
            // Diteruskan, bukan dibatalkan: yang menjalankan ini sudah melihat
            // daftarnya lewat --kering. Tapi hilangnya jaring pengaman harus
            // terlihat jelas, bukan lewat begitu saja.
            CLI::error('  cadangan GAGAL: ' . $e->getMessage());
            CLI::write('  penghapusan diteruskan tanpa cadangan.', 'yellow');
        }
    }
}
