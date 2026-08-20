<?php

namespace App\Libraries;

/**
 * Menebak rumpun pekerjaan dari judul lowongan, untuk posisi yang TIDAK ada di
 * bank pertanyaan tim DS.
 *
 * Gagasan dan daftar kata kuncinya diadaptasi dari InterviewQuestionModel milik
 * tim DS (Fitri, 4 Agustus 2026). Yang diambil bukan kodenya - kode itu berdiri
 * di atas tabel interview_questions dan job_category_overrides yang tidak ada di
 * sini, dan migrasinya memakai sintaks MySQL - melainkan rancangannya:
 *
 *   1. tebak dari kata kunci judul;
 *   2. kalau tidak ketemu, JANGAN diam-diam memakai kategori asal. Kembalikan
 *      penanda bahwa ini tebakan supaya UI bisa mengatakannya ke recruiter.
 *
 * Butir 2 itu inti sebenarnya. Menyajikan pertanyaan gudang kepada pelamar kasir
 * tanpa memberi tahu siapa pun adalah kegagalan diam - jenis yang sama dengan
 * skor karangan yang sudah kita buang dari pipeline ini.
 *
 * Rumpun di sini memakai nilai job_family apa adanya dari
 * interview_softskill_hardskill.csv, supaya hasil tebakan bisa langsung
 * dicocokkan dengan kolom jobs.kategori hasil impor.
 */
final class KategoriPosisi
{
    /**
     * Kata kunci per rumpun, dicocokkan sebagai substring pada judul posisi.
     *
     * Urutan penting: yang lebih khusus didahulukan, karena judul seperti
     * "Sales Administration XPENG" memuat kata "sales" maupun "administration".
     *
     * @var array<string, list<string>>
     */
    private const KATA_KUNCI = [
        'Warehouse & Logistik'     => ['gudang', 'kurir', 'warehouse', 'logistik', 'oca'],
        'Security'                 => ['security', 'satpam', 'keamanan'],
        // Admin & Customer Service HARUS didahulukan dari Teknisi: "Customer
        // Service Officer" memuat kata "service".
        'Admin & Customer Service' => ['customer service', 'cs officer', 'administration',
            'administrasi', 'admin'],
        // 'service' telanjang sengaja TIDAK dipakai - terlalu rakus, ia menelan
        // Customer Service. Yang khas teknisi adalah kata teknisi itu sendiri.
        'Teknisi & Service'        => ['teknisi', 'technician', 'servis'],
        'F&B Retail'               => ['f&b', 'baguette', 'resto', 'cafe', 'barista'],
        'Automotive Sales'         => ['xpeng', 'otomotif', 'automotive', 'mobil'],
        'Store Generalist'         => ['store', 'frontliner', 'toko'],
        'Retail Gadget Sales'      => ['sales', 'retail', 'promotor', 'gadget',
            'dailyworker', 'part time', 'tam ', 'erafone', 'ibox', 'samsung', 'garmin'],
    ];

    /**
     * Rumpun yang dikenali, untuk dipilih recruiter saat membuat lowongan.
     *
     * Diambil dari kunci KATA_KUNCI supaya cuma ada satu sumber: rumpun yang
     * bisa ditebak dari judul dan rumpun yang bisa dipilih di form selalu
     * daftar yang sama. Lowongan hasil impor tim DS bisa memuat job_family di
     * luar daftar ini; yang menggabungkannya Recruiter::daftarKategori(),
     * bukan di sini - berkas ini tidak menyentuh basis data.
     *
     * @return list<string>
     */
    public static function rumpun(): array
    {
        return array_keys(self::KATA_KUNCI);
    }

    /**
     * @return array{kategori: string|null, cocok: bool}
     *         kategori null = tidak ada tebakan sama sekali.
     *         cocok false   = jangan perlakukan hasilnya sebagai kepastian.
     */
    public static function tebak(string $judul): array
    {
        $j = mb_strtolower(trim($judul));
        if ($j === '') {
            return ['kategori' => null, 'cocok' => false];
        }

        foreach (self::KATA_KUNCI as $rumpun => $kunci) {
            foreach ($kunci as $k) {
                if (str_contains($j, $k)) {
                    return ['kategori' => $rumpun, 'cocok' => false];
                }
            }
        }

        // Sengaja TIDAK ada kategori cadangan. Tim DS memakai 'sales_retail'
        // sebagai fallback supaya recruiter tidak melihat layar kosong; di sini
        // layar kosong justru jawaban yang benar, karena halamannya menawarkan
        // pembuatan pertanyaan lewat AI sebagai jalan keluar. Menebak "sales"
        // untuk posisi yang tak dikenali cuma memindahkan kesalahan ke tempat
        // yang lebih sulit terlihat.
        return ['kategori' => null, 'cocok' => false];
    }
}
