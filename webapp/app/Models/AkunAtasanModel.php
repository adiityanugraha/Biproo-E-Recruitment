<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Akun atasan untuk Interview User, satu per lowongan (19 Agustus 2026).
 *
 * Terpisah dari recruiters: akun ini hanya boleh melihat kandidat lowongannya
 * sendiri. Lihat alasan lengkapnya di migrasi AkunAtasanPerPosisi.
 */
class AkunAtasanModel extends Model
{
    protected $table         = 'akun_atasan';
    protected $allowedFields = ['job_id', 'nama', 'email', 'password_hash', 'dikirim_at'];

    /**
     * Panjang sandi acak yang dikirim ke atasan.
     *
     * 12 karakter dari alfabet 32 huruf = sekitar 60 bit, jauh di atas yang
     * bisa ditebak. Huruf yang mudah tertukar saat dibaca dari email - 0, O,
     * 1, l, I - sengaja tidak dipakai: sandi ini dibaca manusia lalu diketik
     * ulang, dan satu salah ketik berarti satu keluhan ke HRD.
     */
    public const PANJANG_SANDI = 12;
    public const ALFABET       = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** @return array<string, mixed>|null */
    public function untukLowongan(int $jobId): ?array
    {
        return $this->where('job_id', $jobId)->first();
    }

    /** @return array<string, mixed>|null */
    public function untukEmail(string $email): ?array
    {
        return $this->where('email', $email)->first();
    }

    /** Sandi acak yang bisa dibaca ulang orang tanpa salah ketik. */
    public static function sandiAcak(): string
    {
        $panjang = strlen(self::ALFABET) - 1;
        $out     = '';
        for ($i = 0; $i < self::PANJANG_SANDI; $i++) {
            $out .= self::ALFABET[random_int(0, $panjang)];
        }

        return $out;
    }

    /**
     * Buat akunnya, atau perbarui yang sudah ada, dan kembalikan sandi barunya.
     *
     * SELALU menerbitkan sandi baru, termasuk saat cuma namanya yang diperbaiki.
     * Alasannya: satu-satunya saat kredensial itu terkirim adalah di sini, dan
     * membiarkan sandi lama berlaku sementara emailnya menyebut sandi baru
     * membuat atasan mencoba yang salah lalu mengira akunnya rusak.
     *
     * @return string sandi mentah - HANYA di sini ia pernah ada dalam bentuk
     *                terbaca, untuk langsung dimasukkan ke email
     */
    public function terbitkan(int $jobId, string $nama, string $email): string
    {
        $sandi = self::sandiAcak();
        $data  = [
            'job_id'        => $jobId,
            'nama'          => $nama,
            'email'         => $email,
            'password_hash' => password_hash($sandi, PASSWORD_DEFAULT),
            'dikirim_at'    => date('Y-m-d H:i:s'),
        ];

        $ada = $this->untukLowongan($jobId);
        if ($ada === null) {
            $this->insert($data);
        } else {
            $this->update($ada['id'], $data);
        }

        return $sandi;
    }
}
