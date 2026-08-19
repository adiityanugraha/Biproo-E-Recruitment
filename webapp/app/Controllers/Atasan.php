<?php

namespace App\Controllers;

use App\Libraries\LembarPenilaian;
use App\Libraries\StageLogger;
use App\Models\AkunAtasanModel;
use App\Models\ApplicationModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;

/**
 * Interview User: halaman atasan yang mewawancarai kandidat (19 Agustus 2026).
 *
 * ALURNYA. Head Developer meminta karyawan tambahan, HRD menyaring pelamarnya,
 * dan kandidat yang lolos wawancara HRD diteruskan ke sini. Atasan membaca
 * lembar profil dan hasil wawancara HRD-nya, mewawancarai sendiri, lalu
 * MEMUTUSKAN - dan keputusan itulah yang dikirim ke kandidat.
 *
 * SATU AKUN SATU LOWONGAN. Semua kueri di berkas ini disaring job_id dari SESI,
 * bukan dari parameter URL. Atasan yang mengetik id lamaran orang lain di alamat
 * peramban tetap ditolak, karena yang membatasi bukan tautan yang ia klik.
 *
 * Kompetensinya berbeda dari wawancara HRD: tujuh butir skala 10 (LembarPenilaian
 * ::USER) mengikuti formulir BIPROO, bukan sembilan butir skala 5.
 */
class Atasan extends BaseController
{
    public function login()
    {
        if ($this->request->is('post')) {
            $akun = (new AkunAtasanModel())->untukEmail((string) $this->request->getPost('email'));

            if ($akun === null || ! password_verify((string) $this->request->getPost('password'), $akun['password_hash'])) {
                return view('atasan/login', [
                    'judul'  => 'Masuk Interview User',
                    'errors' => ['login' => 'Email atau kata sandi salah.'],
                ]);
            }

            $job = db_connect()->table('jobs')->where('id', $akun['job_id'])->get()->getRowArray();
            session()->set([
                'atasan_id'     => $akun['id'],
                'atasan_nama'   => $akun['nama'],
                'atasan_job_id' => (int) $akun['job_id'],
                'atasan_posisi' => (string) ($job['judul'] ?? ''),
            ]);

            return redirect()->to('/atasan');
        }

        return view('atasan/login', ['judul' => 'Masuk Interview User']);
    }

    public function logout()
    {
        session()->remove(['atasan_id', 'atasan_nama', 'atasan_job_id', 'atasan_posisi']);

        return redirect()->to('/atasan/login');
    }

    /**
     * Daftar kandidat lowongan ini yang menunggu Interview User.
     *
     * Yang tampil hanya yang tahap interview_user-nya sudah 'entered' - artinya
     * wawancara HRD-nya selesai dan lolos. Kandidat yang masih diproses HRD
     * tidak muncul sama sekali: menampilkannya cuma mengundang atasan menilai
     * orang yang belum tentu diteruskan kepadanya.
     */
    public function index()
    {
        $jobId  = (int) session('atasan_job_id');
        $sh     = new StageHistoryModel();
        $daftar = [];

        $kandidat = (new ApplicationModel())
            ->select('applications.id, applications.cv_path, candidates.nama, candidates.email')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->where('applications.job_id', $jobId)
            ->orderBy('applications.id')
            ->findAll();

        foreach ($kandidat as $a) {
            $peta = $sh->latestStatusMap((int) $a['id']);
            if (($peta['interview_user'] ?? null) === null) {
                continue;
            }
            $a['diputus'] = $peta['gate_2'] ?? null;
            $daftar[]     = $a;
        }

        return view('atasan/daftar', [
            'judul'  => 'Interview User',
            'daftar' => $daftar,
        ]);
    }

    /**
     * Lembar penilaian satu kandidat: bahan bacaannya dulu, baru formnya.
     *
     * Atasan perlu tahu apa yang sudah terjadi di tahap HRD sebelum menilai -
     * transkrip wawancaranya, penilaian AI beserta alasannya, dan riwayat
     * kerjanya. Menyembunyikan itu membuatnya mewawancarai orang yang belum ia
     * kenal sama sekali, padahal datanya sudah ada.
     */
    public function nilai(int $appId)
    {
        $app = $this->kandidat($appId);
        if ($app === null) {
            return redirect()->to('/atasan')->with('error', 'Kandidat tidak ditemukan pada posisi Anda.');
        }

        if ($this->request->is('post')) {
            return $this->simpan($appId, $app);
        }

        $sr = (new ScreeningResultModel())->latestFor($appId);
        $ex = $sr === null ? [] : (json_decode((string) ($sr['extracted_json'] ?? ''), true) ?: []);

        return view('atasan/nilai', [
            'judul'     => 'Penilaian Interview User',
            'app'       => $app,
            'riwayat'   => is_array($ex['riwayat'] ?? null) ? $ex['riwayat'] : [],
            'transkrip' => (new InterviewTranskripModel())->terakhirUntuk($appId),
            'penilaian' => (new InterviewPenilaianModel())->untukLamaran($appId),
            'diputus'   => (new StageHistoryModel())->latestStatus($appId, 'gate_2'),
        ]);
    }

