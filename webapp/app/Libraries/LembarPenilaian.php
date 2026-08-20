<?php

namespace App\Libraries;

/**
 * Lembar penilaian interview mengikuti formulir BIPROO yang asli
 * (arahan atasan 12 Agustus 2026).
 *
 * Menggantikan rubrik per lowongan dari bank tim DS sebagai ALAT NILAI. Bank
 * itu tetap dipakai sebagai sumber PERTANYAAN; yang berubah cuma apa yang diisi
 * recruiter setelah wawancara, supaya lembar profil kandidat bisa dirakit
 * otomatis tanpa menerjemahkan kompetensi yang berbeda-beda tiap posisi.
 *
 * Catatan yang perlu diketahui pengganti saya: rubrik lama sengaja memakai TIGA
 * tingkat, dengan alasan pembedaan lima tingkat cenderung semu pada wawancara 30
 * menit - dua penilai memberi 3 dan 4 untuk jawaban yang sama. Formulir BIPROO
 * memakai lima, dan itu yang dipakai di sini karena lembar profilnya memang
 * begitu. Keberatan itu tidak hilang, cuma kalah oleh keharusan mengikuti format
 * perusahaan.
 *
 * Semua baris disimpan di tabel yang sama, interview_penilaian, dibedakan lewat
 * kolom kategori. Tidak ada tabel baru: bentuk barisnya sudah pas, dan sifat
 * tambah-saja tabel itu justru yang dibutuhkan lembar profil.
 */
final class LembarPenilaian
{
    /** Sembilan kompetensi Interview HRD, urut persis seperti di lembar profil. */
    public const HRD = [
        'Appearance',
        'Communication Skills',
        'Adaptability',
        'Retail Experience',
        'Attitude & Professionalism',
        'Personal Grooming',
        'Self-Presentation Skills',
        'Problem-Solving Ability',
        'Service Orientation',
    ];

    /**
     * Tiga kompetensi yang HANYA bisa dinilai manusia yang melihat kandidat.
     *
     * Tidak ada cara jujur menilai penampilan, kerapian, atau cara membawa diri
     * dari transkrip: yang tersimpan cuma kata-kata yang terucap. Menyerahkannya
     * ke AI berarti menghasilkan angka yang terlihat sah tanpa dasar apa pun.
     *
     * Sisanya (enam) dinilai dari transkrip. Pembagiannya bukan soal apa yang
     * mampu dikerjakan mesin, melainkan apa yang datanya memang ada.
     */
    public const MATA_MANUSIA = [
        'Appearance',
        'Personal Grooming',
        'Self-Presentation Skills',
    ];

    /** Asal sebuah nilai, disimpan di interview_penilaian.sumber. */
    public const DARI_AI = 'ai';
    public const DARI_RECRUITER = 'recruiter';
    // Atasan yang mewawancarai di tahap Interview User (19 Agustus 2026).
    // Dibedakan dari DARI_RECRUITER karena orangnya memang berbeda, dan
    // lembar profil menyebut siapa yang memberi tiap nilai.
    public const DARI_ATASAN = 'atasan';

    /**
     * Kompetensi yang dinilai dari transkrip.
     *
     * Diturunkan dari HRD dikurangi MATA_MANUSIA, bukan didaftar ulang: dua
     * daftar yang harus dijaga tetap sama pada akhirnya selalu berbeda.
     *
     * @return list<string>
     */
    public static function dariTranskrip(): array
    {
        return array_values(array_diff(self::HRD, self::MATA_MANUSIA));
    }

    /** Skala 1-5, label mengikuti lembar aslinya yang berbahasa Inggris. */
    public const SKALA = [
        1 => 'Poor',
        2 => 'Below Average',
        3 => 'Average',
        4 => 'Above Average',
        5 => 'Excellent',
    ];

    public const MAKS_SKALA = 5;

