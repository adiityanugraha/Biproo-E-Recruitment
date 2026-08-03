<?php

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Recruiter membuka berkas CV kandidat (arahan atasan 3 Agustus 2026).
 *
 * Sebelumnya tombol CV di tabel recruiter cuma memunculkan modal "segera hadir",
 * jadi tidak ada cara melihat CV sebelum interview.
 *
 * @internal
 */
final class CvRecruiterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    /** @var list<string> berkas yang dibuat uji ini, dibersihkan di tearDown */
    private array $berkas = [];

    /** @return array{0:int,1:int,2:string} [candidateId, applicationId, namaBerkas] */
    private function fixture(string $ext = 'pdf', bool $berkasAda = true): array
    {
        $cid  = (new CandidateModel())->insert(['nama' => 'Sinta Rahma', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $jid  = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $nama = 'uji-cv-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $aid  = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/' . $nama,
        ]);
        // upload_cv wajib ada supaya kandidat muncul di tabel tahap Upload CV
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new StageLogger())->log($aid, 'gate_1', 'passed', 'system');

        if ($berkasAda) {
            $path = WRITEPATH . 'uploads/cv/' . $nama;
            file_put_contents($path, '%PDF-1.4 isi cv uji');
            $this->berkas[] = $path;
        }

        return [(int) $cid, $aid, $nama];
    }

    protected function tearDown(): void
    {
        foreach ($this->berkas as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    public function testRecruiterBisaMembukaCvPdfLangsungDiBrowser(): void
    {
        [, $aid] = $this->fixture();

        $res = $this->withSession($this->sesiRec)->get("recruiter/cv/{$aid}");

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/pdf');
        // inline, bukan attachment: recruiter membacanya sambil menyiapkan interview
        $this->assertStringContainsString('inline', $res->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('isi cv uji', (string) $res->getBody());
    }

    public function testNamaBerkasMemakaiNamaKandidatBukanNamaAcakDiDisk(): void
    {
        [, $aid] = $this->fixture();

        $res = $this->withSession($this->sesiRec)->get("recruiter/cv/{$aid}");

        $this->assertStringContainsString('CV - Sinta Rahma.pdf', $res->response()->getHeaderLine('Content-Disposition'));
    }

    public function testKandidatTidakBisaMembukaCvLewatJalurRecruiter(): void
    {
        [$cid, $aid] = $this->fixture();

        $res = $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta Rahma'])
            ->get("recruiter/cv/{$aid}");

        $res->assertRedirectTo(site_url('recruiter/login'));
    }

    public function testTanpaSesiSamaSekaliDitolak(): void
    {
        [, $aid] = $this->fixture();

        $this->get("recruiter/cv/{$aid}")->assertRedirectTo(site_url('recruiter/login'));
    }

    public function testBerkasHilangDitanganiRamahBukanCrash(): void
    {
        [, $aid] = $this->fixture(berkasAda: false);

        $res = $this->withSession($this->sesiRec)->get("recruiter/cv/{$aid}");

        $res->assertStatus(302);   // dialihkan dengan pesan, bukan 500
        $this->assertStringNotContainsString('Exception', (string) $res->getBody());
    }

    public function testLamaranTidakAdaDialihkanBukanError(): void
    {
        $res = $this->withSession($this->sesiRec)->get('recruiter/cv/999999');

        $res->assertRedirectTo(site_url('recruiter/kandidat'));
    }

    /**
     * cv_path datang dari database. Kalau baris itu tercemar, jalur ini tidak
     * boleh berubah jadi pembaca berkas sembarang di server.
     */
    public function testPathTraversalLewatCvPathDitolak(): void
    {
        [, $aid] = $this->fixture();
        (new ApplicationModel())->update($aid, ['cv_path' => 'uploads/cv/../../../app/Config/App.php']);

        $res = $this->withSession($this->sesiRec)->get("recruiter/cv/{$aid}");

        $res->assertStatus(302);
        $this->assertStringNotContainsString('appTimezone', (string) $res->getBody());
    }

    public function testTombolCvMunculDiTabelTahapDanDaftarKandidat(): void
    {
        [, $aid] = $this->fixture();

        $tahap = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/upload_cv')->getBody();
        $this->assertStringContainsString("recruiter/cv/{$aid}", $tahap);
        // tombol lama yang cuma memunculkan modal sudah tidak ada
        $this->assertStringNotContainsString("segera('Lihat File CV')", $tahap);

        $daftar = (string) $this->withSession($this->sesiRec)->get('recruiter/kandidat')->getBody();
        $this->assertStringContainsString("recruiter/cv/{$aid}", $daftar);
    }

    public function testTautanCvAdaDiHalamanReview(): void
    {
        [, $aid] = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/review/{$aid}")->getBody();

        $this->assertStringContainsString("recruiter/cv/{$aid}", $html);
        $this->assertStringContainsString('Buka CV Kandidat', $html);
    }

    public function testDaftarKandidatTidakLagiMemuatTombolAccTolakYangRouteNyaSudahDihapus(): void
    {
        [, $aid] = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/kandidat')->getBody();

        $this->assertStringNotContainsString('interview/acc/', $html);
        $this->assertStringNotContainsString('interview/tolak/', $html);
    }
}
