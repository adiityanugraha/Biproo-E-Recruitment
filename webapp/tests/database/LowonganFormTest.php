<?php

use App\Libraries\AlurRekrutmen as A;
use App\Libraries\KategoriPosisi;
use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Form data lowongan di halaman Settings (20 Agustus 2026).
 *
 * Sebelum ini lowongan cuma bisa masuk lewat seeder dan perintah impor, jadi
 * menambah posisi berarti menyentuh basis data langsung.
 *
 * Yang paling dijaga di sini kolom syaratnya. Tiga bagian sistem membacanya -
 * skor kemiripan CV, penyusun pertanyaan, dan penilai kecocokan posisi - dan
 * ketiganya menjawab dengan yakin walau bahannya asal terisi.
 *
 * @internal
 */
final class LowonganFormTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private const ISI = [
        'judul'          => 'Sales Assistant - Retail Gadget (Ibox)',
        'kategori'       => 'Retail Gadget Sales',
        'req_skill'      => 'Perbandingan Spesifikasi, Garansi & After-sales, Cicilan',
        'req_pengalaman' => 'Entry level, terbuka untuk fresh graduate',
        'req_pendidikan' => '',
        'deskripsi'      => 'Retail Gadget Sales. Brand: Ibox. Level: Entry',
    ];

    private function simpan(array $ubah = [], ?int $jobId = null)
    {
        return $this->withSession($this->sesiRec)->post(
            'recruiter/pengaturan/lowongan' . ($jobId === null ? '' : '/' . $jobId),
            $ubah + self::ISI,
        );
    }

    // --- membuat ---

    public function testLowonganBaruTersimpan(): void
    {
        $this->simpan();

        $this->seeInDatabase('jobs', [
            'judul'    => self::ISI['judul'],
            'kategori' => 'Retail Gadget Sales',
        ]);
    }

    /**
     * Lowongan baru langsung punya alur, tanpa recruiter menyentuh halaman
     * alurnya. Kalau tidak, posisi yang baru dibuat menerima lamaran yang
     * kemudian tidak tahu harus melewati tahap apa.
     */
    public function testLowonganBaruLangsungPakaiAlurBawaan(): void
    {
        $this->simpan();
        $job = (new JobModel())->where('judul', self::ISI['judul'])->first();

        $this->assertNull($job['alur_json']);
        $this->assertSame(A::bawaan(), A::untukLowongan($job['alur_json']));
    }

    // --- syarat yang dibaca mesin penilai ---

    /**
     * @dataProvider syaratTakLayak
     */
    public function testSyaratTakLayakDitolak(array $ubah, string $sebab): void
    {
        $this->simpan($ubah);

        $this->assertSame(
            0,
            (new JobModel())->where('judul', $ubah['judul'] ?? self::ISI['judul'])->countAllResults(),
            "seharusnya ditolak: {$sebab}",
        );
    }

    public static function syaratTakLayak(): array
    {
        return [
            'keahlian sifat umum' => [['req_skill' => 'Rajin'], 'bukan keahlian yang bisa diuji'],
            'keahlian setrip'     => [['req_skill' => '-'], 'memenuhi required tapi kosong isinya'],
            'tanpa pengalaman'    => [['req_pengalaman' => ''], 'kolom pengalaman kosong'],
            'tanpa rumpun'        => [['kategori' => ''], 'rumpun belum dipilih'],
            'rumpun karangan'     => [['kategori' => 'Rumpun Karangan'], 'di luar daftar yang ada'],
            'judul terlalu pendek' => [['judul' => 'AB'], 'dua huruf bukan nama posisi'],
        ];
    }

    /**
     * Ditolak TANPA mengembalikan isian berarti recruiter mengetik ulang lima
     * kolom demi satu kolom yang salah, dan kolom syaratnya justru yang paling
     * panjang.
     */
    public function testIsianDikembalikanSaatDitolak(): void
    {
        $hasil = $this->simpan(['req_skill' => 'Rajin']);

        $hasil->assertSee(self::ISI['judul']);
        $hasil->assertSee('Entry level');
    }

    // --- mengubah ---

    public function testSuntingMengubahBarisYangAdaBukanMembuatBaru(): void
    {
        $this->simpan();
        $id = (int) (new JobModel())->where('judul', self::ISI['judul'])->first()['id'];

        $this->simpan(['judul' => 'Sales Assistant - Retail Gadget (Erafone)'], $id);

        $this->assertSame(1, (new JobModel())->countAllResults());
        $this->seeInDatabase('jobs', ['id' => $id, 'judul' => 'Sales Assistant - Retail Gadget (Erafone)']);
    }

    /**
     * Lowongan impor tim DS membawa job_family apa adanya dari CSV mereka, dan
     * daftar itu tidak dijamin sama dengan kata kunci di KategoriPosisi. Kalau
     * form menolak kategorinya sendiri, menyunting lowongan impor akan
     * memindahkannya ke rumpun lain diam-diam - memutuskannya dari bank soal
     * serumpunnya.
     */
    public function testKategoriImporDiLuarDaftarTetapBolehDipertahankan(): void
    {
        $asing = 'Rumpun Impor Tim DS';
        $this->assertNotContains($asing, KategoriPosisi::rumpun(), 'prasyarat: memang di luar daftar');

        $id = (int) (new JobModel())->insert([
            'judul'          => 'Posisi Impor',
            'kategori'       => $asing,
            'req_skill'      => 'Keahlian dari berkas impor',
            'req_pendidikan' => '',
            'req_pengalaman' => 'Menengah',
        ]);

        $this->simpan(['judul' => 'Posisi Impor', 'kategori' => $asing], $id);

        $this->seeInDatabase('jobs', ['id' => $id, 'kategori' => $asing]);
    }

    public function testLowonganTidakAdaTidakMeledak(): void
    {
        $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/lowongan/9999')
            ->assertRedirect();
    }

    // --- akibat kategori pilihan recruiter ---

    /**
     * Rumpun yang DIPILIH recruiter harus dipakai, bukan ditebak ulang dari
     * judulnya.
     *
     * Inilah satu-satunya alasan kolom rumpun ada di form. Judul buatan sendiri
     * seperti "Staff Operasional Cabang" tidak memuat satu pun kata kunci, jadi
     * KategoriPosisi::tebak() mengembalikan null - dan posisi itu berdiri tanpa
     * bank soal cadangan walaupun recruiter sudah menyatakan rumpunnya.
     */
    public function testRumpunPilihanRecruiterDipakaiSaatMeminjamBankSoal(): void
    {
        (new JobModel())->insert([
            'judul'           => 'Admin Staff',
            'kategori'        => 'Admin & Customer Service',
            'req_skill'       => 'Kontrol kualitas data, pengelolaan dokumen',
            'req_pendidikan'  => '',
            'req_pengalaman'  => 'Entry level',
            'pertanyaan_json' => json_encode(['Ceritakan pengalaman Anda merapikan dokumen yang berantakan.']),
        ]);

        $this->simpan([
            'judul'    => 'Staff Operasional Cabang',
            'kategori' => 'Admin & Customer Service',
        ]);
        $baru = (new JobModel())->where('judul', 'Staff Operasional Cabang')->first();

        $this->withSession($this->sesiRec)
            ->get('recruiter/pertanyaan/' . $baru['id'])
            ->assertSee('Admin Staff');
    }

    // --- jalan masuknya ---

    public function testFormBaruTampilBesertaSeluruhRumpun(): void
    {
        $hasil = $this->withSession($this->sesiRec)->get('recruiter/pengaturan/lowongan');

        $hasil->assertSee('Rumpun posisi');
        $hasil->assertSee('Keahlian yang dibutuhkan');
        foreach (KategoriPosisi::rumpun() as $r) {
            // esc(): "Warehouse & Logistik" sampai ke halaman sebagai
            // "Warehouse &amp; Logistik", dan memang seharusnya begitu.
            $hasil->assertSee(esc($r));
        }
    }

    /** Form sunting datang terisi, bukan kosong. */
    public function testFormSuntingTerisiDataLamanya(): void
    {
        $this->simpan();
        $id = (int) (new JobModel())->where('judul', self::ISI['judul'])->first()['id'];

        $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/lowongan/' . $id)
            ->assertSee(esc(self::ISI['req_skill']));
    }

    public function testHalamanPengaturanMenawarkanTambahDanSunting(): void
    {
        $this->simpan();

        $hasil = $this->withSession($this->sesiRec)->get('recruiter/pengaturan');

        $hasil->assertSee('Tambah Lowongan');
        $hasil->assertSee('pengaturan/lowongan');
    }
}
