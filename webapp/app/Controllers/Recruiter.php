<?php

namespace App\Controllers;

use App\Libraries\AiServiceException;
use App\Libraries\GateTwo;
use App\Libraries\KategoriPosisi;
use App\Libraries\KirimRekaman;
use App\Libraries\LembarPenilaian;
use App\Libraries\PertanyaanKandidat;
use App\Libraries\StageLogger;
use App\Libraries\ZoomException;
use App\Models\ApplicationModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Database\RawSql;
use DateTime;

class Recruiter extends BaseController
{
    public function login()
    {
        if ($this->request->is('post')) {
            $recruiter = db_connect()->table('recruiters')
                ->where('email', $this->request->getPost('email'))
                ->get()->getRowArray();

            if ($recruiter === null || ! password_verify((string) $this->request->getPost('password'), $recruiter['password_hash'])) {
                return view('recruiter/login', ['errors' => ['login' => 'Email atau password salah.']]);
            }

            session()->set(['recruiter_id' => $recruiter['id'], 'recruiter_nama' => $recruiter['nama']]);

            return redirect()->to('/recruiter');
        }

        return view('recruiter/login');
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/recruiter/login');
    }

    /** Home recruiter (replika BIPROO): banner, KPI, quick action, stepper, jadwal, kalender, chat. */
    public function index()
    {
        $jobs    = (new JobModel())->orderBy('judul')->findAll();
        $apps    = new ApplicationModel();
        $history = new StageHistoryModel();

        $totalFlagged = 0;
        foreach ($jobs as $job) {
            foreach ($apps->where('job_id', $job['id'])->findAll() as $app) {
                if ($this->statusGate1($app['id']) === 'flagged') {
                    $totalFlagged++;
                }
            }
        }
        $totalPelamar = $apps->countAllResults();
        $lolos        = $history->where(['stage' => 'gate_1', 'status' => 'passed'])
            ->select('application_id')->distinct()->countAllResults();

        return view('recruiter/dashboard', [
            // label mengikuti BIPROO; angka campuran real + dummy (metrik SLA belum dilacak)
            'kpi'  => [
                ['v' => 0, 't' => 'Outstanding > 14'],
                ['v' => $totalFlagged, 't' => 'Outstanding < 14'],
                ['v' => $lolos, 't' => 'Fulfill'],
                ['v' => $totalPelamar, 't' => 'Total Need'],
                ['v' => ($totalPelamar > 0 ? round($lolos / $totalPelamar * 100) : 0) . '%', 't' => 'Fulfill Rate'],
            ],
            // url = tabel tahap (yang memetakan ke stage nyata) atau null (segera hadir)
            'assessmentSteps' => [
                ['Upload CV', '📄', site_url('recruiter/tahap/upload_cv')],
                ['Tes Intelegensi Umum 5', '🧠', site_url('recruiter/tahap/online_assessment')],
                ['D.I.S.C (Personality Test)', '🎯', null],
                ['QLEAP', '🚀', null],
            ],
            'selectionSteps' => [
                ['Interview HRD', '👔', site_url('recruiter/tahap/interview_online')],
                // Dinonaktifkan 6 Agustus 2026: Interview User ternyata tahap
                // tersendiri yang tidak menumpang jadwal HRD, dan bank
                // pertanyaannya sudah pindah ke Interview HRD.
                ['Interview User', '💬', null],
                ['On Job Training', '🛠️', null],
                ['Training Class', '🎓', null],
                ['Input Data & Berkas', '🗂️', site_url('recruiter/tahap/berkas_kontrak')],
                ['Tanda Tangan Kontrak', '✍️', null],
            ],
            'schedule' => [
                ['Rabu, 1 Juli 2026', 'Walk-In Interview', 'Ni Putu Dita Suari', '-'],
                ['Kamis, 2 Juli 2026', 'Walk-In Interview', 'Angelique Karel Sonya Sefia', '-'],
                ['Senin, 6 Juli 2026', 'Psikotes Online', 'Ahmad Marzuki', '-'],
                ['Rabu, 8 Juli 2026', 'Interview User', 'Supriyatin Pratiwi', '-'],
            ],
            'weekDays' => [
                ['Sen', '#2B2B2B', '#F7941D'], ['Sel', '#2B2B2B', '#2E9E5B'], ['Rab', '#2B2B2B', '#1E73E8'],
                ['Kam', '#2B2B2B', '#9B59B6'], ['Jum', '#2B2B2B', '#F5B301'], ['Sab', '#2F6FED', '#2F6FED'], ['Min', '#E23B4E', '#E23B4E'],
            ],
            'calendar' => $this->buildCalendar(),
        ]);
    }

    /** Kalender Juli 2026 dengan event dummy Online/Offline (replika BIPROO). */
    private function buildCalendar(): array
    {
        $lead   = (int) date('N', strtotime('2026-07-01')) - 1; // Senin=0
        $events = [
            2 => ['off' => 2], 3 => ['on' => 1], 7 => ['on' => 3, 'off' => 1], 9 => ['off' => 2],
            13 => ['on' => 4, 'off' => 2], 15 => ['on' => 1], 16 => ['on' => 2, 'off' => 1],
            18 => ['off' => 1], 21 => ['on' => 2], 24 => ['on' => 1, 'off' => 3], 28 => ['off' => 2],
        ];
        $cells = array_fill(0, $lead, ['day' => '', 'on' => 0, 'off' => 0, 'today' => false]);
        for ($d = 1; $d <= 31; $d++) {
            $e       = $events[$d] ?? [];
            $cells[] = ['day' => $d, 'on' => $e['on'] ?? 0, 'off' => $e['off'] ?? 0, 'today' => $d === 16];
        }

        return $cells;
    }

    /** Tabel kandidat per tahap (replika BIPROO Upload CV / Assessment / Interview / Input Data). */
    public function tahap(string $stage)
    {
        $valid = [
            'upload_cv'         => 'Upload CV',
            'online_assessment' => 'Tes Intelegensi Umum 5',
            'interview_online'  => 'Interview HRD',
            'berkas_kontrak'    => 'Input Data & Berkas',
        ];
        if (! isset($valid[$stage])) {
            return redirect()->to('/recruiter')->with('error', 'Tahap tidak dikenal.');
        }

        // Upload CV tidak punya keputusan lolos/gagal - satu-satunya status yang
        // pernah ditulis di tahap ini adalah 'entered'. Memecahnya jadi On Progress
        // / Passed / Failed cuma menghasilkan dua tab yang selamanya kosong, jadi
        // tahap ini memakai satu tab: semua CV yang masuk.
        $satuTab = $stage === 'upload_cv';
        $status  = $this->tabAktif($stage, $satuTab);

        // Dua sumber baris yang berbeda, bukan dua cabang dari sumber yang sama.
        // Interview HRD dibaca dari JADWAL (tabel interviews), tahap lain dari
        // RIWAYAT tahapan. Karena itu keduanya dipisah jadi method sendiri.
        if ($stage === 'interview_online') {
            $ivMap = $this->jadwalPerTab($status);
            $ids   = array_keys($ivMap);
        } else {
            $ivMap = [];
            $ids   = $this->idsDariRiwayat($stage, $status, $satuTab);
        }

        return view('recruiter/tahap', [
            'stage'  => $stage,
            'judul'  => $valid[$stage],
            'status' => $status,
            'daftar' => $this->barisTabel($ids, $ivMap, $stage === 'interview_online' && $status === 'completed'),
        ]);
    }

