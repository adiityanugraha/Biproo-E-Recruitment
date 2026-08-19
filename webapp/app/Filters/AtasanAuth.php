<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Area Interview User: hanya untuk atasan yang login (19 Agustus 2026).
 *
 * Sesi atasan DIPISAH dari sesi recruiter dan kandidat, dan itu bukan sekadar
 * kerapian. Akun atasan hanya berhak melihat pelamar SATU lowongan; kalau ia
 * berbagi kunci sesi dengan recruiter, satu kekeliruan saat menyalin kode
 * halaman sudah cukup untuk membuka seluruh data rekrutmen kepada orang di luar
 * HRD.
 *
 * atasan_job_id ikut disimpan di sesi dan ikut diperiksa tiap kueri, jadi
 * pembatasan posisinya tidak bergantung pada tautan yang diklik.
 */
class AtasanAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session('atasan_id')) {
            return redirect()->to('/atasan/login')->with('error', 'Silakan masuk dengan akun Interview User.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
