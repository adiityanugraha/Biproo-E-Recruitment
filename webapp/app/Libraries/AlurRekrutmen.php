<?php

namespace App\Libraries;

/**
 * Alur rekrutmen per lowongan (18 Agustus 2026).
 *
 * Mengikuti web recruiter BIPROO: tiap posisi punya rangkaian tahap sendiri,
 * dibagi dua kelompok - Assessment lalu Selection - dan bisa disunting di
 * halaman Settings. Sales Gadget memakai QLEAP tanpa Interview User; Sales
 * Administration memakai Excel Test dan memakai Interview User. Sebelum ini
 * E-REQ memakai satu rangkaian yang sama untuk semua posisi.
 *
 * INTERVIEW HRD DAN INTERVIEW USER DUA HAL YANG BERBEDA. Yang pertama
 * wawancara oleh recruiter dan sudah otomatis sampai Gate 2; yang kedua
 * wawancara oleh calon atasan di unit yang bersangkutan, tidak selalu ada, dan
 * hasilnya diketik manusia. Menyatukan keduanya membuat posisi yang tidak
 * memakai Interview User tetap menampilkannya, dan posisi yang memakainya
 * kehilangan satu tahap dari catatannya.
 *
 * TAHAP INTI TIDAK BISA DIMATIKAN. Mesinnya bergantung pada urutan itu:
 * screening mengisi ai_verification, assessment memutus gate_1, ruang interview
 * mengisi interview_online, callback transkripsi menutup gate_2. Membiarkan
 * orang mencabutnya lewat Settings berarti mematikan otomatisasi diam-diam,
 * dan yang terlihat cuma kandidat yang berhenti bergerak tanpa sebab. Yang
 * bisa dipilih tahap TAMBAHAN, yang memang dikerjakan dan dicatat manusia.
 */
class AlurRekrutmen
{
    public const ASSESSMENT = 'assessment';
    public const SELECTION  = 'selection';

    /**
     * Katalog tahap yang tersedia: kunci => [label, kelompok, wajib, ikon].
     *
     * wajib true  = digerakkan mesin, selalu ikut, tidak bisa dicabut.
     * wajib false = dikerjakan di luar sistem, recruiter yang menandainya.
     *
     * URUTAN DI SINI PUNYA ARTI: ia yang dipakai menyisipkan tahap wajib yang
     * hilang ke posisi yang benar.
     */
    public const KATALOG = [
        // --- Assessment ---
        'upload_cv'         => ['Upload CV', self::ASSESSMENT, true, '📄'],
        'online_assessment' => ['Tes Intelegensi Umum 5', self::ASSESSMENT, true, '📝'],
        'disc'              => ['D.I.S.C (Personality Test)', self::ASSESSMENT, false, '🧩'],
        'qleap'             => ['QLEAP', self::ASSESSMENT, false, '🧠'],
        'excel_test'        => ['Excel Test', self::ASSESSMENT, false, '📊'],
        'papikostik'        => ['Papikostik', self::ASSESSMENT, false, '🗂'],
        'leap'              => ['LEAP', self::ASSESSMENT, false, '📈'],
        'english_test'      => ['English Test', self::ASSESSMENT, false, '🔤'],
        'gate_1'            => ['Keputusan Tahap 1', self::ASSESSMENT, true, '🎯'],
        // --- Selection ---
        'penjadwalan'       => ['Penjadwalan Interview', self::SELECTION, true, '📅'],
        'interview_online'  => ['Interview HRD', self::SELECTION, true, '🎥'],
        'interview_user'    => ['Interview User', self::SELECTION, false, '👔'],
        'gate_2'            => ['Keputusan Akhir', self::SELECTION, true, '✅'],
        'training_class'    => ['Training Class', self::SELECTION, false, '🎓'],
        'on_job_training'   => ['On Job Training', self::SELECTION, false, '🛠'],
        'berkas_kontrak'    => ['Input Data & Berkas', self::SELECTION, true, '📁'],
    ];

    /**
     * Tahap internal yang TIDAK pernah tampil di alur.
     *
     * ai_verification proses latar yang tidak menuntut tindakan siapa pun dan
     * tidak lagi menentukan kelolosan Gate 1. Riwayat lengkapnya tetap tercatat
     * untuk audit, cuma tidak ikut digambar sebagai tahap yang dilalui kandidat.
     */
    public const TERSEMBUNYI = ['ai_verification'];

    /**
     * Kunci tahap wajib, berurutan sesuai katalog.
     *
     * @return list<string>
     */
    public static function wajib(): array
    {
        return array_keys(array_filter(self::KATALOG, static fn (array $t): bool => $t[2]));
    }

    /**
     * Kunci tahap yang boleh dipilih recruiter.
     *
     * @return list<string>
     */
    public static function opsional(): array
    {
        return array_keys(array_filter(self::KATALOG, static fn (array $t): bool => ! $t[2]));
    }

