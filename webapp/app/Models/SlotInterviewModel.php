<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Slot jadwal interview, terpisah per jenis wawancara dan - untuk Interview
 * User - per posisi.
 *
 * Menggantikan daftar slot yang dulu dihasilkan kode (SlotJadwal). Yang tersisa
 * di kelas itu tinggal urusan tanggal murni - menghitung hari kerja - dan itu
 * memang tidak menyentuh basis data.
 *
 * KENAPA DIPISAH (31 Agustus 2026). Yang mewawancarai HRD dan yang mewawancarai
 * atasan dua orang berbeda dengan kesibukan berbeda, dan tiap posisi punya
 * atasannya sendiri. Satu daftar bersama membuat jam yang dibuka recruiter
 * otomatis jadi jam atasan, dan kandidat posisi A menutup jam kandidat posisi B.
 */
class SlotInterviewModel extends Model
{
    protected $table         = 'slot_interview';
    protected $allowedFields = ['scheduled_at', 'jenis', 'job_id', 'kuota', 'created_at'];

    /** Kuota terbesar yang masuk akal untuk satu jam wawancara. */
    public const MAKS_KUOTA = 20;

    public const FORMAT = 'Y-m-d H:i:s';

    /**
     * Posisi untuk slot yang tidak terikat posisi mana pun.
     *
     * 0, bukan NULL: SQL Server menganggap dua NULL sama di indeks unik,
     * SQLite menganggapnya berbeda. Baris kembar akan ditolak di produksi tapi
     * lolos di seluruh berkas uji - jenis selisih yang paling mahal ditemukan.
     */
    public const TANPA_POSISI = 0;

    /**
     * Slot yang MASIH BOLEH DIPILIH pada waktu $now, terurut dari yang terdekat.
     *
     * Yang jamnya sudah lewat tidak ikut: slot lama sengaja TIDAK dihapus dari
     * tabel, karena ia jejak jadwal yang pernah berlaku dan masih ditunjuk
     * wawancara yang sudah terjadi.
     *
     * @return list<array<string, mixed>>
     */
    public function tersedia(string $jenis, int $jobId = self::TANPA_POSISI, ?DateTimeInterface $now = null): array
    {
        $batas = ($now === null ? new DateTimeImmutable() : DateTimeImmutable::createFromInterface($now))
            ->format(self::FORMAT);

        return $this->normalkan(
            $this->where('jenis', $jenis)->where('job_id', $jobId)
                ->where('scheduled_at >', $batas)->orderBy('scheduled_at')->findAll()
        );
    }

    /** Seluruh slot satu jenis untuk halaman pengaturan, termasuk yang sudah lewat. */
    public function semua(string $jenis, int $jobId = self::TANPA_POSISI): array
    {
        return $this->normalkan(
            $this->where('jenis', $jenis)->where('job_id', $jobId)->orderBy('scheduled_at')->findAll()
        );
    }

    /**
     * Kuota satu waktu tertentu. 0 = waktunya bukan slot yang terdaftar.
     *
     * Dicari di daftar yang SUDAH dinormalkan, bukan lewat WHERE. Alasannya
     * sama dengan normalkan() di bawah: bentuk teks waktu dari basis data tidak
     * bisa dipercaya sama dengan bentuk yang dikirim formulir.
     *
     * ponytail: pemindaian penuh satu jenis, sekali per pemilihan slot.
     * Tabelnya puluhan sampai ratusan baris; kalau kelak jadi puluhan ribu,
     * ganti dengan WHERE pada kolom yang sudah dinormalkan saat disimpan.
     */
    public function kuota(string $scheduledAt, string $jenis, int $jobId = self::TANPA_POSISI): int
    {
        foreach ($this->semua($jenis, $jobId) as $baris) {
            if ($baris['scheduled_at'] === $scheduledAt) {
                return max(0, (int) $baris['kuota']);
            }
        }

        return 0;
    }

    /**
     * scheduled_at disamakan bentuknya jadi 'Y-m-d H:i:s'.
     *
     * DRIVER SQLSRV MENGEMBALIKAN PECAHAN DETIK: "2026-08-28 10:00:00.000",
     * 23 karakter, sementara seluruh sisa sistem - formulir jadwal kandidat,
     * kunci hitungPerSlot(), nilai yang disimpan ke tabel interviews - memakai
     * 19 karakter tanpa pecahan. Selisih empat karakter itu membuat SETIAP
     * pemilihan slot ditolak "Slot itu tidak tersedia", padahal slotnya jelas
     * tertulis di layar kandidat.
     *
     * Dinormalkan DI SINI, di tempat nilainya lahir, bukan di pemanggilnya:
     * yang membandingkan bukan cuma satu tempat, dan yang berikutnya menulis
     * perbandingan baru tidak akan tahu ada jebakan ini.
     *
     * TIDAK terlihat satu pun uji: berkas uji memakai SQLite, yang
     * mengembalikan persis apa yang ditulis.
     *
     * @param  list<array<string, mixed>> $baris
     * @return list<array<string, mixed>>
     */
    private function normalkan(array $baris): array
    {
        foreach ($baris as &$b) {
            $b['scheduled_at'] = (new DateTimeImmutable((string) $b['scheduled_at']))->format(self::FORMAT);
        }
        unset($b);

        return $baris;
    }
}