    /**
     * Tab yang sedang dibuka, dari query string.
     *
     * Interview HRD: tab 'passed' dan 'failed' dihapus 3 Agustus 2026. Tautan
     * lama diarahkan ke penggantinya, bukan dibiarkan jatuh diam-diam ke
     * On Progress dan menampilkan daftar yang salah.
     */
    private function tabAktif(string $stage, bool $satuTab): string
    {
        if ($satuTab) {
            return 'uploaded';
        }

        $req    = $this->request->getGet('status');
        $status = in_array($req, ['passed', 'failed', 'rescheduled', 'completed'], true) ? $req : 'progress';

        return $stage === 'interview_online'
            ? (['passed' => 'progress', 'failed' => 'rescheduled'][$status] ?? $status)
            : $status;
    }

    /**
     * Baris interview untuk satu tab Interview HRD, dipetakan per application_id.
     *
     * Ketiga tab SALING LEPAS - satu kandidat hanya boleh berada di satu tab,
     * kalau tidak recruiter mengerjakan orang yang sama dua kali:
     *   On Progress = approved & sesi BELUM selesai (akan datang / berlangsung)
     *   Rescheduled = jadwal dilepas, menunggu kandidat memilih slot lain
     *   Completed   = approved & sesi SUDAH selesai (siap dinilai Gate 2)
     *
     * On Progress dan Completed dipisah WAKTU, bukan status: kandidat berpindah
     * sendiri begitu sesi 30 menitnya berakhir, tanpa ada yang perlu menekan apa
     * pun. Rescheduled bukan riwayat: memilih slot baru memakai BARIS YANG SAMA
     * (Lamaran::ajukanInterview), jadi kandidat langsung kembali ke On Progress
     * dan tidak tertinggal di sini.
     *
     * @return array<int, array<string, mixed>> application_id => baris interview
     */
    private function jadwalPerTab(string $status): array
    {
        $q = new InterviewModel();

        // Batas dihitung di PHP, bukan CURRENT_TIMESTAMP. Sejak appTimezone
        // diperbaiki ke Asia/Jakarta, jam PHP dan jam DB sama, sedangkan
        // CURRENT_TIMESTAMP berbeda arti di SQLite (UTC) dan SQL Server (WIB)
        // sehingga uji dan produksi tidak sepakat.
        $selesai = (new DateTime())->modify('-' . InterviewModel::TUTUP_MENIT . ' minutes')->format('Y-m-d H:i:s');

        if ($status === 'completed') {
            $rows = $q->where('status', 'approved')->where('scheduled_at <=', $selesai)->findAll();
        } elseif ($status === 'rescheduled') {
            // 'rejected' ikut di sini: peninggalan alur lama yang sama-sama
            // berarti jadwalnya lepas dan kandidat perlu memilih ulang
            $rows = $q->whereIn('status', ['rescheduled', 'rejected'])->findAll();
        } else {
            // 'requested' ikut ditampilkan: sisa alur lama sebelum slot otomatis
            // disetujui. Tidak ada yang membuatnya lagi, tapi kalau ada baris
            // peninggalan ia tidak boleh hilang dari pandangan recruiter.
            $rows = $q->whereIn('status', ['approved', 'requested'])
                ->where('scheduled_at >', $selesai)->findAll();
        }

        $ivMap = [];
        foreach ($rows as $iv) {
            $ivMap[$iv['application_id']] = $iv;
        }

        return $ivMap;
    }

    /**
     * Lamaran yang status TERKINI-nya pada tahap ini masuk tab yang diminta.
     *
     * Yang menentukan baris terakhir per lamaran, bukan ada tidaknya baris:
     * kandidat yang pernah 'entered' lalu 'failed' hanya boleh muncul di Failed.
     *
     * @return list<int>
     */
    private function idsDariRiwayat(string $stage, string $status, bool $satuTab): array
    {
        $terkini = [];
        foreach ((new StageHistoryModel())->where('stage', $stage)->orderBy('id')->findAll() as $r) {
            $terkini[$r['application_id']] = $r['status'];
        }

        $ids = [];
        foreach ($terkini as $appId => $st) {
            $tab = $satuTab
                ? 'uploaded'
                : ($st === 'passed' ? 'passed' : ($st === 'failed' ? 'failed' : 'progress'));
            if ($tab === $status) {
                $ids[] = $appId;
            }
        }

        return $ids;
    }

    /**
     * Baris tabel siap tampil: data lamaran plus kolom yang cuma dipakai tampilan.
     *
     * @param list<int>                            $ids
     * @param array<int, array<string, mixed>>     $ivMap jadwal per application_id
     * @param bool                                 $gate2 sertakan keputusan Gate 2 (tab Completed)
     *
     * @return list<array<string, mixed>>
     */
    private function barisTabel(array $ids, array $ivMap, bool $gate2): array
    {
        if ($ids === []) {
            return [];
        }

        $daftar = (new ApplicationModel())
            // cv_path ikut diambil hanya untuk mengetahui jenis berkasnya: PDF
            // dibuka di jendela pratinjau, DOCX tidak bisa dirender browser.
            ->select('applications.id, applications.job_id, applications.cv_path, candidates.nama, candidates.email, jobs.judul')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->whereIn('applications.id', $ids)
            ->orderBy('applications.id')
            ->findAll();

        $sh = new StageHistoryModel();
        foreach ($daftar as &$a) {
            $a['jadwal']   = $ivMap[$a['id']]['scheduled_at'] ?? null; // waktu jadwal (interview_online)
            $a['join_url'] = $ivMap[$a['id']]['join_url'] ?? null;     // link Zoom
            // tab Completed: keputusan Gate 2 (null = belum diputus -> tampilkan tombol menilai)
            $a['gate2'] = $gate2 ? $sh->latestStatus($a['id'], 'gate_2') : null;
        }
        unset($a);

        return $daftar;
    }

    /**
     * Batas jumlah dan panjang pertanyaan yang boleh tersimpan di satu lowongan.
     *
     * 24, bukan 12: sejak pertanyaan pembuka umum ikut disimpan, satu posisi
     * membawa 6 umum + 9-10 khusus. Batas lama memotong sebagian, dan
     * potongannya tidak meninggalkan jejak apa pun.
     */
    public const MAKS_PERTANYAAN = 24;
    public const MAKS_PANJANG_PERTANYAAN = 300;