    /**
     * Simpan penilaian atasan DAN keputusan akhirnya, dalam satu tindakan.
     *
     * Sengaja tidak dipisah jadi dua tombol. Keputusan yang dikirim terpisah
     * dari lembarnya membuka jeda di mana kandidat sudah dikabari sementara
     * alasan penilaiannya belum tersimpan - dan lembar itulah yang dibaca orang
     * setahun lagi saat menanyakan dasar keputusannya.
     *
     * @param array<string, mixed> $app
     */
    private function simpan(int $appId, array $app)
    {
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null) {
            return redirect()->to('/atasan')->with('error', 'Kandidat ini sudah diputuskan.');
        }

        $nilai = $this->nilaiUser();
        if ($nilai === null) {
            return redirect()->to('atasan/nilai/' . $appId)
                ->with('error', 'Semua butir penilaian harus diisi sebelum keputusan disimpan.');
        }

        $baris = [];
        foreach ($nilai as $kompetensi => $n) {
            $baris[] = [
                'kompetensi' => $kompetensi,
                'kategori'   => LembarPenilaian::KAT_USER,
                'bobot'      => 1,
                'tingkat'    => (string) $n,
                'catatan'    => '',
            ];
        }
        foreach (LembarPenilaian::NARASI_RECRUITER as $kunci) {
            $teks = LembarPenilaian::rapikan((string) ($this->request->getPost('narasi')[$kunci] ?? ''));
            if ($teks !== '') {
                $baris[] = [
                    'kompetensi' => $kunci,
                    'kategori'   => LembarPenilaian::KAT_NARASI,
                    'bobot'      => 0,
                    'tingkat'    => '',
                    'catatan'    => $teks,
                ];
            }
        }

        // Ganti, bukan tambah - sama dengan lembar recruiter. Atasan yang
        // mengirim ulang formnya tidak boleh meninggalkan dua set nilai yang
        // ikut dirata-ratakan.
        (new InterviewPenilaianModel())->ganti($appId, LembarPenilaian::DARI_ATASAN, $baris);

        $lolos  = $this->request->getPost('keputusan') === 'lolos';
        $logger = new StageLogger();
        $actor  = 'atasan:' . session('atasan_nama');
        $rerata = round(array_sum($nilai) / count($nilai), 1);

        $logger->log($appId, 'interview_user', $lolos ? 'passed' : 'failed', $actor,
            'Rata-rata penilaian atasan ' . $rerata . '/' . LembarPenilaian::MAKS_USER);

        // INILAH keputusan akhirnya untuk posisi yang memakai Interview User.
        // Gate 2 sengaja tidak ditutup sesudah wawancara HRD (lihat
        // Interview::putuskan), jadi email ke kandidat baru terkirim di sini.
        $logger->log($appId, 'gate_2', $lolos ? 'passed' : 'failed', $actor,
            'Keputusan atasan setelah Interview User. Rata-rata penilaian '
            . $rerata . '/' . LembarPenilaian::MAKS_USER . '.',
            ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => session('atasan_posisi')]);

        if ($lolos) {
            $logger->log($appId, 'berkas_kontrak', 'entered', $actor);
        }

        return redirect()->to('/atasan')->with('sukses', 'Keputusan tersimpan: '
            . $app['nama'] . ($lolos ? ' DITERIMA' : ' TIDAK DITERIMA') . ' - kandidat dikabari via email.');
    }

    /**
     * Tujuh nilai dari form, atau null bila ada yang kosong atau di luar skala.
     *
     * Lembar yang separuh terisi bukan penilaian, dan keputusan yang berdiri di
     * atasnya tidak bisa dipertanggungjawabkan kepada kandidat yang bertanya.
     *
     * @return array<string, int>|null
     */
    private function nilaiUser(): ?array
    {
        $kiriman = (array) ($this->request->getPost('nilai') ?? []);
        $out     = [];
        foreach (LembarPenilaian::USER as $i => $kompetensi) {
            $n = $kiriman[$i] ?? null;
            if (! is_numeric($n) || (int) $n < 1 || (int) $n > LembarPenilaian::MAKS_USER) {
                return null;
            }
            $out[$kompetensi] = (int) $n;
        }

        return $out;
    }

    /**
     * Kandidat itu, HANYA bila ia melamar lowongan milik sesi ini.
     *
     * Disaring job_id dari SESI, bukan dari URL. Atasan yang mengetik id lamaran
     * orang lain di alamat peramban mendapat null, bukan data pelamar posisi
     * lain.
     *
     * @return array<string, mixed>|null
     */
    private function kandidat(int $appId): ?array
    {
        return (new ApplicationModel())
            ->select('applications.id, applications.cv_path, candidates.nama, candidates.email, jobs.judul')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('applications.id', $appId)
            ->where('applications.job_id', (int) session('atasan_job_id'))
            ->first();
    }
}
