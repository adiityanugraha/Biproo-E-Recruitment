<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Menyajikan berkas CV kandidat ke peramban.
 *
 * Dipakai dua peran yang berbeda pintu masuknya: recruiter lewat
 * Recruiter::cvKandidat, dan atasan lewat Atasan::cv. Dijadikan satu tempat
 * BUKAN demi kerapian, melainkan karena isinya kode keamanan - penjagaan
 * path traversal dan pembersihan header. Kode seperti itu yang disalin ke dua
 * tempat pada akhirnya diperbaiki di satu tempat saja.
 *
 * YANG TIDAK ADA DI SINI: penentuan siapa boleh melihat CV siapa. Itu tugas
 * pemanggilnya, dan sengaja tidak dititipkan ke kelas ini - aturannya berbeda
 * per peran. Recruiter boleh melihat seluruh kandidat; atasan hanya kandidat
 * pada posisinya sendiri.
 */
final class BerkasCv
{
    /**
     * @param string $cvPath relatif terhadap WRITEPATH, dari applications.cv_path
     *
     * @return ResponseInterface|null null = berkasnya tidak ada atau di luar
     *                                folder unggahan; pemanggil yang memutuskan
     *                                pesan galatnya
     */
    public static function sajikan(ResponseInterface $response, string $cvPath, string $namaKandidat): ?ResponseInterface
    {
        // cv_path selalu buatan Lamaran::kirim (nama acak), tapi tetap dipastikan
        // berada DI DALAM folder unggahan. Satu baris database yang tercemar tidak
        // boleh berubah jadi pembaca berkas sembarang di server.
        $dasar = realpath(WRITEPATH . 'uploads/cv');
        $path  = realpath(WRITEPATH . $cvPath);
        if ($dasar === false || $path === false || ! str_starts_with($path, $dasar . DIRECTORY_SEPARATOR)) {
            return null;
        }

        // Nama kandidat masuk header Content-Disposition, jadi karakter yang bisa
        // menyisipkan header baru atau menutup tanda kutip dibuang lebih dulu.
        $aman = preg_replace('/[^A-Za-z0-9 _.-]/', '', $namaKandidat) ?: 'Kandidat';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $nama = 'CV - ' . trim($aman) . '.' . $ext;

        // PDF ditampilkan langsung di peramban supaya pewawancara bisa membacanya
        // sambil menyiapkan wawancara tanpa mengunduh dulu. DOCX tidak bisa
        // dirender peramban, jadi tetap diunduh.
        if ($ext === 'pdf') {
            return $response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $nama . '"')
                ->setBody((string) file_get_contents($path));
        }

        return $response->download($path, null)->setFileName($nama);
    }
}
