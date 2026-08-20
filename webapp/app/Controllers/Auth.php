<?php

namespace App\Controllers;

use App\Models\CandidateModel;

class Auth extends BaseController
{
    public function daftar()
    {
        if ($this->request->is('post')) {
            $rules = [
                'nama'  => 'required|min_length[3]|max_length[160]',
                'email' => 'required|valid_email|is_unique[candidates.email]',
                // Bentuk larik, bukan rangkaian berpemisah '|': regexnya memuat
                // tanda kurung siku, dan aturan yang dirangkai gampang terpotong
                // di tempat yang salah begitu polanya ditambah.
                'password' => [
                    'rules' => [
                        'required',
                        'min_length[8]',
                        // Huruf kecil, huruf besar, dan angka. Tiga kelas ini
                        // yang menghalangi sandi seperti 'password' dan
                        // '12345678' - dua yang paling sering dipakai orang.
                        'regex_match[/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/]',
                    ],
                ],
            ];
            $pesan = [
                'email'    => ['is_unique' => 'Email sudah terdaftar - silakan login.'],
                'password' => [
                    'min_length'  => 'Password minimal 8 karakter.',
                    'regex_match' => 'Password harus memuat huruf besar, huruf kecil, dan angka.',
                ],
            ];
            if (! $this->validate($rules, $pesan)) {
                return view('auth/daftar', ['errors' => $this->validator->getErrors()]);
            }

            $id = (new CandidateModel())->insert([
                'nama'          => $this->request->getPost('nama'),
                'email'         => $this->request->getPost('email'),
                'password_hash' => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
            ]);

            session()->set(['candidate_id' => $id, 'candidate_nama' => $this->request->getPost('nama')]);

            return redirect()->to('/dashboard')->with('sukses', 'Akun berhasil dibuat - selamat datang!');
        }

        return view('auth/daftar');
    }

    public function login()
    {
        if ($this->request->is('post')) {
            $kandidat = (new CandidateModel())
                ->where('email', $this->request->getPost('email'))
                ->first();

            if ($kandidat === null || ! password_verify((string) $this->request->getPost('password'), $kandidat['password_hash'])) {
                return view('auth/login', ['errors' => ['login' => 'Email atau password salah.']]);
            }

            session()->set(['candidate_id' => $kandidat['id'], 'candidate_nama' => $kandidat['nama']]);

            return redirect()->to('/dashboard');
        }

        return view('auth/login');
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/login');
    }
}
