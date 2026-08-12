<?php

use App\Libraries\StageLogger;
use App\Libraries\ZoomException;
use App\Libraries\ZoomService;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * php spark lamaran:hapus --email ALAMAT
 *
 * Menghapus lamaran itu sebelumnya dikerjakan dengan DELETE manual, dan tiga
 * bagiannya mudah terlewat: urutan hapus, ruang Zoom yang hidup di server Zoom,
 * dan berkas CV di disk. Uji ini mengunci ketiganya.
 *
 * @internal
 */
final class LamaranHapusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private object $jejakZoom;

    /** @var list<string> berkas yang dibuat uji ini */
    private array $berkas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->jejakZoom          = new stdClass();
        $this->jejakZoom->dihapus = [];

        Services::injectMock('zoomService', new class ($this->jejakZoom) extends ZoomService {
            public function __construct(private object $jejak)
            {
            }

            public function hapusMeeting(string $meetingId): void
            {
                $this->jejak->dihapus[] = $meetingId;
            }
        });
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

    /** @return array{0:int,1:int} [candidateId, applicationId] */
    private function fixture(string $email = 'sinta@example.com', bool $berkasAda = true): array
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => $email, 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Admin Gudang', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C']);

        $nama = 'uji-hapus-' . bin2hex(random_bytes(4)) . '.pdf';
        $aid  = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/' . $nama,
        ]);

        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'uji-' . $aid,
            'status' => 'success', 'score_overall' => 0.8, 'provider' => 'dummy', 'model_version' => 'uji',
        ]);
        // Jam dibuat unik per lamaran: satu slot cuma boleh dipegang satu
        // kandidat (indeks ux_interviews_slot_aktif), jadi dua fixture dengan
        // jam yang sama akan ditolak database.
        (new InterviewModel())->insert([
            'application_id' => $aid, 'status' => 'approved',
            'scheduled_at' => '2026-08-12 ' . str_pad((string) (9 + $aid), 2, '0', STR_PAD_LEFT) . ':00:00',
            'meeting_id' => '777' . $aid,
            'join_url' => 'https://zoom.us/j/777',
        ]);

        if ($berkasAda) {
            $path = WRITEPATH . 'uploads/cv/' . $nama;
            file_put_contents($path, 'cv uji');
            $this->berkas[] = $path;
        }

        return [(int) $cid, $aid];
    }

    public function testMenghapusLamaranBesertaSeluruhTurunannya(): void
    {
        [, $aid] = $this->fixture();

        command('lamaran:hapus --email sinta@example.com');

        $this->dontSeeInDatabase('applications', ['id' => $aid]);
        $this->dontSeeInDatabase('screening_results', ['application_id' => $aid]);
        $this->dontSeeInDatabase('candidate_stage_history', ['application_id' => $aid]);
        $this->dontSeeInDatabase('interviews', ['application_id' => $aid]);
    }

    /** Akun harus selamat - kandidat masih bisa masuk dan melamar lagi. */
    public function testAkunTidakIkutTerhapus(): void
    {
        [$cid] = $this->fixture();

        command('lamaran:hapus --email sinta@example.com');

        $this->seeInDatabase('candidates', ['id' => $cid, 'email' => 'sinta@example.com']);
    }

    /**
     * Ruang Zoom hidup di server Zoom, bukan di basis data. Menghapus barisnya
     * saja meninggalkan ruangan yang masih bisa dimasuki pemegang tautannya.
     */
    public function testRuangZoomIkutDicabut(): void
    {
        [, $aid] = $this->fixture();

        command('lamaran:hapus --email sinta@example.com');

        $this->assertSame(['777' . $aid], $this->jejakZoom->dihapus);
    }

    public function testBerkasCvIkutDihapusDariDisk(): void
    {
        [, $aid] = $this->fixture();
        $path    = WRITEPATH . (new ApplicationModel())->find($aid)['cv_path'];
        $this->assertFileExists($path, 'prasyarat uji');

        command('lamaran:hapus --email sinta@example.com');

        $this->assertFileDoesNotExist($path);
    }

    public function testKeringTidakMengubahApaPun(): void
    {
        [, $aid] = $this->fixture();
        $path    = WRITEPATH . (new ApplicationModel())->find($aid)['cv_path'];

        command('lamaran:hapus --email sinta@example.com --kering');

        $this->seeInDatabase('applications', ['id' => $aid]);
        $this->seeInDatabase('interviews', ['application_id' => $aid]);
        $this->assertFileExists($path);
        $this->assertSame([], $this->jejakZoom->dihapus, 'kering tidak boleh menyentuh Zoom');
    }

    /** Lamaran milik email lain tidak boleh ikut terbawa. */
    public function testHanyaMenyentuhEmailYangDiminta(): void
    {
        [, $aid]  = $this->fixture('sinta@example.com');
        [, $lain] = $this->fixture('budi@example.com');

        command('lamaran:hapus --email sinta@example.com');

        $this->dontSeeInDatabase('applications', ['id' => $aid]);
        $this->seeInDatabase('applications', ['id' => $lain]);
        $this->seeInDatabase('interviews', ['application_id' => $lain]);
    }

    public function testEmailTanpaLamaranTidakDianggapGagal(): void
    {
        command('lamaran:hapus --email tidakada@example.com');

        $this->assertStringContainsString('Tidak ada lamaran', $this->getStreamFilterBuffer());
    }

    public function testTanpaEmailDitolak(): void
    {
        [, $aid] = $this->fixture();

        command('lamaran:hapus');

        $this->seeInDatabase('applications', ['id' => $aid]);
    }

    /**
     * Zoom gagal dicabut TIDAK membatalkan penghapusan - ruangan yatim lebih
     * baik daripada data yang setengah terhapus. Tapi harus terbaca di layar.
     */
    public function testZoomGagalTetapMenghapusTapiMemberitahu(): void
    {
        Services::injectMock('zoomService', new class () extends ZoomService {
            public function __construct()
            {
            }

            public function hapusMeeting(string $meetingId): void
            {
                throw new ZoomException('Zoom membalas status 500');
            }
        });
        [, $aid] = $this->fixture();

        command('lamaran:hapus --email sinta@example.com');

        $this->dontSeeInDatabase('applications', ['id' => $aid]);
        $this->assertStringContainsString('GAGAL dicabut', $this->getStreamFilterBuffer());
    }
}
