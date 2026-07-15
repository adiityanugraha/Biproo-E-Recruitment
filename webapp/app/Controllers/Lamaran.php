<?php

namespace App\Controllers;

use App\Libraries\GateOne;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;

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

    /** Assessment placeholder: satu pertanyaan ya/tidak (arahan atasan - bukan tes asli). */
    public function assessment(int $appId)
    {
        $app = $this->lamaranMilikSendiri($appId);
        if ($app === null) {
            return redirect()->to('/status')->with('error', 'Lamaran tidak ditemukan.');
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
