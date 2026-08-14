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

        // Yang menahan di sini KEPUTUSAN, bukan sekadar adanya baris gate_2.
        //
        // 'flagged' bukan keputusan - ia justru penanda bahwa datanya kurang
        // dan pekerjaannya perlu diulang. Penjaga lamanya menahan apa pun yang
        // bukan null, sehingga `transkrip:resend` atas lamaran yang tersangkut
        // - satu-satunya jalan kembali ke penilaian otomatis - dijawab "sudah
        // diputuskan sebelumnya" dan hasilnya dibuang diam-diam. Kesalahan yang
        // sama dengan tiga penjaga di Recruiter, dan yang ini terlewat.
        //
        // Kiriman kedua sekarang aman: InterviewPenilaianModel::ganti()
        // mengganti lembarnya, bukan menumpuk set nilai baru di atas yang lama.
        if (in_array((new StageHistoryModel())->latestStatus($appId, 'gate_2'), ['passed', 'failed'], true)) {
            return $this->response->setJSON(['ok' => true, 'catatan' => 'sudah diputuskan sebelumnya']);
        }

        if (! $gagal) {
            // Satu panggilan untuk nilai DAN narasi. Keduanya bersumber 'ai',
            // dan dua panggilan berturut membuat yang kedua menghapus hasil
            // yang pertama.
            (new InterviewPenilaianModel())->ganti($appId, LembarPenilaian::DARI_AI, array_merge(
                $this->barisPenilaian((array) ($b['penilaian'] ?? [])),
                $this->barisNarasi([
                    'strengths'  => (string) ($b['kekuatan'] ?? ''),
                    'weaknesses' => (string) ($b['kelemahan'] ?? ''),
                ]),
            ));
        }

        $this->putuskan($appId, $app, $gagal, (string) ($b['catatan'] ?? ''),
            trim((string) ($b['teks'] ?? '')) !== '',
            $this->rekomendasiAi($b));

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
     * @param  list<array<string, mixed>>  $penilaian
     * @return list<array<string, mixed>>
     */
    private function barisPenilaian(array $penilaian): array
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
                'kompetensi' => $nama,
                'kategori'   => LembarPenilaian::KAT_HRD,
                'bobot'      => 1,
                'tingkat'    => (string) $n,
                // Alasan ikut disimpan, dan itu bukan hiasan: tanpa kutipan dari
                // transkrip, penilaian otomatis cuma angka yang tidak bisa
                // dibantah siapa pun, termasuk kandidat yang bertanya kenapa
                // ia gugur.
                'catatan'    => mb_substr((string) ($p['alasan'] ?? ''), 0, LembarPenilaian::MAKS_CATATAN),
            ];
        }

        return $baris;
    }

    /**
     * Candidate's Strengths dan Weaknesses hasil rangkuman AI.
     *
     * Bobot 0: narasi tidak pernah ikut dihitung jadi skor. Yang kosong tidak
     * disimpan - "tidak cukup bahan" adalah jawaban yang sah, dan baris kosong
     * di lembar profil hanya akan terbaca sebagai kolom yang gagal terisi.
     *
     * Kunci yang bukan milik AI diabaikan: Additional Notes dan Other Remarks
     * tetap punya recruiter, dan endpoint ini terbuka bagi siapa pun yang
     * memegang token bersama.
     *
     * @param  array<string, string>       $narasi
     * @return list<array<string, mixed>>
     */
    private function barisNarasi(array $narasi): array
    {
        $baris = [];
        foreach (LembarPenilaian::NARASI_AI as $kunci) {
            $teks = trim(preg_replace('/\s+/u', ' ', $narasi[$kunci] ?? ''));
            if ($teks === '') {
                continue;
            }
            $baris[] = [
                'kompetensi' => $kunci,
                'kategori'   => LembarPenilaian::KAT_NARASI,
                'bobot'      => 0,
                'tingkat'    => '',
                'catatan'    => mb_substr($teks, 0, LembarPenilaian::MAKS_CATATAN),
            ];
        }

        return $baris;
    }

    /**
     * Rekomendasi dari AI, atau null bila ia tidak menjawab dengan salah satu
     * dari dua nilai yang diakui.
     *
     * @param  array<string, mixed> $b badan callback
     * @return array{0: ?bool, 1: string} [lolos, alasan]
     */
    private function rekomendasiAi(array $b): array
    {
        $lolos = match ((string) ($b['rekomendasi'] ?? '')) {
            'recommended'     => true,
            'not_recommended' => false,
            default           => null,
        };

        // Titik penutup dibuang: kalimatnya disambung dengan '. [pembanding]',
        // dan model hampir selalu mengakhirinya dengan titik sendiri.
        $alasan = rtrim(trim((string) ($b['alasan_rekomendasi'] ?? '')), '.');

        return [$lolos, mb_substr($alasan, 0, 400)];
    }

    /**
     * Tutup Gate 2 dari lembar yang sudah lengkap.
     *
     * YANG MEMUTUSKAN SEKARANG AI, BUKAN RUMUS (permintaan atasan, 14 Agustus
     * 2026). Sebelumnya keputusannya aritmetika murni: 0,4 x skor CV + 0,6 x
     * skor interview dibandingkan dengan ambang 0,7. Sekarang model yang menilai
     * transkrip juga yang menyatakan recommended atau tidak, dengan skor CV ikut
     * dikirim kepadanya sebagai bahan.
     *
     * RUMUSNYA TETAP DIHITUNG DAN DICATAT. Ia tidak lagi memutuskan apa pun,
     * tapi angkanya satu-satunya yang bisa diperiksa ulang orang lain: model
     * bahasa tidak menjawab sama persis dua kali pada transkrip yang sama,
     * sedangkan aritmetika iya. Tanpa baris pembanding ini, satu-satunya
     * keterangan yang tersisa saat kandidat bertanya kenapa ia gugur adalah
     * kalimat yang ditulis model itu sendiri.
     *
     * TIGA keadaan TIDAK diputus di sini dan diserahkan ke manusia: transkripsi
     * yang gagal, skor CV yang tidak tersedia, dan AI yang tidak memutuskan.
     * Ketiganya berarti bahannya kurang, dan memutus dengan bahan yang kurang
     * bukan otomatisasi melainkan tebakan yang dikirim lewat email.
     *
     * @param array<string, mixed>   $app
     * @param array{0: ?bool, 1: string} $rekomendasi [lolos, alasan] dari AI
     */
    private function putuskan(int $appId, array $app, bool $gagal, string $catatan,
        bool $adaTranskrip = false, array $rekomendasi = [null, '']): void
    {
        $logger = new StageLogger();
        $actor  = 'system:transkrip';
        $email  = ['to' => $app['email'], 'nama' => $app['nama'], 'posisi' => $app['judul']];

        $penilaian     = (new InterviewPenilaianModel())->untukLamaran($appId);
        $skorInterview = LembarPenilaian::skor($penilaian);
        $skorCv        = $this->skorCv($appId);

        if ($gagal || $skorInterview === null) {
            // Transkripsi dan penilaian dua langkah terpisah, dan sejak
            // transkripsinya jalan lokal (14 Agustus 2026) keduanya kerap
            // berbeda nasib - transkrip jadi, penilaiannya kena kuota. Sebab
            // yang dicatat di sini yang dibaca recruiter besok, jadi menyebut
            // "transkripsi gagal" untuk transkrip yang lengkap membuatnya
            // mencari-cari masalah yang tidak ada.
            $logger->log($appId, 'gate_2', 'flagged', $actor,
                ($adaTranskrip
                    ? 'Transkrip berhasil dibuat, penilaiannya yang gagal'
                    : 'Transkripsi tidak menghasilkan penilaian')
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

        $lemah   = LembarPenilaian::terlemah($penilaian);
        $rincian = 'Skor interview ' . $skorInterview . '/100 (dari transkrip)'
            . ($lemah === [] ? '' : ', terlemah: ' . implode(', ', $lemah))
            . ', kemiripan CV ' . kemiripan_teks($skorCv)
            // Satu angka di belakang koma, dan itu bukan kerapian. Ambangnya
            // 70/100, dan pembulatan ke bilangan bulat membuat skor 0,6988
            // tercatat "70/100" pada kandidat yang justru DITOLAK karenanya -
            // baris yang tidak bisa dijelaskan kepada siapa pun, termasuk
            // kandidat yang bertanya kenapa ia gugur di angka yang sama dengan
            // ambangnya. Terlihat pada uji e2e 14 Agustus 2026.
            . '. Skor akhir rumus ' . skor_100($rec['score'], 1) . '/100';

        [$lolos, $alasanAi] = $rekomendasi;

        if ($lolos === null) {
            // AI tidak memutuskan - aturan 13 di SYSTEM_NILAI memang membolehkan
            // itu saat transkripnya terlalu tipis. Rumusnya TIDAK dipakai
            // menggantikan: sejak keputusannya dipindahkan ke AI, memutus lewat
            // rumus hanya untuk kandidat yang bahannya paling sedikit berarti
            // sebagian orang dinilai dengan aturan yang berbeda dari sebelahnya,
            // tanpa ada yang tahu siapa.
            $logger->log($appId, 'gate_2', 'flagged', $actor,
                $rincian . '. AI tidak memberi rekomendasi, keputusan diserahkan ke recruiter.');

            return;
        }

        // Kalimat AI ditulis DULUAN: inilah sebab yang sebenarnya, dan angka
        // rumus di belakangnya cuma pembanding. Menaruhnya terbalik membuat
        // pembaca mengira angka itu yang menggugurkan.
        $rumus = $rec['recommendation'] === 'hire';
        $catat = 'Rekomendasi AI: ' . ($lolos ? 'Recommended' : 'Not Recommended')
            . ($alasanAi === '' ? '' : ' - ' . $alasanAi)
            . '. [pembanding] ' . $rincian
            // Ketidaksepakatan disebut terang-terangan. Ia tidak mengubah
            // keputusan, tapi ia satu-satunya penanda yang bisa dihitung kalau
            // suatu hari ada yang bertanya seberapa sering keduanya berbeda.
            . ($rumus === $lolos ? ' (sepakat)' : ' (BERBEDA dari rekomendasi AI)');

        $logger->log($appId, 'gate_2', $lolos ? 'passed' : 'failed', $actor, $catat, $email);
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
