<?php

use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Vektor embedding ikut tersimpan dari callback screening (21 Agustus 2026).
 *
 * Dipakai fitur saran posisi. Menghitungnya ulang menuntut 108 teks embedding
 * per kandidat dari jatah 1.000 sehari; dengan vektornya tersimpan, sarannya
 * jadi aritmetika murni tanpa satu pun panggilan API.
 *
 * @internal
 */
final class VektorTersimpanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private string $token = 'token-uji';
    private int $appId;
    private int $jobId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('aiservice.sharedToken=' . $this->token);
        $_ENV['aiservice.sharedToken'] = $this->token;

        $this->jobId = (int) (new JobModel())->insert([
            'judul'          => 'Admin Gudang',
            'req_skill'      => 'Stok, surat jalan',
            'req_pendidikan' => 'SMA',
            'req_pengalaman' => '1 tahun',
        ]);
        $db = db_connect();
        $db->table('candidates')->insert([
            'nama' => 'Reza', 'email' => 'reza@uji.test',
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);
        $cid = (int) $db->insertID();
        $db->table('applications')->insert([
            'candidate_id' => $cid, 'job_id' => $this->jobId, 'cv_path' => 'uploads/cv/x.pdf',
        ]);
        $this->appId = (int) $db->insertID();
    }

    private function kirim(array $ubah = [])
    {
        return $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('screening/callback', $ubah + [
                'job_id_internal'  => $this->appId,
                'screening_job_id' => 'abc123',
                'status'           => 'success',
                'scores'           => ['overall' => 0.7, 'skill' => 0.7, 'pendidikan' => 0.7, 'pengalaman' => 0.7],
                'extracted_fields' => [],
                'flags'            => [],
                'vektor_cv'        => ['skill' => [0.1, 0.2], 'pengalaman' => [0.3, 0.4]],
                'vektor_job'       => ['skill' => [0.5, 0.6]],
            ]);
    }

    public function testVektorCvTersimpanDiHasilScreening(): void
    {
        $this->kirim()->assertStatus(200);

        $sr = (new ScreeningResultModel())->latestFor($this->appId);

        $this->assertSame(
            ['skill' => [0.1, 0.2], 'pengalaman' => [0.3, 0.4]],
            json_decode((string) $sr['vektor_json'], true),
        );
    }

    /** Vektor lowongan menempel di lowongannya: ia sama untuk semua pelamar. */
    public function testVektorLowonganTersimpanDiLowongan(): void
    {
        $this->kirim()->assertStatus(200);

        $job = (new JobModel())->find($this->jobId);

        $this->assertSame(['skill' => [0.5, 0.6]], json_decode((string) $job['vektor_json'], true));
    }

    /**
     * Nilai bukan-angka DIBUANG, tidak disimpan apa adanya.
     *
     * Kolom ini kelak jadi bahan hitungan saran posisi. Satu nilai ngawur yang
     * lolos ke sana muncul sebagai kecocokan 0 pada posisi yang justru cocok,
     * dan tak seorang pun akan tahu kenapa.
     */
    public function testBidangDenganNilaiNgawurDibuang(): void
    {
        $this->kirim(['vektor_cv' => [
            'skill'      => [0.1, 'bukan angka'],
            'pengalaman' => [0.3, 0.4],
        ]])->assertStatus(200);

        $sr = (new ScreeningResultModel())->latestFor($this->appId);

        $this->assertSame(['pengalaman' => [0.3, 0.4]], json_decode((string) $sr['vektor_json'], true));
    }

    /** Callback tanpa vektor sama sekali tetap sah - kolomnya null, bukan gagal. */
    public function testTanpaVektorTetapDiterima(): void
    {
        $this->kirim(['vektor_cv' => null, 'vektor_job' => null])->assertStatus(200);

        $sr = (new ScreeningResultModel())->latestFor($this->appId);

        $this->assertNull($sr['vektor_json']);
        $this->assertNull((new JobModel())->find($this->jobId)['vektor_json']);
    }
}
