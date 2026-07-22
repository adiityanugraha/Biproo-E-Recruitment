<?php

namespace App\Controllers;

use App\Libraries\GateOne;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;
use DateTime;

class Lamaran extends BaseController
{
    private const MIME_SAH = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const STAGE_LABEL = [
        'upload_cv'         => 'CV Terkirim',
        'ai_verification'   => 'Screening CV (AI)',
        'online_assessment' => 'Assessment',
        'gate_1'            => 'Keputusan Tahap 1',
        'penjadwalan'       => 'Penjadwalan Interview',
        'interview_online'  => 'Interview',
        'gate_2'            => 'Keputusan Akhir',
        'berkas_kontrak'    => 'Berkas & Kontrak',
    ];

    public const STATUS_LABEL = [
        'entered'      => 'berjalan',
        'passed'       => 'lolos',
        'failed'       => 'tidak lolos',
        'flagged'      => 'menunggu review recruiter',
        'retry_queued' => 'diproses ulang',
    ];

    /** Definisi stepper (stage nyata dipetakan ke dua kolom ala BIPROO). */
    private const STEPPER = [
        'assessment' => [
            ['upload_cv', 'Upload CV', '📄'],
            ['ai_verification', 'Verifikasi CV (AI)', '🤖'],
            ['online_assessment', 'Assessment', '📝'],
            ['gate_1', 'Keputusan Tahap 1', '🎯'],
        ],
        'selection' => [
            ['penjadwalan', 'Penjadwalan Interview', '📅'],
            ['interview_online', 'Interview', '🎥'],
            ['gate_2', 'Keputusan Akhir', '✅'],
            ['berkas_kontrak', 'Berkas & Kontrak', '📁'],
        ],
    ];

    public function dashboard()
    {
        $apps = (new ApplicationModel())
            ->select('applications.id, jobs.judul')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('candidate_id', session('candidate_id'))
            ->orderBy('applications.id', 'DESC')
            ->findAll();

        $pilih = (int) ($this->request->getGet('app') ?? 0);
        $aktif = null;
        foreach ($apps as $a) {
            if ($a['id'] === $pilih) {
                $aktif = $a;
            }
        }
        $aktif ??= $apps[0] ?? null;

        // status terkini per stage (baris terakhir per stage yang menang)
        $statusMap = $aktif !== null ? (new StageHistoryModel())->latestStatusMap($aktif['id']) : [];
        // urutan global 8 tahap -> tahap sebelum tahap terkini dianggap done
        $urutan   = array_column(array_merge(self::STEPPER['assessment'], self::STEPPER['selection']), 0);
        $maxIdx   = -1;
        foreach ($urutan as $i => $stage) {
            if (isset($statusMap[$stage])) {
                $maxIdx = $i;
            }
        }
        // begitu lolos Gate 1, tahap Penjadwalan Interview terbuka -> halaman pengajuan jadwal
        $gate1Passed = ($statusMap['gate_1'] ?? null) === 'passed';

        // halaman tujuan per tahap; null = belum ada halaman (modal "segera hadir")
        $appId    = $aktif['id'] ?? 0;
        $urlStage = [
            'upload_cv'         => site_url('lamar'),
            'ai_verification'   => site_url('status'),
            'online_assessment' => site_url('assessment/' . $appId),
            'gate_1'            => site_url('status'),
            'penjadwalan'       => $gate1Passed ? site_url('jadwal') : null,
        ];
        $build = static function (array $list) use ($statusMap, $urutan, $maxIdx, $urlStage, $gate1Passed): array {
            return array_map(static function (array $s) use ($statusMap, $urutan, $maxIdx, $urlStage, $gate1Passed): array {
                [$stage, $label, $icon] = $s;
                $i   = array_search($stage, $urutan, true);
                $st  = ! isset($statusMap[$stage]) ? ($i > $maxIdx ? 'locked' : 'done')
                    : ($statusMap[$stage] === 'failed' ? 'failed'
                    : ($statusMap[$stage] === 'passed' || $i < $maxIdx ? 'done' : 'current'));
                // Penjadwalan Interview: jangan terkunci begitu lolos Gate 1 (agar bisa diklik ke /jadwal)
                if ($stage === 'penjadwalan' && $gate1Passed && ! isset($statusMap['penjadwalan'])) {
                    $st = 'current';
                }
                $url = $urlStage[$stage] ?? null;

                return compact('label', 'icon', 'st', 'url');
            }, $list);
        };

        return view('lamaran/dashboard', [
            'apps'            => $apps,
            'aktif'           => $aktif,
            'jumlahLamaran'   => count($apps),
            'assessmentSteps' => $build(self::STEPPER['assessment']),
            'selectionSteps'  => $build(self::STEPPER['selection']),
        ]);
    }