    /**
     * Pertanyaan interview per lowongan, dibuat AI (arahan atasan 4 Agustus 2026).
     *
     * Disimpan PER LOWONGAN, bukan per kandidat: tier gratis Gemini cuma memberi
     * 20 panggilan generateContent per hari dan screening CV sudah memakai 1-2
     * per CV. Sekali dibuat, set ini dibaca dari basis data dan tidak memanggil
     * LLM lagi kecuali recruiter menekan "Buat Ulang".
     */
    public function pertanyaan(int $jobId)
    {
        $model = new JobModel();
        $job   = $model->find($jobId);
        if ($job === null) {
            return redirect()->to('/recruiter/tahap/interview_online')->with('error', 'Lowongan tidak ditemukan.');
        }

        // Halaman ini bisa dibuka utuh, atau di dalam jendela pratinjau di atas
        // tabel Interview HRD. Penandanya ikut dibawa pada redirect setelah
        // simpan, kalau tidak, halaman di dalam bingkai berubah jadi versi utuh
        // lengkap dengan topbar dan sidebar yang terjepit di kotak kecil.
        $bingkai = $this->request->getGet('bingkai') === '1';

        if ($this->request->is('post')) {
            $tujuan = 'recruiter/pertanyaan/' . $jobId . ($bingkai ? '?bingkai=1' : '');

            if ($this->request->getPost('aksi') === 'buat') {
                // Tombolnya memang disembunyikan di halaman, tapi menyembunyikan
                // tombol bukan penjagaan: form yang dikirim ulang dari riwayat
                // browser tetap sampai ke sini. Menimpa set bank dengan keluaran
                // LLM berarti kehilangan kompetensi, indikator, red flag, dan
                // bobot - kurasi manusia yang tidak bisa dipulihkan dari sini.
                if (array_filter($this->pertanyaanJob($job), 'is_array') !== []) {
                    return redirect()->to($tujuan)->with('error',
                        'Posisi ini sudah memakai bank soal tim rekrutmen yang lengkap dengan '
                        . 'indikator penilaian. Membuatnya ulang dengan AI akan menghapus rubrik itu, '
                        . 'jadi tidak dilakukan. Sunting pertanyaannya langsung bila perlu diubah.');
                }

                try {
                    $hasil = service('aiService')->post('pertanyaan', [
                        'judul'      => (string) $job['judul'],
                        'skill'      => (string) ($job['req_skill'] ?? ''),
                        'pendidikan' => (string) ($job['req_pendidikan'] ?? ''),
                        'pengalaman' => (string) ($job['req_pengalaman'] ?? ''),
                        'deskripsi'  => (string) ($job['deskripsi'] ?? ''),
                        'jumlah'     => 8,
                    ]);
                } catch (AiServiceException $e) {
                    // Sebab paling sering: kuota harian LLM habis atau ai-service mati.
                    // Set yang lama TIDAK ditimpa - lebih baik pertanyaan kemarin
                    // daripada halaman kosong menjelang interview.
                    log_message('error', 'Pembuatan pertanyaan gagal: ' . $e->getMessage());

                    return redirect()->to($tujuan)->with('error',
                        'Gagal membuat pertanyaan. Layanan AI tidak menjawab atau kuota hariannya habis. '
                        . 'Pertanyaan yang tersimpan sebelumnya tidak diubah.');
                }
                $daftar = $hasil['pertanyaan'] ?? [];
                $pesan  = 'Pertanyaan baru dibuat AI. Silakan sunting bila perlu.';
            } else {
                // Form cuma mengirim teks. Rubrik (kompetensi, indikator, red
                // flag, bobot) digabungkan kembali dari yang TERSIMPAN menurut
                // urutan baris, bukan dititipkan lewat field tersembunyi -
                // standar penilaian tidak boleh bisa diubah dari browser.
                $lama   = $this->pertanyaanJob($job);
                $daftar = [];
                foreach ((array) ($this->request->getPost('pertanyaan') ?? []) as $i => $teks) {
                    $rubrik    = is_array($lama[$i] ?? null) ? $lama[$i] : [];
                    $daftar[]  = $rubrik === [] ? $teks : ['pertanyaan' => $teks] + $rubrik;
                }
                $pesan = 'Pertanyaan tersimpan.';
            }

            $model->update($jobId, ['pertanyaan_json' => json_encode($this->rapikan($daftar), JSON_UNESCAPED_UNICODE)]);

            return redirect()->to($tujuan)->with('sukses', $pesan);
        }

        $milik = $this->pertanyaanJob($job);
        $pinjam = $milik === [] ? $this->pinjamSerumpun($job) : ['pertanyaan' => [], 'dari' => null];

        return view('recruiter/pertanyaan', [
            'judul'      => 'Pertanyaan Interview',
            'job'        => $job,
            'pertanyaan' => $milik !== [] ? $milik : $pinjam['pertanyaan'],
            'pinjamDari' => $milik !== [] ? null : $pinjam['dari'],
            'bingkai'    => $bingkai,
        ]);
    }

    /**
     * Pertanyaan pinjaman dari lowongan serumpun, untuk posisi yang belum punya
     * set sendiri.
     *
     * Rancangannya dari InterviewQuestionModel tim DS: tebak rumpun dari kata
     * kunci judul, lalu SAMPAIKAN bahwa itu tebakan. Bedanya, kalau tebakannya
     * gagal kita tidak memakai kategori cadangan - halaman kosong berikut tombol
     * "Buat dengan AI" adalah jawaban yang lebih jujur daripada menyodorkan
     * pertanyaan gudang untuk pelamar kasir.
     *
     * Pinjaman TIDAK disimpan. Ia tampil sebagai bahan siap pakai; recruiter yang
     * memutuskan menjadikannya milik posisi ini lewat tombol simpan.
     *
     * @param array<string, mixed> $job
     *
     * @return array{pertanyaan: list<mixed>, dari: string|null}
     */
    private function pinjamSerumpun(array $job): array
    {
        $tebakan = KategoriPosisi::tebak((string) $job['judul']);
        if ($tebakan['kategori'] === null) {
            return ['pertanyaan' => [], 'dari' => null];
        }

        $serumpun = (new JobModel())
            ->where('kategori', $tebakan['kategori'])
            ->where('id !=', $job['id'])
            ->orderBy('id')
            ->findAll();

        foreach ($serumpun as $lain) {
            $soal = $this->pertanyaanJob($lain);
            if ($soal !== []) {
                return ['pertanyaan' => $soal, 'dari' => (string) $lain['judul']];
            }
        }

        return ['pertanyaan' => [], 'dari' => null];
    }

    /**
     * Bersihkan daftar pertanyaan sebelum disimpan.
     *
     * Isinya datang dari dua sumber yang sama-sama tak bisa dipercaya bulat:
     * LLM (bisa mengembalikan apa saja) dan form recruiter (bisa dikirim ulang
     * dengan isi apa pun). Baris kosong dibuang supaya form yang ditinggal
     * kosong berarti menghapus, bukan menyimpan deretan baris hampa.
     *
     * @param list<mixed> $daftar
     *
     * @return list<string>
     */
    private function rapikan(array $daftar): array
    {
        $bersih = [];
        foreach ($daftar as $p) {
            // Dua bentuk yang sah, sengaja:
            //   string  - hasil generasi LLM, pertanyaan saja
            //   objek   - bank tim DS, pertanyaan + rubrik penilaiannya
            // Rubrik dipertahankan apa adanya; yang disunting recruiter cuma
            // teks pertanyaannya, karena indikator dan red flag itu standar
            // penilaian yang tidak boleh diubah lewat form biasa.
            $rubrik = [];
            if (is_array($p)) {
                $rubrik = array_intersect_key($p, array_flip(['kompetensi', 'kategori', 'indikator', 'red_flag', 'bobot']));
                $p      = $p['pertanyaan'] ?? '';
            }
            if (! is_scalar($p)) {
                continue;
            }
            $t = trim(preg_replace('/\s+/u', ' ', (string) $p));
            if ($t === '') {
                continue;
            }
            $t = mb_substr($t, 0, self::MAKS_PANJANG_PERTANYAAN);

            $bersih[] = $rubrik === [] ? $t : ['pertanyaan' => $t] + $rubrik;
        }

        return array_slice($bersih, 0, self::MAKS_PERTANYAAN);
    }

