<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * php spark transkrip:resend
 *
 * Menutup celah yang tersisa dari Tahap 5: ai-service menyimpan status
 * pekerjaan di memori saja, jadi callback yang gagal mendarat membuat barisnya
 * tertinggal berstatus 'proses' selamanya sambil layar recruiter berbunyi
 * "sedang ditranskripsi".
 *
 * @internal
 */
final class TranskripResendTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private int $urut = 0;

    /** @var list<int> id transkrip yang diminta dikirim ai-service */
    private array $terkirim = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->terkirim = [];
    }

    private function mockAi(bool $mati = false): void
    {
        $jejak = new stdClass();
        $jejak->url = [];
        $this->jejak = $jejak;

        Services::injectMock('aiService', new class ($jejak, $mati) extends AiService {
            public function __construct(private object $jejak, private bool $mati)
            {
            }

            public function post(string $path, array $payload): array
            {
                if ($this->mati) {
                    throw new AiServiceException('ai-service tidak terjangkau');
                }
                $this->jejak->url[] = $payload['audio_url'];

                return ['interview_job_id' => 'uji'];
            }
        });
    }

    private object $jejak;

    private function transkrip(string $status, ?string $berkas = 'uploads/rekaman/x.wav'): int
    {
        $n   = ++$this->urut;
        $cid = (new CandidateModel())->insert([
            'nama' => 'Reza ' . $n, 'email' => "reza{$n}@example.com", 'password_hash' => 'x',
        ]);
        $jid = (new JobModel())->insert([
            'judul' => 'Admin Gudang', 'req_skill' => 'A', 'req_pendidikan' => 'B', 'req_pengalaman' => 'C',
        ]);
        $aid = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);

        return (int) (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => $status, 'berkas' => $berkas,
        ]);
    }

    public function testYangTersangkutDikirimUlang(): void
    {
        $this->mockAi();
        $proses = $this->transkrip('proses');
        $antre  = $this->transkrip('antre');

        command('transkrip:resend');

        $this->assertCount(2, $this->jejak->url);
        foreach ([$proses, $antre] as $id) {
            $this->assertSame('proses', (new InterviewTranskripModel())->find($id)['status']);
        }
    }

    /**
     * Yang sudah selesai TIDAK pernah ikut.
     *
     * Mengirim ulang yang berhasil berarti menghabiskan kuota untuk menulis
     * transkrip yang sudah ada, dan callback keduanya akan ditolak karena
     * Gate 2-nya sudah diputus.
     */
    public function testYangSudahSelesaiTidakIkutDikirim(): void
    {
        $this->mockAi();
        $this->transkrip('selesai');

        command('transkrip:resend');

        $this->assertSame([], $this->jejak->url);
    }

    /** Yang gagal butuh diminta secara sadar - sebabnya bisa saja berkasnya memang rusak. */
    public function testYangGagalHanyaIkutBilaDiminta(): void
    {
        $this->mockAi();
        $this->transkrip('gagal');

        command('transkrip:resend');
        $this->assertSame([], $this->jejak->url);

        command('transkrip:resend --gagal');
        $this->assertCount(1, $this->jejak->url);
    }

    public function testKeringTidakMengirimApaPun(): void
    {
        $this->mockAi();
        $id = $this->transkrip('proses');

        command('transkrip:resend --kering');

        $this->assertSame([], $this->jejak->url);
        $this->assertSame('proses', (new InterviewTranskripModel())->find($id)['status']);
    }

    public function testBisaDibatasiSatuLamaran(): void
    {
        $this->mockAi();
        $satu = $this->transkrip('proses');
        $this->transkrip('proses');
        $aid = (new InterviewTranskripModel())->find($satu)['application_id'];

        command('transkrip:resend --id ' . $aid);

        $this->assertCount(1, $this->jejak->url);
    }

    /** Baris tanpa berkas tidak bisa dikirim - tidak ada yang mau ditranskripsi. */
    public function testBarisTanpaBerkasDilewati(): void
    {
        $this->mockAi();
        $this->transkrip('proses', null);

        command('transkrip:resend');

        $this->assertSame([], $this->jejak->url);
    }

    /**
     * Satu rekaman yang bermasalah tidak menahan sisanya, dan sebabnya
     * tersimpan supaya terbaca recruiter di layar.
     */
    public function testAiMatiMenandaiBarisnyaGagalBukanMenghentikanSisanya(): void
    {
        $this->mockAi(mati: true);
        $a = $this->transkrip('proses');
        $b = $this->transkrip('proses');

        command('transkrip:resend');

        $model = new InterviewTranskripModel();
        foreach ([$a, $b] as $id) {
            $this->assertSame('gagal', $model->find($id)['status']);
            $this->assertStringContainsString('tidak menjawab', (string) $model->find($id)['catatan']);
        }
    }

    /** Tidak ada yang tersangkut adalah keadaan NORMAL, bukan kegagalan. */
    public function testTidakAdaYangTersangkutBukanKegagalan(): void
    {
        $this->mockAi();

        // command() tidak mengembalikan keluarannya; ia menulis ke buffer
        // filter aliran, dan dari situ dibacanya.
        command('transkrip:resend');

        $this->assertStringContainsString('Tidak ada rekaman', $this->getStreamFilterBuffer());
    }
}
