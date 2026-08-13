<?php

namespace App\Commands;

use App\Libraries\KirimRekaman;
use App\Models\InterviewTranskripModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Kirim ulang rekaman wawancara yang transkripsinya tersangkut.
 *
 * MASALAH YANG DITUTUP: ai-service menyimpan status pekerjaan di memori saja.
 * Kalau callback-nya gagal mendarat - jaringan putus sesaat, CI4 sedang
 * direstart, atau layanannya mati di tengah jalan - transkrip yang sudah jadi
 * ikut hilang bersama prosesnya, dan barisnya tertinggal berstatus 'proses'
 * selamanya sambil layar recruiter berbunyi "sedang ditranskripsi".
 *
 * Tanpa perintah ini jalan keluarnya cuma satu: recruiter mengunggah ulang
 * rekaman yang sama. Itu bekerja, tapi memakan dua panggilan LLM lagi dari
 * jatah dua puluh sehari, dan mengandaikan recruiter masih menyimpan berkasnya.
 *
 * Yang 'antre' ikut dijemput: statusnya begitu berarti pengiriman pertamanya
 * memang tidak pernah berhasil (lihat KirimRekaman).
 */
class TranskripResend extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'transkrip:resend';
    protected $description = 'Kirim ulang rekaman wawancara yang transkripsinya tersangkut atau gagal.';
    protected $usage       = 'transkrip:resend [--kering] [--id <appId>] [--gagal]';
    protected $options     = [
        '--kering' => 'Tampilkan daftarnya tanpa mengirim apa pun.',
        '--id'     => 'Batasi ke satu application_id.',
        '--gagal'  => 'Ikut sertakan yang berstatus gagal (coba lagi).',
    ];

    /**
     * Yang dijemput tanpa --gagal.
     *
     * 'selesai' TIDAK pernah ikut: mengirim ulang yang sudah berhasil berarti
     * menghabiskan kuota untuk menulis transkrip yang sudah ada, dan callback
     * keduanya akan ditolak Interview::callback karena Gate 2 sudah diputus.
     */
    private const TERSANGKUT = ['antre', 'proses'];

    public function run(array $params)
    {
        $kering = array_key_exists('kering', $params);
        $gagal  = array_key_exists('gagal', $params);
        $id     = isset($params['id']) ? (int) $params['id'] : 0;

        $model = new InterviewTranskripModel();
        $q     = $model->whereIn('status', $gagal ? [...self::TERSANGKUT, 'gagal'] : self::TERSANGKUT)
            ->where('berkas IS NOT NULL', null, false);
        if ($id > 0) {
            $q->where('application_id', $id);
        }
        $baris = $q->orderBy('id')->findAll();

        if ($baris === []) {
            CLI::write('Tidak ada rekaman yang perlu dikirim ulang.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::write(count($baris) . ' rekaman:', 'yellow');
        foreach ($baris as $b) {
            CLI::write(sprintf('  #%d  lamaran %d  %s  %s',
                $b['id'], $b['application_id'], str_pad($b['status'], 8), $b['berkas']));
        }

        if ($kering) {
            CLI::write('Kering: tidak ada yang dikirim.', 'green');

            return EXIT_SUCCESS;
        }

        $ok = 0;
        foreach ($baris as $b) {
            if ((new KirimRekaman())->kirim((int) $b['id'])) {
                $ok++;
                CLI::write('  #' . $b['id'] . ' dikirim ulang', 'green');
            } else {
                // KirimRekaman sudah menandai barisnya gagal beserta sebabnya,
                // jadi di sini cukup dilaporkan - bukan dihentikan. Satu rekaman
                // yang bermasalah tidak boleh menahan sisanya.
                CLI::error('  #' . $b['id'] . ' GAGAL dikirim');
            }
        }

        CLI::write("{$ok} dari " . count($baris) . ' rekaman terkirim.', $ok === count($baris) ? 'green' : 'yellow');

        return EXIT_SUCCESS;
    }
}
