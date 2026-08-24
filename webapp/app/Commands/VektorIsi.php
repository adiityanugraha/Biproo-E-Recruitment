<?php

namespace App\Commands;

use App\Libraries\AiServiceException;
use App\Models\JobModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Hitung dan simpan vektor syarat lowongan.
 *
 *     php spark vektor:isi --kering
 *     php spark vektor:isi
 *     php spark vektor:isi --id 31 --paksa
 *
 * KENAPA PERINTAH INI ADA. Vektor lowongan terisi sendiri saat ada kandidat
 * melamar ke sana - callback screening membawanya. Tapi lowongan yang belum
 * pernah dilamar tidak punya vektor sama sekali, dan tanpa vektor ia tidak
 * pernah bisa muncul sebagai saran posisi untuk siapa pun. Justru lowongan
 * sepi itulah yang paling butuh diusulkan.
 *
 * Memakai kuota EMBEDDING (1.000 teks sehari), bukan kuota chat yang cuma 20.
 * Satu lowongan paling banyak 3 teks, jadi 36 lowongan sekitar 100 - sekali
 * jalan, dan sesudahnya tidak perlu diulang kecuali syaratnya diubah.
 */
class VektorIsi extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'vektor:isi';
    protected $description = 'Hitung vektor syarat lowongan untuk bahan saran posisi.';
    protected $usage       = 'vektor:isi [--kering] [--id <jobId>] [--paksa]';
    protected $options     = [
        '--kering' => 'Tampilkan daftarnya tanpa memanggil ai-service.',
        '--id'     => 'Batasi ke satu lowongan.',
        '--paksa'  => 'Hitung ulang lowongan yang vektornya sudah ada.',
    ];

    /** Bidang syarat yang di-embed, sama dengan scoring.BIDANG di ai-service. */
    private const BIDANG = ['skill', 'pendidikan', 'pengalaman'];

    public function run(array $params)
    {
        $kering = array_key_exists('kering', $params) || CLI::getOption('kering');
        $paksa  = array_key_exists('paksa', $params) || CLI::getOption('paksa');
        $id     = (int) ($params['id'] ?? CLI::getOption('id') ?? 0);

        $model = new JobModel();
        $q     = $model->orderBy('id');
        if (! $paksa) {
            $q->where('vektor_json IS NULL');
        }
        if ($id > 0) {
            $q->where('id', $id);
        }
        $lowongan = $q->findAll();

        if ($lowongan === []) {
            CLI::write('Tidak ada lowongan yang perlu dihitung.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::write(count($lowongan) . ' lowongan:', 'yellow');
        $teks = 0;
        foreach ($lowongan as $j) {
            $isi = $this->syarat($j);
            $teks += count($isi);
            CLI::write(sprintf('  #%-3d %-42s %d bidang', $j['id'], mb_substr($j['judul'], 0, 42), count($isi)));
        }
        CLI::write("Total {$teks} teks embedding (jatah 1.000 sehari).", 'yellow');

        if ($kering) {
            CLI::write('Kering: tidak ada yang dikirim.', 'green');

            return EXIT_SUCCESS;
        }

        $ok = $lewat = $gagal = 0;
        foreach ($lowongan as $j) {
            $isi = $this->syarat($j);
            if ($isi === []) {
                // Lowongan tanpa satu pun syarat terisi tidak bisa dicocokkan
                // dengan CV mana pun. Dilewati, bukan disimpan kosong.
                CLI::write("  #{$j['id']} dilewati: tidak ada syarat terisi", 'yellow');
                $lewat++;
                continue;
            }

            try {
                $hasil = service('aiService')->post('vektor', $isi);
                $model->update((int) $j['id'], ['vektor_json' => json_encode($hasil['vektor'] ?? [])]);
                CLI::write("  #{$j['id']} tersimpan", 'green');
                $ok++;
            } catch (AiServiceException $e) {
                // Satu lowongan gagal tidak menghentikan sisanya: yang gagal
                // bisa diulang besok, dan perintah ini memang aman diulang.
                CLI::error("  #{$j['id']} gagal: " . $e->getMessage());
                $gagal++;
            }
        }

        CLI::write("Selesai: {$ok} tersimpan, {$lewat} dilewati, {$gagal} gagal.", $gagal === 0 ? 'green' : 'yellow');

        return $gagal === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * Syarat yang terisi saja.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, string>
     */
    private function syarat(array $job): array
    {
        $isi = [];
        foreach (self::BIDANG as $b) {
            $t = trim((string) ($job['req_' . $b] ?? ''));
            if ($t !== '') {
                $isi[$b] = $t;
            }
        }

        return $isi;
    }
}
