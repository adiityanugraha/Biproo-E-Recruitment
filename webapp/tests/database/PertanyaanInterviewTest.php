<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Pertanyaan interview yang dibuat AI (arahan atasan 4 Agustus 2026).
 *
 * Recruiter membuka halaman Interview HRD sebelum wawancara dan sudah punya
 * daftar pertanyaan khusus posisi itu, bukan menyusunnya sendiri tiap kali.
 *
 * Pindah dari Interview User ke Interview HRD pada 6 Agustus 2026: kedua tahap
 * itu ternyata tidak berhubungan, dan yang punya jadwal serta ruang Zoom adalah
 * Interview HRD. Interview User dinonaktifkan.
 *
 * Pertanyaan menempel pada LOWONGAN, bukan kandidat. Alasannya kuota: tier
 * gratis Gemini cuma memberi 20 panggilan generateContent per hari, dan
 * screening CV sudah memakai 1-2 per CV. Per kandidat akan menghabiskannya
 * dalam sehari; per lowongan cukup sekali seumur lowongan.
 *
 * @internal
 */
final class PertanyaanInterviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private function job(): int
    {
        return (int) (new JobModel())->insert([
            'judul'          => 'Admin Gudang',
            'req_skill'      => 'Administrasi stok, Excel',
            'req_pendidikan' => 'D3 semua jurusan',
            'req_pengalaman' => '1 tahun logistik',
            'deskripsi'      => 'Mengelola pencatatan stok masuk-keluar gudang.',
        ]);
    }

    /** Kandidat dengan jadwal interview aktif, supaya muncul di tabel Interview HRD. */
    private function kandidatTerjadwal(int $jobId): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jobId, 'cv_path' => 'uploads/cv/x.pdf']);
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'scheduled_at'   => (new DateTime())->modify('+2 days')->format('Y-m-d H:00:00'),
            'status'         => 'approved',
            'join_url'       => 'https://us04web.zoom.us/j/123',
            'meeting_id'     => '123',
        ]);

        return $aid;
    }

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

    // --- Halaman Interview HRD ---

    /**
     * Interview User dinonaktifkan 6 Agustus 2026. Tautan lamanya tidak boleh
     * menampilkan halaman kosong atau error: rutenya sudah tidak dikenal, jadi
     * pengunjung dikembalikan ke dashboard.
     */
    public function testInterviewUserSudahTidakBisaDibuka(): void
    {
        $jid = $this->job();
        $this->kandidatTerjadwal($jid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_user')
            ->assertRedirectTo(site_url('recruiter'));
    }

    /** Menunya tetap terlihat tapi mati, seperti FPK dan Job Posting. */
    public function testMenuInterviewUserTampilTapiNonaktif(): void
    {
        $html = (string) $this->withSession($this->sesiRec)->get('recruiter')->getBody();

        $this->assertStringContainsString('Interview User', $html);
        $this->assertStringNotContainsString('recruiter/tahap/interview_user', $html);
    }

    /** Interview HRD tetap punya tiga tabnya. */
    public function testInterviewHrdMasihPunyaTabRescheduled(): void
    {
        $jid = $this->job();
        $this->kandidatTerjadwal($jid);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        $this->assertStringContainsString('Rescheduled', $html);
    }

    /** On Progress = yang akan diwawancarai, lengkap dengan pertanyaan dan CV. */
    public function testOnProgressMenyediakanRuangInterviewDanCv(): void
    {
        $jid = $this->job();
        $aid = $this->kandidatTerjadwal($jid);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        $this->assertStringContainsString('recruiter/ruang/' . $aid, $html);
        $this->assertStringContainsString('recruiter/cv/' . $aid, $html);
    }

    /**
     * Halaman pertanyaan per LOWONGAN tidak lagi dipasang di tabel.
     *
     * Ia masih hidup dan masih bisa dibuka langsung - bank soal tim DS berperan
     * sebagai cadangan saat kuota LLM habis - tapi jalan masuk recruiter
     * sehari-hari sekarang Ruang Interview, yang pertanyaannya milik kandidat.
     */
    public function testTabelTidakLagiMenautkanPertanyaanPerLowongan(): void
    {
        $jid = $this->job();
        $this->kandidatTerjadwal($jid);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        $this->assertStringNotContainsString('recruiter/pertanyaan/', $html);
    }

    /** Completed = yang sudah selesai; sesi berakhir memindahkannya sendiri. */
    public function testCompletedBerisiKandidatYangSesinyaSudahLewat(): void
    {
        $jid = $this->job();
        $cid = (new CandidateModel())->insert(['nama' => 'Bagas', 'email' => 'bagas@example.com', 'password_hash' => 'x']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/y.pdf']);
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'scheduled_at'   => (new DateTime())->modify('-3 hours')->format('Y-m-d H:i:s'),
            'status'         => 'approved',
            'join_url'       => 'https://us04web.zoom.us/j/9',
            'meeting_id'     => '9',
        ]);

        $progress  = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();
        $completed = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringNotContainsString('Bagas', $progress);
        $this->assertStringContainsString('Bagas', $completed);
        $this->assertStringContainsString('Nilai Interview', $completed);
    }

    /**
     * Pertanyaan dibuka di jendela pratinjau di atas tabel, bukan dengan
     * berpindah halaman. Isi jendela memakai layout_bingkai: tanpa topbar dan
     * tanpa sidebar, karena keduanya sudah ada di halaman induk.
     */
    public function testHalamanPertanyaanDalamBingkaiTanpaTopbarDanSidebar(): void
    {
        $jid = $this->job();

        $penuh   = (string) $this->withSession($this->sesiRec)->get("recruiter/pertanyaan/{$jid}")->getBody();
        $bingkai = (string) $this->withSession($this->sesiRec)->get("recruiter/pertanyaan/{$jid}?bingkai=1")->getBody();

        $this->assertStringContainsString('class="rtop"', $penuh, 'versi penuh tetap punya topbar');
        $this->assertStringNotContainsString('class="rtop"', $bingkai);
        $this->assertStringNotContainsString('class="rside"', $bingkai);
        // isinya sendiri tetap ada
        $this->assertStringContainsString('Admin Gudang', $bingkai);
    }

    /** Tombol di tabel membuka jendela, bukan meninggalkan halaman. */
    public function testTombolRuangMembukaJendelaBukanPindahHalaman(): void
    {
        $jid = $this->job();
        $aid = $this->kandidatTerjadwal($jid);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        $this->assertStringContainsString("recruiter/ruang/{$aid}?bingkai=1", $html);
        $this->assertStringContainsString('bukaJendela(this.href', $html);
        $this->assertStringContainsString('id="jendelaModal"', $html);
    }

    /**
     * Setelah Simpan, penandanya harus ikut terbawa. Tanpa ini halaman di dalam
     * bingkai berubah jadi versi utuh lengkap dengan topbar dan sidebar yang
     * terjepit di kotak kecil.
     */
    public function testSimpanDariDalamBingkaiTetapDiDalamBingkai(): void
    {
        $jid = $this->job();
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['Ceritakan pengalaman Anda.'])]);

        $this->withSession($this->sesiRec)
            ->post("recruiter/pertanyaan/{$jid}?bingkai=1", ['aksi' => 'simpan', 'pertanyaan' => ['Sudah disunting.']])
            ->assertRedirectTo(site_url("recruiter/pertanyaan/{$jid}") . '?bingkai=1');
    }

    /**
     * Kebalikan dari yang dijaga uji ini sebelum 12 Agustus 2026.
     *
     * Dulu tombolnya sengaja menunjuk LOWONGAN: satu set pertanyaan dipakai
     * bersama semua pelamar, demi menghemat kuota LLM. Revisi meminta
     * pertanyaan disusun dari riwayat kerja kandidat, jadi tautannya sekarang
     * harus menunjuk LAMARAN. Uji lama tetap ditulis ulang, bukan dihapus:
     * yang berubah maksudnya, bukan pentingnya.
     */
    public function testTombolRuangMenunjukLamaranBukanLowongan(): void
    {
        // Dua lowongan dibuat dulu supaya job_id != application_id. Tanpa ini
        // keduanya sama-sama bernilai 1 dan uji ini tidak membuktikan apa pun.
        $this->job();
        $jid = $this->job();
        $aid = $this->kandidatTerjadwal($jid);
        $this->assertNotSame($jid, $aid, 'prasyarat uji: id lowongan dan id lamaran harus beda');

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        $this->assertStringContainsString('recruiter/ruang/' . $aid, $html);
        $this->assertStringNotContainsString('recruiter/ruang/' . $jid . '?', $html);
    }

    // --- Halaman pertanyaan ---

    public function testLowonganBaruBelumPunyaPertanyaan(): void
    {
        $jid = $this->job();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Belum ada pertanyaan', $html);
        $this->assertStringContainsString('Buat dengan AI', $html);
    }

    public function testBuatDenganAiMenyimpanPertanyaanKeLowongan(): void
    {
        $jid = $this->job();
        $this->mockAi(['pertanyaan' => [
            'Ceritakan saat Anda menemukan selisih stok.',
            'Bagaimana Anda memastikan pencatatan barang masuk akurat?',
        ]]);

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, ['aksi' => 'buat']);

        $tersimpan = json_decode((new JobModel())->find($jid)['pertanyaan_json'], true);
        $this->assertCount(2, $tersimpan);
        $this->assertSame('Ceritakan saat Anda menemukan selisih stok.', $tersimpan[0]);
    }

    public function testUraianLowonganDikirimKeAiServiceBukanJudulSaja(): void
    {
        $jid   = $this->job();
        $rekam = new stdClass();
        Services::injectMock('aiService', new class ($rekam) extends AiService {
            public function __construct(private stdClass $rekam)
            {
            }

            public function post(string $path, array $payload): array
            {
                $this->rekam->path    = $path;
                $this->rekam->payload = $payload;

                return ['pertanyaan' => ['a']];
            }
        });

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, ['aksi' => 'buat']);

        $this->assertSame('pertanyaan', $rekam->path);
        $this->assertSame('Admin Gudang', $rekam->payload['judul']);
        $this->assertStringContainsString('Excel', $rekam->payload['skill']);
        $this->assertStringContainsString('stok masuk-keluar', $rekam->payload['deskripsi']);
    }

    public function testPertanyaanTersimpanTampilDanBisaDisunting(): void
    {
        $jid = $this->job();
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['Pertanyaan lama'])]);

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, [
            'aksi'       => 'simpan',
            'pertanyaan' => ['Pertanyaan yang sudah disunting'],
        ]);

        $tersimpan = json_decode((new JobModel())->find($jid)['pertanyaan_json'], true);
        $this->assertSame(['Pertanyaan yang sudah disunting'], $tersimpan);
    }

    public function testKotakDikosongkanBerartiMenghapusBarisnya(): void
    {
        $jid = $this->job();
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['satu', 'dua', 'tiga'])]);

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, [
            'aksi'       => 'simpan',
            'pertanyaan' => ['satu', '   ', 'tiga'],
        ]);

        $this->assertSame(['satu', 'tiga'], json_decode((new JobModel())->find($jid)['pertanyaan_json'], true));
    }

    /**
     * Sebab paling sering: kuota harian LLM habis. Menimpa set lama dengan
     * kosong berarti recruiter kehilangan pertanyaan kemarin tepat saat mau
     * mewawancarai orang.
     */
    public function testAiGagalTidakMenimpaPertanyaanYangSudahAda(): void
    {
        $jid = $this->job();
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['Pertanyaan lama yang berharga'])]);
        $this->mockAiMati();

        $res = $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, ['aksi' => 'buat']);

        $res->assertStatus(302);
        $this->assertSame(['Pertanyaan lama yang berharga'], json_decode((new JobModel())->find($jid)['pertanyaan_json'], true));
    }

    public function testJumlahDanPanjangPertanyaanDibatasi(): void
    {
        $jid = $this->job();
        $this->mockAi(['pertanyaan' => array_map(
            static fn (int $i): string => 'p' . $i . str_repeat(' panjang', 200),
            range(1, 50)
        )]);

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, ['aksi' => 'buat']);

        $tersimpan = json_decode((new JobModel())->find($jid)['pertanyaan_json'], true);
        $this->assertCount(App\Controllers\Recruiter::MAKS_PERTANYAAN, $tersimpan);
        foreach ($tersimpan as $p) {
            $this->assertLessThanOrEqual(300, mb_strlen($p));
        }
    }

    public function testLowonganTidakAdaDialihkanBukanError(): void
    {
        $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/999999')
            ->assertRedirectTo(site_url('recruiter/tahap/interview_online'));
    }

    public function testKandidatTidakBisaMembukaPertanyaan(): void
    {
        $jid = $this->job();
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 's@example.com', 'password_hash' => 'x']);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->get('recruiter/pertanyaan/' . $jid)
            ->assertRedirectTo(site_url('recruiter/login'));
    }

    /** Teks pertanyaan berasal dari LLM dan dari form recruiter - dua-duanya tak dipercaya bulat. */
    public function testPertanyaanDiEscapeSebelumDitampilkan(): void
    {
        $jid = $this->job();
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['<script>alert(1)</script>'])]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Regresi: layout_recruiter sempat tidak menampilkan pesan flash sama sekali,
     * sementara 17 aksi recruiter menyetelnya. Akibatnya menekan tombol terlihat
     * seperti tidak terjadi apa-apa - persis keluhan yang muncul di lapangan.
     */
    public function testPesanBerhasilTampilDiHalamanRecruiter(): void
    {
        $jid = $this->job();

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, [
            'aksi'       => 'simpan',
            'pertanyaan' => ['Sebuah pertanyaan'],
        ]);
        $html = (string) $this->withSession(array_merge($this->sesiRec, ['sukses' => 'Pertanyaan tersimpan.']))
            ->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Pertanyaan tersimpan.', $html);
        $this->assertStringContainsString('pesan-sukses', $html);
    }

    public function testPesanGagalTampilSaatAiMati(): void
    {
        $jid = $this->job();
        $this->mockAiMati();

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, ['aksi' => 'buat']);
        $html = (string) $this->withSession(array_merge($this->sesiRec, ['error' => 'Gagal membuat pertanyaan.']))
            ->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Gagal membuat pertanyaan.', $html);
        $this->assertStringContainsString('pesan-error', $html);
    }
}
