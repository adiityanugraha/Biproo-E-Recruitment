<?php

namespace App\Controllers;

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\ScreeningResultModel;

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
        (new ScreeningResultModel())->insert([
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
            'model_version'    => 'fase4-day1-wiring',
        ]);

        // Gagal ekstraksi -> tercatat di riwayat sebagai "diproses ulang", kandidat
        // TIDAK digugurkan (A3.2: antrian proses ulang; pelajaran bug umur-nan DS).
        // Sukses: baris screening_results cukup - alur stage tetap digerakkan
        // assessment sampai skor nyata tersedia (Day 3).
        if ($status !== 'success') {
            $catatan = (string) (($b['extracted_fields']['catatan'] ?? '') ?: $status);
            (new StageLogger())->log($appId, 'ai_verification', 'retry_queued', 'system', $catatan);
        }

        return $this->response->setJSON(['ok' => true]);
    }
}
