<?php

namespace App\Controllers;

use App\Libraries\AiServiceException;
use App\Libraries\GateTwo;
use App\Libraries\KategoriPosisi;
use App\Libraries\PenilaianRubrik;
use App\Libraries\StageLogger;
use App\Libraries\ZoomException;
use App\Models\ApplicationModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewModel;
use App\Models\InterviewPenilaianModel;
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
                ['Interview User', '💬', site_url('recruiter/tahap/interview_user')],
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

    /** Report / E-Recruitment Summary (replika BIPROO, data dummy). */
    public function report()
    {
        return view('recruiter/report', [
            'judul' => 'Report',
            'tab'   => $this->request->getGet('tab') === 'fpk' ? 'fpk' : 'summary',
            'fpk' => [
                'total'       => 3431,
                'outstanding' => ['n' => 568, 'pct' => 16.6, 'sla' => ['≤ 7' => 6.4, '≤ 14' => 2.9, '> 14' => 7.3]],
                'fulfilled'   => ['n' => 2863, 'pct' => 83.4, 'sla' => ['≤ 7' => 40.9, '≤ 14' => 15.8, '> 14' => 26.7]],
                'rate'        => 83.4,
                'bySla'       => 66.0,
            ],
            'program' => [
                ['201700563', 'Wiwik Widiyanti', 61, 214, 45, 169, 21.0],
                ['201805521', 'Ahmad Marzuki', 78, 489, 61, 428, 12.5],
                ['201902685', 'Rezki ZN Herlangga', 27, 158, 18, 140, 11.4],
                ['202202424', 'Dendy Andiarto', 28, 92, 19, 73, 20.7],
                ['202206068', 'Annisa Nur Islami', 93, 1083, 101, 982, 9.3],
                ['202206538', 'Ni Putu Dita Suari', 157, 809, 147, 662, 18.2],
            ],
            // [id, nama, inprogress, need, fulfill, open, f7,f14,f14+, o7,o14,o14+]
            'recruiters' => [
                ['202303776', '(Non Active) Hestha pramayshinta', 0, 39, 39, 0, 17, 10, 12, 0, 0, 0],
                ['202504797', '(Non Active) Gadiezatayumni Kh...', 3, 89, 85, 4, 45, 5, 35, 1, 0, 3],
                ['202504070', 'Angel Nurlady Simbolon', 9, 161, 152, 9, 84, 30, 38, 3, 2, 4],
                ['201805521', 'Ahmad Marzuki', 14, 169, 159, 10, 90, 22, 47, 4, 3, 3],
                ['201902685', '(Non Active) Rezki Zn Herlangga', 6, 95, 89, 6, 46, 15, 28, 0, 2, 4],
                ['202301869', 'Supriyatin Pratiwi', 0, 172, 161, 11, 90, 42, 29, 2, 3, 6],
                ['202501566', 'Ditania Dwi Agustina', 2, 217, 203, 14, 83, 46, 74, 2, 4, 8],
            ],
            'total' => [259, 3431, 2863, 568, 1403, 543, 917, 219, 98, 251],
            // label => [need, fulfill, open]
            'vertical' => [
                'Vertical Erajaya Digital'            => [1810, 1506, 304],
                'Vertical Erajaya Active Lifestyle'   => [910, 793, 117],
                'Group HC GA Litigation & CSR'        => [391, 288, 103],
                'Vertical Erajaya Food & Nourishment' => [201, 170, 31],
                'Group Operations'                    => [60, 50, 10],
            ],
            'region' => [
                'REGION 3' => [841, 695, 146],
                'REGION 4' => [685, 562, 123],
                'REGION 1' => [680, 551, 129],
                'REGION 5' => [552, 457, 95],
                'REGION 2' => [358, 325, 33],
                'REGION 6' => [291, 249, 42],
            ],
            // [nama, ero, trainee, junior, existing, ideal, gap, endContract, talentPool]
            'monitoring' => [
                ['Slamet Purwadi', 118, 0, 55, 173, 211, -38, 43, 0],
                ['Adriansyah', 120, 25, 55, 200, 210, -10, 93, 0],
                ['Nugroho', 233, 8, 83, 324, 348, -24, 101, 0],
                ['Yopi Nopika', 215, 28, 105, 348, 388, -40, 133, 0],
                ['Yuni Fransiska Pangaribuan', 87, 5, 39, 131, 147, -16, 52, 0],
            ],
            // [recruiter, requestNo, tanggal, sla, companyCode, jobTitle]
            'dataFpk' => [
                ['Ni Kadek Parika Dewi', '003-1/EXT-DA/FPK/08/25', '2025-08-11', 161, 'DA', 'Promotor Apple'],
                ['(Resign) Rezki ZN Herlangga', '032-1/NASA/FPK/09/2025', '2025-10-07', 92, 'DKSN', 'Trainee'],
                ['Andi Alya Fitriani', '008-2/EGI/FPK/11/2025', '2025-11-11', 69, 'DKSN', 'Store Staff'],
                ['Angelique Karel Sonya Sefia', '013-1/NASA/FPK/07/2025', '2025-07-16', 192, 'DA', 'ERO'],
                ['Ni Putu Dita Suari', '264-1/EAR/FPK/10/2025', '2025-12-29', 15, 'DA', 'ERO'],
                ['Andi Alya Fitriani', '110-2/DCM/FPK/10/2025', '2025-12-29', 33, 'DA', 'Security'],
                ['Ni Putu Dita Suari', '063-1/DCM/FPK/11/2025', '2025-11-07', 76, 'DA', 'ERO'],
                ['Rambo Simatupang', '013-1/EBP/FPK/09/2025', '2025-09-03', 132, 'DKSN', 'Frontliner Leader'],
                ['Ditania Dwi Agustina', '001-1/DTI/FPK/12/2025', '2025-11-13', 62, 'DKSN', 'Administration'],
                ['Angel Nurlady Simbolon', '067-6/DCM/FPK/11/2025', '2026-01-13', 3, 'DA', 'ERO'],
            ],
        ]);
    }

    /** Tabel kandidat per tahap (replika BIPROO Upload CV / Assessment / Interview / Input Data). */
    public function tahap(string $stage)
    {
        $valid = [
            'upload_cv'         => 'Upload CV',
            'online_assessment' => 'Tes Intelegensi Umum 5',
            'interview_online'  => 'Interview HRD',
            'interview_user'    => 'Interview User',
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

        $req    = $this->request->getGet('status');
        $status = $satuTab
            ? 'uploaded'
            : (in_array($req, ['passed', 'failed', 'rescheduled', 'completed'], true) ? $req : 'progress');

        // Interview HRD: tab 'passed' dan 'failed' dihapus 3 Agustus 2026.
        // Tautan lama diarahkan ke penggantinya, bukan dibiarkan jatuh diam-diam
        // ke On Progress dan menampilkan daftar yang salah.
        // Interview User menumpang jadwal HRD (arahan atasan 4 Agustus 2026):
        // tidak ada penjadwalan sendiri, jadi tab dan sumber barisnya sama persis.
        $lewatJadwal = in_array($stage, ['interview_online', 'interview_user'], true);

        if ($lewatJadwal) {
            $status = ['passed' => 'progress', 'failed' => 'rescheduled'][$status] ?? $status;
        }

        // Interview User cuma punya On Progress dan Completed. Tautan lama ke
        // Rescheduled dikembalikan ke On Progress, bukan dibiarkan menampilkan
        // daftar kandidat yang jadwalnya justru sedang tidak ada.
        if ($stage === 'interview_user' && $status === 'rescheduled') {
            $status = 'progress';
        }

        $ivMap = [];
        if ($lewatJadwal) {
            // Tab Interview HRD (arahan atasan 3 Agustus 2026). Ketiganya SALING
            // LEPAS - satu kandidat hanya boleh berada di satu tab, kalau tidak
            // recruiter mengerjakan orang yang sama dua kali:
            //   On Progress = approved & sesi BELUM selesai (akan datang / berlangsung)
            //   Rescheduled = jadwal dilepas, menunggu kandidat memilih slot lain
            //   Completed   = approved & sesi SUDAH selesai (siap dinilai Gate 2)
            //
            // On Progress dan Completed dipisah WAKTU, bukan status: kandidat
            // berpindah sendiri begitu sesi 30 menitnya berakhir, tanpa ada yang
            // perlu menekan apa pun.
            //
            // Rescheduled bukan riwayat: memilih slot baru memakai BARIS YANG SAMA
            // (Lamaran::ajukanInterview), jadi kandidat langsung kembali ke
            // On Progress dan tidak tertinggal di sini.
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
            foreach ($rows as $iv) {
                $ivMap[$iv['application_id']] = $iv;
            }
            $ids = array_keys($ivMap);
        } else {
            // status terkini tiap application pada tahap ini (dari stage_history)
            $latest = [];
            foreach ((new StageHistoryModel())->where('stage', $stage)->orderBy('id')->findAll() as $r) {
                $latest[$r['application_id']] = $r['status'];
            }
            $ids = [];
            foreach ($latest as $appId => $st) {
                $bucket = $satuTab
                    ? 'uploaded'
                    : ($st === 'passed' ? 'passed' : ($st === 'failed' ? 'failed' : 'progress'));
                if ($bucket === $status) {
                    $ids[] = $appId;
                }
            }
        }

        $daftar = $ids === [] ? [] : (new ApplicationModel())
            ->select('applications.id, applications.job_id, candidates.nama, candidates.email, jobs.judul')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->whereIn('applications.id', $ids)
            ->orderBy('applications.id')
            ->findAll();
        $sh = new StageHistoryModel();
        foreach ($daftar as &$a) {
            $a['jadwal']   = $ivMap[$a['id']]['scheduled_at'] ?? null; // waktu jadwal (interview_online)
            $a['join_url'] = $ivMap[$a['id']]['join_url'] ?? null;     // link Zoom (tab Passed)
            // tab Completed: keputusan Gate 2 (null = belum diputus -> tampilkan slider)
            $a['gate2'] = ($stage === 'interview_online' && $status === 'completed')
                ? $sh->latestStatus($a['id'], 'gate_2') : null;
        }
        unset($a);

        return view('recruiter/tahap', [
            'stage'  => $stage,
            'judul'  => $valid[$stage],
            'status' => $status,
            'daftar' => $daftar,
        ]);
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
            return redirect()->to('/recruiter/tahap/interview_user')->with('error', 'Lowongan tidak ditemukan.');
        }

        if ($this->request->is('post')) {
            $tujuan = 'recruiter/pertanyaan/' . $jobId;

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
            ->select('applications.id, candidates.nama, candidates.email, jobs.judul AS posisi, applications.created_at')
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
        return view('recruiter/nilai', [
            'judul'     => 'Penilaian Interview',
            'app'       => $app,
            'rubrik'    => $rubrik,
            'sudah'     => (new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null,
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

        // Dua jalur masukan. Rubrik didahulukan; slider hanya untuk lowongan
        // yang belum punya bank soal.
        $job       = (new JobModel())->find($app['job_id']);
        $rubrik    = $job === null ? [] : $this->pertanyaanJob($job);
        $penilaian = PenilaianRubrik::rakit(
            $rubrik,
            (array) ($this->request->getPost('nilai') ?? []),
            (array) ($this->request->getPost('catatan') ?? [])
        );

        $perluDinilai = PenilaianRubrik::jumlahDinilai($rubrik);
        if ($perluDinilai > 0) {
            // Separuh terisi bukan penilaian, itu tebakan dengan langkah lebih
            // banyak. Butir yang belum diisi TIDAK dihitung nol - itu akan
            // menggugurkan kandidat karena recruiter belum selesai mengklik.
            if (count($penilaian) < $perluDinilai) {
                return redirect()->to('recruiter/nilai/' . $appId)->with('error',
                    'Masih ada ' . ($perluDinilai - count($penilaian)) . ' butir yang belum dinilai. '
                    . 'Lengkapi dulu supaya skornya tidak dihitung dari penilaian separuh.');
            }
            $skorInterview = PenilaianRubrik::skor($penilaian);
        } else {
            $penilaian     = [];
            $skorInterview = max(0, min(100, (int) $this->request->getPost('skor')));
        }

        $skorCv = $this->skorCv($appId);

        // Gate 2 = skor CV digabung skor interview (bobot per posisi dari jobs).
        // Tanpa skor CV, bobotnya dialihkan seluruhnya ke interview - bukan diisi
        // angka karangan yang ikut menentukan kelulusan orang.
        $config = GateTwo::configFromJob($app['bobot_json'] ?? null, $app['threshold_json'] ?? null);
        $rec    = $skorCv === null
            ? GateTwo::recommend($skorInterview / 100, $skorInterview / 100, $config)
            : GateTwo::recommend($skorCv, $skorInterview / 100, $config);

        $lolos  = $rec['recommendation'] === 'hire';
        $logger = new StageLogger();
        $actor  = 'recruiter:' . session('recruiter_nama');
        $email  = ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']];

        // Kompetensi terlemah ikut dicatat. Inilah yang membuat keputusan bisa
        // dijelaskan ke kandidat yang bertanya: bukan "skor 62", melainkan butir
        // mana yang kurang.
        $lemah   = PenilaianRubrik::terlemah($penilaian);
        $rincian = 'Skor interview ' . $skorInterview . '/100'
            . ($penilaian === [] ? '' : ' (dari ' . count($penilaian) . ' kompetensi)')
            . ($lemah === [] ? '' : ', terlemah: ' . implode(', ', $lemah))
            . ($skorCv === null
                ? ', skor CV belum tersedia (bobot dialihkan ke interview)'
                : ', kemiripan CV ' . kemiripan_teks($skorCv))
            . '. Skor akhir ' . skor_100($rec['score']) . '/100';

        if ($penilaian !== []) {
            $model = new InterviewPenilaianModel();
            foreach ($penilaian as $p) {
                $model->insert($p + ['application_id' => $appId]);
            }
        }

        $logger->log($appId, 'interview_online', 'passed', $actor, 'Skor interview ' . $skorInterview . '/100');
        $logger->log($appId, 'gate_2', $lolos ? 'passed' : 'failed', $actor, $rincian, $email);
        if ($lolos) {
            $logger->log($appId, 'berkas_kontrak', 'entered', $actor);
        }

        return redirect()->to($kembali)->with('sukses', 'Skor tersimpan. Keputusan akhir: '
            . ($lolos ? 'LOLOS' : 'TIDAK LOLOS') . ' (skor akhir ' . skor_100($rec['score']) . '/100)'
            . ' - kandidat dikabari via email.');
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

        if ($this->request->is('post')) {
            if ($this->statusGate1($appId) !== 'flagged') {
                return redirect()->to('/recruiter')->with('error', 'Lamaran ini tidak sedang menunggu review.');
            }

            $keputusan = $this->request->getPost('keputusan') === 'approve' ? 'passed' : 'failed';
            (new StageLogger())->log($appId, 'gate_1', $keputusan, 'recruiter:' . session('recruiter_nama'),
                'Keputusan manual recruiter', ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']]);

            return redirect()->to('/recruiter/kandidat')
                ->with('sukses', 'Keputusan tersimpan: kandidat ' . ($keputusan === 'passed' ? 'diloloskan' : 'tidak diloloskan') . '.');
        }

        return view('recruiter/review', [
            'app'     => $app,
            'skorCv'  => $this->skorCv($appId),
            'bukti'   => $this->buktiCv($appId),
            'riwayat' => (new StageHistoryModel())->where('application_id', $appId)->orderBy('id')->findAll(),
            'flagged' => $this->statusGate1($appId) === 'flagged',
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
            return ['riwayat' => [], 'flags' => []];
        }

        $ex = json_decode((string) ($sr['extracted_json'] ?? ''), true);
        $fl = json_decode((string) ($sr['flags_json'] ?? ''), true);

        return [
            'riwayat' => is_array($ex) && is_array($ex['riwayat'] ?? null) ? $ex['riwayat'] : [],
            'flags'   => is_array($fl) ? $fl : [],
        ];
    }

    /** Status gate_1 terkini sebuah lamaran (baris terakhir yang berlaku). */
    private function statusGate1(int $appId): ?string
    {
        return (new StageHistoryModel())->latestStatus($appId, 'gate_1');
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
