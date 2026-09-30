<?php

namespace App\Controllers;

use App\Libraries\AiServiceException;
use App\Libraries\PemanduWawancara;
use App\Libraries\PenilaianRubrik;
use App\Models\ApplicationModel;
use App\Models\InterviewDialogModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use Config\Wawancara as WawancaraConfig;

/**
 * Ruang wawancara suara AI, sisi kandidat (arahan 12 Agustus 2026).
 *
 * Pendamping sesi Zoom, bukan penggantinya. Kandidat membuka halaman ini di tab
 * sebelah jendela Zoom-nya: AI membacakan pertanyaan lewat pengeras suara (jadi
 * recruiter ikut mendengar di Zoom), mendengarkan lewat mikrofon yang sama, lalu
 * menyusun pertanyaan lanjutan dari isi jawaban barusan. Recruiter memantau
 * transkrip dan skor sementaranya di Recruiter::konsolWawancara.
 *
 * PEMBAGIAN TUGAS DENGAN BROWSER. Pengenalan suara dan penyaringan derau
 * seluruhnya di browser (public/js/wawancara.js) - gratis, tanpa kuota, dan
 * tanpa satu byte audio pun meninggalkan komputer kandidat. Yang sampai ke
 * server hanya teks hasil transkrip. Server memegang yang tidak boleh dipegang
 * browser: butir mana yang ditanyakan, penilaiannya, dan anggaran panggilan LLM.
 *
 * YANG TIDAK PERNAH DIKIRIM KE KANDIDAT: tingkat dan alasan penilaian. Jawaban
 * JSON ke browser kandidat sengaja hanya memuat pertanyaan berikutnya. Kandidat
 * yang membuka DevTools tidak boleh membaca "kurang" atas jawabannya sendiri di
 * tengah wawancara - itu mengubah sisa wawancaranya, dan itu penilaian yang
 * belum ditinjau recruiter.
 */
class Wawancara extends BaseController
{
    /** Ruang wawancara. Jendelanya sama persis dengan jendela link Zoom. */
    public function ruang(int $appId)
    {
        $app = $this->lamaranMilikSendiri($appId);
        if ($app === null) {
            return redirect()->to('/jadwal')->with('error', 'Lamaran tidak ditemukan.');
        }

        $iv = (new InterviewModel())->forApplication($appId);
        if (! InterviewModel::siapDimasuki($iv)) {
            return redirect()->to('/jadwal')->with('error',
                'Ruang wawancara AI hanya terbuka selama jendela sesi interview Anda.');
        }

        $dialog = (new InterviewDialogModel())->untukLamaran($appId);

        return view('lamaran/ruang_wawancara', [
            'judul'    => 'Ruang Wawancara AI',
            'app'      => $app,
            'appId'    => $appId,
            'selesai'  => PemanduWawancara::selesai($dialog),
            'sapaan'   => PemanduWawancara::SAPAAN,
            'terjawab' => count(array_filter($dialog, static fn (array $r): bool => $r['jawaban'] !== null)),
        ]);
    }

    /**
     * Mulai atau lanjutkan sesi: kembalikan pertanyaan yang harus diucapkan AI.
     *
     * Idempoten dengan sengaja. Kandidat yang koneksinya putus lalu memuat ulang
     * halaman mendapat kembali pertanyaan yang sedang menggantung, bukan
     * pertanyaan baru dan bukan wawancara yang dimulai dari nol.
     */
    public function mulai(int $appId)
    {
        $siap = $this->siapkan($appId);
        if (! is_array($siap)) {
            return $siap;
        }
        [$app, $rubrik, $model] = $siap;

        $dialog = $model->untukLamaran($appId);
        if (PemanduWawancara::selesai($dialog)) {
            return $this->balas(['selesai' => true, 'pertanyaan' => PemanduWawancara::PENUTUP]);
        }

        // Pertanyaan yang sudah diajukan tapi belum dijawab: ulangi apa adanya.
        $gantung = $this->gantung($dialog);
        if ($gantung !== null) {
            return $this->balas($this->kartu($gantung, $rubrik, $dialog));
        }

        $giliran = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($rubrik, $this->cfg()->maksButir),
            $dialog,
            null,
            $this->cfg()->maksLanjutan,
            $this->cfg()->maksGiliran,
        );