    /**
     * Teks pertanyaan dari sebuah entri, apa pun bentuknya.
     *
     * @param array<string, mixed>|string $p
     */
    public static function teksPertanyaan(array|string $p): string
    {
        return is_array($p) ? (string) ($p['pertanyaan'] ?? '') : $p;
    }

    /**
     * Pertanyaan tersimpan milik sebuah lowongan.
     *
     * @param array<string, mixed> $job
     *
     * @return list<string>
     */
    private function pertanyaanJob(array $job): array
    {
        $d = json_decode((string) ($job['pertanyaan_json'] ?? ''), true);

        return is_array($d) ? $this->rapikan($d) : [];
    }

    /**
     * Recruiter membuka berkas CV kandidat (arahan atasan 3 Agustus 2026).
     *
     * Sebelum ini tombol CV di tabel cuma memunculkan modal "segera hadir",
     * sehingga recruiter tidak punya cara melihat CV sebelum interview.
     *
     * Berbeda dari Screening::cvFile yang melayani ai-service lewat token
     * bersama: jalur ini memakai sesi recruiter (filter recruiterauth), jadi
     * keduanya sengaja dipisah supaya kebocoran token internal tidak otomatis
     * membuka seluruh CV, dan sebaliknya.
     */
    public function cvKandidat(int $appId)
    {
        $app = (new ApplicationModel())
            ->select('applications.id, applications.cv_path, candidates.nama')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->where('applications.id', $appId)
            ->first();

        if ($app === null) {
            return redirect()->to('/recruiter/kandidat')->with('error', 'Lamaran tidak ditemukan.');
        }

        // cv_path selalu buatan Lamaran::kirim (nama acak), tapi tetap dipastikan
        // berada DI DALAM folder unggahan. Satu baris database yang tercemar tidak
        // boleh berubah jadi pembaca berkas sembarang di server.
        $dasar = realpath(WRITEPATH . 'uploads/cv');
        $path  = realpath(WRITEPATH . $app['cv_path']);
        if ($dasar === false || $path === false || ! str_starts_with($path, $dasar . DIRECTORY_SEPARATOR)) {
            return redirect()->back()->with('error', 'Berkas CV tidak ditemukan di server.');
        }

        // Nama kandidat masuk header Content-Disposition, jadi karakter yang bisa
        // menyisipkan header baru atau menutup tanda kutip dibuang lebih dulu.
        $aman = preg_replace('/[^A-Za-z0-9 _.-]/', '', $app['nama']) ?: 'Kandidat';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $nama = 'CV - ' . trim($aman) . '.' . $ext;

        // PDF ditampilkan langsung di browser supaya recruiter bisa membacanya
        // sambil menyiapkan interview tanpa mengunduh dulu. DOCX tidak bisa
        // dirender browser, jadi tetap diunduh.
        if ($ext === 'pdf') {
            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $nama . '"')
                ->setBody((string) file_get_contents($path));
        }

