<?php

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\InterviewPenilaianModel;
use App\Models\JobModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Penilaian interview lewat rubrik per kompetensi.
 *
 * Menggantikan slider 0-100 yang menempel di sel tabel tab Completed. Angka itu
 * digeser sesuka hati, tidak punya dasar apa pun, dan ikut menentukan Gate 2 -
 * tidak ada yang bisa menjelaskan kenapa 70 dan bukan 65.
 *
 * @internal
 */
final class PenilaianInterviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private function fixture(bool $denganRubrik = true): int
    {
        $rubrik = [
            ['pertanyaan' => 'Ceritakan tentang diri Anda.', 'kompetensi' => 'Pembuka', 'kategori' => 'Lainnya'],
            ['pertanyaan' => 'Bagaimana Anda menghadapi penolakan?', 'kompetensi' => 'Ketahanan',
                'kategori' => 'Soft Skill', 'indikator' => 'Tetap tenang.', 'red_flag' => 'Menyalahkan pelanggan.', 'bobot' => 5],
            ['pertanyaan' => 'Bandingkan dua produk ini.', 'kompetensi' => 'Perbandingan Spesifikasi',
                'kategori' => 'Hard Skill', 'indikator' => 'Menggali pola pakai.', 'red_flag' => 'Hafal angka saja.', 'bobot' => 4],
        ];
        $jid = (int) (new JobModel())->insert([
            'judul'           => 'Retail Gadget Erafone',
            'req_skill'       => 'Penjualan',
            'req_pendidikan'  => 'SMA',
            'req_pengalaman'  => '1 tahun',
            'pertanyaan_json' => $denganRubrik ? json_encode($rubrik) : null,
        ]);
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new InterviewModel())->insert([
            'application_id' => $aid,
            // sesi sudah lewat -> masuk tab Completed, siap dinilai
            'scheduled_at'   => (new DateTime())->modify('-3 hours')->format('Y-m-d H:i:s'),
            'status'         => 'approved',
            'join_url'       => 'https://us04web.zoom.us/j/1',
            'meeting_id'     => '1',
        ]);

        return $aid;
    }

    public function testFormMenampilkanTiapButirRubrikBerikutIndikatornya(): void
    {
        $aid = $this->fixture();

        $res = $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid);

        $res->assertStatus(200);
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Ketahanan', $html);
        $this->assertStringContainsString('Tetap tenang.', $html);
        $this->assertStringContainsString('Menyalahkan pelanggan.', $html);
        foreach (['Kurang', 'Cukup', 'Baik'] as $tingkat) {
            $this->assertStringContainsString($tingkat, $html);
        }
    }

    /** Butir "Lainnya" (gaji, pembuka) ditanyakan tapi bukan penilaian kemampuan. */
    public function testButirTanpaBobotTidakDapatKotakNilai(): void
    {
        $aid = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();

        $this->assertStringNotContainsString('nilai[0]', $html);
        $this->assertStringContainsString('nilai[1]', $html);
        $this->assertStringContainsString('nilai[2]', $html);
    }

    public function testSkorDihitungDariBobotBukanDiketikManusia(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai' => [1 => 'baik', 2 => 'baik'],
        ]);

        $catatan = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'interview_online'])
            ->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('Skor interview 100/100', $catatan['note']);
    }

    public function testPenilaianTersimpanPerKompetensi(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai'   => [1 => 'kurang', 2 => 'baik'],
            'catatan' => [1 => 'jawaban terlalu umum'],
        ]);

        $baris = (new InterviewPenilaianModel())->untukLamaran($aid);
        $this->assertCount(2, $baris);
        $this->assertSame('Ketahanan', $baris[0]['kompetensi']);
        $this->assertSame('kurang', $baris[0]['tingkat']);
        $this->assertSame(5, (int) $baris[0]['bobot']);
        $this->assertSame('jawaban terlalu umum', $baris[0]['catatan']);
    }

    /** Inilah yang membuat keputusan bisa dijelaskan ke kandidat yang bertanya. */
    public function testKompetensiTerlemahIkutDicatatDiRiwayat(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai' => [1 => 'kurang', 2 => 'baik'],
        ]);

        $gate2 = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])
            ->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('terlemah: Ketahanan', $gate2['note']);
    }

    /**
     * Butir kosong TIDAK dihitung nol - itu menggugurkan kandidat karena
     * recruiter belum selesai mengklik, bukan karena jawabannya buruk.
     */
    public function testPenilaianSeparuhDitolakBukanDihitungNol(): void
    {
        $aid = $this->fixture();

        $res = $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai' => [1 => 'baik'],
        ]);

        $res->assertStatus(302);
        $this->assertNull((new StageHistoryModel())->latestStatus($aid, 'gate_2'));
        $this->assertSame([], (new InterviewPenilaianModel())->untukLamaran($aid));
    }

    /** Bobot datang dari rubrik tersimpan, bukan dari kiriman browser. */
    public function testBobotPalsuDariBrowserDiabaikan(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai' => [1 => 'baik', 2 => 'baik'],
            'bobot' => [1 => 99],
        ]);

        $this->assertSame(5, (int) (new InterviewPenilaianModel())->untukLamaran($aid)[0]['bobot']);
    }

    /** Lowongan tanpa rubrik tetap memakai slider - jalur lama yang jujur. */
    public function testLowonganTanpaRubrikMasihMemakaiSlider(): void
    {
        $aid = $this->fixture(denganRubrik: false);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();
        $this->assertStringContainsString('belum punya rubrik penilaian', $html);
        $this->assertStringContainsString('name="skor"', $html);

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, ['skor' => 80]);
        $this->assertNotNull((new StageHistoryModel())->latestStatus($aid, 'gate_2'));
    }

    public function testKandidatYangSudahDiputusTidakBisaDinilaiUlang(): void
    {
        $aid = $this->fixture();
        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai' => [1 => 'baik', 2 => 'baik'],
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();

        $this->assertStringContainsString('sudah diputuskan', $html);
        $this->assertStringNotContainsString('name="nilai[1]"', $html);
    }

    public function testTabelTahapMenautkanKeHalamanPenilaianBukanSliderInline(): void
    {
        $aid = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringContainsString('recruiter/nilai/' . $aid, $html);
        $this->assertStringNotContainsString('type="range"', $html);
    }

    public function testKandidatTidakBisaMembukaFormPenilaian(): void
    {
        $aid = $this->fixture();

        $this->withSession(['candidate_id' => 1, 'candidate_nama' => 'Sinta'])
            ->get('recruiter/nilai/' . $aid)
            ->assertRedirectTo(site_url('recruiter/login'));
    }
}