        return $this->balas($this->kartu($this->simpanPertanyaan($appId, $dialog, $giliran), $rubrik, $dialog));
    }

    /**
     * Kandidat selesai menjawab satu pertanyaan.
     *
     * Urutannya penting: transkrip disimpan LEBIH DULU, baru LLM dipanggil.
     * Kalau LLM mati atau kuotanya habis di tengah wawancara, yang hilang cuma
     * penilaian otomatisnya - jawaban kandidat tetap tercatat lengkap dan
     * recruiter tetap bisa menilainya sendiri dari transkrip.
     */
    public function jawab(int $appId)
    {
        $siap = $this->siapkan($appId);
        if (! is_array($siap)) {
            return $siap;
        }
        [$app, $rubrik, $model] = $siap;

        $dialog  = $model->untukLamaran($appId);
        $gantung = $this->gantung($dialog);
        if ($gantung === null) {
            return $this->balas(['error' => 'Tidak ada pertanyaan yang sedang menunggu jawaban.'], 409);
        }

        $teks = trim((string) $this->request->getPost('teks'));
        $model->update($gantung['id'], [
            'jawaban'   => mb_substr($teks, 0, $this->cfg()->maksJawaban),
            'keyakinan' => $this->angka('keyakinan', 0, 1),
            'derau'     => $this->angka('derau', 0, 1),
            'durasi_ms' => (int) $this->request->getPost('durasi_ms'),
        ]);

        $balasan = $this->nilai($app, $rubrik, $gantung, $dialog, $teks);
        if ($balasan['tingkat'] !== null || $balasan['alasan'] !== '') {
            $model->update($gantung['id'], [
                'tingkat' => $balasan['tingkat'],
                'alasan'  => $balasan['alasan'],
            ]);
        }

        // Baca ulang: baris yang barusan diperbarui harus ikut menentukan
        // giliran berikutnya (butir mana yang sudah terjawab, sisa anggaran).
        $dialog  = $model->untukLamaran($appId);
        $giliran = PemanduWawancara::berikutnya(
            PemanduWawancara::rencana($rubrik, $this->cfg()->maksButir),
            $dialog,
            $balasan,
            $this->cfg()->maksLanjutan,
            $this->cfg()->maksGiliran,
        );

        return $this->balas($this->kartu($this->simpanPertanyaan($appId, $dialog, $giliran), $rubrik, $dialog));
    }

    /**
     * Minta penilaian + pertanyaan lanjutan ke ai-service.
     *
     * Kegagalan apa pun TIDAK menghentikan wawancara. Yang dikembalikan adalah
     * balasan kosong yang menyuruh pemandu lanjut ke butir berikutnya - lebih
     * baik wawancara tanpa pendalaman daripada kandidat yang ditinggal terdiam
     * di depan mikrofon karena kuota harian habis.
     *
     * @return array{tingkat:?string, alasan:string, lanjutan:string, nyambung:bool}
     */
    private function nilai(array $app, array $rubrik, array $gantung, array $dialog, string $teks): array
    {
        $butir = $gantung['butir_index'] === null ? [] : ($rubrik[(int) $gantung['butir_index']] ?? []);

        try {
            $r = service('aiService')->post('/wawancara/giliran', [
                'posisi'     => (string) $app['judul'],
                'pertanyaan' => (string) $gantung['pertanyaan'],
                'jawaban'    => $teks,
                'butir'      => [
                    'kompetensi' => is_array($butir) ? (string) ($butir['kompetensi'] ?? '') : '',
                    'indikator'  => is_array($butir) ? ($butir['indikator'] ?? '') : '',
                    'red_flag'   => is_array($butir) ? ($butir['red_flag'] ?? '') : '',
                ],
                'riwayat'        => $this->riwayat($dialog),
                'minta_lanjutan' => true,
            ]);
        } catch (AiServiceException $e) {
            log_message('error', 'wawancara ai-service gagal: {m}', ['m' => $e->getMessage()]);

            return ['tingkat' => null, 'alasan' => 'Penilaian otomatis tidak tersedia (layanan AI tidak menjawab).',
                'lanjutan' => '', 'nyambung' => true];
        }

        $tingkat = (string) ($r['tingkat'] ?? '');

        return [
            'tingkat'  => isset(PenilaianRubrik::TINGKAT[$tingkat]) ? $tingkat : null,
            'alasan'   => mb_substr((string) ($r['alasan'] ?? ''), 0, 500),
            'lanjutan' => (string) ($r['lanjutan'] ?? ''),
            'nyambung' => ($r['nyambung'] ?? true) !== false,
        ];
    }

    /**
     * Beberapa giliran terakhir sebagai konteks LLM.
     *
     * Dibatasi, bukan seluruh wawancara: prompt yang tumbuh tiap giliran
     * membuat jawaban makin lambat justru saat kandidat paling gugup menunggu.
     *
     * @return list<array{pertanyaan:string, jawaban:string}>
     */
    private function riwayat(array $dialog): array
    {
        $isi = array_values(array_filter(
            $dialog,
            static fn (array $r): bool => $r['sumber'] !== 'penutup' && trim((string) $r['jawaban']) !== '',
        ));

        return array_map(
            static fn (array $r): array => [
                'pertanyaan' => (string) $r['pertanyaan'],
                'jawaban'    => (string) $r['jawaban'],
            ],
            array_slice($isi, -$this->cfg()->riwayatKonteks),
        );
    }

    /** Catat pertanyaan yang akan diucapkan AI, lalu kembalikan barisnya. */
    private function simpanPertanyaan(int $appId, array $dialog, array $giliran): array
    {
        $model  = new InterviewDialogModel();
        $urutan = $dialog === [] ? 1 : ((int) $dialog[count($dialog) - 1]['urutan'] + 1);

        $baris = [
            'application_id' => $appId,
            'urutan'         => $urutan,
            'sumber'         => $giliran['sumber'],
            'butir_index'    => $giliran['butir_index'],
            'kompetensi'     => $giliran['kompetensi'],
            'bobot'          => $giliran['bobot'],
            'pertanyaan'     => mb_substr($giliran['pertanyaan'], 0, 500),
        ];
        $id = $model->insert($baris);

        return $baris + ['id' => $id, 'jawaban' => null];
    }

    /**
     * Bentuk jawaban JSON untuk browser kandidat.
     *
     * Nomor dan total sengaja ditampilkan: wawancara suara tanpa penanda kemajuan
     * terasa tidak berujung, dan kandidat yang tidak tahu tinggal berapa lagi
     * cenderung memangkas jawabannya.
     */
    private function kartu(array $baris, array $rubrik, array $dialog): array
    {
        $selesai = $baris['sumber'] === 'penutup';
        $total   = count(PemanduWawancara::rencana($rubrik, $this->cfg()->maksButir));
        $nomor   = count(array_filter($dialog, static fn (array $r): bool => $r['sumber'] === 'bank'))
            + ($baris['sumber'] === 'bank' ? 1 : 0);

        return [
            'selesai'    => $selesai,
            'pertanyaan' => (string) $baris['pertanyaan'],
            // 'lanjutan'/'ulangi' ditandai supaya antarmuka bisa menerangkan
            // kenapa pertanyaannya terdengar seperti kelanjutan, bukan soal baru
            'sumber'     => (string) $baris['sumber'],
            'nomor'      => min($nomor, $total),
            'total'      => $total,
        ];
    }

    /** Pertanyaan yang sudah diajukan tapi belum ada jawabannya. */
    private function gantung(array $dialog): ?array
    {
        foreach (array_reverse($dialog) as $r) {
            if ($r['sumber'] !== 'penutup' && $r['jawaban'] === null) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Penjagaan yang sama untuk /mulai dan /jawab: lamaran milik sendiri,
     * jendela sesi terbuka, dan lowongan punya pertanyaan.
     *
     * @return array{0: array, 1: array, 2: InterviewDialogModel}|ResponseInterface
     */
    private function siapkan(int $appId)
    {
        $app = $this->lamaranMilikSendiri($appId);
        if ($app === null) {
            return $this->balas(['error' => 'Lamaran tidak ditemukan.'], 404);
        }

        if (! InterviewModel::siapDimasuki((new InterviewModel())->forApplication($appId))) {
            return $this->balas(['error' => 'Sesi wawancara Anda sedang tidak terbuka.'], 403);
        }

        $job    = (new JobModel())->find($app['job_id']);
        $rubrik = json_decode((string) ($job['pertanyaan_json'] ?? ''), true);
        if (! is_array($rubrik) || $rubrik === []) {
            return $this->balas(['error' => 'Belum ada pertanyaan interview untuk posisi ini. '
                . 'Silakan lanjutkan wawancara bersama recruiter di Zoom.'], 409);
        }

        return [$app, $rubrik, new InterviewDialogModel()];
    }

    /** Angka pecahan dari form, dijepit ke rentang; null bila tidak dikirim. */
    private function angka(string $nama, float $min, float $maks): ?float
    {
        $v = $this->request->getPost($nama);

        return $v === null || $v === '' ? null : max($min, min($maks, (float) $v));
    }

    private function cfg(): WawancaraConfig
    {
        return config('Wawancara');
    }

    /** JSON + token CSRF terbaru, pola yang sama dengan Chat::reply(). */
    private function balas(array $data, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON($data + ['csrf' => csrf_hash()]);
    }

    private function lamaranMilikSendiri(int $appId): ?array
    {
        return (new ApplicationModel())
            ->select('applications.id, applications.job_id, jobs.judul')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where(['applications.id' => $appId, 'candidate_id' => session('candidate_id')])
            ->first();
    }
}