    /** Tujuh butir Interview User, skala angka 1-10. Diisi user departemen. */
    public const USER = [
        'Pengetahuan',
        'Ketertarikan',
        'Pengalaman',
        'Ekspektasi',
        'Kemampuan Untuk Melakukan Pekerjaan',
        'Pendidikan/Training',
        'Stabilitas dari pekerjaan sebelumnya',
    ];

    /**
     * Skala Interview User: 1-5, sama dengan lembar HRD (19 Agustus 2026).
     *
     * Semula 1-10. Disamakan atas permintaan, dan hasilnya lebih baik: satu
     * orang yang membaca lembar profil tidak lagi harus mengingat bahwa 7 di
     * bagian atas berarti sesuatu yang lain dari 7 di bagian bawah.
     *
     * Tetap konstanta tersendiri walau nilainya kebetulan sama dengan
     * MAKS_SKALA: keduanya mengikuti DUA formulir BIPROO yang berbeda, dan
     * menyatukannya membuat perubahan pada salah satunya diam-diam menggeser
     * yang lain.
     */
    public const MAKS_USER = 5;

    /** Kotak isian bebas di bawah tabel kompetensi. */
    public const NARASI = [
        'strengths'  => "Candidate's Strengths",
        'weaknesses' => "Candidate's Weaknesses",
        'notes'      => 'Additional Notes',
        'remarks'    => 'Other Remarks',
    ];

    /**
     * Dua kotak yang DISUSUN AI dari riwayat kerja dan transkrip wawancara.
     *
     * Keduanya rangkuman dari bahan yang sama dengan yang dipakai menilai
     * kompetensi, jadi menyusunnya sekali lagi dari ingatan cuma menghasilkan
     * versi yang lebih kabur. Sisanya - Additional Notes dan Other Remarks -
     * tetap milik recruiter: itu pengamatannya sendiri, hal yang justru tidak
     * ada di transkrip maupun CV.
     *
     * Tidak menambah panggilan LLM: ikut di jawaban yang sama dengan penilaian
     * kompetensi, karena bahannya memang sudah dikirim ke sana.
     */
    public const NARASI_AI = ['strengths', 'weaknesses'];

    /** Sisanya, ditulis recruiter di form unggah rekaman. */
    public const NARASI_RECRUITER = ['notes', 'remarks'];

    public const MAKS_CATATAN = 500;

    /** Nilai kategori pada interview_penilaian. */
    public const KAT_HRD    = 'hrd';
    public const KAT_USER   = 'user';
    public const KAT_NARASI = 'narasi';

    /**
     * Interview Result: DITURUNKAN dari keputusan Gate 2, bukan diisi recruiter.
     *
     * Dulu ada pilihan Recommended / Not Recommended tersendiri di form, diisi
     * SEBELUM sistem menghitung kelulusan. Dua sumber kebenaran untuk satu hal
     * yang sama, dan tidak ada apa pun yang mencegah keduanya bertentangan:
     * lembar profil bisa berbunyi "Recommended" pada kandidat yang catatan
     * Gate 2-nya berbunyi TIDAK LOLOS. Sekarang keputusannya cuma satu.
     *
     * @param string|null $gate2 status terakhir tahap gate_2 di stage_history
     *
     * @return string '' bila belum diputuskan (termasuk 'flagged')
     */
    public static function hasil(?string $gate2): string
    {
        return match ($gate2) {
            'passed' => 'Recommended',
            'failed' => 'Not Recommended',
            default  => '',
        };
    }

