<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class JobSeeder extends Seeder
{
    public function run()
    {
        $jobs = [
            [
                'judul'          => 'Frontliner Retail Gadget - Kota Pontianak',
                'req_skill'      => 'Komunikasi, penjualan retail, product knowledge gadget',
                'req_pendidikan' => 'SMA/SMK sederajat',
                'req_pengalaman' => '1 tahun di retail atau customer service',
                'deskripsi'      => 'Melayani pelanggan dan penjualan di toko retail gadget Erafone.',
            ],
            [
                'judul'          => 'Admin Gudang',
                'req_skill'      => 'Administrasi stok, Excel, ketelitian data',
                'req_pendidikan' => 'D3 semua jurusan',
                'req_pengalaman' => '1 tahun administrasi gudang/logistik',
                'deskripsi'      => 'Mengelola pencatatan stok masuk-keluar gudang.',
            ],
            [
                'judul'          => 'Backend Developer',
                'req_skill'      => 'PHP, CodeIgniter, SQL Server, REST API',
                'req_pendidikan' => 'S1 Teknik Informatika / sederajat',
                'req_pengalaman' => '2 tahun backend development',
                'deskripsi'      => 'Membangun dan memelihara sistem internal perusahaan.',
            ],
        ];

        foreach ($jobs as $job) {
            // idempoten: seed ulang tidak menggandakan baris
            if ($this->db->table('jobs')->where('judul', $job['judul'])->countAllResults() === 0) {
                $this->db->table('jobs')->insert($job);
            }
        }
    }
}
