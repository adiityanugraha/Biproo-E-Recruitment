<?php

use App\Models\CandidateModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Syarat sandi kandidat.
 *
 * Sebelum ini syaratnya cuma panjang 8, jadi "password" dan "12345678" lolos -
 * dua sandi yang paling sering dipakai orang, dan tiga halaman login di
 * aplikasi ini tidak membatasi jumlah percobaan.
 *
 * Yang dijaga di sini BUKAN regexnya, melainkan akibatnya: sandi lemah tidak
 * boleh sampai membuat baris di tabel candidates.
 *
 * @internal
 */
final class RegistrasiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private function daftar(string $sandi, string $email = 'calon@uji.test')
    {
        return $this->post('daftar', [
            'nama'     => 'Calon Kandidat',
            'email'    => $email,
            'password' => $sandi,
        ]);
    }

    public function testSandiLengkapDiterima(): void
    {
        $this->daftar('Rahasia123');

        $this->seeInDatabase('candidates', ['email' => 'calon@uji.test']);
    }

    /**
     * @dataProvider sandiLemah
     */
    public function testSandiLemahDitolak(string $sandi, string $sebab): void
    {
        $this->daftar($sandi);

        $this->assertSame(
            0,
            (new CandidateModel())->where('email', 'calon@uji.test')->countAllResults(),
            "sandi '{$sandi}' seharusnya ditolak: {$sebab}",
        );
    }

    public static function sandiLemah(): array
    {
        return [
            'tanpa huruf besar' => ['rahasia123', 'tidak ada huruf kapital'],
            'tanpa angka'       => ['RahasiaKu', 'tidak ada angka'],
            'tanpa huruf kecil' => ['RAHASIA123', 'tidak ada huruf kecil'],
            'kurang dari 8'     => ['Rhs123', 'cuma 6 karakter'],
            'kata umum'         => ['password', 'sandi paling sering dipakai'],
            'deret angka'       => ['12345678', 'sandi kedua paling sering dipakai'],
        ];
    }

    public function testKandidatDiberitahuSyaratnya(): void
    {
        /*
         * Ditolak tanpa menyebut sebabnya membuat orang mencoba sandi lemah
         * lain sampai menyerah, lalu memakai email orang lain untuk mendaftar
         * ulang. Pesannya harus menyebut yang kurang.
         */
        $hasil = $this->daftar('rahasia123');

        $hasil->assertSee('huruf besar');
    }

    public function testFormMenyebutSyaratnyaSebelumDiisi(): void
    {
        $this->get('daftar')->assertSee('huruf besar');
    }
}
