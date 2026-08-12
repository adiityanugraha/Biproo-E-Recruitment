<?php

use App\Libraries\LembarPenilaian as L;
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

    /**
     * Lembar penuh: kesembilan kompetensi terisi. Dipakai uji yang tidak sedang
     * menguji kelengkapan itu sendiri.
     *
     * @return array<string, mixed>
     */
    private function lembar(int $nilai = 5, array $tambahan = []): array
    {
        return ['nilai' => array_fill(0, count(L::HRD), $nilai)] + $tambahan;
    }

    public function testFormMenampilkanSembilanKompetensiLembarBiproo(): void
    {
        $aid = $this->fixture();

        $res = $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid);

        $res->assertStatus(200);
        $html = (string) $res->getBody();
        foreach (L::HRD as $kompetensi) {
            // esc(): "Attitude & Professionalism" tampil sebagai "&amp;" di HTML
            $this->assertStringContainsString(esc($kompetensi), $html, $kompetensi . ' harus ada di lembar');
        }
        foreach (L::SKALA as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /** Kotak narasi lembar BIPROO ikut tersedia. */
    public function testFormMenyediakanKotakNarasi(): void
    {
        $aid = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();

        foreach (array_keys(L::NARASI) as $kunci) {
            $this->assertStringContainsString('narasi[' . $kunci . ']', $html);
        }
    }

    /** Slider 0-100 sudah tidak ada: lembarnya berlaku untuk semua posisi. */
    public function testSliderLamaSudahTidakAda(): void
    {
        $aid = $this->fixture(denganRubrik: false);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();

        $this->assertStringNotContainsString('name="skor"', $html);
        $this->assertStringContainsString('nilai[0]', $html, 'posisi tanpa bank soal tetap dapat lembar');
    }

    public function testSkorDihitungDariLembarBukanDiketikManusia(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, $this->lembar(5));

        $catatan = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'interview_online'])
            ->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('Skor interview 100/100', $catatan['note']);
    }

    /** Average di semua butir = tepat setengah skala. */
    public function testAverageMenghasilkanLimaPuluh(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, $this->lembar(3));

        $catatan = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'interview_online'])
            ->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('Skor interview 50/100', $catatan['note']);
    }

    public function testPenilaianTersimpanPerKompetensi(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, $this->lembar(4));

        $baris = (new InterviewPenilaianModel())->untukLamaran($aid);
        $hrd   = array_values(array_filter($baris, static fn ($b) => $b['kategori'] === L::KAT_HRD));
        $this->assertCount(9, $hrd);
        $this->assertSame('Appearance', $hrd[0]['kompetensi']);
        $this->assertSame('4', $hrd[0]['tingkat']);
    }

    public function testNarasiIkutTersimpan(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, $this->lembar(4, [
            'narasi' => ['strengths' => 'Komunikatif dan rapi', 'weaknesses' => 'Kurang pengalaman retail'],
        ]));

        $baris  = (new InterviewPenilaianModel())->untukLamaran($aid);
        $narasi = array_values(array_filter($baris, static fn ($b) => $b['kategori'] === L::KAT_NARASI));

        $this->assertCount(2, $narasi);
        $this->assertSame('Komunikatif dan rapi', $narasi[0]['catatan']);
    }

    /** Form tidak lagi punya pilihan Recommended - hasilnya diturunkan dari Gate 2. */
    public function testFormTidakPunyaPilihanHasil(): void
    {
        $aid = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();

        $this->assertStringNotContainsString('name="hasil"', $html);
        $this->assertStringNotContainsString('Not Recommended', $html);
    }

    /** Inilah yang membuat keputusan bisa dijelaskan ke kandidat yang bertanya. */
    public function testKompetensiTerlemahIkutDicatatDiRiwayat(): void
    {
        $aid   = $this->fixture();
        $nilai = array_fill(0, count(L::HRD), 4);
        $nilai[1] = 1;   // Communication Skills = Poor

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, ['nilai' => $nilai]);

        $gate2 = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])
            ->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('terlemah: Communication Skills', $gate2['note']);
    }

    /**
     * Butir kosong TIDAK dihitung nol - itu menggugurkan kandidat karena
     * recruiter belum selesai mengklik, bukan karena jawabannya buruk.
     */
    public function testPenilaianSeparuhDitolakBukanDihitungNol(): void
    {
        $aid = $this->fixture();

        $res = $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, [
            'nilai' => [0 => 5, 1 => 4],
        ]);

        $res->assertStatus(302);
        $this->assertNull((new StageHistoryModel())->latestStatus($aid, 'gate_2'));
        $this->assertSame([], (new InterviewPenilaianModel())->untukLamaran($aid));
    }

    /** Nama kompetensi datang dari lembar, bukan dari kiriman browser. */
    public function testKompetensiPalsuDariBrowserDiabaikan(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, $this->lembar(4, [
            'kompetensi' => [0 => 'Karangan'],
        ]));

        $baris = (new InterviewPenilaianModel())->untukLamaran($aid);
        $this->assertSame('Appearance', $baris[0]['kompetensi']);
        $this->assertStringNotContainsString('Karangan', json_encode($baris));
    }

    public function testKandidatYangSudahDiputusTidakBisaDinilaiUlang(): void
    {
        $aid = $this->fixture();
        $this->withSession($this->sesiRec)->post('recruiter/interview/putus/' . $aid, $this->lembar(4));

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/nilai/' . $aid)->getBody();

        $this->assertStringContainsString('sudah diputuskan', $html);
        $this->assertStringNotContainsString('name="nilai[0]"', $html);
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
