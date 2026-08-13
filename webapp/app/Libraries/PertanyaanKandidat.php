<?php

namespace App\Libraries;

use App\Models\ApplicationModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;

/**
 * Tiga pertanyaan interview milik SATU kandidat (revisi 12 Agustus 2026).
 *
 * Sebelumnya pertanyaan dibuat per LOWONGAN dan dipakai bersama oleh semua
 * pelamarnya. Sekarang disusun dari riwayat kerja kandidat itu sendiri, hasil
 * baca CV-nya, sehingga wawancara berangkat dari pekerjaan yang benar-benar
 * pernah ia jalani, bukan dari kalimat lowongan.
 *
 * TIGA SUMBER, DAN RECRUITER PERLU TAHU YANG MANA:
 *
 *   pengalaman - disusun dari riwayat kerja kandidat. Yang paling diinginkan.
 *   posisi     - kandidat tidak punya riwayat kerja (fresh graduate), jadi
 *                disusun dari uraian lowongan. Bukan kegagalan.
 *   bank       - ai-service mati atau kuotanya habis, jadi dipinjam dari bank
 *                soal lowongan. INI kegagalan, dan pertanyaannya tidak
 *                disesuaikan dengan siapa pun.
 *
 * Membedakan 'posisi' dari 'bank' penting justru saat keadaan sedang buruk:
 * keduanya sama-sama pertanyaan umum, tapi yang satu memang seharusnya begitu
 * dan yang satu lagi tanda ada yang perlu diperbaiki.
 *
 * Disimpan di applications.pertanyaan_json supaya satu kandidat cuma memakai
 * satu panggilan LLM seumur lamarannya. Kuota gratis 20 panggilan sehari, dan
 * membuat ulang tiap kali halaman dibuka akan menghabiskannya sebelum siang.
 */
class PertanyaanKandidat
{
    public const JUMLAH = 3;

    public const SUMBER_PENGALAMAN = 'pengalaman';
    public const SUMBER_POSISI     = 'posisi';
    public const SUMBER_BANK       = 'bank';

    /** Sama dengan batas yang berlaku di halaman pertanyaan per lowongan. */
    public const MAKS_PANJANG = 300;

    /**
     * Pertanyaan untuk sebuah lamaran, dibuat sekali lalu dipakai terus.
     *
     * @return list<array{pertanyaan: string, sumber: string}>
     */
    public function untukLamaran(int $appId): array
    {
        $tersimpan = $this->baca($appId);

        return $tersimpan !== [] ? $tersimpan : $this->buatUlang($appId);
    }

    /**
     * Apakah pertanyaan tersimpan boleh dipakai selamanya.
     *
     * TIDAK, bila ia disusun sebelum CV kandidat selesai dibaca. Terjadi
     * sungguhan 13 Agustus 2026: ai-service mati saat CV masuk, screening-nya
     * gagal terkirim, lalu recruiter membuka ruang interview. Riwayat kerjanya
     * belum ada, jadi pertanyaannya jatuh ke 'posisi' - dan tersimpan begitu.
     * Kandidat dengan empat riwayat kerja permanen ditanyai pertanyaan umum,
     * dan tidak ada yang tahu kecuali membandingkan sendiri dengan CV-nya.
     *
     * Yang diperiksa keberadaan HASIL SCREENING, bukan ada tidaknya riwayat:
     * fresh graduate yang CV-nya sudah terbaca memang seharusnya dapat
     * pertanyaan dari posisi, dan itu bukan keadaan yang perlu diperbaiki.
     */
    public function perluDisusunUlang(int $appId): bool
    {
        $tersimpan = $this->baca($appId);
        if ($tersimpan === [] || $tersimpan[0]['sumber'] !== self::SUMBER_POSISI) {
            return false;
        }

        return $this->riwayat($appId) !== [];
    }

    /**
     * Paksa susun ulang, menimpa yang tersimpan.
     *
     * Dipakai tombol "buat ulang" recruiter. Pertanyaan hasil AI kadang meleset
     * dari maksudnya, dan memperbaikinya sebelum kandidat terlanjur ditanyai
     * jauh lebih murah daripada sesudahnya. Satu panggilan LLM sekali tekan,
     * jadi tombolnya memang tidak boleh ada di tempat yang mudah tersenggol.
     *
     * @return list<array{pertanyaan: string, sumber: string}>
     */
    public function buatUlang(int $appId): array
    {
        $job = $this->lowongan($appId);
        if ($job === null) {
            return [];
        }

        try {
            $hasil = service('aiService')->post('pertanyaan', [
                'judul'      => (string) $job['judul'],
                'skill'      => (string) ($job['req_skill'] ?? ''),
                'pendidikan' => (string) ($job['req_pendidikan'] ?? ''),
                'pengalaman' => (string) ($job['req_pengalaman'] ?? ''),
                'deskripsi'  => (string) ($job['deskripsi'] ?? ''),
                'jumlah'     => self::JUMLAH,
                'riwayat'    => $this->riwayat($appId),
            ]);
            $daftar = $hasil['pertanyaan'] ?? [];
            $sumber = (string) ($hasil['sumber'] ?? self::SUMBER_POSISI);
        } catch (AiServiceException $e) {
            // Sebab paling sering: kuota harian LLM habis atau ai-service mati.
            // Wawancara TIDAK boleh terhenti karenanya - recruiter sudah duduk
            // di ruang Zoom bersama kandidat. Bank soal lowongan dipinjam apa
            // adanya, dan sumbernya ditandai supaya kelihatan ini jalan darurat.
            log_message('error', 'Pertanyaan kandidat gagal dibuat: ' . $e->getMessage());
            $daftar = $this->bankLowongan($job);
            $sumber = self::SUMBER_BANK;
        }

        $rakit = $this->rakit((array) $daftar, $sumber);
        if ($rakit === []) {
            return [];   // jangan menyimpan daftar kosong: untukLamaran() akan mencoba lagi
        }

        $this->tulis($appId, $rakit);

        return $rakit;
    }