    public function index()
    {
        return view('lamaran/form', [
            'jobs'    => (new JobModel())->orderBy('judul')->findAll(),
            'lamaran' => (new ApplicationModel())
                ->select('applications.id, applications.created_at, jobs.judul')
                ->join('jobs', 'jobs.id = applications.job_id')
                ->where('candidate_id', session('candidate_id'))
                ->findAll(),
        ]);
    }

    public function kirim()
    {
        $apps        = new ApplicationModel();
        $candidateId = (int) session('candidate_id');

        $rules = [
            'job_id' => 'required|is_natural_no_zero',
            'cv'     => [
                // ext_in CI4 memakai guessExtension() (magic bytes) - file HTML
                // menyamar .pdf sudah tertolak di sini; cek getMimeType() di bawah
                // jadi lapisan kedua
                'rules'  => 'uploaded[cv]|max_size[cv,2048]|ext_in[cv,pdf,docx]',
                'errors' => ['ext_in' => 'File harus PDF atau DOCX asli.', 'max_size' => 'Ukuran CV maksimal 2 MB.'],
            ],
        ];
        if (! $this->validate($rules)) {
            return redirect()->to('/lamar')->with('error', implode(' ', $this->validator->getErrors()));
        }

        // validasi tipe file dari konten asli (magic bytes via finfo), bukan ekstensi -
        // antisipasi file HTML menyamar .pdf (Blueprint A2.1)
        $cv = $this->request->getFile('cv');
        if (! in_array($cv->getMimeType(), self::MIME_SAH, true)) {
            return redirect()->to('/lamar')->with('error', 'Isi file bukan PDF/DOCX asli.');
        }

        $job = (new JobModel())->find($this->request->getPost('job_id'));
        if ($job === null) {
            return redirect()->to('/lamar')->with('error', 'Posisi tidak ditemukan.');
        }

        if ($apps->where('candidate_id', $candidateId)->countAllResults() >= ApplicationModel::MAX_LAMARAN) {
            return redirect()->to('/lamar')->with('error', 'Maksimal ' . ApplicationModel::MAX_LAMARAN . ' lamaran per kandidat.');
        }
        if ($apps->where(['candidate_id' => $candidateId, 'job_id' => $job['id']])->countAllResults() > 0) {
            return redirect()->to('/lamar')->with('error', 'Anda sudah melamar posisi ini.');
        }

        // nama file acak: cegah path traversal & tebak-tebakan nama (Blueprint Fase 2)
        $namaFile = $cv->getRandomName();
        $cv->move(WRITEPATH . 'uploads/cv', $namaFile);

        $appId = $apps->insert([
            'candidate_id' => $candidateId,
            'job_id'       => $job['id'],
            'cv_path'      => 'uploads/cv/' . $namaFile,
        ]);

        $kandidat = (new CandidateModel())->find($candidateId);
        (new StageLogger())->log((int) $appId, 'upload_cv', 'entered', 'system', null, [
            'to'     => $kandidat['email'],
            'nama'   => $kandidat['nama'],
            'posisi' => $job['judul'],
        ]);

        return redirect()->to('/lamar')->with('sukses', 'Lamaran terkirim! CV Anda sedang diproses - pantau email Anda.');
    }

    /** Portal status: timeline tiap lamaran dari candidate_stage_history. */
    public function status()
    {
        $apps = (new ApplicationModel())
            ->select('applications.id, applications.created_at, jobs.judul')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('candidate_id', session('candidate_id'))
            ->orderBy('applications.id')
            ->findAll();

        $history = new StageHistoryModel();
        foreach ($apps as &$app) {
            $app['riwayat'] = $history->where('application_id', $app['id'])->orderBy('id')->findAll();
            // assessment bisa dikerjakan bila gate_1 belum diputus utk lamaran ini
            $app['bisa_assessment'] = ! in_array('gate_1', array_column($app['riwayat'], 'stage'), true);
        }

        return view('lamaran/status', ['apps' => $apps]);
    }

    /**
     * Halaman pendaftaran jadwal interview - hanya lamaran yang lolos Gate 1.
     * Di sini kandidat mengajukan jadwal + melihat keterangan diterima/ditolak.
     */
    public function jadwalInterview()
    {
        $apps = (new ApplicationModel())
            ->select('applications.id, jobs.judul')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('candidate_id', session('candidate_id'))
            ->orderBy('applications.id')
            ->findAll();

        $history   = new StageHistoryModel();
        $interview = new InterviewModel();
        $lolos     = [];
        foreach ($apps as $app) {
            if ($history->latestStatus($app['id'], 'gate_1') === 'passed') {
                $app['interview'] = $interview->forApplication($app['id']);
                $lolos[]          = $app;
            }
        }

        return view('lamaran/jadwal', ['apps' => $lolos]);
    }

