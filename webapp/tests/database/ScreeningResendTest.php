<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * screening:resend menutup celah "job hilang saat ai-service mati".
 * Wajib idempoten: dijalankan berulang tidak boleh membuat job ganda.
 *
 * @internal
 */
final class ScreeningResendTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;   // menangkap keluaran CLI

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    /** @var list<int> application_id yang dikirim ke ai-service */
    private array $terkirim = [];

    protected function setUp(): void
    {
        parent::setUp();
        config('AiService')->sharedToken = 'token-uji';
        $this->terkirim                  = [];

        $rekam = function (array $payload): void {
            $this->terkirim[] = (int) $payload['job_id_internal'];
        };
        Services::injectMock('aiService', new class ($rekam) extends AiService {
            public function __construct(private $rekam)
            {
            }

            public function post(string $path, array $payload): array
            {
                ($this->rekam)($payload);

                return ['screening_job_id' => 'uji'];
            }
        });
    }

    private function lamaran(string $email, bool $cvAda = true): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Uji', 'email' => $email, 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Frontliner', 'req_skill' => 'Excel',
            'req_pendidikan' => 'SMA', 'req_pengalaman' => '1th']);
        $nama = 'resend-' . bin2hex(random_bytes(4)) . '.pdf';
        $aid  = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/' . $nama,
        ]);
        if ($cvAda) {
            file_put_contents(WRITEPATH . 'uploads/cv/' . $nama, '%PDF-1.4 uji');
        }

        return $aid;
    }

    private function jalankan(array $params = []): string
    {
        command('screening:resend ' . implode(' ', $params));

        return $this->getStreamFilterBuffer();
    }

    protected function tearDown(): void
    {
        foreach (glob(WRITEPATH . 'uploads/cv/resend-*') as $f) {
            unlink($f);
        }
        parent::tearDown();
    }

    public function testLamaranTanpaSkorDikirimUlang(): void
    {
        $aid = $this->lamaran('a@example.test');

        $this->jalankan();

        $this->assertSame([$aid], $this->terkirim);
    }

    public function testLamaranYangSudahBerskorDilewati(): void
    {
        $aid = $this->lamaran('b@example.test');
        (new ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'x', 'status' => 'success',
            'score_overall' => 0.71, 'provider' => 'ai-service', 'model_version' => 'v1',
        ]);

        $this->jalankan();

        $this->assertSame([], $this->terkirim, 'tidak boleh mengirim job ganda');
    }

    public function testPaksaIkutMengirimYangSudahBerskor(): void
    {
        // dipakai setelah pipeline diperbaiki: nilai ulang kandidat yang skornya
        // sah tapi dihitung dengan cara yang lebih buruk (mis. skill gagal terurai)
        $aid = $this->lamaran('paksa@example.test');
        (new ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'x', 'status' => 'success',
            'score_overall' => 0.6385, 'provider' => 'ai-service', 'model_version' => 'v1',
        ]);

        $this->jalankan(['--paksa']);

        $this->assertSame([$aid], $this->terkirim);
    }

    public function testTanpaPaksaYangSudahBerskorTetapDilewati(): void
    {
        $aid = $this->lamaran('nopaksa@example.test');
        (new ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'x', 'status' => 'success',
            'score_overall' => 0.6385, 'provider' => 'ai-service', 'model_version' => 'v1',
        ]);

        $this->jalankan();

        $this->assertSame([], $this->terkirim, 'default harus tetap idempoten');
    }

    public function testBarisTanpaSkorTetapDikirimUlang(): void
    {
        // callback pernah tiba tapi status gagal / skor null -> masih perlu diulang
        $aid = $this->lamaran('c@example.test');
        (new ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'x', 'status' => 'failed_extraction',
            'score_overall' => null, 'provider' => 'ai-service', 'model_version' => 'v1',
        ]);

        $this->jalankan();

        $this->assertSame([$aid], $this->terkirim);
    }

    public function testBerkasCvHilangDilewatiBukanDikirim(): void
    {
        $this->lamaran('d@example.test', cvAda: false);

        $keluaran = $this->jalankan();

        $this->assertSame([], $this->terkirim);
        $this->assertStringContainsString('berkas CV hilang', $keluaran);
    }

    public function testModeDryTidakMengirimApaPun(): void
    {
        $aid = $this->lamaran('e@example.test');

        $keluaran = $this->jalankan(['--dry']);

        $this->assertSame([], $this->terkirim);
        $this->assertStringContainsString("app#{$aid}", $keluaran);
    }

    public function testOpsiIdMembatasiKeSatuLamaran(): void
    {
        $a1 = $this->lamaran('f@example.test');
        $this->lamaran('g@example.test');

        $this->jalankan(['--id', (string) $a1]);

        $this->assertSame([$a1], $this->terkirim);
    }

    public function testTokenKosongMenolakJalan(): void
    {
        config('AiService')->sharedToken = '';
        $this->lamaran('h@example.test');

        $keluaran = $this->jalankan();

        $this->assertSame([], $this->terkirim);
        $this->assertStringContainsString('sharedToken', $keluaran);
    }

    public function testAiServiceMatiDilaporkanTanpaMenjatuhkanPerintah(): void
    {
        Services::injectMock('aiService', new class () extends AiService {
            public function __construct()
            {
            }

            public function post(string $path, array $payload): array
            {
                throw new AiServiceException('ai-service tidak dapat dihubungi');
            }
        });
        $aid = $this->lamaran('i@example.test');

        $keluaran = $this->jalankan();

        $this->assertStringContainsString("app#{$aid}", $keluaran);
        $this->assertStringContainsString('GAGAL', $keluaran);
    }
}
