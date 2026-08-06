<?php

namespace App\Commands;

use App\Models\JobModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Impor posisi + bank pertanyaan interview dari berkas tim DS:
 *
 *     php spark lowongan:impor
 *     php spark lowongan:impor --berkas "D:\lain\bank.csv" --kering
 *
 * SATU sumber saja: interview_softskill_hardskill.csv. Berkas itu TIDAK
 * dipindahkan ke dalam repo - perintah ini membacanya di tempat.
 *
 * Kenapa bank ini menggantikan generasi LLM untuk posisi yang tercakup:
 *   - kuota tier gratis cuma 20 panggilan generateContent per hari,
 *     bank ini nol panggilan;
 *   - tiap soal membawa kompetensi, indikator jawaban baik, red flag, dan
 *     bobot - rubrik yang TIDAK bisa dikarang LLM tanpa mengarang standar
 *     penilaian;
 *   - kurasi manusia dan spesifik Erajaya, bukan keluaran model umum.
 *
 * Yang TIDAK diambil dari berkas ini karena memang tidak ada di dalamnya:
 * pendidikan minimal. Dikosongkan, bukan dikarang - bidang kosong tidak
 * dinilai oleh scorer dan bobotnya dinormalkan ulang (scoring.py).
 */
class LowonganImpor extends BaseCommand
{
    protected $group       = 'E-REQ';
    protected $name        = 'lowongan:impor';
    protected $description = 'Impor posisi dan bank pertanyaan interview dari CSV tim DS.';
    protected $usage       = 'lowongan:impor [--berkas PATH] [--kering]';
    protected $options     = [
        '--berkas' => 'Jalur CSV bank pertanyaan (bawaan: ../../data/interview_softskill_hardskill.csv)',
        '--kering' => 'Tampilkan rencana tanpa menulis ke basis data.',
    ];

    /**
     * Pertanyaan pembuka yang berlaku untuk SEMUA posisi.
     *
     * Diadaptasi dari kategori 'general' pada InterviewQuestionSeeder tim DS
     * (Fitri, 4 Agustus 2026). Bank CSV tidak memuat satu pun pertanyaan seperti
     * ini - isinya seluruhnya spesifik posisi - padahal wawancara sungguhan
     * selalu dibuka begini.
     *
     * Kategori 'Lainnya' juga dari sana, dan pembedaannya penting: ekspektasi
     * gaji dan kesediaan penempatan memang ditanyakan, tapi BUKAN penilaian
     * skill. Kalau nanti skor interview dihitung dari rubrik, pertanyaan gaji
     * tidak boleh ikut menentukan nilai kandidat.
     *
     * @var list<array{pertanyaan: string, kompetensi: string, kategori: string}>
     */
    private const UMUM = [
        ['pertanyaan' => 'Ceritakan sedikit tentang diri Anda dan pengalaman kerja sebelumnya.',
            'kompetensi' => 'Pembuka', 'kategori' => 'Lainnya'],
        ['pertanyaan' => 'Mengapa Anda tertarik melamar posisi ini di perusahaan kami?',
            'kompetensi' => 'Motivasi', 'kategori' => 'Lainnya'],
        ['pertanyaan' => 'Apakah Anda bersedia ditempatkan di outlet atau cabang sesuai kebutuhan perusahaan?',
            'kompetensi' => 'Ketersediaan', 'kategori' => 'Lainnya'],
        ['pertanyaan' => 'Apa ekspektasi gaji Anda dan kapan Anda bisa mulai bekerja?',
            'kompetensi' => 'Ekspektasi', 'kategori' => 'Lainnya'],
        ['pertanyaan' => 'Bagaimana Anda mengatur waktu jika harus bekerja dengan sistem shift?',
            'kompetensi' => 'Manajemen Waktu', 'kategori' => 'Soft Skill'],
        ['pertanyaan' => 'Ceritakan pengalaman Anda menghadapi rekan kerja atau atasan yang sulit diajak kerja sama.',
            'kompetensi' => 'Kerja Sama Tim', 'kategori' => 'Soft Skill'],
    ];

    /** Kolom yang wajib ada; berkas tanpa salah satunya ditolak, bukan diimpor separuh. */
    private const KOLOM_WAJIB = [
        'posisi', 'posisi_id', 'job_family', 'level',
        'kategori_skill', 'kompetensi', 'pertanyaan',
        'indikator_jawaban_baik', 'red_flag', 'bobot',
    ];

    /**
     * Opsi datang dari dua tempat: CLI::getOption() saat dijalankan lewat
     * terminal, atau array $params saat dipanggil helper command() (dipakai
     * test). Pola yang sama dengan ScreeningResend::opsi().
     */
    private function opsi(array $params, string $nama): string|bool|null
    {
        // Flag tanpa nilai (mis. --kering) masuk sebagai null, jadi KEBERADAAN
        // kuncinya yang berarti true - bukan nilainya.
        if (array_key_exists($nama, $params)) {
            return $params[$nama] ?? true;
        }

        return CLI::getOption($nama);
    }