    /**
     * Alur bawaan: hanya tahap wajib.
     *
     * Lowongan yang alur_json-nya masih null jatuh ke sini, dan hasilnya sama
     * persis dengan rangkaian tetap sebelum 18 Agustus 2026 - jadi tidak ada
     * lamaran berjalan yang berubah artinya saat migrasi ini dipasang.
     *
     * @return list<string>
     */
    public static function bawaan(): array
    {
        return self::wajib();
    }

    /**
     * Alur satu lowongan dari kolom jobs.alur_json.
     *
     * Toleran: JSON rusak, null, atau kunci tak dikenal jatuh ke bawaan alih-
     * alih melempar. Halaman kandidat tidak boleh mati gara-gara satu kolom
     * konfigurasi yang salah ketik.
     *
     * @return list<string>
     */
    public static function untukLowongan(?string $json): array
    {
        $pilihan = json_decode((string) $json, true);
        if (! is_array($pilihan)) {
            return self::bawaan();
        }

        return self::lengkapi(self::bersihkan($pilihan));
    }

    /**
     * Buang kunci asing dan yang kembar, pertahankan urutannya.
     *
     * @param  array<mixed> $pilihan
     * @return list<string>
     */
    private static function bersihkan(array $pilihan): array
    {
        $out = [];
        foreach ($pilihan as $kunci) {
            if (is_string($kunci) && isset(self::KATALOG[$kunci]) && ! in_array($kunci, $out, true)) {
                $out[] = $kunci;
            }
        }

        return $out;
    }

    /**
     * Rakit alur yang sah dari pilihan recruiter.
     *
     * DUA ATURAN, dan keduanya ada sebabnya.
     *
     * Tahap WAJIB selalu ikut dan selalu berurutan menurut katalog. Mesinnya
     * berjalan pada urutan itu - assessment memutus gate_1, ruang interview
     * mengisi interview_online, callback menutup gate_2 - jadi gate_2 yang
     * ditaruh sebelum Interview HRD bukan alur lain melainkan alur yang tidak
     * mungkin terjadi.
     *
     * Tahap PILIHAN bebas letaknya, dan itu memang gunanya: satu posisi menaruh
     * D.I.S.C sebelum TIU 5, posisi lain sesudahnya. Letaknya ditentukan sudah
     * berapa tahap wajib yang dilewati sebelum ia disebut.
     *
     * @param  list<string> $pilihan
     * @return list<string>
     */
    public static function lengkapi(array $pilihan): array
    {
        $wajib = self::wajib();

        // Tahap pilihan dikelompokkan menurut berapa tahap wajib yang sudah
        // lewat saat ia disebut. Yang disebut sebelum tahap wajib mana pun
        // masuk slot 0, yaitu paling depan.
        $slot   = array_fill(0, count($wajib) + 1, []);
        $lewat  = 0;
        foreach ($pilihan as $kunci) {
            $i = array_search($kunci, $wajib, true);
            if ($i !== false) {
                // Tahap wajib yang disebut di luar urutannya tidak menggeser
                // apa pun - urutan mereka tetap urutan mesin.
                $lewat = max($lewat, $i + 1);

                continue;
            }
            $slot[$lewat][] = $kunci;
        }

        $out = $slot[0];
        foreach ($wajib as $i => $kunci) {
            $out[] = $kunci;
            $out   = array_merge($out, $slot[$i + 1]);
        }

        return $out;
    }

    /**
     * Pilihan recruiter jadi JSON siap simpan.
     *
     * @param array<mixed> $pilihan
     */
    public static function keJson(array $pilihan): string
    {
        return json_encode(self::lengkapi(self::bersihkan($pilihan)));
    }

    /**
     * Alur dipecah dua kelompok untuk digambar.
     *
     * @param  list<string> $alur
     * @return array<string, list<array<string, mixed>>>
     */
    public static function perKelompok(array $alur): array
    {
        $out = [self::ASSESSMENT => [], self::SELECTION => []];
        foreach ($alur as $kunci) {
            if (! isset(self::KATALOG[$kunci])) {
                continue;
            }
            [$label, $grup, $wajib, $ikon] = self::KATALOG[$kunci];
            $out[$grup][] = ['kunci' => $kunci, 'label' => $label, 'ikon' => $ikon, 'wajib' => $wajib];
        }

        return $out;
    }

    /**
     * Apakah lowongan ini memakai Interview User.
     *
     * Penentu apakah Gate 2 masih keputusan akhir. Kalau posisinya memakai
     * Interview User, wawancara HRD hanya menyaring dan yang memutuskan
     * terakhir atasannya - lihat Interview::putuskan().
     */
    public static function pakaiInterviewUser(?string $alurJson): bool
    {
        return in_array('interview_user', self::untukLowongan($alurJson), true);
    }

    /** Label satu tahap, atau kuncinya sendiri bila tak dikenal. */
    public static function label(string $kunci): string
    {
        return self::KATALOG[$kunci][0] ?? $kunci;
    }
}
