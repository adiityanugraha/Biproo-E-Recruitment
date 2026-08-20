<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeInterface;

class InterviewModel extends Model
{
    /**
     * Jendela link Zoom kandidat: aktif sejak 15 menit sebelum jadwal, mati 30
     * menit sesudah jam mulai. Slot 10.00 bisa dimasuki 09.45 dan mati 10.30.
     *
     * TUTUP_MENIT 30 (dulu 60) mengikuti arahan atasan 3 Agustus 2026: durasi
     * satu sesi interview 30 menit, di luar itu link tidak berlaku lagi.
     */
    public const BUKA_MENIT  = 15;
    public const TUTUP_MENIT = 30;

    protected $table         = 'interviews';
    protected $allowedFields  = ['application_id', 'jenis', 'status', 'scheduled_at', 'meeting_id', 'join_url', 'start_url', 'recording_url'];

    /**
     * Wawancara oleh recruiter, dan wawancara oleh calon atasan.
     *
     * Dua-duanya memakai tabel dan alur penjadwalan yang sama - kandidat
     * memilih slot, Zoom dibuatkan seketika - yang berbeda siapa yang
     * mewawancarai dan di layar siapa tautannya muncul.
     */
    public const JENIS_HRD  = 'hrd';
    public const JENIS_USER = 'user';

    protected $validationRules = [
        'application_id' => 'required|is_natural_no_zero',
        // rescheduled = recruiter meminta kandidat memilih slot lain. Dibedakan
        // dari rejected (menolak jam yang DIAJUKAN kandidat, alur lama) karena
        // peristiwanya beda: di sini jadwalnya tadinya sudah pasti.
        'status'         => 'required|in_list[requested,approved,rejected,rescheduled]',
        'scheduled_at'   => 'required|valid_date',
    ];

    /**
     * Jadwal terkini sebuah lamaran untuk SATU jenis wawancara.
     *
     * Jenisnya wajib disebut lewat bawaan 'hrd', bukan diambil yang terbaru:
     * sejak Interview User punya jadwalnya sendiri, "yang terbaru" akan
     * mengembalikan jadwal atasan kepada ruang interview HRD - lengkap dengan
     * tautan Zoom ke ruangan yang salah.
     */
    public function forApplication(int $applicationId, string $jenis = self::JENIS_HRD): ?array
    {
        return $this->where('application_id', $applicationId)
            ->where('jenis', $jenis)
            ->orderBy('id', 'DESC')->first();
    }

    /**
     * Slot yang sudah dikunci kandidat lain, sebagai 'Y-m-d H:i:s'.
     *
     * Berlaku GLOBAL lintas posisi: pewawancaranya orang yang sama, jadi satu
     * jam hanya untuk satu kandidat (arahan atasan 3 Agustus 2026). Ajuan yang
     * ditolak TIDAK menahan slot - slotnya kembali ke daftar.
     *
     * Ini penyaring untuk tampilan. Penjamin sebenarnya indeks unik di database
     * (migrasi SlotInterviewUnik), karena pengecekan-lalu-simpan selalu punya
     * celah antara "dicek kosong" dan "disimpan".
     *
     * @return list<string>
     */
    public function slotTerpakai(string $jenis = self::JENIS_HRD): array
    {
        // Disaring per JENIS: pewawancaranya orang yang berbeda, jadi wawancara
        // HRD pukul 10.00 tidak menghalangi Interview User pukul 10.00. Indeks
        // uniknya di basis data memakai pasangan kolom yang sama.
        $baris = $this->select('scheduled_at')
            ->where('jenis', $jenis)
            ->whereIn('status', ['requested', 'approved'])
            ->findAll();

        return array_map(
            static fn (array $r): string => (new DateTimeImmutable($r['scheduled_at']))->format('Y-m-d H:i:s'),
            $baris
        );
    }

    /**
     * Apakah link Zoom kandidat masih boleh dipakai pada waktu $now.
     * Fungsi murni (tanpa DB, waktu bisa disuntik) supaya langsung bisa dites.
     *
     * Zoom tidak punya mekanisme kedaluwarsa untuk join_url - hanya start_url
     * (token zak) yang mati sendiri setelah 2 jam. Jadi jendela ini kita hitung
     * sendiri dari scheduled_at, dan penjaganya Lamaran::masukInterview.
     */
    public static function linkAktif(string $scheduledAt, ?DateTimeInterface $now = null): bool
    {
        $jadwal = new DateTimeImmutable($scheduledAt);
        $now ??= new DateTimeImmutable();

        return $now >= $jadwal->modify('-' . self::BUKA_MENIT . ' minutes')
            && $now <= $jadwal->modify('+' . self::TUTUP_MENIT . ' minutes');
    }

    /**
     * Sesinya sudah lewat, jadi wawancaranya semestinya sudah terjadi.
     *
     * Batasnya sama dengan matinya link Zoom kandidat: jadwal + TUTUP_MENIT.
     * Slot 10.00 dianggap selesai pukul 10.30. Satu aturan, bukan dua - tab
     * "Selesai" di tabel Interview HRD memakai batas yang sama (lihat
     * Recruiter::jadwalPerTab), dan kalau keduanya ditulis terpisah suatu hari
     * salah satunya bergeser tanpa ada yang tahu.
     *
     * Fungsi murni (tanpa DB, waktu bisa disuntik) supaya langsung bisa dites.
     */
    public static function sudahSelesai(string $scheduledAt, ?DateTimeInterface $now = null): bool
    {
        $jadwal = new DateTimeImmutable($scheduledAt);
        $now ??= new DateTimeImmutable();

        return $now >= $jadwal->modify('+' . self::TUTUP_MENIT . ' minutes');
    }

    /**
     * Sesi interview benar-benar siap dimasuki kandidat: jadwalnya sudah di-acc
     * recruiter DAN jendela waktunya terbuka. Dipakai halaman jadwal maupun
     * stepper dashboard - keduanya harus sepakat, kalau tidak stepper menyala
     * padahal tombol Zoom-nya belum ada.
     *
     * @param array|null $iv baris interviews, null = belum ada ajuan
     */
    public static function siapDimasuki(?array $iv, ?DateTimeInterface $now = null): bool
    {
        return $iv !== null
            && $iv['status'] === 'approved'
            && self::linkAktif($iv['scheduled_at'], $now);
    }
}
