<?php

namespace App\Libraries;

use App\Models\InterviewTranskripModel;

/**
 * Kirim rekaman wawancara ke ai-service untuk ditranskripsi dan dinilai.
 *
 * Dipisah dari controller karena punya DUA pemanggil: recruiter yang baru
 * mengunggah, dan perintah ereq:transkrip:resend untuk rekaman yang tersangkut
 * karena layanannya sedang mati. Keduanya harus berperilaku sama persis.
 *
 * Polanya sama dengan screening CV: CI4 mengirim URL, ai-service mengunduh
 * sendiri berkasnya lewat token bersama, lalu membalas ke callback. Berkas
 * puluhan MB tidak dikirim di dalam badan permintaan.
 */
class KirimRekaman
{
    /**
     * @return bool false bila ai-service tidak menerimanya (dan barisnya
     *              ditandai gagal supaya recruiter melihat sebabnya di layar)
     */
    public function kirim(int $transkripId): bool
    {
        $model = new InterviewTranskripModel();
        $baris = $model->find($transkripId);
        if ($baris === null || ($baris['berkas'] ?? '') === '') {
            return false;
        }

        $token = (string) config('AiService')->sharedToken;

        try {
            service('aiService')->post('interview', [
                'application_id' => (int) $baris['application_id'],
                'audio_url'      => site_url('internal/rekaman/' . $transkripId),
                'mime'           => $this->mime($baris['berkas']),
                'kompetensi'     => LembarPenilaian::dariTranskrip(),
                'callback_url'   => site_url('interview/callback'),
                'callback_token' => $token,
            ]);
        } catch (AiServiceException $e) {
            log_message('error', 'Kirim rekaman gagal: ' . $e->getMessage());
            $model->update($transkripId, [
                'status'  => 'gagal',
                'catatan' => 'Layanan AI tidak menjawab. Rekaman tetap tersimpan dan bisa dicoba lagi.',
            ]);

            return false;
        }

        $model->update($transkripId, ['status' => 'proses', 'catatan' => null]);

        return true;
    }

    /**
     * Tipe berkas dari ekstensinya.
     *
     * Ekstensi sudah dijaga saat unggah (Recruiter::REKAMAN_EKSTENSI), jadi di
     * sini cukup pemetaan. Yang tak dikenal jatuh ke audio/mp4, tipe rekaman
     * bawaan Zoom.
     *
     * Keempat ekstensi ini yang MUNGKIN ada di disk: Recruiter::unggahRekaman
     * menamai berkasnya menurut jenis yang terbaca dari isinya (REKAMAN_MIME),
     * bukan menurut nama kiriman, jadi tidak ada ekstensi liar yang masuk.
     */
    private function mime(string $berkas): string
    {
        return [
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'mp4' => 'video/mp4',
        ][strtolower(pathinfo($berkas, PATHINFO_EXTENSION))] ?? 'audio/mp4';
    }
}