    /** Kandidat mengajukan jadwal interview (setelah lolos Gate 1). Recruiter yang meng-acc. */
    public function ajukanInterview(int $appId)
    {
        if ($this->lamaranMilikSendiri($appId) === null) {
            return redirect()->to('/jadwal')->with('error', 'Lamaran tidak ditemukan.');
        }
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_1') !== 'passed') {
            return redirect()->to('/jadwal')->with('error', 'Ajukan interview hanya setelah lolos Tahap 1.');
        }

        $interview = new InterviewModel();
        $iv        = $interview->forApplication($appId);
        if ($iv !== null && in_array($iv['status'], ['requested', 'approved'], true)) {
            return redirect()->to('/jadwal')->with('error', 'Sudah ada ajuan/jadwal interview untuk lamaran ini.');
        }

        $jadwal = (string) $this->request->getPost('jadwal');
        $dt     = DateTime::createFromFormat('Y-m-d\TH:i', $jadwal) ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $jadwal);
        if ($dt === false) {
            return redirect()->to('/jadwal')->with('error', 'Jadwal tidak valid.');
        }
        if ($dt <= new DateTime('+1 hour')) {
            return redirect()->to('/jadwal')->with('error', 'Pilih jadwal minimal 1 jam ke depan.');
        }

        $data = ['application_id' => $appId, 'status' => 'requested', 'scheduled_at' => $dt->format('Y-m-d H:i:s')];
        if ($iv !== null) {
            // sebelumnya rejected -> ajukan ulang: pakai baris sama, bersihkan meeting lama
            $interview->update($iv['id'], $data + ['meeting_id' => null, 'join_url' => null, 'start_url' => null]);
        } else {
            $interview->insert($data);
        }

        return redirect()->to('/jadwal')->with('sukses', 'Ajuan jadwal interview terkirim, menunggu persetujuan recruiter.');
    }

    /** Assessment placeholder: satu pertanyaan ya/tidak (arahan atasan - bukan tes asli). */
    public function assessment(int $appId)
    {
        $app = $this->lamaranMilikSendiri($appId);
        if ($app === null) {
            return redirect()->to('/status')->with('error', 'Lamaran tidak ditemukan.');
        }
        // sudah dinilai -> arahkan ke status, bukan tampilkan form lagi
        if ((new StageHistoryModel())->where(['application_id' => $appId, 'stage' => 'gate_1'])->countAllResults() > 0) {
            return redirect()->to('/status');
        }

        return view('lamaran/assessment', ['app' => $app]);
    }

    public function jawabAssessment(int $appId)
    {
        $app = $this->lamaranMilikSendiri($appId);
        if ($app === null) {
            return redirect()->to('/status')->with('error', 'Lamaran tidak ditemukan.');
        }

        $history = new StageHistoryModel();
        if ($history->where(['application_id' => $appId, 'stage' => 'gate_1'])->countAllResults() > 0) {
            return redirect()->to('/status')->with('error', 'Assessment lamaran ini sudah dikerjakan.');
        }

        $nilai    = $this->request->getPost('jawaban') === 'ya' ? 1.0 : 0.0;
        $logger   = new StageLogger();
        $kandidat = (new CandidateModel())->find(session('candidate_id'));
        $email    = ['to' => $kandidat['email'], 'nama' => $kandidat['nama'], 'posisi' => $app['judul']];

        // ponytail: skor CV dummy acak 0.30-0.90 sampai pipeline nyata minggu 5 -
        // acak (bukan konstan) supaya zona flagged muncul utk review recruiter Day 3
        $skorCv = mt_rand(30, 90) / 100;
        $logger->log($appId, 'ai_verification', 'entered');
        $logger->log($appId, 'ai_verification', 'passed', 'system', "skor_cv={$skorCv} (dummy)");
        $logger->log($appId, 'online_assessment', 'entered');
        $logger->log($appId, 'online_assessment', $nilai >= 1.0 ? 'passed' : 'failed', 'system', "nilai={$nilai}");

        // konfigurasi gate per posisi dari jobs.bobot_json/threshold_json (docs/gate-logic.md)
        $bobot     = json_decode((string) $app['bobot_json'], true) ?? [];
        $threshold = json_decode((string) $app['threshold_json'], true) ?? [];
        $gate      = GateOne::evaluate($skorCv, $nilai, [
            'weights'   => $bobot['gate1'] ?? [],
            'threshold' => $threshold['gate1'] ?? [],
        ]);

        $logger->log($appId, 'gate_1', $gate['decision'], 'system', "skor_gabungan={$gate['score']}", $email);

        return redirect()->to('/status')->with('sukses', 'Assessment terkirim - lihat status terbaru di bawah.');
    }

    /** @return array|null lamaran + judul + config gate, hanya milik kandidat yang login */
    private function lamaranMilikSendiri(int $appId): ?array
    {
        return (new ApplicationModel())
            ->select('applications.id, jobs.judul, jobs.bobot_json, jobs.threshold_json')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where(['applications.id' => $appId, 'candidate_id' => session('candidate_id')])
            ->first();
    }
}
