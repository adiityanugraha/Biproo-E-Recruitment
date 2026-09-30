<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewDialogModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Ruang wawancara suara AI, pendamping sesi Zoom (arahan 12 Agustus 2026).
 *
 * Yang diuji di sini adalah bagian yang tidak bisa diuji lewat mikrofon:
 * penjagaan jendela sesi, kerahasiaan penilaian dari kandidat, ketahanan saat
 * layanan AI mati, dan pertemuan hasilnya dengan form penilaian recruiter.
 * Pengenalan suara dan penyaring derau ada di browser (public/js/wawancara.js).
 *
 * @internal
 */
final class WawancaraSuaraTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];
    private int $candidateId;

    private function rubrik(): array
    {
        return [
            ['pertanyaan' => 'Ceritakan tentang diri Anda.', 'kompetensi' => 'Pembuka', 'kategori' => 'Lainnya'],
            ['pertanyaan' => 'Bagaimana Anda menghadapi penolakan?', 'kompetensi' => 'Ketahanan',
                'kategori' => 'Soft Skill', 'indikator' => 'Tetap tenang.', 'red_flag' => 'Menyalahkan pelanggan.', 'bobot' => 5],
            ['pertanyaan' => 'Bandingkan dua produk ini.', 'kompetensi' => 'Perbandingan',
                'kategori' => 'Hard Skill', 'indikator' => 'Menggali pola pakai.', 'bobot' => 4],
        ];
    }

    /**
     * Kandidat dengan sesi interview yang SEDANG berlangsung - jendela ruang
     * wawancara AI sama persis dengan jendela link Zoom.
     */
    private function fixture(string $mulai = '-5 minutes', bool $denganRubrik = true): int
    {
        $jid = (int) (new JobModel())->insert([
            'judul'           => 'Frontliner Retail Gadget',
            'req_skill'       => 'Penjualan',
            'req_pendidikan'  => 'SMA',
            'req_pengalaman'  => '1 tahun',
            'pertanyaan_json' => $denganRubrik ? json_encode($this->rubrik()) : null,
        ]);
        $cid               = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $this->candidateId = (int) $cid;
        $aid               = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'scheduled_at'   => (new DateTime())->modify($mulai)->format('Y-m-d H:i:s'),
            'status'         => 'approved',
            'join_url'       => 'https://us04web.zoom.us/j/1',
            'meeting_id'     => '1',
        ]);

        return $aid;
    }

    private function sesiKandidat(): array
    {
        return ['candidate_id' => $this->candidateId, 'candidate_nama' => 'Sinta'];
    }

    /** @param array<string, mixed> $balasan jawaban /wawancara/giliran */
    private function mockAi(array $balasan): void
    {
        Services::injectMock('aiService', new class ($balasan) extends AiService {
            public function __construct(private array $balasan)
            {
            }

            public function post(string $path, array $payload): array
            {
                return $this->balasan;
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

    /** Satu putaran penuh: minta pertanyaan, lalu jawab. */
    private function jawab(int $aid, string $teks, array $balasanAi): array
    {
        $this->mockAi($balasanAi);

        return $this->withSession($this->sesiKandidat())
            ->post("wawancara/{$aid}/jawab", ['teks' => $teks, 'keyakinan' => '0.9', 'durasi_ms' => '4200'])
            ->getJSON(true);
    }

    // --- penjagaan pintu masuk ----------------------------------------------

    public function testRuangTerbukaSelamaJendelaSesiInterview(): void
    {
        $res = $this->withSession($this->sesiKandidat())->get('wawancara/' . $this->fixture());

        $res->assertOK();
        $res->assertSee('Wawancara Suara AI', 'html');
    }

    public function testRuangTertutupDiLuarJendelaSesi(): void
    {
        // Sesi tiga jam lalu: link Zoom-nya sudah mati, ruang AI ikut mati.
        $res = $this->withSession($this->sesiKandidat())->get('wawancara/' . $this->fixture('-3 hours'));

        $res->assertRedirectTo(site_url('jadwal'));
        $this->assertStringContainsString('hanya terbuka selama jendela sesi', (string) session('error'));
    }

    public function testKandidatLainTidakBisaMembukaRuangOrangLain(): void
    {
        $aid = $this->fixture();
        $penyusup = (new CandidateModel())->insert(['nama' => 'Andi', 'email' => 'andi@example.com', 'password_hash' => 'x']);

        $res = $this->withSession(['candidate_id' => $penyusup, 'candidate_nama' => 'Andi'])->get("wawancara/{$aid}");

        $res->assertRedirectTo(site_url('jadwal'));
    }

    public function testMulaiDiTolakSaatSesiSudahLewat(): void
    {
        $res = $this->withSession($this->sesiKandidat())->post('wawancara/' . $this->fixture('-3 hours') . '/mulai');

        $res->assertStatus(403);
    }

    public function testLowonganTanpaPertanyaanMenolakDenganPesanYangBisaDitindaklanjuti(): void
    {
        $res = $this->withSession($this->sesiKandidat())
            ->post('wawancara/' . $this->fixture('-5 minutes', false) . '/mulai');

        $res->assertStatus(409);
        $this->assertStringContainsString('Belum ada pertanyaan interview', $res->getJSON(true)['error']);
    }

    // --- jalannya wawancara --------------------------------------------------

    public function testMulaiMengembalikanPertanyaanPertamaDanMencatatnya(): void
    {
        $aid = $this->fixture();

        $j = $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai")->getJSON(true);

        $this->assertSame('Ceritakan tentang diri Anda.', $j['pertanyaan']);
        $this->assertSame('bank', $j['sumber']);
        $this->assertSame(1, $j['nomor']);
        $this->assertSame(3, $j['total']);
        $this->assertFalse($j['selesai']);

        $this->seeInDatabase('interview_dialog', ['application_id' => $aid, 'urutan' => 1, 'jawaban' => null]);
    }

    public function testMulaiDuaKaliTidakMembuatPertanyaanBaru(): void
    {
        // Kandidat yang koneksinya putus lalu memuat ulang harus mendapat
        // pertanyaan yang sedang menggantung, bukan wawancara yang mengulang.
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");
        $j = $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai")->getJSON(true);

        $this->assertSame('Ceritakan tentang diri Anda.', $j['pertanyaan']);
        $this->assertSame(1, (new InterviewDialogModel())->where('application_id', $aid)->countAllResults());
    }

    public function testJawabanTersimpanDanPertanyaanLanjutanMenyusul(): void
    {
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");

        $j = $this->jawab($aid, 'saya lulusan SMA dan sudah dua tahun jadi kasir di gerai ponsel', [
            'tingkat'  => 'baik',
            'alasan'   => 'Menyebut pengalaman nyata.',
            'lanjutan' => 'Tadi Anda sebut dua tahun di gerai ponsel. Bagian mana yang paling sulit?',
            'nyambung' => true,
        ]);

        $this->seeInDatabase('interview_dialog', [
            'application_id' => $aid,
            'urutan'         => 1,
            'tingkat'        => 'baik',
            'durasi_ms'      => 4200,
        ]);
        $this->assertSame('lanjutan', $j['sumber']);
        $this->assertStringContainsString('gerai ponsel', $j['pertanyaan']);
    }

    public function testPenilaianTidakPernahDikirimKeBrowserKandidat(): void
    {
        // Kandidat yang membuka DevTools tidak boleh membaca "kurang" atas
        // jawabannya sendiri di tengah wawancara.
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");

        $j = $this->jawab($aid, 'kurang lebih begitu saja sih pak tidak ada lagi', [
            'tingkat'  => 'kurang',
            'alasan'   => 'Tidak menjawab pertanyaan.',
            'lanjutan' => '',
            'nyambung' => true,
        ]);

        $this->assertArrayNotHasKey('tingkat', $j);
        $this->assertArrayNotHasKey('alasan', $j);
        $this->assertStringNotContainsStringIgnoringCase('kurang', json_encode($j));
    }

    public function testLayananAiMatiTidakMenghentikanWawancara(): void
    {
        // Yang hilang cuma penilaian otomatisnya. Jawaban kandidat tetap
        // tercatat lengkap, dan recruiter tetap bisa menilainya dari transkrip.
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");
        $this->mockAiMati();

        $j = $this->withSession($this->sesiKandidat())
            ->post("wawancara/{$aid}/jawab", ['teks' => 'saya pernah menangani keluhan pelanggan yang marah'])
            ->getJSON(true);

        $this->seeInDatabase('interview_dialog', [
            'application_id' => $aid,
            'urutan'         => 1,
            'jawaban'        => 'saya pernah menangani keluhan pelanggan yang marah',
            'tingkat'        => null,
        ]);
        $this->assertSame('bank', $j['sumber'], 'wawancara lanjut ke butir berikutnya');
        $this->assertFalse($j['selesai']);
    }

    public function testJawabTanpaPertanyaanYangMenggantungDitolak(): void
    {
        $res = $this->withSession($this->sesiKandidat())
            ->post('wawancara/' . $this->fixture() . '/jawab', ['teks' => 'halo']);

        $res->assertStatus(409);
    }

    public function testSeluruhButirTerjawabMakaSesiDitutup(): void
    {
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");

        $balasan = ['tingkat' => 'cukup', 'alasan' => 'Jawaban umum.', 'lanjutan' => '', 'nyambung' => true];
        $j       = null;
        for ($i = 0; $i < 3; $i++) {
            $j = $this->jawab($aid, 'saya menjawab pertanyaan ini dengan cukup panjang dan jelas', $balasan);
        }

        $this->assertTrue($j['selesai']);
        $this->seeInDatabase('interview_dialog', ['application_id' => $aid, 'sumber' => 'penutup']);
    }

    public function testWawancaraYangSudahSelesaiTidakBisaDijalankanLagi(): void
    {
        $aid = $this->fixture();
        (new InterviewDialogModel())->insert([
            'application_id' => $aid, 'urutan' => 1, 'sumber' => 'penutup', 'pertanyaan' => 'Terima kasih.',
        ]);

        $j = $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai")->getJSON(true);

        $this->assertTrue($j['selesai']);
        $this->assertSame(1, (new InterviewDialogModel())->where('application_id', $aid)->countAllResults());
    }

    // --- sisi recruiter ------------------------------------------------------

    public function testKonsolMenampilkanTranskripDanSkorSementara(): void
    {
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");
        $this->jawab($aid, 'saya sudah dua tahun bekerja di gerai ponsel sebagai kasir', [
            'tingkat' => 'baik', 'alasan' => 'Pengalaman nyata.', 'lanjutan' => '', 'nyambung' => true,
        ]);

        $res = $this->withSession($this->sesiRec)->get("recruiter/wawancara/{$aid}");

        $res->assertOK();
        $res->assertSee('Sinta', 'html');
    }

    public function testUmpanKonsolMemuatDialogDanPrefill(): void
    {
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");
        $this->jawab($aid, 'saya sudah dua tahun bekerja di gerai ponsel sebagai kasir', [
            'tingkat' => 'baik', 'alasan' => 'Pengalaman nyata.', 'lanjutan' => '', 'nyambung' => true,
        ]);

        $j = $this->withSession($this->sesiRec)->get("recruiter/wawancara/{$aid}/feed")->getJSON(true);

        $this->assertCount(2, $j['dialog']);   // butir 0 terjawab + butir 1 menunggu
        $this->assertSame('baik', $j['dialog'][0]['tingkat']);
        $this->assertFalse($j['selesai']);
    }

    public function testFormNilaiTercentangMenurutPenilaianAiDanMemuatTranskripnya(): void
    {
        $aid = $this->fixture();
        (new InterviewDialogModel())->insert([
            'application_id' => $aid, 'urutan' => 1, 'sumber' => 'bank', 'butir_index' => 1,
            'kompetensi' => 'Ketahanan', 'bobot' => 5, 'pertanyaan' => 'Bagaimana Anda menghadapi penolakan?',
            'jawaban' => 'saya tarik napas dulu lalu tanya keberatannya di mana', 'tingkat' => 'baik',
            'alasan' => 'Menyebut tindakan konkret.',
        ]);

        $res = $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid);

        $res->assertOK();
        $res->assertSee('saya tarik napas dulu lalu tanya keberatannya di mana', 'html');
        $this->assertStringContainsString(
            'value="baik" checked',
            str_replace(['  ', "\n"], ' ', preg_replace('/\s+/', ' ', $res->getBody())),
            'pilihan tingkat harus sudah tercentang sesuai penilaian AI',
        );
    }

    public function testPenilaianAiBelumMasukTabelPenilaianSebelumRecruiterMenyimpan(): void
    {
        // Model tidak memutuskan sendiri: yang tercatat adalah submit recruiter.
        $aid = $this->fixture();
        $this->withSession($this->sesiKandidat())->post("wawancara/{$aid}/mulai");
        $this->jawab($aid, 'saya sudah dua tahun bekerja di gerai ponsel sebagai kasir', [
            'tingkat' => 'baik', 'alasan' => 'Pengalaman nyata.', 'lanjutan' => '', 'nyambung' => true,
        ]);

        $this->dontSeeInDatabase('interview_penilaian', ['application_id' => $aid]);
    }
}
