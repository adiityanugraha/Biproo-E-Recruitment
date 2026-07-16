<?php

namespace App\Controllers;

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;

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

    /** Dashboard: KPI ringkas + daftar lowongan + jumlah pelamar/menunggu review. */
    public function index()
    {
        $jobs    = (new JobModel())->orderBy('judul')->findAll();
        $apps    = new ApplicationModel();
        $history = new StageHistoryModel();

        $totalFlagged = 0;
        foreach ($jobs as &$job) {
            $job['jumlah_pelamar'] = $apps->where('job_id', $job['id'])->countAllResults();
            $job['jumlah_flagged'] = 0;
            foreach ($apps->where('job_id', $job['id'])->findAll() as $app) {
                if ($this->statusGate1($app['id']) === 'flagged') {
                    $job['jumlah_flagged']++;
                    $totalFlagged++;
                }
            }
        }
        unset($job);

        $totalPelamar = $apps->countAllResults();
        // distinct application dengan gate_1 lolos
        $lolos = $history->where(['stage' => 'gate_1', 'status' => 'passed'])
            ->select('application_id')->distinct()->countAllResults();

        return view('recruiter/dashboard', [
            'jobs' => $jobs,
            'kpi'  => [
                ['v' => count($jobs), 't' => 'Total Lowongan'],
                ['v' => $totalPelamar, 't' => 'Total Pelamar'],
                ['v' => $totalFlagged, 't' => 'Menunggu Review'],
                ['v' => $lolos, 't' => 'Lolos Gate 1'],
                ['v' => ($totalPelamar > 0 ? round($lolos / $totalPelamar * 100) : 0) . '%', 't' => 'Fulfill Rate'],
            ],
        ]);
    }

    /** Manajemen lowongan/FPK: form tambah + daftar. */
    public function lowongan()
    {
        if ($this->request->is('post')) {
            $rules = [
                'judul'          => 'required|min_length[3]|max_length[160]',
                'req_skill'      => 'required',
                'req_pendidikan' => 'required|max_length[160]',
                'req_pengalaman' => 'required|max_length[160]',
            ];
            if (! $this->validate($rules)) {
                return view('recruiter/lowongan', [
                    'jobs'   => (new JobModel())->orderBy('judul')->findAll(),
                    'errors' => $this->validator->getErrors(),
                ]);
            }

            (new JobModel())->insert($this->request->getPost(['judul', 'req_skill', 'req_pendidikan', 'req_pengalaman', 'deskripsi']));

            return redirect()->to('/recruiter/lowongan')->with('sukses', 'Lowongan dibuat.');
        }

        return view('recruiter/lowongan', ['jobs' => (new JobModel())->orderBy('judul')->findAll()]);
    }

    /** Daftar kandidat satu lowongan: stage terkini + skor + flag. */
    public function kandidat(int $jobId)
    {
        $job = (new JobModel())->find($jobId);
        if ($job === null) {
            return redirect()->to('/recruiter')->with('error', 'Lowongan tidak ditemukan.');
        }

        // ponytail: N+1 query riwayat per pelamar - cukup utk volume KP;
        // ganti window function ROW_NUMBER() bila pelamar ribuan
        $daftar  = (new ApplicationModel())
            ->select('applications.id, candidates.nama, candidates.email, applications.created_at')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->where('job_id', $jobId)
            ->orderBy('applications.id')
            ->findAll();
        $history = new StageHistoryModel();

        foreach ($daftar as &$app) {
            $terakhir            = $history->where('application_id', $app['id'])->orderBy('id', 'DESC')->first();
            $app['stage_akhir']  = $terakhir['stage'] ?? '-';
            $app['status_akhir'] = $terakhir['status'] ?? '-';
            $gate1               = $history->where(['application_id' => $app['id'], 'stage' => 'gate_1'])->orderBy('id', 'DESC')->first();
            $app['gate1']        = $gate1['status'] ?? null;
            $app['skor']         = $gate1['note'] ?? '';
        }

        return view('recruiter/kandidat', ['job' => $job, 'daftar' => $daftar]);
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
                'review manual zona abu-abu', ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']]);

            return redirect()->to('/recruiter/kandidat/' . $app['job_id'])
                ->with('sukses', 'Keputusan tersimpan: kandidat ' . ($keputusan === 'passed' ? 'diloloskan' : 'tidak diloloskan') . '.');
        }

        return view('recruiter/review', [
            'app'     => $app,
            'riwayat' => (new StageHistoryModel())->where('application_id', $appId)->orderBy('id')->findAll(),
            'flagged' => $this->statusGate1($appId) === 'flagged',
        ]);
    }

    /** Status gate_1 terkini sebuah lamaran (baris terakhir yang berlaku). */
    private function statusGate1(int $appId): ?string
    {
        $row = (new StageHistoryModel())
            ->where(['application_id' => $appId, 'stage' => 'gate_1'])
            ->orderBy('id', 'DESC')->first();

        return $row['status'] ?? null;
    }

    private function lamaranDetail(int $appId): ?array
    {
        return (new ApplicationModel())
            ->select('applications.id, applications.job_id, candidates.nama, candidates.email, jobs.judul')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('applications.id', $appId)
            ->first();
    }
}
