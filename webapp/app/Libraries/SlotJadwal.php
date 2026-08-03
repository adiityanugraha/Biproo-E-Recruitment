<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Daftar slot interview yang boleh dipilih kandidat.
 *
 * Kandidat TIDAK lagi mengetik waktu bebas: ia memilih dari daftar slot tetap
 * yang disiapkan sistem. Alasannya bukan pembatasan demi pembatasan - waktu
 * bebas berarti recruiter harus menilai tiap ajuan satu per satu, dan dua
 * kandidat bisa mengajukan jam yang sama.
 *
 * Aturan (arahan atasan, 3 Agustus 2026):
 *   - 7 slot per hari: 10.00, 11.00, 12.00, 13.00, 14.00, 15.00, 16.00
 *     (slot terakhir mulai 16.00 dan berakhir 17.00)
 *   - hanya hari kerja, 7 hari kerja ke depan
 *   - slot yang jamnya sudah lewat tidak ditawarkan lagi
 *
 * Fungsi murni: "sekarang" bisa disuntik, jadi batas-batasnya bisa diuji tepat
 * di detiknya tanpa menunggu waktu nyata. Keterpakaian slot oleh kandidat lain
 * BUKAN urusan kelas ini - itu dicek ke database (InterviewModel::slotTerpakai).
 */
final class SlotJadwal
{
    /** Jam mulai slot pertama dan terakhir dalam sehari. */
    public const JAM_PERTAMA = 10;
    public const JAM_TERAKHIR = 16;

    /** Berapa hari KERJA ke depan yang ditawarkan (akhir pekan dilewati). */
    public const HARI_KERJA = 7;

    public const FORMAT = 'Y-m-d H:i:s';

    /**
     * Semua slot yang sah pada waktu $now, terurut dari yang terdekat.
     *
     * @return list<string> masing-masing 'Y-m-d H:i:s'
     */
    public static function tersedia(?DateTimeInterface $now = null): array
    {
        $now  = $now === null ? new DateTimeImmutable() : DateTimeImmutable::createFromInterface($now);
        $hari = $now->setTime(0, 0);

        $slot = [];
        for ($terkumpul = 0; $terkumpul < self::HARI_KERJA;) {
            // 6 = Sabtu, 7 = Minggu (ISO-8601)
            if ((int) $hari->format('N') <= 5) {
                $terkumpul++;
                for ($jam = self::JAM_PERTAMA; $jam <= self::JAM_TERAKHIR; $jam++) {
                    $s = $hari->setTime($jam, 0);
                    // slot yang sudah dimulai tidak ditawarkan lagi
                    if ($s > $now) {
                        $slot[] = $s->format(self::FORMAT);
                    }
                }
            }
            $hari = $hari->modify('+1 day');
        }

        return $slot;
    }

    /**
     * Apakah $scheduledAt salah satu slot yang sah pada waktu $now?
     *
     * Sekaligus menutup empat hal dalam satu pemeriksaan: format benar, jamnya
     * termasuk daftar, harinya hari kerja, dan waktunya belum lewat. Kandidat
     * yang mengirim POST langsung tanpa lewat halaman tetap tersaring di sini.
     */
    public static function sah(string $scheduledAt, ?DateTimeInterface $now = null): bool
    {
        return in_array($scheduledAt, self::tersedia($now), true);
    }

    /**
     * Slot dikelompokkan per tanggal untuk ditampilkan.
     *
     * @param list<string> $terpakai slot yang sudah diambil kandidat lain
     *
     * @return array<string, list<array{waktu: string, jam: string, terpakai: bool}>>
     */
    public static function perTanggal(array $terpakai = [], ?DateTimeInterface $now = null): array
    {
        $keluar = [];
        foreach (self::tersedia($now) as $s) {
            $keluar[substr($s, 0, 10)][] = [
                'waktu'    => $s,
                'jam'      => substr($s, 11, 5),
                'terpakai' => in_array($s, $terpakai, true),
            ];
        }

        return $keluar;
    }
}
