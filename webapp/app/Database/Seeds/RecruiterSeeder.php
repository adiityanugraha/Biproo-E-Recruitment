<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/** Akun recruiter internal (tidak ada registrasi mandiri). */
class RecruiterSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('recruiters')->where('email', 'recruiter@biproo.test')->countAllResults() === 0) {
            $this->db->table('recruiters')->insert([
                'nama'          => 'Irpan Apandi',
                'email'         => 'recruiter@biproo.test',
                'password_hash' => password_hash('recruiter123', PASSWORD_DEFAULT), // dev only, ganti saat deploy
            ]);
        }
    }
}
