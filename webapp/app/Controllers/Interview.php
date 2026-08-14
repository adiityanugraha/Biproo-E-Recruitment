<?php

namespace App\Controllers;

use App\Libraries\GateTwo;
use App\Libraries\LembarPenilaian;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;

/**
 * Jalur internal untuk transkripsi wawancara (revisi 12 Agustus 2026).
 *
 *  - GET  internal/rekaman/{id}   ai-service mengunduh berkas rekamannya
 *  - POST interview/callback      ai-service mengembalikan transkrip + penilaian
 *
 * Dijaga token bersama yang sama dengan screening CV (header X-Token). Token
 * kosong = kedua endpoint menolak semuanya, supaya instalasi yang belum
 * dikonfigurasi tidak diam-diam menyerahkan rekaman wawancara ke siapa pun yang
 * menebak URL-nya.
 *
 * DI SINILAH GATE 2 DITUTUP. Penilaian AI selalu tiba PALING AKHIR - recruiter
 * sudah mengunggah rekaman dan menilai tiga kompetensi yang butuh mata dalam
 * satu tindakan sebelum ini - jadi begitu callback mendarat, lembarnya lengkap
 * dan keputusannya bisa dihitung tanpa menunggu siapa pun lagi.
 */
class Interview extends BaseController
{
    private const MODEL_VERSION = 'revisi-transkrip-v1';

    private function tokenSah(): bool
    {
        $rahasia = (string) config('AiService')->sharedToken;

        return $rahasia !== '' && hash_equals($rahasia, (string) $this->request->getHeaderLine('X-Token'));
    }

