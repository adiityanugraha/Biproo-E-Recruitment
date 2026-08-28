<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Slot jadwal interview yang dikelola recruiter, berikut kuotanya.
 *
 * Menggantikan daftar slot yang dulu dihasilkan kode (SlotJadwal). Yang tersisa
 * di kelas itu tinggal urusan tanggal murni - menghitung hari kerja - dan itu
 * memang tidak menyentuh basis data.
 */
class SlotInterviewModel extends Model
{
    protected $table         = 'slot_interview';
    protected $allowedFields = ['scheduled_at', 'kuota', 'created_at'];

    /** Kuota terbesar yang masuk akal untuk satu jam wawancara. */
    public const MAKS_KUOTA = 20;

    public const FORMAT = 'Y-m-d H:i:s';

    /**
     * Slot yang MASIH BOLEH DIPILIH pada waktu $now, terurut dari yang terdekat.
     *
     * Yang jamnya sudah lewat tidak ikut: slot lama sengaja TIDAK dihapus dari
     * tabel, karena ia jejak jadwal yang pernah berlaku dan masih ditunjuk
     * wawancara yang sudah terjadi.
     *
     * @return list<array<string, mixed>>
     */
    public function tersedia(?DateTimeInterface $now = null): array
    {
        $batas = ($now === null ? new DateTimeImmutable() : DateTimeImmutable::createFromInterface($now))
            ->format(self::FORMAT);

        return $this->where('scheduled_at >', $batas)->orderBy('scheduled_at')->findAll();
    }

    /** Seluruh slot untuk halaman pengaturan, termasuk yang sudah lewat. */
    public function semua(): array
    {
        return $this->orderBy('scheduled_at')->findAll();
    }

    /**
     * Kuota satu waktu tertentu. 0 = waktunya bukan slot yang terdaftar.
     */
    public function kuota(string $scheduledAt): int
    {
        $baris = $this->where('scheduled_at', $scheduledAt)->first();

        return $baris === null ? 0 : max(0, (int) $baris['kuota']);
    }
}