    /**
     * Rakit baris penilaian Interview HRD dari kiriman form.
     *
     * Nama kompetensi diambil dari KONSTANTA, bukan dari kiriman browser. Yang
     * boleh datang dari form hanya angkanya. Pola yang sama dengan rubrik lama:
     * standar penilaian tidak boleh bisa diubah dari sisi klien.
     *
     * @param array<int|string, mixed> $nilai  kiriman form, kunci = indeks kompetensi
     * @param array<string, mixed>     $narasi kiriman form, kunci = kunci NARASI
     *
     * @return list<array<string, mixed>>
     */
    public static function rakitHrd(array $nilai, array $narasi = []): array
    {
        $baris = [];

        foreach (self::HRD as $i => $kompetensi) {
            $n = self::angka($nilai[$i] ?? null, self::MAKS_SKALA);
            if ($n === null) {
                continue;   // belum diisi: tidak ikut, BUKAN dianggap nol
            }
            $baris[] = [
                'kompetensi' => $kompetensi,
                'kategori'   => self::KAT_HRD,
                'bobot'      => 1,   // lembar BIPROO tidak membobot, semua sederajat
                'tingkat'    => (string) $n,
                'catatan'    => '',
            ];
        }

        foreach (self::NARASI as $kunci => $label) {
            $teks = self::bersih((string) ($narasi[$kunci] ?? ''));
            if ($teks === '') {
                continue;
            }
            // bobot 0: kotak narasi tidak pernah ikut dihitung jadi skor
            $baris[] = [
                'kompetensi' => $kunci,
                'kategori'   => self::KAT_NARASI,
                'bobot'      => 0,
                'tingkat'    => '',
                'catatan'    => $teks,
            ];
        }

        return $baris;
    }

    /**
     * Skor 0-100 dari baris HRD, untuk Gate 2.
     *
     * Skala 1-5 dipetakan ke 0-100 dengan 1 = 0 dan 5 = 100. Nilai terendah
     * memang harus bernilai nol: "Poor" bukan seperlima kemampuan, ia batas
     * bawah skalanya.
     *
     * @param list<array<string, mixed>> $penilaian
     *
     * @return int|null null = tak satu pun kompetensi dinilai
     */
    public static function skor(array $penilaian): ?int
    {
        $angka = [];
        foreach ($penilaian as $p) {
            if (($p['kategori'] ?? '') !== self::KAT_HRD) {
                continue;
            }
            $n = self::angka($p['tingkat'] ?? null, self::MAKS_SKALA);
            if ($n !== null) {
                $angka[] = $n;
            }
        }

        if ($angka === []) {
            return null;
        }

        $rata = array_sum($angka) / count($angka);

        return (int) round(($rata - 1) / (self::MAKS_SKALA - 1) * 100);
    }

    /**
     * Kompetensi terlemah, untuk ditulis di riwayat kandidat.
     *
     * Inilah yang membuat keputusan bisa dijelaskan ke kandidat yang bertanya:
     * bukan "skor 62", melainkan butir mana yang kurang.
     *
     * @param list<array<string, mixed>> $penilaian
     *
     * @return list<string>
     */
    public static function terlemah(array $penilaian, int $maks = 3): array
    {
        $hrd = [];
        foreach ($penilaian as $p) {
            if (($p['kategori'] ?? '') === self::KAT_HRD) {
                $n = self::angka($p['tingkat'] ?? null, self::MAKS_SKALA);
                if ($n !== null) {
                    $hrd[] = ['kompetensi' => (string) $p['kompetensi'], 'n' => $n];
                }
            }
        }
        if ($hrd === []) {
            return [];
        }

        // Hanya yang di BAWAH "Average". Mengurutkan lalu mengambil tiga teratas
        // tanpa saringan membuat kandidat yang semuanya bagus tetap punya
        // "terlemah" - itu menyesatkan pembaca riwayat.
        $lemah = array_filter($hrd, static fn (array $p): bool => $p['n'] < 3);
        usort($lemah, static fn (array $a, array $b): int => $a['n'] <=> $b['n']);

        return array_slice(array_column($lemah, 'kompetensi'), 0, $maks);
    }

    /** Angka 1..$maks, atau null bila kosong / di luar rentang / bukan angka. */
    private static function angka(mixed $nilai, int $maks): ?int
    {
        if (! is_numeric($nilai)) {
            return null;
        }
        $n = (int) $nilai;

        return $n >= 1 && $n <= $maks ? $n : null;
    }

    /** Rapikan teks isian bebas agar muat di kolom catatan. */
    public static function rapikan(string $teks): string
    {
        return self::bersih($teks);
    }

    private static function bersih(string $teks): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $teks)), 0, self::MAKS_CATATAN);
    }
}