    /** ai-service mengunduh rekaman wawancara. */
    public function rekamanFile(int $transkripId)
    {
        if (! $this->tokenSah()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'token salah']);
        }

        $baris = (new InterviewTranskripModel())->find($transkripId);
        $path  = $baris === null ? '' : WRITEPATH . ($baris['berkas'] ?? '');
        if ($baris === null || ($baris['berkas'] ?? '') === '' || ! is_file($path)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'rekaman tidak ditemukan']);
        }

        return $this->response->download($path, null);
    }

    /** ai-service mengembalikan hasil transkripsi dan penilaiannya. */
    public function callback()
    {
        if (! $this->tokenSah()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'token salah']);
        }

        $b     = (array) $this->request->getJSON(true);
        $appId = (int) ($b['application_id'] ?? 0);
        $app   = $appId <= 0 ? null : $this->lamaran($appId);
        if ($app === null || ! in_array((string) ($b['status'] ?? ''), ['selesai', 'gagal'], true)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'payload tidak valid']);
        }

        // Baris yang ditulis ditentukan id yang IKUT DIBAWA pekerjaannya, bukan
        // ditebak dari yang terbaru. Satu lamaran bisa punya beberapa rekaman -
        // unggah ulang menambah baris - dan menebak lewat yang terbaru membuat
        // hasil rekaman lama mendarat di baris rekaman baru. Terlihat 14 Agustus
        // 2026 saat dua rekaman lamaran #72 dikirim ulang bersamaan.
        //
        // Tanpa id (pekerjaan dari versi lama yang masih di antrian) tetap jatuh
        // ke tebakan lama, supaya yang sedang berjalan tidak hilang begitu saja.
        $tModel = new InterviewTranskripModel();
        $id     = (int) ($b['transkrip_id'] ?? 0);
        $baris  = $id > 0 ? $tModel->find($id) : $tModel->terakhirUntuk($appId);

        if ($baris === null || (int) $baris['application_id'] !== $appId) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'tidak ada rekaman untuk lamaran ini']);
        }

        $gagal = (string) $b['status'] === 'gagal';
        $tModel->update($baris['id'], [
            'status'        => $gagal ? 'gagal' : 'selesai',
            // Transkrip disimpan WALAU penilaiannya gagal: ia hasil yang sudah
            // didapat, dan recruiter masih bisa membacanya lalu menilai sendiri.
            'teks'          => (string) ($b['teks'] ?? ''),
            'catatan'       => mb_substr((string) ($b['catatan'] ?? ''), 0, 500) ?: null,
            // Mesin transkripsinya datang dari ai-service, bukan ditulis di
            // sini: sejak 14 Agustus 2026 ada dua - Whisper lokal dan Gemini
            // sebagai cadangan - dan hanya ai-service yang tahu mana yang
            // akhirnya dipakai. Yang lokal tidak memberi penanda pembicara,
            // jadi bedanya terbaca di transkripnya dan harus bisa dilacak.
            'model_version' => mb_substr((string) ($b['mesin'] ?? '') ?: self::MODEL_VERSION, 0, 100),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // Callback yang datang dua kali tidak boleh menilai dua kali. Tabel
        // penilaian bersifat tambah-saja dan tidak punya jalur perbaikan, jadi
        // yang menjaga di sini keputusan Gate 2: sekali ada, berhenti.
        if ((new StageHistoryModel())->latestStatus($appId, 'gate_2') !== null) {
            return $this->response->setJSON(['ok' => true, 'catatan' => 'sudah diputuskan sebelumnya']);
        }

        if (! $gagal) {
            $this->simpanPenilaian($appId, (array) ($b['penilaian'] ?? []));
            $this->simpanNarasi($appId, [
                'strengths'  => (string) ($b['kekuatan'] ?? ''),
                'weaknesses' => (string) ($b['kelemahan'] ?? ''),
            ]);
        }

        $this->putuskan($appId, $app, $gagal, (string) ($b['catatan'] ?? ''));

        return $this->response->setJSON(['ok' => true]);
    }

    /**
     * Simpan nilai per kompetensi beserta alasannya.
     *
     * Kompetensi yang TIDAK bisa dinilai dari transkrip sengaja dilewati, bukan
     * disimpan bernilai nol: butir yang tak terisi tidak ikut dihitung
     * LembarPenilaian::skor(), sedangkan nol akan menyeret rata-ratanya turun
     * dan menggugurkan kandidat karena bahannya kurang, bukan karena jawabannya.
     *
     * @param list<array<string, mixed>> $penilaian
     */
    private function simpanPenilaian(int $appId, array $penilaian): void
    {
        $sah   = LembarPenilaian::dariTranskrip();
        $baris = [];
        foreach ($penilaian as $p) {
            $nama  = (string) ($p['kompetensi'] ?? '');
            $nilai = $p['nilai'] ?? null;
            if (! in_array($nama, $sah, true) || ! is_numeric($nilai)) {
                continue;
            }
            $n = (int) $nilai;
            if ($n < 1 || $n > LembarPenilaian::MAKS_SKALA) {
                continue;
            }
            $baris[] = [
                'application_id' => $appId,
                'kompetensi'     => $nama,
                'kategori'       => LembarPenilaian::KAT_HRD,
                'sumber'         => LembarPenilaian::DARI_AI,
                'bobot'          => 1,
                'tingkat'        => (string) $n,
                // Alasan ikut disimpan, dan itu bukan hiasan: tanpa kutipan dari
                // transkrip, penilaian otomatis cuma angka yang tidak bisa
                // dibantah siapa pun, termasuk kandidat yang bertanya kenapa
                // ia gugur.
                'catatan'        => mb_substr((string) ($p['alasan'] ?? ''), 0, LembarPenilaian::MAKS_CATATAN),
            ];
        }

        if ($baris === []) {
            return;
        }

        // Satu transaksi untuk seluruh lembar. Tanpa ini, kegagalan di tengah
        // meninggalkan penilaian separuh yang tidak akan pernah dibersihkan -
        // tabel ini tambah-saja. Sudah pernah terjadi 12 Agustus 2026.
        $db = db_connect();
        $db->transException(true)->transStart();
        $model = new InterviewPenilaianModel();
        foreach ($baris as $r) {
            $model->insert($r);
        }
        $db->transComplete();
    }

    /**
     * Simpan Candidate's Strengths dan Weaknesses hasil rangkuman AI.
     *
     * Bobot 0: narasi tidak pernah ikut dihitung jadi skor. Yang kosong tidak
     * disimpan - "tidak cukup bahan" adalah jawaban yang sah, dan baris kosong
     * di lembar profil hanya akan terbaca sebagai kolom yang gagal terisi.
     *
     * Kunci yang bukan milik AI diabaikan: Additional Notes dan Other Remarks
     * tetap punya recruiter, dan endpoint ini terbuka bagi siapa pun yang
     * memegang token bersama.
     *
     * @param array<string, string> $narasi
     */
    private function simpanNarasi(int $appId, array $narasi): void
    {
        $model = new InterviewPenilaianModel();
        foreach (LembarPenilaian::NARASI_AI as $kunci) {
            $teks = trim(preg_replace('/\s+/u', ' ', $narasi[$kunci] ?? ''));
            if ($teks === '') {
                continue;
            }
            $model->insert([
                'application_id' => $appId,
                'kompetensi'     => $kunci,
                'kategori'       => LembarPenilaian::KAT_NARASI,
                'sumber'         => LembarPenilaian::DARI_AI,
                'bobot'          => 0,
                'tingkat'        => '',
                'catatan'        => mb_substr($teks, 0, LembarPenilaian::MAKS_CATATAN),
            ]);
        }
    }

    /**
     * Tutup Gate 2 dari lembar yang sudah lengkap.
     *
     * Otomatis, tanpa recruiter menekan apa pun - itu yang diminta revisi 12
     * Agustus. Dua keadaan TIDAK diputus di sini dan diserahkan ke manusia:
     * transkripsi yang gagal, dan skor CV yang tidak tersedia. Keduanya berarti
     * datanya kurang, dan memutus dengan data yang kurang bukan otomatisasi
     * melainkan tebakan yang dikirim lewat email.
     *
     * @param array<string, mixed> $app
     */
    private function putuskan(int $appId, array $app, bool $gagal, string $catatan): void
    {
        $logger = new StageLogger();
        $actor  = 'system:transkrip';
        $email  = ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']];

        $penilaian     = (new InterviewPenilaianModel())->untukLamaran($appId);
        $skorInterview = LembarPenilaian::skor($penilaian);
        $skorCv        = $this->skorCv($appId);

        if ($gagal || $skorInterview === null) {
            $logger->log($appId, 'gate_2', 'flagged', $actor,
                'Transkripsi tidak menghasilkan penilaian'
                . ($catatan === '' ? '' : ': ' . mb_substr($catatan, 0, 200))
                . '. Keputusan diserahkan ke recruiter.');

            return;
        }

        $logger->log($appId, 'interview_online', 'passed', $actor,
            'Skor interview ' . $skorInterview . '/100 (dinilai dari transkrip)');

        if ($skorCv === null) {
            // Sama seperti jalur lama: tanpa skor CV, sistem tidak memutus.
            // Mengalihkan bobotnya ke interview diam-diam mengubah rumusnya,
            // sehingga kandidat yang CV-nya gagal terbaca dinilai dengan aturan
            // lain dari kandidat sebelahnya tanpa ada yang tahu.
            $logger->log($appId, 'gate_2', 'flagged', $actor,
                'Skor interview ' . $skorInterview . '/100. Skor CV tidak tersedia, '
                . 'keputusan diserahkan ke recruiter.');

            return;
        }

        $config = GateTwo::configFromJob($app['bobot_json'] ?? null, $app['threshold_json'] ?? null);
        $rec    = GateTwo::recommend($skorCv, $skorInterview / 100, $config);
        $lolos  = $rec['recommendation'] === 'hire';

        $lemah   = LembarPenilaian::terlemah($penilaian);
        $rincian = 'Skor interview ' . $skorInterview . '/100 (dari transkrip)'
            . ($lemah === [] ? '' : ', terlemah: ' . implode(', ', $lemah))
            . ', kemiripan CV ' . kemiripan_teks($skorCv)
            . '. Skor akhir ' . skor_100($rec['score']) . '/100';

        $logger->log($appId, 'gate_2', $lolos ? 'passed' : 'failed', $actor, $rincian, $email);
        if ($lolos) {
            $logger->log($appId, 'berkas_kontrak', 'entered', $actor);
        }
    }

    private function skorCv(int $appId): ?float
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);

        return $sr === null || $sr['score_overall'] === null ? null : (float) $sr['score_overall'];
    }

    /** @return array<string, mixed>|null */
    private function lamaran(int $appId): ?array
    {
        return (new ApplicationModel())
            ->select('applications.id, candidates.nama, candidates.email, jobs.judul, jobs.bobot_json, jobs.threshold_json')
            ->join('candidates', 'candidates.id = applications.candidate_id')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('applications.id', $appId)
            ->first();
    }
}
