<?php

namespace App\Libraries;

use App\Models\ApplicationModel;
use App\Models\InterviewTranskripModel;
use App\Models\ScreeningResultModel;

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
                // Ikut dibawa supaya hasilnya mendarat di BARIS YANG BENAR. Satu
                // lamaran bisa punya beberapa rekaman - unggah ulang menambah
                // baris, tidak menimpa - dan tanpa id ini callback cuma bisa
                // menebak lewat baris terbaru.
                'transkrip_id'   => $transkripId,
                'audio_url'      => site_url('internal/rekaman/' . $transkripId),
                'mime'           => $this->mime($baris['berkas']),
                'kompetensi'     => LembarPenilaian::dariTranskrip(),
                // Bahan untuk merangkum kekuatan dan kelemahan. Keduanya
                // sebagiannya terbaca dari apa yang pernah DIKERJAKAN kandidat,
                // bukan cuma dari apa yang sempat ia katakan dalam wawancara
                // 30 menit. Biodata TIDAK ikut.
                'riwayat'        => $this->riwayat((int) $baris['application_id']),
                'posisi'         => $this->posisi((int) $baris['application_id']),
                // Ikut sejak rekomendasinya diputuskan di sisi AI (permintaan
                // atasan, 14 Agustus 2026). Tanpa angka ini kecocokan CV hilang
                // sama sekali dari keputusan - padahal di rumus lama ia 40%
                // bobotnya - dan kandidat dinilai semata dari 30 menit bicara.
                'skor_cv'        => $this->skorCv((int) $baris['application_id']),
                // Syarat lowongan dan tiga pertanyaan yang BENAR-BENAR diajukan
                // (18 Agustus 2026). Sebelumnya yang dikirim cuma judul posisi,
                // dan judul tidak menerangkan apa pun tentang pekerjaannya:
                // transkrip operator gudang lolos dengan nilai bagus di posisi
                // Security System karena tidak ada yang bisa dipakai model untuk
                // menyimpulkan wawancaranya membahas pekerjaan yang lain.
                'syarat'         => $this->syarat((int) $baris['application_id']),
                'pertanyaan'     => $this->pertanyaan((int) $baris['application_id']),
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
     * Riwayat kerja kandidat dari hasil baca CV.
     *
     * Empat bidang saja, sama dengan yang dipakai menyusun pertanyaan. Hasil
     * baca CV juga memuat gaji terakhir, alasan keluar, dan biodata; tidak satu
     * pun dari itu boleh ikut menentukan kekuatan atau kelemahan seseorang.
     *
     * @return list<array<string, string>>
     */
    private function riwayat(int $appId): array
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);
        $ex = $sr === null ? null : json_decode((string) ($sr['extracted_json'] ?? ''), true);
        if (! is_array($ex) || ! is_array($ex['riwayat'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($ex['riwayat'] as $r) {
            if (is_array($r)) {
                $out[] = [
                    'jabatan'    => (string) ($r['jabatan'] ?? ''),
                    'perusahaan' => (string) ($r['perusahaan'] ?? ''),
                    'periode'    => (string) ($r['periode'] ?? ''),
                    'deskripsi'  => (string) ($r['deskripsi'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /** Skor kecocokan CV, atau null bila screening belum menghasilkan angka. */
    private function skorCv(int $appId): ?float
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);

        return $sr === null || $sr['score_overall'] === null ? null : (float) $sr['score_overall'];
    }

    /**
     * Syarat lowongan apa adanya dari tabel jobs.
     *
     * Empat bidang yang sama dengan yang dipakai screening CV, jadi penilai
     * wawancara menimbang lowongan yang sama dengan yang menyeleksi CV-nya.
     *
     * @return array<string, string>
     */
    private function syarat(int $appId): array
    {
        $app = (new ApplicationModel())
            ->select('jobs.req_skill, jobs.req_pendidikan, jobs.req_pengalaman, jobs.deskripsi')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('applications.id', $appId)
            ->first();

        return [
            'skill'      => (string) ($app['req_skill'] ?? ''),
            'pendidikan' => (string) ($app['req_pendidikan'] ?? ''),
            'pengalaman' => (string) ($app['req_pengalaman'] ?? ''),
            'deskripsi'  => (string) ($app['deskripsi'] ?? ''),
        ];
    }

    /**
     * Tiga pertanyaan yang diajukan ke kandidat ini.
     *
     * Tanpa ini penilai tidak bisa melihat bahwa jawabannya tidak menjawab apa
     * pun yang ditanyakan - petunjuk paling terang bahwa transkripnya berasal
     * dari wawancara yang lain.
     *
     * @return list<string>
     */
    private function pertanyaan(int $appId): array
    {
        // tersimpan(), BUKAN untukLamaran(): yang kedua menyusun pertanyaan
        // baru bila belum ada, dan itu satu panggilan LLM yang tidak diminta
        // siapa pun - lahir pula SESUDAH wawancaranya terjadi. Yang belum
        // punya pertanyaan tersimpan cukup mengirim daftar kosong.
        return array_column((new PertanyaanKandidat())->tersimpan($appId), 'pertanyaan');
    }

    private function posisi(int $appId): string
    {
        $app = (new ApplicationModel())
            ->select('jobs.judul')
            ->join('jobs', 'jobs.id = applications.job_id')
            ->where('applications.id', $appId)
            ->first();

        return (string) ($app['judul'] ?? '');
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
