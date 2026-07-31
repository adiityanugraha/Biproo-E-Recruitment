<?php

namespace App\Controllers;

use App\Libraries\AiServiceException;
use App\Libraries\GateOne;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
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

        // Kirim job screening ke ai-service (kontrak A3.1; wiring Fase 4 Day 1).
        // Tahan-gagal: ai-service mati TIDAK boleh menggagalkan lamaran - job
        // hilang tercatat di log dan bisa dipicu ulang nanti.
        try {
            service('aiService')->post('/screening', [
                'job_id_internal' => (int) $appId,
                'cv_file_url'     => site_url("internal/cv/{$appId}"),
                'job_requirement' => [
                    'skill'      => (string) $job['req_skill'],
                    'pendidikan' => (string) $job['req_pendidikan'],
                    'pengalaman' => (string) $job['req_pengalaman'],
                    'deskripsi'  => (string) ($job['deskripsi'] ?? ''),
                ],
                'callback_url'   => site_url('screening/callback'),
                'callback_token' => config('AiService')->sharedToken,
            ]);
        } catch (AiServiceException $e) {
            log_message('warning', 'screening tidak terkirim utk lamaran {id}: {m}', ['id' => $appId, 'm' => $e->getMessage()]);
        }

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
                $iv                = $interview->forApplication($app['id']);
                $app['interview']  = $iv;
                $app['link_aktif'] = $iv !== null && $iv['status'] === 'approved'
                    && InterviewModel::linkAktif($iv['scheduled_at']);
                $lolos[] = $app;
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

    /**
     * Gerbang link Zoom kandidat: cek jendela waktu lalu redirect ke Zoom.
     * Email undangan/pengingat dan portal memuat URL ini, bukan join_url mentah,
     * supaya link yang diteruskan ke orang lain tidak berguna di luar jendela
     * interview. Batas jendela ada di InterviewModel::linkAktif().
     */
    public function masukInterview(int $appId)
    {
        $app = $this->lamaranMilikSendiri($appId);
        if ($app === null) {
            return redirect()->to('/jadwal')->with('error', 'Lamaran tidak ditemukan.');
        }

        $iv = (new InterviewModel())->forApplication($appId);
        if ($iv === null || $iv['status'] !== 'approved' || empty($iv['join_url'])) {
            return redirect()->to('/jadwal')->with('error', 'Belum ada jadwal interview yang disetujui untuk lamaran ini.');
        }

        if (! InterviewModel::linkAktif($iv['scheduled_at'])) {
            return view('lamaran/link_kedaluwarsa', [
                'judul'     => $app['judul'],
                'jadwal'    => $iv['scheduled_at'],
                'belum'     => new DateTime() < new DateTime($iv['scheduled_at']),
                'bukaMenit' => InterviewModel::BUKA_MENIT,
            ]);
        }

        return redirect()->to($iv['join_url']);
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

        // Skor CV dari hasil screening ai-service (Fase 4 Day 3). Fallback dummy
        // hanya bila callback belum mendarat / belum bisa dinilai, supaya alur
        // demo tidak terhenti. Keduanya tercatat di screening_results (A7)
        // sehingga pembaca skor tetap satu jalur.
        [$skorCv, $noteSkorCv] = $this->skorCv($appId);

        // Callback screening biasanya sudah mencatat tahap ini (Screening::callback).
        // Hanya dicatat di sini bila belum ada, supaya riwayat tidak dobel.
        if ($history->latestStatus($appId, 'ai_verification') === null) {
            $logger->log($appId, 'ai_verification', 'entered');
            $logger->log($appId, 'ai_verification', 'passed', 'system', $noteSkorCv);
        }
        $lulus = $nilai >= 1.0;
        $logger->log($appId, 'online_assessment', 'entered');
        $logger->log($appId, 'online_assessment', $lulus ? 'passed' : 'failed', 'system',
            'Hasil assessment: ' . ($lulus ? 'lulus' : 'tidak lulus'));

        // Gate 1 diputus MURNI oleh assessment. Skor CV tidak ikut - ia sudah
        // tersimpan di screening_results dan baru dipakai di Gate 2 bersama skor
        // interview (lihat GateOne dan docs/kalibrasi-gate.md).
        $logger->log($appId, 'gate_1', GateOne::dariAssessment($lulus), 'system',
            'Keputusan dari hasil assessment' . ($skorCv === null ? '' : '. Skor CV ' . skor_100($skorCv) . '/100 dipakai di Tahap 2'),
            $email);

        return redirect()->to('/status')->with('sukses', 'Assessment terkirim - lihat status terbaru di bawah.');
    }

    /**
     * Skor kecocokan CV dari hasil screening ai-service.
     *
     * Tidak ada fallback dummy lagi: skor CV kini hanya dipakai di Gate 2, jadi
     * belum tersedianya skor TIDAK memblokir alur Gate 1. Mengarang angka acak
     * untuk mengisi kekosongan justru berbahaya - itu masuk ke keputusan akhir.
     *
     * @return array{0: float|null, 1: string} [skor 0..1 atau null, catatan riwayat]
     */
    private function skorCv(int $appId): array
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);
        if ($sr !== null && $sr['score_overall'] !== null) {
            $skor = (float) $sr['score_overall'];

            return [$skor, 'Skor kecocokan CV ' . skor_100($skor) . '/100 (' . $sr['model_version'] . ')'];
        }

        return [null, 'Screening CV belum menghasilkan skor - akan ditinjau recruiter di Tahap 2'];
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
