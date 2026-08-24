<?php

use App\Libraries\LembarPenilaian as L;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Saran posisi untuk kandidat yang digugurkan karena SALAH POSISI
 * (24 Agustus 2026).
 *
 * Yang diuji di sini bukan aritmetikanya - itu sudah dikunci 16 tes di
 * SaranPosisiTest - melainkan sambungannya: siapa yang dapat, kapan dibekukan,
 * dan apakah ia sampai ke layar kandidat maupun ke emailnya.
 *
 * @internal
 */
final class SaranPenolakanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private string $token = 'token-uji-saran';
    private int $urut     = 0;
    private int $cid      = 0;

    /** Vektor dua dimensi; cukup untuk mengurutkan, dan terbaca oleh manusia. */
    private const CV_VEKTOR   = ['pengalaman' => [1.0, 0.0]];
    private const JAUH        = ['pengalaman' => [0.0, 1.0]];
    private const SEDANG      = ['pengalaman' => [0.8, 0.6]];
    private const DEKAT       = ['pengalaman' => [0.99, 0.14]];

    protected function setUp(): void
    {
        parent::setUp();
        config('AiService')->sharedToken = $this->token;
    }

    private function lowongan(string $judul, ?array $vektor): int
    {
        return (int) (new JobModel())->insert([
            'judul'       => $judul,
            'req_skill'   => 'Stok', 'req_pendidikan' => 'D3', 'req_pengalaman' => '1 tahun',
            'vektor_json' => $vektor === null ? null : json_encode($vektor),
        ]);
    }

    /** Lamaran siap dinilai, pada posisi yang kecocokannya JAUH dari CV-nya. */
    private function fixture(?array $vektorCv = self::CV_VEKTOR): int
    {
        $n = ++$this->urut;
        if ($this->cid === 0) {
            $this->cid = (int) (new CandidateModel())->insert([
                'nama' => 'Reza', 'email' => 'reza@uji.test', 'password_hash' => 'x',
            ]);
        }

        $jid = $this->lowongan('Posisi Salah ' . $n, self::JAUH);
        $aid = (int) (new ApplicationModel())->insert([
            'candidate_id' => $this->cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);

        (new ScreeningResultModel())->insert([
            'application_id' => $aid, 'screening_job_id' => 'uji-' . $aid, 'status' => 'success',
            'score_overall'  => 0.6, 'provider' => 'dummy', 'model_version' => 'uji',
            'vektor_json'    => $vektorCv === null ? null : json_encode($vektorCv),
        ]);
        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'proses',
            'berkas'         => 'uploads/rekaman/x.wav',
        ]);
        $penilaian = new InterviewPenilaianModel();
        foreach (L::MATA_MANUSIA as $kompetensi) {
            $penilaian->insert([
                'application_id' => $aid, 'kompetensi' => $kompetensi,
                'kategori' => L::KAT_HRD, 'sumber' => L::DARI_RECRUITER,
                'bobot' => 1, 'tingkat' => '4', 'catatan' => '',
            ]);
        }

        return $aid;
    }

    private function tolak(int $aid, string $kecocokan = 'rendah')
    {
        return $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id'     => $aid,
                'status'             => 'selesai',
                'teks'               => 'Kandidat: saya mengelola stok gudang.',
                'penilaian'          => array_map(
                    static fn (string $k): array => ['kompetensi' => $k, 'nilai' => 3, 'alasan' => 'cukup'],
                    L::dariTranskrip(),
                ),
                'rekomendasi'        => 'not_recommended',
                'alasan_rekomendasi' => 'Wawancara tidak menyangkut posisi ini.',
                'kecocokan'          => $kecocokan,
                'alasan_kecocokan'   => 'Membahas pekerjaan lain.',
            ]);
    }

    /** @return list<array<string, mixed>> */
    private function saran(int $aid): array
    {
        $d = json_decode((string) (new ApplicationModel())->find($aid)['saran_json'], true);

        return is_array($d) ? $d : [];
    }

    // --- siapa yang dapat saran ---

    public function testGugurKarenaSalahPosisiDapatSaran(): void
    {
        $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $this->lowongan('Cukup Cocok', self::DEKAT);
        $this->lowongan('Agak Cocok', self::SEDANG);
        $aid = $this->fixture();

        $this->tolak($aid)->assertStatus(200);

        $this->assertSame(
            ['Paling Cocok', 'Cukup Cocok', 'Agak Cocok'],
            array_column($this->saran($aid), 'judul'),
        );
    }

    /**
     * Gugur karena wawancaranya memang kurang TIDAK dapat saran.
     *
     * Kandidat yang melamar posisi yang tepat lalu gagal di wawancaranya, kemudian
     * ditawari posisi lain, akan membacanya sebagai hadiah hiburan.
     */
    public function testGugurBukanKarenaPosisiTidakDapatSaran(): void
    {
        $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $aid = $this->fixture();

        $this->tolak($aid, 'tinggi')->assertStatus(200);

        $this->assertSame([], $this->saran($aid));
    }

    /** Posisi yang sudah pernah dilamar tidak diusulkan, termasuk yang menolak. */
    public function testPosisiYangSudahDilamarTidakDiusulkan(): void
    {
        $cocok = $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $aid   = $this->fixture();
        (new ApplicationModel())->insert([
            'candidate_id' => $this->cid, 'job_id' => $cocok, 'cv_path' => 'uploads/cv/y.pdf',
        ]);

        $this->tolak($aid)->assertStatus(200);

        $this->assertSame([], array_column($this->saran($aid), 'judul'));
    }

    /**
     * Yang TIDAK lebih cocok daripada posisi penolak tidak ditawarkan, walau
     * kotaknya jadi kosong.
     */
    public function testYangTidakLebihCocokTidakDitawarkan(): void
    {
        $this->lowongan('Sama Jauhnya', self::JAUH);
        $aid = $this->fixture();

        $this->tolak($aid)->assertStatus(200);

        $this->assertSame([], $this->saran($aid));
    }

    /**
     * Posisi yang alurnya memakai Interview User TETAP dapat saran.
     *
     * Gugur di tahap HRD pada posisi seperti itu juga berakhir 'gate_2 failed',
     * dari keputusan AI yang sama dengan posisi biasa. Versi pertama fitur ini
     * menghitung sarannya SESUDAH percabangan alur, jadi seluruh posisi
     * ber-Interview User terlewat tanpa ada yang kelihatan salah.
     */
    public function testPosisiBerInterviewUserJugaDapatSaran(): void
    {
        $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $aid = $this->fixture();
        (new JobModel())->update(
            (int) (new ApplicationModel())->find($aid)['job_id'],
            ['alur_json' => json_encode([
                'upload_cv', 'online_assessment', 'gate_1', 'penjadwalan',
                'interview_online', 'interview_user', 'gate_2', 'berkas_kontrak',
            ])],
        );

        $this->tolak($aid)->assertStatus(200);

        $this->assertSame(['Paling Cocok'], array_column($this->saran($aid), 'judul'));
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed',
        ]);
    }

    // --- ketahanan ---

    /**
     * Lamaran lama tanpa vektor CV tetap bisa diputuskan.
     *
     * Saran itu bahan tambahan yang sifatnya menolong; keputusan Gate 2 berikut
     * emailnya tidak boleh jatuh gara-gara ia tidak bisa dihitung.
     */
    public function testTanpaVektorCvKeputusanTetapJalan(): void
    {
        $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $aid = $this->fixture(null);

        $this->tolak($aid)->assertStatus(200);

        $this->assertSame([], $this->saran($aid));
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed',
        ]);
    }

    public function testLowonganTanpaVektorTidakDiusulkan(): void
    {
        $this->lowongan('Belum Dihitung', null);
        $aid = $this->fixture();

        $this->tolak($aid)->assertStatus(200);

        $this->assertSame([], $this->saran($aid));
    }

    // --- sampai ke kandidat ---

    public function testSaranIkutDiEmailPenolakan(): void
    {
        $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $aid = $this->fixture();

        $this->tolak($aid)->assertStatus(200);

        $antrian = (new EmailQueueModel())->where('template', 'hasil_gate')->orderBy('id', 'DESC')->first();
        $payload = json_decode((string) $antrian['payload_json'], true);

        $this->assertSame('Paling Cocok', $payload['saran'][0]['judul']);
    }

    public function testTombolSaranTampilDiHalamanStatusKandidat(): void
    {
        $this->lowongan('Paling Cocok', self::CV_VEKTOR);
        $aid = $this->fixture();
        $this->tolak($aid);

        $hasil = $this->withSession(['candidate_id' => $this->cid, 'candidate_nama' => 'Reza'])
            ->get('status?app=' . $aid);

        $hasil->assertSee('Lihat posisi lain yang mungkin cocok');
        $hasil->assertSee('Paling Cocok');
    }

    /** Tanpa saran, tidak ada tombol yang menggantung tanpa isi. */
    public function testTanpaSaranTidakAdaTombol(): void
    {
        $aid = $this->fixture();
        $this->tolak($aid);

        $this->withSession(['candidate_id' => $this->cid, 'candidate_nama' => 'Reza'])
            ->get('status?app=' . $aid)
            ->assertDontSee('Lihat posisi lain yang mungkin cocok');
    }
}
