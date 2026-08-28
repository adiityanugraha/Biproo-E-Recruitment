<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Urusan TANGGAL untuk slot interview. Tidak menyentuh basis data sama sekali.
 *
 * Sampai 28 Agustus 2026 kelas ini juga yang MENENTUKAN slot apa saja yang ada:
 * 10.00-16.00, hari kerja, 7 hari kerja ke depan, satu slot satu orang. Aturan
 * itu terkunci di kode, sehingga libur nasional, hari recruiter berhalangan,
 * atau jam yang ingin ditutup semuanya menuntut perubahan kode.
 *
 * Sekarang daftarnya ada di tabel slot_interview dan dikelola recruiter lewat
 * Settings. Yang tersisa di sini dua hal yang memang murni perhitungan tanggal:
 * menghitung hari kerja untuk tombol "ulangi 7 hari kerja", dan mengelompokkan
 * slot per tanggal untuk ditampilkan.
 *
 * Fungsi murni: "sekarang" bisa disuntik, jadi batas-batasnya bisa diuji tepat
 * di detiknya tanpa menunggu waktu nyata.
 */
final class SlotJadwal
{
    public const FORMAT = 'Y-m-d H:i:s';

    /** Bawaan tombol ulangi, sama dengan pola yang dulu terkunci di kode. */
    public const HARI_KERJA = 7;

    /**
     * Tanggal hari kerja berturut-turut mulai dari $mulai, akhir pekan dilewati.
     *
     * $mulai ikut dihitung bila ia hari kerja. Dipakai tombol "ulangi untuk 7
     * hari kerja ke depan" di halaman pengaturan jadwal.
     *
     * @return list<string> masing-masing 'Y-m-d'
     */
    public static function hariKerja(int $jumlah, ?DateTimeInterface $mulai = null): array
    {
        $hari = ($mulai === null ? new DateTimeImmutable() : DateTimeImmutable::createFromInterface($mulai))
            ->setTime(0, 0);

        $tanggal = [];
        while (count($tanggal) < max(0, $jumlah)) {
            // 6 = Sabtu, 7 = Minggu (ISO-8601)
            if ((int) $hari->format('N') <= 5) {
                $tanggal[] = $hari->format('Y-m-d');
            }
            $hari = $hari->modify('+1 day');
        }

        return $tanggal;
    }

    /**
     * Slot dikelompokkan per tanggal untuk ditampilkan ke kandidat.
     *
     * KUOTA DIBANDINGKAN DENGAN JUMLAH PEMAKAI, bukan sekadar ada atau tidak.
     * Slot berkuota 2 yang baru diambil satu orang masih boleh dipilih, dan
     * kandidat perlu melihat sisanya - "1 dari 2 terisi" jauh lebih berguna
     * daripada tombol yang entah kenapa masih menyala.
     *
     * @param list<array<string, mixed>> $slot     baris slot_interview
     * @param array<string, int>         $terpakai jumlah pemakai per waktu
     *
     * @return array<string, list<array{waktu: string, jam: string, kuota: int, terpakai: int, penuh: bool}>>
     */
    public static function perTanggal(array $slot, array $terpakai = []): array
    {
        $keluar = [];
        foreach ($slot as $s) {
            $waktu = (new DateTimeImmutable((string) $s['scheduled_at']))->format(self::FORMAT);
            $kuota = max(0, (int) ($s['kuota'] ?? 1));
            $pakai = (int) ($terpakai[$waktu] ?? 0);

            $keluar[substr($waktu, 0, 10)][] = [
                'waktu'    => $waktu,
                'jam'      => substr($waktu, 11, 5),
                'kuota'    => $kuota,
                'terpakai' => $pakai,
                'penuh'    => $pakai >= $kuota,
            ];
        }

        return $keluar;
    }
}