        return $this->response->download($path, null)->setFileName($nama);
    }

    /** Daftar SEMUA kandidat lintas posisi: stage terkini + skor + flag + label posisi. */
    public function kandidat()
    {
        // ponytail: N+1 query riwayat per pelamar - cukup utk volume KP;
        // ganti window function ROW_NUMBER() bila pelamar ribuan
        $daftar  = (new ApplicationModel())
            ->select('applications.id, applications.cv_path, candidates.nama, candidates.email, jobs.judul AS posisi, applications.created_at')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->orderBy('applications.id')
            ->findAll();
        $history   = new StageHistoryModel();
        $interview = new InterviewModel();

        foreach ($daftar as &$app) {
            $terakhir            = $history->where('application_id', $app['id'])->orderBy('id', 'DESC')->first();
            $app['stage_akhir']  = $terakhir['stage'] ?? '-';
            $app['status_akhir'] = $terakhir['status'] ?? '-';
            $gate1               = $history->where(['application_id' => $app['id'], 'stage' => 'gate_1'])->orderBy('id', 'DESC')->first();
            $app['gate1']        = $gate1['status'] ?? null;
            // skor kecocokan CV sebagai ANGKA dari screening_results, bukan teks
            // catatan riwayat - itu yang bikin note mentah bocor ke tabel
            $app['skor_cv']      = $this->skorCv((int) $app['id']);
            // ajuan/jadwal interview terkini (kandidat yang mengajukan, recruiter meng-acc)
            $app['interview']    = $interview->forApplication($app['id']);
        }

        return view('recruiter/kandidat', ['daftar' => $daftar]);
    }



    /**
     * Recruiter meminta kandidat memilih slot lain (reschedule).
     *
     * Bukan pembatalan proses: kandidatnya tetap jalan, cuma jamnya diganti.
     * Slot yang dilepas otomatis kembali ke daftar pilihan karena penyaring slot
     * dan indeks unik sama-sama hanya mengunci status requested/approved.
     *
     * Meeting Zoom lama SENGAJA tidak dihapus. Gerbang interview/masuk menolak
     * begitu status bukan 'approved', dan email undangan hanya memuat URL gerbang
     * bukan link Zoom mentah, jadi kandidat tidak bisa masuk lewat jalur normal.
     * Yang tidak tercabut: link Zoom asli yang mungkin tersimpan di riwayat
     * browser kandidat bila ia pernah membuka gerbang saat jendelanya terbuka.
     * Mencabutnya butuh DELETE meeting ke Zoom API - belum dikerjakan.
     */
    public function rescheduleInterview(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $kembali = '/recruiter/tahap/interview_online?status=passed';

        $interview = new InterviewModel();
        $iv        = $interview->forApplication($appId);
        if ($iv === null || $iv['status'] !== 'approved') {
            return redirect()->to($kembali)->with('error', 'Tidak ada jadwal interview aktif untuk lamaran ini.');
        }

        $dt = new DateTime($iv['scheduled_at']);
        // Jadwal yang sudah tiba tidak boleh diubah: wawancaranya mungkin benar-benar
        // terjadi, dan mengubahnya akan mengacaukan tab Completed serta Gate 2.
        if ($dt <= new DateTime()) {
            return redirect()->to($kembali)->with('error', 'Jadwal ini sudah berlangsung, tidak bisa dijadwalkan ulang.');
        }

        $alasan = trim((string) $this->request->getPost('alasan'));
        $alasan = $alasan === '' ? 'tidak disebutkan' : mb_substr($alasan, 0, 200);

        $interview->update($iv['id'], ['status' => 'rescheduled']);

        // Mencabut jadwal di basis data TIDAK mematikan ruangannya. Tanpa langkah
        // ini meeting tetap hidup di Zoom, dan siapa pun yang sempat menyimpan
        // join_url masih bisa masuk - gerbang aplikasi cuma menjaga pintu depan.
        //
        // Kegagalannya TIDAK menjatuhkan reschedule: jadwal sudah dilepas dan
        // kandidat sudah diminta memilih ulang, jadi membatalkan semuanya karena
        // Zoom sedang tidak menjawab justru meninggalkan keadaan setengah jadi.
        // Yang terjadi: tautannya dicatat sebagai masih hidup supaya bisa dikejar.
        $sisaTautan = false;
        if (! empty($iv['meeting_id'])) {
            try {
                service('zoomService')->hapusMeeting((string) $iv['meeting_id']);
                // Dikosongkan hanya setelah Zoom benar-benar menghapusnya. Kalau
                // gagal, kolomnya dibiarkan supaya jejak ruangan yang masih hidup
                // tidak ikut hilang.
                $interview->update($iv['id'], ['meeting_id' => null, 'join_url' => null, 'start_url' => null]);
            } catch (ZoomException $e) {
                $sisaTautan = true;
                log_message('error', 'Meeting Zoom {m} gagal dihapus untuk lamaran {a}: {e}',
                    ['m' => $iv['meeting_id'], 'a' => $appId, 'e' => $e->getMessage()]);
            }
        }

        // penjadwalan/failed memicu email jadwal_reschedule lewat StageLogger,
        // sekaligus membuat tahap Penjadwalan menyala merah di stepper kandidat
        // supaya ia tahu harus memilih ulang tanpa menunggu membaca email
        (new StageLogger())->log($appId, 'penjadwalan', 'failed', 'recruiter:' . session('recruiter_nama'),
            'Diminta jadwal ulang: ' . $alasan, [
                'to'     => $app['email'],
                'nama'   => $app['nama'],
                'posisi' => $app['judul'],
                'jadwal' => $this->jadwalIndo($dt),
                'alasan' => $alasan,
            ]);

        $pesan = 'Jadwal dilepas. ' . $app['nama'] . ' diminta memilih slot lain via email.';
        if ($sisaTautan) {
            // Recruiter perlu tahu, bukan cuma berkas log: ruangan lama masih
            // bisa dimasuki siapa pun yang menyimpan tautannya.
            return redirect()->to($kembali)->with('error', $pesan
                . ' Tapi ruang Zoom lama GAGAL dihapus dan masih bisa dimasuki - hapus manual di akun Zoom Anda.');
        }

        return redirect()->to($kembali)->with('sukses', $pesan);
    }

    /**
     * Keputusan akhir (Gate 2) di tab Completed: recruiter beri skor interview
     * (slider) + putuskan Lolos/Tidak. Selalu manual - sistem hanya merekomendasi.
     */
    /**
     * Berkas rekaman yang diterima. Zoom merekam lokal ke M4A (audio saja) atau
     * MP4 (audio + video); MP3 dan WAV ikut diterima karena recruiter kadang
     * mengonversinya dulu agar berkasnya lebih kecil.
     */
    public const REKAMAN_EKSTENSI = 'm4a,mp4,mp3,wav';

    /**
     * 35 MB. Rekaman audio Zoom 30 menit sekitar 15-30 MB; video jauh lebih
     * besar dan memang sebaiknya tidak diunggah - yang dibutuhkan cuma suaranya.
     *
     * Angka ini HARUS di bawah upload_max_filesize dan post_max_size php.ini.
     * Kalau melebihi, PHP membuang berkasnya sebelum CI4 sempat memeriksa, dan
     * yang terlihat recruiter cuma form yang kembali kosong tanpa pesan apa pun.
     */
    public const REKAMAN_MAKS_KB = 35840;

    /**
     * Ruang interview recruiter (revisi 12 Agustus 2026).
     *
     * Satu halaman untuk satu kandidat, dibuka berdampingan dengan jendela Zoom:
     * tautan ruangannya, tiga pertanyaan yang disusun dari CV kandidat itu, dan
     * tempat mengunggah rekaman setelah wawancara selesai.
     *
     * Menggantikan tombol "Pertanyaan" yang dulu membuka daftar milik LOWONGAN
     * dan sama untuk semua pelamarnya.
     */
    public function ruangInterview(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }

        // Pertanyaan dibuat saat halaman ini DIBUKA, bukan saat Gate 1 lolos.
        // Kalau kuota habis di detik Gate 1, kandidat akan tersangkut tanpa
        // pertanyaan dan tanpa jalan keluar; di sini kegagalan masih bisa
        // jatuh ke bank soal lowongan, dan recruiter melihatnya seketika.
        $pertanyaan = (new PertanyaanKandidat())->untukLamaran($appId);

        return view('recruiter/ruang', [
            'judul'      => 'Ruang Interview',
            'app'        => $app,
            'iv'         => (new InterviewModel())
                ->where('application_id', $appId)->orderBy('id', 'DESC')->first(),
            'pertanyaan' => $pertanyaan,
            'transkrip'  => (new InterviewTranskripModel())->terakhirUntuk($appId),
            // Penilaian AI beserta alasannya ditampilkan DI SINI, bukan cuma
            // tersimpan. Tanpa itu, kalimat "setiap nilai wajib disertai
            // kutipan" cuma berlaku di dalam basis data - dan penilaian yang
            // tidak pernah dibaca siapa pun sama saja dengan tidak beralasan.
            'penilaian'  => (new InterviewPenilaianModel())->untukLamaran($appId),
            'sudah'      => (new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null,
            'bingkai'    => $this->request->getGet('bingkai') === '1',
        ]);
    }

    /**
     * Sunting atau susun ulang tiga pertanyaan kandidat.
     *
     * Dua tindakan dalam satu endpoint karena keduanya menulis ke tempat yang
     * sama dan berangkat dari form yang sama. Yang membedakan tombol mana yang
     * ditekan, bukan alur yang berbeda.
     */
    public function simpanPertanyaan(int $appId)
    {
        if ($this->lamaranDetail($appId) === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $bingkai = $this->request->getPost('bingkai') === '1';
        $tujuan  = 'recruiter/ruang/' . $appId . ($bingkai ? '?bingkai=1' : '');

        // Setelah kandidat diputus, pertanyaannya jadi catatan: ia dasar
        // penilaian yang sudah terlanjur dipakai. Mengubahnya di belakang
        // membuat lembar profil bercerita tentang wawancara yang tidak terjadi.
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null) {
            return redirect()->to($tujuan)->with('error',
                'Kandidat ini sudah diputuskan, pertanyaannya tidak bisa diubah lagi.');
        }

        $lib = new PertanyaanKandidat();
        if ($this->request->getPost('aksi') === 'buat') {
            $hasil = $lib->buatUlang($appId);

            return redirect()->to($tujuan)->with(
                $hasil === [] ? 'error' : 'sukses',
                $hasil === []
                    ? 'Gagal menyusun pertanyaan. Layanan AI tidak menjawab atau kuota hariannya habis, '
                      . 'dan lowongan ini belum punya bank soal sebagai cadangan.'
                    : 'Pertanyaan disusun ulang.'
            );
        }

        $lib->simpanTeks($appId, array_map('strval', (array) ($this->request->getPost('pertanyaan') ?? [])));

        return redirect()->to($tujuan)->with('sukses', 'Pertanyaan tersimpan.');
    }

    /**
     * Unggah rekaman wawancara SEKALIGUS menilai tiga kompetensi yang butuh mata.
     *
     * Satu form, sekali kirim, dan itu disengaja. Gate 2 ditutup otomatis begitu
     * penilaian AI mendarat; kalau tiga nilai ini dikirim terpisah, callback
     * bisa tiba lebih dulu dan kandidat diputuskan dari lembar yang belum
     * lengkap. Menggabungkannya membuat urutannya tidak mungkin terbalik.
     *
     * Appearance, Personal Grooming, dan Self-Presentation Skills tidak bisa
     * dibaca dari transkrip - yang tersimpan di sana cuma kata-kata yang
     * terucap. Recruiter yang menonton wawancaranya yang menilai.
     */
    public function unggahRekaman(int $appId)
    {
        if ($this->lamaranDetail($appId) === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $bingkai = $this->request->getPost('bingkai') === '1';
        $tujuan  = 'recruiter/ruang/' . $appId . ($bingkai ? '?bingkai=1' : '');

        // Form-nya memang disembunyikan setelah kandidat diputus, tapi
        // menyembunyikan form bukan penjagaan: kiriman ulang dari riwayat
        // browser tetap sampai ke sini. Rekaman yang mendarat sesudah keputusan
        // akan tampak sebagai dasar penilaian padahal tidak pernah dibaca.
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null) {
            return redirect()->to($tujuan)->with('error',
                'Kandidat ini sudah diputuskan, rekamannya tidak bisa ditambah lagi.');
        }

        $aturan = ['rekaman' => [
            'rules'  => 'uploaded[rekaman]|max_size[rekaman,' . self::REKAMAN_MAKS_KB . ']'
                        . '|ext_in[rekaman,' . self::REKAMAN_EKSTENSI . ']',
            'errors' => [
                'uploaded' => 'Belum ada berkas rekaman yang dipilih.',
                'max_size' => 'Ukuran rekaman maksimal ' . round(self::REKAMAN_MAKS_KB / 1024) . ' MB. '
                              . 'Rekam audio saja (bukan video) supaya berkasnya jauh lebih kecil.',
                'ext_in'   => 'Berkas harus rekaman audio atau video (' . self::REKAMAN_EKSTENSI . ').',
            ],
        ]];
        if (! $this->validate($aturan)) {
            return redirect()->to($tujuan)->with('error', implode(' ', $this->validator->getErrors()));
        }

        // Ketiganya wajib, dan diperiksa SEBELUM berkasnya dipindahkan: lembar
        // yang separuh terisi bukan penilaian, dan Gate 2 akan menutup sendiri
        // dari apa pun yang ada di tabel saat callback tiba.
        $nilai = $this->nilaiMataManusia();
        if ($nilai === null) {
            return redirect()->to($tujuan)->with('error',
                'Nilai ' . implode(', ', LembarPenilaian::MATA_MANUSIA) . ' harus diisi semua. '
                . 'Ketiganya tidak bisa dibaca dari transkrip, jadi hanya Anda yang bisa menilainya.');
        }

        // Nama acak, sama seperti CV: nama asli dari Zoom memuat nama peserta
        // dan tanggalnya, dan berkas rekaman wawancara jauh lebih peka daripada
        // CV - isinya seluruh pembicaraan, bukan ringkasan yang dipilih sendiri
        // oleh kandidat.
        $berkas = $this->request->getFile('rekaman');
        $nama   = $berkas->getRandomName();
        $berkas->move(WRITEPATH . 'uploads/rekaman', $nama);

        $id = (new InterviewTranskripModel())->insert([
            'application_id' => $appId,
            'sumber'         => 'unggahan',
            'status'         => 'antre',
            'berkas'         => 'uploads/rekaman/' . $nama,
        ]);

        $this->simpanMataManusia($appId, $nilai);

        // Rekaman TETAP tersimpan walau pengirimannya gagal, dan barisnya
        // ditandai supaya bisa dicoba ulang. Wawancara sudah terjadi; kehilangan
        // rekamannya berarti meminta kandidat mengulang.
        $terkirim = (new KirimRekaman())->kirim((int) $id);

        return redirect()->to($tujuan)->with(
            $terkirim ? 'sukses' : 'error',
            $terkirim
                ? 'Rekaman tersimpan dan sedang ditranskripsi. Keputusan akhir muncul otomatis setelah selesai.'
                : 'Rekaman tersimpan, tapi layanan AI tidak menjawab. Coba kirim ulang nanti.'
        );
    }

    /**
     * Tiga nilai dari form, atau null bila ada yang belum diisi.
     *
     * @return array<string, int>|null
     */
    private function nilaiMataManusia(): ?array
    {
        $kiriman = (array) ($this->request->getPost('nilai') ?? []);
        $out     = [];
        foreach (LembarPenilaian::MATA_MANUSIA as $i => $kompetensi) {
            $n = $kiriman[$i] ?? null;
            if (! is_numeric($n) || (int) $n < 1 || (int) $n > LembarPenilaian::MAKS_SKALA) {
                return null;
            }
            $out[$kompetensi] = (int) $n;
        }

        return $out;
    }

    /** @param array<string, int> $nilai */
    private function simpanMataManusia(int $appId, array $nilai): void
    {
        $db = db_connect();
        $db->transException(true)->transStart();
        $model = new InterviewPenilaianModel();
        foreach ($nilai as $kompetensi => $n) {
            $model->insert([
                'application_id' => $appId,
                'kompetensi'     => $kompetensi,
                'kategori'       => LembarPenilaian::KAT_HRD,
                'sumber'         => LembarPenilaian::DARI_RECRUITER,
                'bobot'          => 1,
                'tingkat'        => (string) $n,
                'catatan'        => '',
            ]);
        }
        $db->transComplete();
    }

    /**
     * Form penilaian interview per kompetensi.
     *
     * Menggantikan slider 0-100 yang dulu menempel di sel tabel tab Completed.
     * Lima belas butir rubrik tidak muat di sana, dan yang lebih penting: angka
     * slider itu tidak punya dasar apa pun sementara ia ikut menentukan Gate 2.
     *
     * Lowongan tanpa rubrik (3 dari 34) tetap memakai slider - lebih baik jalur
     * lama yang jujur daripada rubrik karangan.
     */
    public function formNilai(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }

        $job    = (new JobModel())->find($app['job_id']);
        $rubrik = $job === null ? [] : $this->pertanyaanJob($job);
        $gate2  = (new StageHistoryModel())->latestStatus($appId, 'gate_2');

        return view('recruiter/nilai', [
            'judul'     => 'Penilaian Interview',
            'app'       => $app,
            'rubrik'    => $rubrik,
            'gate2'     => $gate2,
            'sudah'     => $gate2 !== null,
            'penilaian' => (new InterviewPenilaianModel())->untukLamaran($appId),
            'skorCv'    => $this->skorCv($appId),
        ]);
    }

    public function putusInterview(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $kembali = '/recruiter/tahap/interview_online?status=completed';

        // Syaratnya HARUS sama persis dengan tab Completed, kalau tidak kandidat
        // muncul di daftar siap dinilai tapi penilaiannya ditolak: sesi 30 menit
        // sudah berakhir, bukan sekadar jam mulai terlewat.
        $selesai = (new DateTime())->modify('-' . InterviewModel::TUTUP_MENIT . ' minutes')->format('Y-m-d H:i:s');
        $iv      = (new InterviewModel())
            ->where('application_id', $appId)
            ->where('status', 'approved')
            ->where('scheduled_at <=', $selesai)
            ->orderBy('id', 'DESC')
            ->first();
        if ($iv === null) {
            return redirect()->to($kembali)->with('error', 'Interview belum bisa dinilai (sesinya belum selesai).');
        }
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null) {
            return redirect()->to($kembali)->with('error', 'Kandidat ini sudah diputuskan.');
        }

        // Lembar penilaian BIPROO: sembilan kompetensi tetap, sama untuk semua
        // posisi. Slider 0-100 untuk lowongan tanpa bank soal sudah tidak ada -
        // lembarnya kini tidak bergantung pada bank pertanyaan sama sekali.
        $penilaian = LembarPenilaian::rakitHrd(
            (array) ($this->request->getPost('nilai') ?? []),
            (array) ($this->request->getPost('narasi') ?? [])
        );

        // Separuh terisi bukan penilaian, itu tebakan dengan langkah lebih
        // banyak. Butir yang belum diisi TIDAK dihitung nol - itu akan
        // menggugurkan kandidat karena recruiter belum selesai mengklik.
        $dinilai = count(array_filter(
            $penilaian,
            static fn (array $p): bool => $p['kategori'] === LembarPenilaian::KAT_HRD
        ));
        if ($dinilai < count(LembarPenilaian::HRD)) {
            return redirect()->to('recruiter/nilai/' . $appId)->with('error',
                'Masih ada ' . (count(LembarPenilaian::HRD) - $dinilai) . ' kompetensi yang belum dinilai. '
                . 'Lengkapi dulu supaya skornya tidak dihitung dari penilaian separuh.');
        }
        $skorInterview = LembarPenilaian::skor($penilaian);

        $skorCv = $this->skorCv($appId);
        $logger = new StageLogger();
        $actor  = 'recruiter:' . session('recruiter_nama');
        $email  = ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']];

        // Kompetensi terlemah ikut dicatat. Inilah yang membuat keputusan bisa
        // dijelaskan ke kandidat yang bertanya: bukan "skor 62", melainkan butir
        // mana yang kurang.
        $lemah = LembarPenilaian::terlemah($penilaian);
        // HANYA baris kompetensi yang dihitung. $penilaian juga memuat kotak
        // narasi dan baris hasil akhir, dan ikut menghitungnya membuat catatan
        // berbunyi "dari 10 kompetensi" padahal kompetensinya sembilan.
        $dasar = 'Skor interview ' . $skorInterview . '/100'
            . ($dinilai === 0 ? '' : ' (dari ' . $dinilai . ' kompetensi)')
            . ($lemah === [] ? '' : ', terlemah: ' . implode(', ', $lemah));

        // Satu transaksi untuk seluruh lembar. Tanpa ini, kegagalan di tengah
        // meninggalkan penilaian separuh yang tidak akan pernah dibersihkan -
        // tabel ini tambah-saja, tidak punya jalur perbaikan.
        //
        // Bukan kekhawatiran teoretis: 12 Agustus 2026 baris 'hasil' ditolak
        // SQL Server karena melebihi lebar kolom, dan sembilan baris kompetensi
        // sebelumnya sudah terlanjur masuk. Lamaran itu jadi punya 18 baris
        // kompetensi untuk satu kali penilaian.
        if ($penilaian !== []) {
            $db = db_connect();
            $db->transException(true)->transStart();
            $model = new InterviewPenilaianModel();
            foreach ($penilaian as $p) {
                $model->insert($p + ['application_id' => $appId]);
            }
            $db->transComplete();
        }

        $logger->log($appId, 'interview_online', 'passed', $actor, 'Skor interview ' . $skorInterview . '/100');

        // TANPA SKOR CV, SISTEM TIDAK MEMUTUS.
        //
        // Sebelumnya bobot CV dialihkan seluruhnya ke interview, sehingga satu
        // komponen yang hilang diam-diam berubah menjadi rumus yang berbeda:
        // kandidat yang CV-nya gagal terbaca dinilai dengan aturan lain dari
        // kandidat sebelahnya, tanpa ada yang tahu. Yang jujur adalah mengakui
        // datanya kurang dan menyerahkan keputusannya ke recruiter, sama seperti
        // Gate 1 menandai kandidat 'flagged' alih-alih menebak.
        //
        // Skor interview tetap dihitung, disimpan, dan ditampilkan - bukan
        // sebagai penentu, melainkan bahan pertimbangan.
        if ($skorCv === null) {
            $logger->log($appId, 'gate_2', 'flagged', $actor,
                $dasar . '. Skor CV tidak tersedia, keputusan diserahkan ke recruiter');

            return redirect()->to($kembali)->with('sukses',
                'Penilaian tersimpan (skor interview ' . $skorInterview . '/100). '
                . 'Skor CV tidak tersedia untuk kandidat ini, jadi keputusan akhirnya '
                . 'Anda yang tentukan lewat tombol Loloskan / Tidak Lolos.');
        }

        // Gate 2 = skor CV digabung skor interview (bobot per posisi dari jobs).
        $config = GateTwo::configFromJob($app['bobot_json'] ?? null, $app['threshold_json'] ?? null);
        $rec    = GateTwo::recommend($skorCv, $skorInterview / 100, $config);
        $lolos  = $rec['recommendation'] === 'hire';

        $rincian = $dasar . ', kemiripan CV ' . kemiripan_teks($skorCv)
            . '. Skor akhir ' . skor_100($rec['score']) . '/100';

        $logger->log($appId, 'gate_2', $lolos ? 'passed' : 'failed', $actor, $rincian, $email);
        if ($lolos) {
            $logger->log($appId, 'berkas_kontrak', 'entered', $actor);
        }

        return redirect()->to($kembali)->with('sukses', 'Skor tersimpan. Keputusan akhir: '
            . ($lolos ? 'LOLOS' : 'TIDAK LOLOS') . ' (skor akhir ' . skor_100($rec['score']) . '/100)'
            . ' - kandidat dikabari via email.');
    }

    /**
     * Keputusan Gate 2 manual, untuk kandidat yang skor CV-nya tidak tersedia.
     *
     * Dipisah dari putusInterview() karena memang dua tindakan berbeda: yang satu
     * mencatat penilaian interview, yang ini memutuskan nasib kandidat. Polanya
     * sama dengan review Gate 1: sistem menandai, manusia memutuskan.
     */
    public function putusGate2(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $kembali = '/recruiter/tahap/interview_online?status=completed';

        // Hanya yang memang sedang menunggu keputusan. Tanpa pemeriksaan ini,
        // form yang dikirim ulang dari riwayat browser bisa menimpa keputusan
        // yang sudah dibuat, dan kandidat menerima dua email yang bertentangan.
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== 'flagged') {
            return redirect()->to($kembali)->with('error', 'Kandidat ini tidak sedang menunggu keputusan.');
        }

        $lolos  = $this->request->getPost('keputusan') === 'lolos';
        $logger = new StageLogger();
        $actor  = 'recruiter:' . session('recruiter_nama');

        $logger->log($appId, 'gate_2', $lolos ? 'passed' : 'failed', $actor,
            'Keputusan manual recruiter (skor CV tidak tersedia)',
            ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']]);

        if ($lolos) {
            $logger->log($appId, 'berkas_kontrak', 'entered', $actor);
        }

        return redirect()->to($kembali)->with('sukses', 'Keputusan tersimpan: kandidat '
            . ($lolos ? 'LOLOS' : 'TIDAK LOLOS') . ' - kandidat dikabari via email.');
    }

    /**
     * Skor kecocokan CV untuk Gate 2, dibaca dari screening_results.
     *
     * Bila screening belum menghasilkan skor, kembalikan null: Gate 2 lalu
     * diputuskan recruiter tanpa komponen CV, bukan memakai angka karangan.
     */
    private function skorCv(int $appId): ?float
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);

        return $sr === null || $sr['score_overall'] === null ? null : (float) $sr['score_overall'];
    }

    /** Format jadwal ramah Bahasa Indonesia untuk email undangan. */
    private function jadwalIndo(DateTime $dt): string
    {
        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')] . ' ' . $dt->format('Y') . ', ' . $dt->format('H:i') . ' WIB';
    }

    /** Review kandidat ber-flag: riwayat lengkap + keputusan approve/reject. */
    public function review(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }

        // Sama seperti halaman pertanyaan: bisa dibuka utuh, atau di dalam
        // jendela pratinjau di atas tabel.
        $bingkai = $this->request->getGet('bingkai') === '1';

        if ($this->request->is('post')) {
            if ($this->statusGate1($appId) !== 'flagged') {
                return redirect()->to('/recruiter')->with('error', 'Lamaran ini tidak sedang menunggu review.');
            }

            $keputusan = $this->request->getPost('keputusan') === 'approve' ? 'passed' : 'failed';
            (new StageLogger())->log($appId, 'gate_1', $keputusan, 'recruiter:' . session('recruiter_nama'),
                'Keputusan manual recruiter', ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']]);

            $pesan = 'Keputusan tersimpan: kandidat ' . ($keputusan === 'passed' ? 'diloloskan' : 'tidak diloloskan') . '.';

            // Di dalam bingkai, kembali ke halaman ini sendiri. Mengarahkan ke
            // daftar kandidat berarti menampilkan daftar kedua di dalam kotak
            // kecil, sementara daftar yang sebenarnya ada di halaman induk.
            return $bingkai
                ? redirect()->to('/recruiter/review/' . $appId . '?bingkai=1')->with('sukses', $pesan)
                : redirect()->to('/recruiter/kandidat')->with('sukses', $pesan);
        }

        return view('recruiter/review', [
            'app'     => $app,
            'skorCv'  => $this->skorCv($appId),
            'bukti'   => $this->buktiCv($appId),
            'riwayat' => (new StageHistoryModel())->where('application_id', $appId)->orderBy('id')->findAll(),
            'flagged' => $this->statusGate1($appId) === 'flagged',
            'bingkai' => $bingkai,
        ]);
    }

    /**
     * Riwayat kerja bertanda bukti + flag dari hasil screening.
     *
     * Skor kemiripan mengukur tumpang tindih makna, bukan kompetensi: CV yang
     * cuma menyalin kata dari iklan lowongan terukur mendapat 0,9592, di atas
     * backend sungguhan berpengalaman 3 tahun (0,9042). Yang membedakan bukan
     * makna tapi bukti - nama tempat kerja dan rentang waktu. Ditampilkan apa
     * adanya di sini biar recruiter yang menilai; sistem tidak menghitung durasi
     * sendiri (format periode di CV asli terlalu liar, lihat structure.py).
     *
     * @return array{riwayat: list<array<string,string>>, flags: list<string>}
     */
    private function buktiCv(int $appId): array
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);
        if ($sr === null) {
            return ['riwayat' => [], 'flags' => [], 'pribadi' => []];
        }

        $ex = json_decode((string) ($sr['extracted_json'] ?? ''), true);
        $fl = json_decode((string) ($sr['flags_json'] ?? ''), true);

        return [
            'riwayat' => is_array($ex) && is_array($ex['riwayat'] ?? null) ? $ex['riwayat'] : [],
            'flags'   => is_array($fl) ? $fl : [],
            // Biodata untuk lembar profil kandidat. Dibaca dari CV, TIDAK ikut
            // menentukan skor apa pun (lihat structure.py: tidak pernah di-embed).
            'pribadi' => is_array($ex) && is_array($ex['data_pribadi'] ?? null) ? $ex['data_pribadi'] : [],
        ];
    }

    /** Status gate_1 terkini sebuah lamaran (baris terakhir yang berlaku). */
    private function statusGate1(int $appId): ?string
    {
        return (new StageHistoryModel())->latestStatus($appId, 'gate_1');
    }

    /**
     * Lembar profil kandidat, tiga halaman, siap dicetak
     * (arahan atasan 12 Agustus 2026).
     *
     * Merakit yang sudah ada di sistem, bukan meminta orang mengetik ulang:
     * biodata dan riwayat kerja dari hasil baca CV, hasil interview dari lembar
     * penilaian recruiter. Halaman 2 (T.I.U 5 dan DISC) belum punya sumber data
     * sama sekali, jadi dicetak apa adanya sebagai belum tersedia - lebih jujur
     * daripada halaman kosong tanpa keterangan.
     */
    public function profil(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }

        $sh = new StageHistoryModel();

        return view('recruiter/profil', [
            'judul'      => 'Lembar Profil Kandidat',
            'app'        => $app,
            'bukti'      => $this->buktiCv($appId),
            'skorCv'     => $this->skorCv($appId),
            'assessment' => $sh->latestStatus($appId, 'online_assessment'),
            'penilaian'  => (new InterviewPenilaianModel())->untukLamaran($appId),
            'gate2'      => $sh->latestStatus($appId, 'gate_2'),
        ]);
    }

    private function lamaranDetail(int $appId): ?array
    {
        return (new ApplicationModel())
            ->select('applications.id, applications.job_id, candidates.nama, candidates.email, jobs.judul, jobs.bobot_json, jobs.threshold_json')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('applications.id', $appId)
            ->first();
    }
}
