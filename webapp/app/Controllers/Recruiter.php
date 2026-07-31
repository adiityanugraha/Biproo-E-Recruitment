<?php

namespace App\Controllers;

use App\Libraries\GateTwo;
use App\Libraries\StageLogger;
use App\Libraries\ZoomException;
use App\Models\ApplicationModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewModel;
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
            : (in_array($req, ['passed', 'failed', 'completed'], true) ? $req : 'progress');

        $ivMap = [];
        if ($stage === 'interview_online') {
            // Tab Interview HRD berdasar status ajuan + waktu. Keempatnya SALING
            // LEPAS - satu kandidat hanya boleh berada di satu tab, kalau tidak
            // recruiter mengerjakan orang yang sama dua kali:
            //   On Progress = requested (menunggu acc)
            //   Passed      = approved & jadwal BELUM lewat (interview akan datang)
            //   Failed      = rejected (recruiter menolak ajuan jadwal kandidat)
            //   Completed   = approved & jadwal SUDAH lewat (siap dinilai Gate 2)
            //
            // Passed dan Completed dipisah tanggal, bukan status: kandidat pindah
            // sendiri dari Passed ke Completed begitu jadwalnya terlewat.
            //
            // Failed bukan riwayat: mengajukan ulang memakai BARIS YANG SAMA
            // (Lamaran::ajukanInterview), jadi kandidat yang mengajukan jadwal
            // baru langsung pindah ke On Progress dan tidak tertinggal di sini.
            //
            // Tanggal dibandingkan di SQL (CURRENT_TIMESTAMP), bukan date() PHP -
            // scheduled_at disimpan dalam jam lokal mesin, sama dgn jam DB.
            // CURRENT_TIMESTAMP portabel SQLSRV + SQLite test, tidak seperti GETDATE.
            $q = new InterviewModel();
            if ($status === 'completed') {
                $rows = $q->where('status', 'approved')->where(new RawSql('scheduled_at <= CURRENT_TIMESTAMP'))->findAll();
            } elseif ($status === 'passed') {
                $rows = $q->where('status', 'approved')->where(new RawSql('scheduled_at > CURRENT_TIMESTAMP'))->findAll();
            } else {
                $ivStatus = ['progress' => 'requested', 'failed' => 'rejected'][$status];
                $rows     = $q->where('status', $ivStatus)->findAll();
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
            ->select('applications.id, candidates.nama, candidates.email, jobs.judul')
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

    /** Recruiter meng-ACC ajuan jadwal kandidat: buat meeting Zoom -> approved -> log + email undangan. */
    public function accInterview(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $kembali   = $this->request->getPost('kembali') === 'interview_hrd'
            ? '/recruiter/tahap/interview_online'
            : '/recruiter/kandidat';
        $interview = new InterviewModel();
        $iv        = $interview->forApplication($appId);
        if ($iv === null || $iv['status'] !== 'requested') {
            return redirect()->to($kembali)->with('error', 'Tidak ada ajuan interview yang menunggu persetujuan.');
        }

        $dt = new DateTime($iv['scheduled_at']);
        try {
            $meeting = service('zoomService')->createMeeting(
                'Interview - ' . $app['nama'] . ' - ' . $app['judul'],
                $dt->format('Y-m-d\TH:i:s'),
            );
        } catch (ZoomException $e) {
            log_message('error', 'Zoom createMeeting gagal: {m}', ['m' => $e->getMessage()]);

            return redirect()->to($kembali)->with('error', 'Gagal membuat meeting Zoom. Coba lagi sebentar.');
        }

        $interview->update($iv['id'], [
            'status'     => 'approved',
            'meeting_id' => $meeting['meeting_id'],
            'join_url'   => $meeting['join_url'],
            'start_url'  => $meeting['start_url'],
        ]);

        (new StageLogger())->log($appId, 'penjadwalan', 'entered', 'recruiter:' . session('recruiter_nama'), 'ajuan interview disetujui', [
            'to'       => $app['email'],
            'nama'     => $app['nama'],
            'posisi'   => $app['judul'],
            'jadwal'   => $this->jadwalIndo($dt),
            // gerbang aplikasi, bukan join_url mentah: link yang diteruskan ke orang
            // lain tidak berguna di luar jendela interview (Lamaran::masukInterview)
            'join_url' => site_url("interview/masuk/{$appId}"),
        ]);

        return redirect()->to($kembali)->with('sukses', 'Ajuan disetujui, undangan interview dikirim ke ' . $app['email'] . '.');
    }

    /** Recruiter menolak ajuan jadwal: kandidat boleh mengajukan jadwal lain. */
    public function tolakInterview(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $kembali   = $this->request->getPost('kembali') === 'interview_hrd'
            ? '/recruiter/tahap/interview_online'
            : '/recruiter/kandidat';
        $interview = new InterviewModel();
        $iv        = $interview->forApplication($appId);
        if ($iv === null || $iv['status'] !== 'requested') {
            return redirect()->to($kembali)->with('error', 'Tidak ada ajuan interview yang menunggu.');
        }

        $interview->update($iv['id'], ['status' => 'rejected']);

        // kabari kandidat lewat email (reject bukan transisi stage, jadi antre langsung)
        (new EmailQueueModel())->insert([
            'to_email'     => $app['email'],
            'template'     => 'jadwal_ditolak',
            'payload_json' => json_encode([
                'nama'   => $app['nama'],
                'posisi' => $app['judul'],
                'jadwal' => $this->jadwalIndo(new DateTime($iv['scheduled_at'])),
            ]),
        ]);

        return redirect()->to($kembali)->with('sukses', 'Ajuan jadwal ditolak, kandidat dikabari via email.');
    }

    /**
     * Keputusan akhir (Gate 2) di tab Completed: recruiter beri skor interview
     * (slider) + putuskan Lolos/Tidak. Selalu manual - sistem hanya merekomendasi.
     */
    public function putusInterview(int $appId)
    {
        $app = $this->lamaranDetail($appId);
        if ($app === null) {
            return redirect()->to('/recruiter')->with('error', 'Lamaran tidak ditemukan.');
        }
        $kembali = '/recruiter/tahap/interview_online?status=completed';

        // approved & jadwal sudah lewat (dibandingkan di SQL supaya konsisten timezone)
        $iv = (new InterviewModel())
            ->where('application_id', $appId)
            ->where('status', 'approved')
            ->where(new RawSql('scheduled_at <= CURRENT_TIMESTAMP'))
            ->orderBy('id', 'DESC')
            ->first();
        if ($iv === null) {
            return redirect()->to($kembali)->with('error', 'Interview belum bisa dinilai (jadwal belum terlewat).');
        }
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null) {
            return redirect()->to($kembali)->with('error', 'Kandidat ini sudah diputuskan.');
        }

        $skorInterview = max(0, min(100, (int) $this->request->getPost('skor')));
        $skorCv        = $this->skorCv($appId);

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

        $rincian = 'Skor interview ' . $skorInterview . '/100'
            . ($skorCv === null
                ? ', skor CV belum tersedia (bobot dialihkan ke interview)'
                : ', kemiripan CV ' . kemiripan_teks($skorCv))
            . '. Skor akhir ' . skor_100($rec['score']) . '/100';

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
            'riwayat' => (new StageHistoryModel())->where('application_id', $appId)->orderBy('id')->findAll(),
            'flagged' => $this->statusGate1($appId) === 'flagged',
        ]);
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
