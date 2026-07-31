<?php

namespace App\Controllers;

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;

/**
 * Jalur internal CI4 <-> ai-service (Fase 4 Day 1, kontrak A3.1).
 *
 * Dua endpoint, dua arah:
 *  - GET  internal/cv/{appId}   ai-service mengunduh berkas CV untuk diekstrak
 *  - POST screening/callback    ai-service mengembalikan hasil screening
 *
 * Keduanya dijaga satu token bersama (header X-Token, config AiService).
 * Token kosong = kedua endpoint menolak semua request (fail-closed), supaya
 * instalasi yang belum dikonfigurasi tidak diam-diam terbuka.
 */
class Screening extends BaseController
{
    /** @var list<string> status callback yang sah (kontrak A3.1 + model) */
    private const STATUS_SAH = ['success', 'failed_extraction', 'failed_provider'];

    /** Versi pipeline screening, ikut tersimpan sebagai audit trail (A3.4). */
    private const MODEL_VERSION = 'fase4-embedding-cosine-v1';

    private function tokenSah(): bool
    {
        $rahasia = (string) config('AiService')->sharedToken;
        $kiriman = (string) $this->request->getHeaderLine('X-Token');

        return $rahasia !== '' && hash_equals($rahasia, $kiriman);
    }

    /** ai-service mengunduh CV milik satu lamaran. */
    public function cvFile(int $appId)
    {
        if (! $this->tokenSah()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'token salah']);
        }

        $app = (new ApplicationModel())->find($appId);
        $path = $app === null ? '' : WRITEPATH . $app['cv_path'];
        if ($app === null || ! is_file($path)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'CV tidak ditemukan']);
        }

        return $this->response->download($path, null);
    }

    /** ai-service mengembalikan hasil screening (kontrak A3.1 + echo job_id_internal). */
    public function callback()
    {
        if (! $this->tokenSah()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'token salah']);
        }

        $b      = (array) $this->request->getJSON(true);
        $appId  = (int) ($b['job_id_internal'] ?? 0);
        $status = (string) ($b['status'] ?? '');
        if ($appId <= 0 || ! in_array($status, self::STATUS_SAH, true)
            || (new ApplicationModel())->find($appId) === null) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'payload tidak valid']);
        }

        $scores = (array) ($b['scores'] ?? []);
        $sr     = new ScreeningResultModel();

        // Dicek SEBELUM insert: skor terakhir yang pernah tercatat untuk lamaran ini.
        // Menentukan apakah callback ini membawa kabar baru bagi riwayat.
        $sebelumnya = $sr->latestFor($appId);
        $skorLama   = $sebelumnya === null || $sebelumnya['score_overall'] === null
            ? null
            : round((float) $sebelumnya['score_overall'], 4);

        $sr->insert([
            'application_id'   => $appId,
            'screening_job_id' => (string) ($b['screening_job_id'] ?? ''),
            'status'           => $status,
            'score_overall'    => $scores['overall'] ?? null,
            'score_skill'      => $scores['skill'] ?? null,
            'score_pendidikan' => $scores['pendidikan'] ?? null,
            'score_pengalaman' => $scores['pengalaman'] ?? null,
            'extracted_json'   => json_encode($b['extracted_fields'] ?? [], JSON_UNESCAPED_UNICODE),
            'flags_json'       => json_encode($b['flags'] ?? [], JSON_UNESCAPED_UNICODE),
            'provider'         => 'ai-service',
            'model_version'    => self::MODEL_VERSION,
        ]);

        // Hasil screening masuk candidate_stage_history saat callback tiba, bukan
        // menunggu kandidat mengerjakan assessment. Tanpa ini ada balapan: kandidat
        // yang langsung mengerjakan assessment beberapa detik setelah upload akan
        // dinilai memakai skor dummy walaupun skor nyata menyusul sesaat kemudian.
        $logger = new StageLogger();
        $skor   = $scores['overall'] ?? null;
        $adaAI  = (new StageHistoryModel())->latestStatus($appId, 'ai_verification') !== null;

        if ($status !== 'success') {
            // Gagal ekstraksi -> "diproses ulang", kandidat TIDAK digugurkan
            // (A3.2 antrian proses ulang; pelajaran bug umur-nan tim DS).
            $catatan = (string) (($b['extracted_fields']['catatan'] ?? '') ?: $status);
            $logger->log($appId, 'ai_verification', 'retry_queued', 'system', $catatan);
        } elseif ($skor !== null && round((float) $skor, 4) !== $skorLama) {
            // Skor nyata yang BERBEDA dari yang tercatat. Mencakup skor pertama
            // (skorLama null) maupun hasil penilaian ulang setelah pipeline
            // diperbaiki (screening:resend --paksa). Riwayat append-only: baris
            // lama tidak diubah, perubahannya muncul sebagai baris baru.
            //
            // Callback berulang dengan skor SAMA tidak menambah baris - itu yang
            // menjaga idempotensi, bukan pengecekan "sudah pernah berskor".
            if (! $adaAI) {
                $logger->log($appId, 'ai_verification', 'entered', 'system');
            }
            $logger->log($appId, 'ai_verification', 'passed', 'system',
                'Kemiripan CV terhadap lowongan: ' . kemiripan_teks($skor) . ' (' . self::MODEL_VERSION . ')'
                . ($skorLama === null ? '' : ', dinilai ulang dari ' . kemiripan_teks($skorLama)));
        } elseif ($skor === null) {
            // Terekstrak, tapi tak ada bidang yang bisa dihitung -> minta mata
            // manusia, bukan diberi angka karangan.
            //
            // Dicatat JUGA ketika riwayat sudah memuat skor lama. Screening ulang
            // bisa menyimpulkan dokumen ini tidak memuat isi CV, dan skor lamanya
            // dengan begitu ditarik. Tanpa baris ini riwayat berhenti di angka
            // lama sementara skor sebenarnya sudah kosong - persis jenis
            // kebohongan diam yang pipeline ini ada untuk mencegah.
            //
            // Idempoten: tidak diulang bila baris terakhir sudah flagged.
            if ((new StageHistoryModel())->latestStatus($appId, 'ai_verification') !== 'flagged') {
                if (! $adaAI) {
                    $logger->log($appId, 'ai_verification', 'entered', 'system');
                }
                $logger->log($appId, 'ai_verification', 'flagged', 'system',
                    'Screening selesai tanpa skor yang bisa dihitung'
                    . ($adaAI ? ' - skor sebelumnya tidak berlaku lagi' : '')
                    . '. Perlu ditinjau recruiter.');
            }
        }

        return $this->response->setJSON(['ok' => true]);
    }
}
