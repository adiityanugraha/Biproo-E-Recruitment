<?php

namespace App\Controllers;

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;

class Lamaran extends BaseController
{
    private const MIME_SAH = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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
}
