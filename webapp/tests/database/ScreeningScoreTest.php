<?php

use App\Libraries\LembarPenilaian as L;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Skor CV tinggal di screening_results, bukan di teks note.
 *
 * Rekomendasi Gate 2 memakai skor gabungan Gate 1 yang DIHITUNG ULANG dari
 * kolom (screening_results.score_overall + status online_assessment), bukan
 * ditarik dari kalimat catatan pakai regex seperti sebelumnya.
 *
 * Angka acuan (bobot default): gate1 = 0.5*cv + 0.5*assessment,
 * gate2 = 0.4*gate1 + 0.6*interview, ambang hire 0.7.
 *
 * @internal
 */
final class ScreeningScoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    /**
     * Lembar interview terisi lewat jalur yang sebenarnya (revisi 12 Agustus 2026).
     *
     * Menggantikan POST ke recruiter/interview/putus, form sembilan kompetensi
     * yang diisi recruiter dari ingatan dan sudah dihapus. Sekarang tiga
     * kompetensi mata manusia ditulis saat mengunggah rekaman dan enam sisanya
     * dinilai dari transkrip; callback ai-service itulah yang menutup Gate 2.
     *
     * Skornya sama persis dengan yang lama pada nilai seragam, jadi ambang yang
     * diuji berkas ini tidak bergeser: 1 -> 0, 3 -> 50, 4 -> 75, 5 -> 100.
     */
    private function nilaiLewatTranskrip(int $aid, int $n): void
    {
        $model = new InterviewPenilaianModel();
        foreach (L::MATA_MANUSIA as $kompetensi) {
            $model->insert([
                'application_id' => $aid, 'kompetensi' => $kompetensi, 'kategori' => L::KAT_HRD,
                'sumber' => L::DARI_RECRUITER, 'bobot' => 1, 'tingkat' => (string) $n, 'catatan' => '',
            ]);
        }
        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'proses',
            'berkas' => 'uploads/rekaman/x.wav',
        ]);

        config('AiService')->sharedToken = 'token-uji';
        $this->withHeaders(['X-Token' => 'token-uji'])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid,
                'status'         => 'selesai',
                'teks'           => 'Pewawancara: Halo. Kandidat: Saya cek ulang stoknya.',
                'penilaian'      => array_map(
                    static fn (string $k): array => ['kompetensi' => $k, 'nilai' => $n, 'alasan' => 'Mengutip transkrip.'],
                    L::dariTranskrip()
                ),
            ]);
    }

    /** @return array{0:int,1:int} [candidateId, applicationId] */
    private function fixture(): array
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        // bobot_json/threshold_json dibiarkan null -> GateOne::DEFAULTS berlaku
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        return [(int) $cid, $aid];
    }

    private function screening(int $aid, float $skorCv): void
    {
        (new ScreeningResultModel())->insert([
            'application_id'   => $aid,
            'screening_job_id' => 'uji-' . $aid,
            'status'           => 'success',
            'score_overall'    => $skorCv,
            'provider'         => 'dummy',
            'model_version'    => 'uji',
        ]);
    }

    /** Siapkan lamaran yang siap diputus di Gate 2, dengan hasil assessment tertentu. */
    private function siapDiputus(int $aid, string $assessment, ?string $noteGate1 = null): void
    {
        $logger = new StageLogger();
        $logger->log($aid, 'online_assessment', $assessment, 'system');
        $logger->log($aid, 'gate_1', 'passed', 'system', $noteGate1);
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'approved',
            'scheduled_at'   => '2020-01-01 10:00:00',
            'meeting_id'     => '333',
            'join_url'       => 'https://zoom.us/j/333',
        ]);
    }

    private function skorAkhir(int $aid): string
    {
        $r = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])->orderBy('id', 'DESC')->first();

        return (string) $r['note'];
    }

    public function testAssessmentTidakLagiMengarangSkorDummy(): void
    {
        // Dulu alur assessment menyisipkan skor acak ke screening_results supaya
        // Gate 1 punya angka. Gate 1 kini diputus assessment, jadi tidak ada lagi
        // alasan mengarang skor - dan angka karangan itu ikut ke keputusan akhir.
        [$cid, $aid] = $this->fixture();

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->assertNull((new ScreeningResultModel())->latestFor($aid));
        $this->dontSeeInDatabase('screening_results', ['application_id' => $aid, 'provider' => 'dummy']);
    }

    public function testGate1DiputusAssessmentBukanSkorCv(): void
    {
        // skor CV sangat rendah, tapi assessment lulus -> Gate 1 LOLOS
        [$cid, $aid] = $this->fixture();
        $this->screening($aid, 0.05);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'passed',
        ]);
    }

    public function testAssessmentTidakLulusMenggugurkanWalauSkorCvTinggi(): void
    {
        [$cid, $aid] = $this->fixture();
        $this->screening($aid, 0.95);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'tidak']);

        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'gate_1', 'status' => 'failed',
        ]);
    }

    public function testLatestForMengambilBarisTerbaru(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.40);
        $this->screening($aid, 0.85); // percobaan ulang -> yang dipakai

        $this->assertSame(0.85, round((float) (new ScreeningResultModel())->latestFor($aid)['score_overall'], 2));
    }

    public function testGate2SkorCvTinggiMenghasilkanLolos(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.90);
        $this->siapDiputus($aid, 'passed');

        // gate2 = 0.4*0.90 (CV) + 0.6*0.75 (interview, semua Above Average) = 0.81 -> LOLOS
        $this->nilaiLewatTranskrip($aid, 4);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
        $this->assertStringContainsString('Skor akhir 81/100', $this->skorAkhir($aid));
        $this->assertStringContainsString('kemiripan CV tinggi (0,90)', $this->skorAkhir($aid));
    }

    public function testGate2SkorCvRendahMenggugurkanWalauInterviewSama(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.30);
        $this->siapDiputus($aid, 'passed');

        // gate2 = 0.4*0.30 + 0.6*0.75 = 0.57 < 0.7 -> TIDAK LOLOS
        // skor interview IDENTIK dengan test di atas; yang membedakan hanya skor CV
        $this->nilaiLewatTranskrip($aid, 4);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->assertStringContainsString('Skor akhir 57/100', $this->skorAkhir($aid));
    }

    /**
     * Tanpa skor CV, SISTEM TIDAK MEMUTUS.
     *
     * Sebelumnya bobot CV dialihkan seluruhnya ke interview. Akibatnya kandidat
     * yang CV-nya gagal terbaca dinilai dengan rumus yang berbeda dari kandidat
     * sebelahnya, diam-diam, tanpa ada yang tahu. Sekarang kandidat ditandai
     * 'flagged' dan keputusannya diserahkan ke recruiter - pola yang sama dengan
     * Gate 1.
     */
    public function testGate2TanpaSkorCvJadiKeputusanManual(): void
    {
        [, $aid] = $this->fixture();   // tanpa baris screening_results
        $this->siapDiputus($aid, 'passed');

        $this->nilaiLewatTranskrip($aid, 3);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'flagged']);
        $catatan = $this->skorAkhir($aid);
        $this->assertStringContainsString('Skor interview 50/100', $catatan, 'skor interview tetap dicatat sebagai bahan');
        $this->assertStringContainsString('keputusan diserahkan ke recruiter', $catatan);
        $this->assertStringNotContainsString('Skor akhir', $catatan, 'tidak boleh ada skor gabungan');
    }

    /** Tidak ada email ke kandidat sebelum recruiter benar-benar memutuskan. */
    public function testKandidatTanpaSkorCvBelumDikabariSebelumDiputuskan(): void
    {
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed');

        $this->nilaiLewatTranskrip($aid, 3);

        $this->dontSeeInDatabase('email_queue', ['template' => 'hasil_gate']);
    }

    public function testKeputusanManualMeloloskanDanMengabariKandidat(): void
    {
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed');
        $this->nilaiLewatTranskrip($aid, 3);

        $this->withSession($this->sesiRec)->post("recruiter/gate2/{$aid}", ['keputusan' => 'lolos']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'berkas_kontrak', 'status' => 'entered']);
        $this->seeInDatabase('email_queue', ['template' => 'hasil_gate']);
    }

    public function testKeputusanManualTidakMeloloskan(): void
    {
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed');
        $this->nilaiLewatTranskrip($aid, 3);

        $this->withSession($this->sesiRec)->post("recruiter/gate2/{$aid}", ['keputusan' => 'gagal']);

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->dontSeeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'berkas_kontrak']);
    }

    /**
     * Form yang dikirim ulang dari riwayat browser tidak boleh menimpa keputusan
     * yang sudah dibuat - kandidat akan menerima dua email yang bertentangan.
     */
    public function testKeputusanManualKeduaDitolak(): void
    {
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed');
        $this->nilaiLewatTranskrip($aid, 3);
        $this->withSession($this->sesiRec)->post("recruiter/gate2/{$aid}", ['keputusan' => 'lolos']);

        $this->withSession($this->sesiRec)->post("recruiter/gate2/{$aid}", ['keputusan' => 'gagal']);

        $this->assertSame(2, (new StageHistoryModel())   // flagged + passed, tanpa baris ketiga
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])->countAllResults());
    }

    /** Tabel Completed menyediakan tombol keputusannya, bukan cuma menyimpan diam-diam. */
    public function testTabelMenampilkanTombolKeputusanManual(): void
    {
        [, $aid] = $this->fixture();
        $this->siapDiputus($aid, 'passed');
        $this->nilaiLewatTranskrip($aid, 3);

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringContainsString('recruiter/gate2/' . $aid, $html);
        $this->assertStringContainsString('Loloskan', $html);
        // Penandanya harus terbaca, bukan tombol yang muncul entah kenapa.
        // Sejak Gate 2 menutup sendiri, DUA tombol ini justru yang tidak biasa:
        // kandidat yang datanya lengkap langsung tampil Lolos / Tidak Lolos.
        $this->assertStringContainsString('perlu keputusan', $html);
        $this->assertStringNotContainsString('Nilai Interview', $html);
    }

    /** Kandidat yang skor CV-nya ADA tetap diputus otomatis seperti sebelumnya. */
    public function testDenganSkorCvTetapOtomatisBukanManual(): void
    {
        [, $aid] = $this->fixture();
        $this->screening($aid, 0.90);
        $this->siapDiputus($aid, 'passed');

        $this->nilaiLewatTranskrip($aid, 4);

        $this->dontSeeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'flagged']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
    }

    public function testCatatanRiwayatTidakLagiMemuatTeksMentah(): void
    {
        // UI menampilkan catatan ini apa adanya, jadi tidak boleh berbentuk
        // "skor_gabungan=0.915" yang tak terbaca manusia.
        [$cid, $aid] = $this->fixture();
        $this->screening($aid, 0.68);

        $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->post("assessment/{$aid}", ['jawaban' => 'ya']);

        $r = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_1'])->orderBy('id', 'DESC')->first();
        $this->assertStringNotContainsString('skor_gabungan', (string) $r['note']);
        $this->assertStringNotContainsString('=0.', (string) $r['note']);
        $this->assertStringContainsString('assessment', (string) $r['note']);
    }
}
