<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Models\JobModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `php spark vektor:isi` - bahan saran posisi untuk lowongan yang belum pernah
 * dilamar siapa pun.
 *
 * Vektor lowongan terisi sendiri lewat callback screening, tapi hanya kalau ada
 * yang melamar ke sana. Lowongan sepi tidak punya vektor, dan tanpa vektor ia
 * tidak pernah bisa diusulkan - padahal justru yang sepi itulah yang paling
 * perlu diusulkan.
 *
 * @internal
 */
final class VektorIsiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    /** @var list<array<string, mixed>> */
    private array $dikirim = [];

    private function lowongan(array $ubah = []): int
    {
        return (int) (new JobModel())->insert($ubah + [
            'judul'          => 'Admin Gudang',
            'req_skill'      => 'Stok opname, surat jalan',
            'req_pendidikan' => 'SMA',
            'req_pengalaman' => '1 tahun',
        ]);
    }

    private function mockAi(): void
    {
        $this->dikirim = [];
        Services::injectMock('aiService', new class ($this->dikirim) extends AiService {
            public function __construct(private array &$dikirim)
            {
            }

            public function post(string $path, array $payload): array
            {
                $this->dikirim[] = $payload;

                return ['vektor' => array_map(static fn (): array => [0.1, 0.2], $payload)];
            }
        });
    }

    private function mockAiMati(): void
    {
        Services::injectMock('aiService', new class () extends AiService {
            public function __construct()
            {
            }

            public function post(string $path, array $payload): array
            {
                throw new AiServiceException('ai-service tidak terjangkau');
            }
        });
    }

    public function testVektorTersimpanPerLowongan(): void
    {
        $id = $this->lowongan();
        $this->mockAi();

        command('vektor:isi');

        $v = json_decode((string) (new JobModel())->find($id)['vektor_json'], true);
        $this->assertSame(['skill' => [0.1, 0.2], 'pendidikan' => [0.1, 0.2], 'pengalaman' => [0.1, 0.2]], $v);
    }

    /** Bidang kosong tidak ikut dikirim: tiap teks memakan jatah embedding. */
    public function testBidangKosongTidakIkutDikirim(): void
    {
        $this->lowongan(['req_pendidikan' => '', 'req_pengalaman' => '  ']);
        $this->mockAi();

        command('vektor:isi');

        $this->assertSame([['skill' => 'Stok opname, surat jalan']], $this->dikirim);
    }

    /**
     * Yang sudah punya vektor dilewati tanpa --paksa.
     *
     * Perintah ini aman diulang, dan itu gunanya: kalau sebagian gagal karena
     * kuota habis, menjalankannya lagi besok cuma mengerjakan sisanya.
     */
    public function testYangSudahAdaDilewati(): void
    {
        $this->lowongan(['vektor_json' => '{"skill":[9.9]}']);
        $this->mockAi();

        command('vektor:isi');

        $this->assertSame([], $this->dikirim, 'tidak boleh memanggil ai-service sama sekali');
    }

    public function testPaksaMenghitungUlang(): void
    {
        $id = $this->lowongan(['vektor_json' => '{"skill":[9.9]}']);
        $this->mockAi();

        command('vektor:isi --paksa');

        $v = json_decode((string) (new JobModel())->find($id)['vektor_json'], true);
        $this->assertSame([0.1, 0.2], $v['skill'], 'vektor lama ditimpa');
    }

    /** Mode kering tidak menyentuh ai-service maupun basis data. */
    public function testKeringTidakMengirimApaPun(): void
    {
        $id = $this->lowongan();
        $this->mockAi();

        command('vektor:isi --kering');

        $this->assertSame([], $this->dikirim);
        $this->assertNull((new JobModel())->find($id)['vektor_json']);
    }

    /**
     * Satu lowongan gagal tidak menghentikan sisanya, dan tidak menyimpan
     * apa-apa untuk yang gagal - kolom null berarti "belum dihitung", dan itu
     * yang membuat pengulangan besok mengerjakan hal yang benar.
     */
    public function testGagalTidakMenyimpanApaPun(): void
    {
        $id = $this->lowongan();
        $this->mockAiMati();

        command('vektor:isi');

        $this->assertNull((new JobModel())->find($id)['vektor_json']);
    }
}