    /**
     * Simpan suntingan recruiter.
     *
     * Yang boleh datang dari form hanya TEKS pertanyaannya. Sumber diambil dari
     * yang sudah tersimpan menurut urutan baris, tidak dititipkan lewat field
     * tersembunyi - pola yang sama dengan rubrik di bank soal lowongan, dengan
     * alasan yang sama: keterangan asal-usul tidak boleh bisa dipalsukan dari
     * browser, karena justru itu yang dibaca orang saat menilai kewajarannya.
     *
     * @param list<string> $teks
     *
     * @return list<array{pertanyaan: string, sumber: string}>
     */
    public function simpanTeks(int $appId, array $teks): array
    {
        $lama  = $this->baca($appId);
        $baru  = [];
        foreach (array_values($teks) as $i => $t) {
            $satu = $this->rakit([$t], $lama[$i]['sumber'] ?? self::SUMBER_POSISI);
            if ($satu !== []) {
                $baru[] = $satu[0];
            }
        }
        $this->tulis($appId, $baru);

        return $baru;
    }

    /**
     * Riwayat kerja kandidat dari hasil baca CV.
     *
     * Hanya empat bidang yang dikirim. Hasil baca CV juga memuat gaji terakhir
     * dan alasan keluar, dan keduanya tidak ada urusannya dengan menyusun
     * pertanyaan; ai-service menolaknya sekali lagi di sisi sana.
     *
     * @return list<array<string, string>>
     */
    private function riwayat(int $appId): array
    {
        $sr = (new ScreeningResultModel())->latestFor($appId);
        if ($sr === null) {
            return [];
        }

        $ex = json_decode((string) ($sr['extracted_json'] ?? ''), true);
        if (! is_array($ex) || ! is_array($ex['riwayat'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($ex['riwayat'] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $out[] = [
                'jabatan'    => (string) ($r['jabatan'] ?? ''),
                'perusahaan' => (string) ($r['perusahaan'] ?? ''),
                'periode'    => (string) ($r['periode'] ?? ''),
                'deskripsi'  => (string) ($r['deskripsi'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Bank soal lowongan sebagai cadangan.
     *
     * Bank tim DS menyimpan pertanyaan sebagai objek berisi rubrik; yang
     * dibutuhkan di sini cuma teksnya, karena penilaian sudah memakai sembilan
     * kompetensi BIPROO dan tidak lagi membaca indikator per pertanyaan.
     *
     * @param array<string, mixed> $job
     *
     * @return list<string>
     */
    private function bankLowongan(array $job): array
    {
        $d = json_decode((string) ($job['pertanyaan_json'] ?? ''), true);
        if (! is_array($d)) {
            return [];
        }

        $teks = [];
        foreach ($d as $p) {
            $t = is_array($p) ? ($p['pertanyaan'] ?? '') : $p;
            if (is_scalar($t) && trim((string) $t) !== '') {
                $teks[] = (string) $t;
            }
        }

        return $teks;
    }

    /**
     * @param list<mixed> $daftar
     *
     * @return list<array{pertanyaan: string, sumber: string}>
     */
    private function rakit(array $daftar, string $sumber): array
    {
        $out = [];
        foreach ($daftar as $p) {
            if (! is_scalar($p)) {
                continue;
            }
            $t = trim(preg_replace('/\s+/u', ' ', (string) $p));
            if ($t === '') {
                continue;
            }
            $out[] = [
                'pertanyaan' => mb_substr($t, 0, self::MAKS_PANJANG),
                'sumber'     => $sumber,
            ];
        }

        return array_slice($out, 0, self::JUMLAH);
    }

    /** @return list<array{pertanyaan: string, sumber: string}> */
    private function baca(int $appId): array
    {
        $app = (new ApplicationModel())->find($appId);
        $d   = json_decode((string) ($app['pertanyaan_json'] ?? ''), true);
        if (! is_array($d)) {
            return [];
        }

        $out = [];
        foreach ($d as $p) {
            if (is_array($p) && trim((string) ($p['pertanyaan'] ?? '')) !== '') {
                $out[] = [
                    'pertanyaan' => (string) $p['pertanyaan'],
                    'sumber'     => (string) ($p['sumber'] ?? self::SUMBER_POSISI),
                ];
            }
        }

        return $out;
    }

    /** @param list<array{pertanyaan: string, sumber: string}> $daftar */
    private function tulis(int $appId, array $daftar): void
    {
        (new ApplicationModel())->update($appId, [
            'pertanyaan_json' => json_encode($daftar, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function lowongan(int $appId): ?array
    {
        $app = (new ApplicationModel())->find($appId);

        return $app === null ? null : (new JobModel())->find($app['job_id']);
    }
}
