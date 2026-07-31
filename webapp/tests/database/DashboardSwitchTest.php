<?php

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Pemilih lamaran di dua halaman kandidat: stepper dashboard ("Proses Seleksi")
 * dan Status Lamaran. Keduanya menampilkan satu posisi pada satu waktu lewat
 * Lamaran::pilihLamaran(), jadi keduanya diuji berdampingan di sini.
 *
 * CATATAN JUJUR soal jangkauan uji ini: bug aslinya adalah beda tipe antar
 * driver - sqlsrv mengembalikan kolom INT sebagai string, SQLite sebagai int -
 * sehingga perbandingan ketat selalu gagal di produksi tapi lolos di sini.
 * Berkas uji ini berjalan di atas SQLite, jadi ia TIDAK bisa memunculkan
 * pemicu itu. Yang dijaganya adalah logika pemilihannya; penjaga untuk beda
 * tipenya adalah normalisasi (int) di pilihLamaran() berikut komentarnya.
 *
 * @internal
 */
final class DashboardSwitchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private int $cid;
    private int $lamaLama;
    private int $lamaBaru;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cid = (int) (new CandidateModel())->insert(
            ['nama' => 'Uji', 'email' => 'uji@example.test', 'password_hash' => 'x']
        );
        $jobs = [];
        foreach (['Backend Developer', 'Admin Gudang'] as $judul) {
            $jobs[$judul] = (new JobModel())->insert(
                ['judul' => $judul, 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C']
            );
        }
        $apps = new ApplicationModel();
        // dilamar berurutan: Backend lebih dulu, Admin Gudang menyusul (paling baru)
        $this->lamaLama = (int) $apps->insert(['candidate_id' => $this->cid, 'job_id' => $jobs['Backend Developer'], 'cv_path' => 'a.pdf']);
        $this->lamaBaru = (int) $apps->insert(['candidate_id' => $this->cid, 'job_id' => $jobs['Admin Gudang'], 'cv_path' => 'b.pdf']);

        (new StageLogger())->log($this->lamaLama, 'upload_cv', 'entered');
        (new StageLogger())->log($this->lamaBaru, 'upload_cv', 'entered');
    }

    private function dashboard(string $query = ''): string
    {
        return (string) $this->withSession(['candidate_id' => $this->cid, 'candidate_nama' => 'Uji'])
            ->get('dashboard' . $query)->getBody();
    }

    /** Judul lamaran yang sedang dipilih, dibaca dari <option ... selected>. */
    private function terpilih(string $html): ?string
    {
        preg_match('#<option value="\d+" selected>([^<]+)</option>#', $html, $m);

        return $m[1] ?? null;
    }

    public function testTanpaParameterMemilihLamaranTerbaru(): void
    {
        $this->assertSame('Admin Gudang', $this->terpilih($this->dashboard()));
    }

    public function testMemilihLamaranLamaBenarBenarBerpindah(): void
    {
        // inti bug yang dilaporkan: dropdown dipindah tapi halaman tetap di lamaran terbaru
        $html = $this->dashboard('?app=' . $this->lamaLama);

        $this->assertSame('Backend Developer', $this->terpilih($html));
        // bukan cuma <option selected> yang ikut - tautan tahap juga harus mengarah
        // ke lamaran yang dipilih, kalau tidak kandidat mengerjakan lamaran yang salah
        $this->assertStringContainsString('assessment/' . $this->lamaLama, $html);
        $this->assertStringNotContainsString('assessment/' . $this->lamaBaru, $html);
    }

    private function bukaStatus(string $query = ''): string
    {
        return (string) $this->withSession(['candidate_id' => $this->cid, 'candidate_nama' => 'Uji'])
            ->get('status' . $query)->getBody();
    }

    public function testStatusLamaranMenampilkanSatuPosisiSaja(): void
    {
        (new StageLogger())->log($this->lamaLama, 'gate_1', 'passed', 'system', 'catatan lamaran lama');

        $html = $this->bukaStatus('?app=' . $this->lamaLama);

        // riwayat posisi lain tidak boleh ikut tercampur di halaman yang sama
        $this->assertSame('Backend Developer', $this->terpilih($html));
        $this->assertStringContainsString('catatan lamaran lama', $html);
        $this->assertStringNotContainsString('assessment/' . $this->lamaBaru, $html);
    }

    public function testStatusLamaranTanpaParameterMemilihLamaranTerbaru(): void
    {
        $this->assertSame('Admin Gudang', $this->terpilih($this->bukaStatus()));
    }

    public function testStatusLamaranTidakMenampilkanPemilihBilaHanyaSatuLamaran(): void
    {
        (new ApplicationModel())->delete($this->lamaLama);

        $html = $this->bukaStatus();

        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('Admin Gudang', $html);
    }

    public function testAppIdMilikOrangLainDiabaikanBukanDitampilkan(): void
    {
        $lain = (int) (new CandidateModel())->insert(['nama' => 'Lain', 'email' => 'lain@example.test', 'password_hash' => 'x']);
        $job  = (new JobModel())->insert(['judul' => 'Rahasia', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C']);
        $milikLain = (int) (new ApplicationModel())->insert(['candidate_id' => $lain, 'job_id' => $job, 'cv_path' => 'c.pdf']);

        $html = $this->dashboard('?app=' . $milikLain);

        // jatuh ke lamaran sendiri yang terbaru, tidak membocorkan lamaran orang lain
        $this->assertSame('Admin Gudang', $this->terpilih($html));
        $this->assertStringNotContainsString('Rahasia', $html);
    }
}