    public function run(array $params)
    {
        $opsiBerkas = $this->opsi($params, 'berkas');
        $berkas     = is_string($opsiBerkas) && $opsiBerkas !== ''
            ? $opsiBerkas
            : realpath(ROOTPATH . '../../data/interview_softskill_hardskill.csv');
        $kering     = (bool) $this->opsi($params, 'kering');

        if (! is_string($berkas) || ! is_file($berkas)) {
            CLI::error('Berkas tidak ditemukan: ' . var_export($berkas, true));
            CLI::write('Pakai --berkas untuk menunjuk jalurnya.');

            return EXIT_ERROR;
        }

        CLI::write('Membaca ' . $berkas);
        try {
            $baris = $this->baca($berkas);
        } catch (\RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write('  ' . count($baris) . ' baris terbaca');

        $perPosisi = [];
        foreach ($baris as $r) {
            $perPosisi[$r['posisi']][] = $r;
        }
        CLI::write('  ' . count($perPosisi) . ' posisi');

        $model  = new JobModel();
        $baru   = 0;
        $ubah   = 0;
        foreach ($perPosisi as $posisi => $soal) {
            $data = [
                'judul'           => $posisi,
                'kategori'        => $soal[0]['job_family'],
                'req_skill'       => $this->skillDariKompetensi($soal),
                'req_pengalaman'  => $this->pengalamanDariLevel($soal[0]['level']),
                'req_pendidikan'  => '',   // tidak ada di berkas ini; sengaja kosong
                'deskripsi'       => $this->deskripsi($soal[0]),
                'pertanyaan_json' => json_encode($this->soal($soal), JSON_UNESCAPED_UNICODE),
            ];

            $ada = $model->where('judul', $posisi)->first();
            if ($kering) {
                CLI::write(sprintf('  [%s] %-42s %d soal', $ada ? 'ubah' : 'baru', $posisi, count($soal)));

                continue;
            }

            if ($ada === null) {
                $model->insert($data);
                $baru++;
            } else {
                // Idempoten: jalankan ulang memperbarui, tidak menggandakan.
                $model->update($ada['id'], $data);
                $ubah++;
            }
        }

        if ($kering) {
            CLI::write('(kering: tidak ada yang ditulis)', 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write("Selesai. Lowongan baru: {$baru}, diperbarui: {$ubah}.", 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @return list<array<string, string>>
     *
     * @throws \RuntimeException bila header tidak lengkap
     */
    private function baca(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('Berkas tidak bisa dibuka: ' . $path);
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);

            throw new \RuntimeException('Berkas kosong.');
        }
        // BOM UTF-8 membuat nama kolom pertama tidak pernah cocok
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $kurang = array_diff(self::KOLOM_WAJIB, $header);
        if ($kurang !== []) {
            fclose($fh);

            throw new \RuntimeException('Kolom wajib tidak ada: ' . implode(', ', $kurang));
        }

        $baris = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) !== count($header)) {
                continue;
            }
            $a = array_combine($header, array_map(static fn ($v): string => trim((string) $v), $r));
            if (($a['posisi'] ?? '') !== '' && ($a['pertanyaan'] ?? '') !== '') {
                $baris[] = $a;
            }
        }
        fclose($fh);

        return $baris;
    }

    /**
     * Syarat keahlian dirakit dari kompetensi HARD SKILL posisi itu.
     *
     * Hanya hard skill: soft skill seperti "Kemauan Belajar" muncul di hampir
     * semua posisi, jadi memasukkannya membuat setiap lowongan terlihat mirip
     * satu sama lain di mata scorer kemiripan CV.
     *
     * @param list<array<string, string>> $soal
     */
    private function skillDariKompetensi(array $soal): string
    {
        $hard = [];
        foreach ($soal as $r) {
            if (stripos($r['kategori_skill'], 'hard') !== false && $r['kompetensi'] !== '') {
                $hard[$r['kompetensi']] = true;
            }
        }
        if ($hard === []) {
            foreach ($soal as $r) {
                $hard[$r['kompetensi']] = true;
            }
        }

        return implode(', ', array_keys($hard));
    }

    /** Level di berkas ini adalah satu-satunya petunjuk pengalaman yang tersedia. */
    private function pengalamanDariLevel(string $level): string
    {
        return match (strtolower(trim($level))) {
            'entry'     => 'Entry level, terbuka untuk fresh graduate',
            'entry-mid' => 'Entry sampai menengah',
            'mid'       => 'Menengah, sudah berpengalaman di bidang yang sama',
            'harian'    => 'Pekerja harian',
            'part-time' => 'Paruh waktu',
            default     => $level,
        };
    }

    /** @param array<string, string> $r */
    private function deskripsi(array $r): string
    {
        $bagian = array_filter([
            $r['job_family'] ?? '',
            ($r['brand'] ?? '') !== '' && strtolower($r['brand']) !== 'nan' ? 'Brand: ' . $r['brand'] : '',
            ($r['level'] ?? '') !== '' ? 'Level: ' . $r['level'] : '',
        ]);

        return implode('. ', $bagian);
    }

    /**
     * Bentuk objek yang disimpan di jobs.pertanyaan_json.
     *
     * Objek, bukan string, karena rubriknya ikut: indikator jawaban baik dan
     * red flag itu yang membuat bank ini lebih berguna daripada daftar
     * pertanyaan biasa.
     *
     * @param list<array<string, string>> $soal
     *
     * @return list<array<string, string|int>>
     */
    private function soal(array $soal): array
    {
        // Pembuka umum lebih dulu: wawancara dimulai dari sana, bukan langsung
        // menghantam pertanyaan teknis.
        $keluar = self::UMUM;

        foreach ($soal as $r) {
            $keluar[] = [
                'pertanyaan' => $r['pertanyaan'],
                'kompetensi' => $r['kompetensi'],
                'kategori'   => $r['kategori_skill'],
                'indikator'  => $r['indikator_jawaban_baik'],
                'red_flag'   => $r['red_flag'],
                'bobot'      => (int) $r['bobot'],
            ];
        }

        return $keluar;
    }
}
