<?php

namespace App\Controllers;

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Models\ApplicationModel;
use App\Models\StageHistoryModel;

/**
 * Chatbot status kandidat (Fase 3 Day 3). CI4 pegang session + DB: merakit
 * konteks status kandidat yang login lalu meneruskan ke ai-service /chat.
 * Grounding ketat - konteks hanya berisi lamaran milik kandidat ini, jadi
 * tidak ada kebocoran antar-kandidat.
 */
class Chat extends BaseController
{
    private const MAX_HISTORY  = 6;   // batasi turn yang diteruskan (hemat token)
    private const MAX_QUESTION = 500;

    public function ask()
    {
        $question = trim((string) $this->request->getPost('question'));
        if ($question === '') {
            return $this->reply(['error' => 'Pertanyaan kosong.'], 400);
        }
        $question = mb_substr($question, 0, self::MAX_QUESTION);

        try {
            $reply = (new AiService())->post('/chat', [
                'question' => $question,
                'context'  => self::buildContext((int) session('candidate_id')),
                'history'  => $this->history(),
            ]);
        } catch (AiServiceException $e) {
            log_message('error', 'chatbot ai-service gagal: {m}', ['m' => $e->getMessage()]);

            return $this->reply(['error' => 'Asisten sedang tidak tersedia. Coba lagi sebentar lagi ya.'], 503);
        }

        return $this->reply(['answer' => (string) ($reply['answer'] ?? '')]);
    }

    /** Riwayat percakapan dari klien (disimpan di memori JS), disaring + dibatasi. */
    private function history(): array
    {
        $raw = json_decode((string) $this->request->getPost('history'), true);
        if (! is_array($raw)) {
            return [];
        }

        $turns = array_filter(array_map(static function ($t): ?array {
            if (! is_array($t) || ! in_array($t['role'] ?? '', ['user', 'model'], true) || ! isset($t['text'])) {
                return null;
            }

            return ['role' => $t['role'], 'text' => (string) $t['text']];
        }, $raw));

        return array_slice(array_values($turns), -self::MAX_HISTORY);
    }

    /**
     * Rakit konteks status untuk satu kandidat: tiap lamaran + timeline
     * tahapnya (label Indonesia), dibaca dari candidate_stage_history.
     * Static + murni supaya bisa dites tanpa panggilan HTTP keluar.
     */
    public static function buildContext(int $candidateId): string
    {
        $apps = (new ApplicationModel())
            ->select('applications.id, jobs.judul')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('candidate_id', $candidateId)
            ->orderBy('applications.id')
            ->findAll();

        if ($apps === []) {
            return 'Kandidat belum memiliki lamaran apa pun.';
        }

        $history = new StageHistoryModel();
        $lines   = [];
        foreach ($apps as $app) {
            $lines[] = 'Lamaran "' . $app['judul'] . '":';
            foreach ($history->where('application_id', $app['id'])->orderBy('id')->findAll() as $r) {
                $stage  = Lamaran::STAGE_LABEL[$r['stage']] ?? $r['stage'];
                $status = Lamaran::STATUS_LABEL[$r['status']] ?? $r['status'];
                $note   = $r['note'] ? ' - ' . $r['note'] : '';
                $lines[] = "  - {$stage}: {$status}{$note} [{$r['created_at']}]";
            }
        }

        return implode("\n", $lines);
    }

    /** JSON + token CSRF terbaru (regenerate=true) supaya request berikut tetap sah. */
    private function reply(array $data, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON($data + ['csrf' => csrf_hash()]);
    }
}
