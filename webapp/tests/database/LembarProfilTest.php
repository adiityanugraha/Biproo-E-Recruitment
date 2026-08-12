<?php

use App\Libraries\LembarPenilaian as L;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Lembar profil kandidat tiga halaman (arahan atasan 12 Agustus 2026).
 *
 * Sebelumnya dokumen ini diketik manual dari data yang tersebar. Sekarang
 * dirakit dari yang sudah ada: biodata dan riwayat kerja dari hasil baca CV,
 * hasil interview dari lembar penilaian recruiter.
 *
 * @internal
 */
final class LembarProfilTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private function fixture(array $extracted = []): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta Rahma', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Retail Gadget', 'req_skill' => 'Penjualan', 'req_pendidikan' => 'SMA', 'req_pengalaman' => '1th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');

        if ($extracted !== []) {
            (new ScreeningResultModel())->insert([
                'application_id'   => $aid,
                'screening_job_id' => 'uji-' . $aid,
                'status'           => 'success',
                'score_overall'    => 0.7,
                'extracted_json'   => json_encode($extracted),
                'provider'         => 'dummy',
                'model_version'    => 'uji',
            ]);
        }

        return $aid;
    }

    /** Isi lembar penilaian lewat HTTP, seperti recruiter menekan Simpan. */
    private function nilai(int $aid, int $n = 4): void
    {
        (new InterviewModel())->insert([
            'application_id' => $aid, 'status' => 'approved',
            'scheduled_at' => '2020-01-01 10:00:00', 'meeting_id' => '1', 'join_url' => 'https://zoom.us/j/1',
        ]);
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", [
            'nilai'  => array_fill(0, count(L::HRD), $n),
            'narasi' => ['strengths' => 'Ramah dan cekatan'],
            'hasil'  => 'recommended',
        ]);
    }

    /**
     * Kandidat yang baru mengunggah CV: hanya bagian yang datanya ada.
     *
     * Ini inti aturannya. Bagian Assessment dan Interview TIDAK muncul sebagai
     * kotak kosong, melainkan tidak ada sama sekali - kotak kosong di dokumen
     * ringkasan selalu memancing pertanyaan yang tidak perlu.
     */
    public function testKandidatBaruUnggahCvHanyaPunyaHalamanPertama(): void
    {
        $aid = $this->fixture();

        $res = $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}");

        $res->assertStatus(200);
        $html = (string) $res->getBody();
        $this->assertStringContainsString('PERSONAL DATA', $html);
        $this->assertStringContainsString('WORK EXPERIENCES', $html);
        $this->assertStringNotContainsString('ASSESSMENT RESULT', $html);
        $this->assertStringNotContainsString('INTERVIEW RESULT', $html);
        $this->assertSame(1, substr_count($html, 'class="lembar"'));
    }

    /** Contoh dari atasan: baru sampai assessment, bagian interview belum ada. */
    public function testBaruSampaiAssessmentBelumPunyaBagianInterview(): void
    {
        $aid = $this->fixture(['data_pribadi' => ['nama' => 'Sinta']]);
        (new StageLogger())->log($aid, 'online_assessment', 'passed', 'system');

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('ASSESSMENT RESULT', $html);
        $this->assertStringContainsString('Online Assessment', $html);
        $this->assertStringNotContainsString('INTERVIEW RESULT', $html);
        $this->assertSame(2, substr_count($html, 'class="lembar"'));
    }

    /** Setelah dinilai, ketiganya lengkap. */
    public function testSetelahInterviewDinilaiKetigaBagianLengkap(): void
    {
        $aid = $this->fixture(['data_pribadi' => ['nama' => 'Sinta']]);
        (new StageLogger())->log($aid, 'online_assessment', 'passed', 'system');
        $this->nilai($aid);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('PERSONAL DATA', $html);
        $this->assertStringContainsString('ASSESSMENT RESULT', $html);
        $this->assertStringContainsString('INTERVIEW RESULT', $html);
        $this->assertSame(3, substr_count($html, 'class="lembar"'));
    }

    /**
     * Bagian Assessment berisi fase yang MEMANG ADA di sistem ini, bukan
     * T.I.U 5 dan DISC yang tesnya belum pernah dibangun.
     */
    public function testAssessmentBerisiFaseYangAdaBukanTiuDanDisc(): void
    {
        $aid = $this->fixture(['data_pribadi' => ['nama' => 'Sinta']]);
        (new StageLogger())->log($aid, 'online_assessment', 'passed', 'system');

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Screening CV (AI)', $html);
        $this->assertStringContainsString('Lulus', $html);
        $this->assertStringNotContainsString('T.I.U 5', $html);
        $this->assertStringNotContainsString('D.I.S.C', $html);
    }

    public function testBiodataDariCvTampilDiHalamanSatu(): void
    {
        $aid = $this->fixture(['data_pribadi' => [
            'nama' => 'Rifqi Rivaldo', 'alamat' => 'Kota Tangerang', 'agama' => 'Islam',
            'status_kawin' => 'Belum Menikah', 'jumlah_anak' => '0',
        ]]);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Rifqi Rivaldo', $html);
        $this->assertStringContainsString('Kota Tangerang', $html);
        $this->assertStringContainsString('Belum Menikah', $html);
    }

    /**
     * Kolom yang CV-nya tidak menuliskan diberi tanda "-", bukan dibiarkan
     * bolong. Keterangan kenapa kosong ada satu kali di bawah tabel, tidak
     * diulang di setiap baris - lembar ini dokumen resmi, bukan layar kerja.
     */
    public function testKolomTanpaSumberDiberiTandaStrip(): void
    {
        $aid = $this->fixture(['data_pribadi' => ['nama' => 'Rifqi']]);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('<span class="kosong">-</span>', $html);
        $this->assertStringNotContainsString('tidak tertulis di CV', $html);
        // keterangannya tetap ada sekali, di bawah tabel
        $this->assertStringContainsString('Kolom kosong berarti', $html);
    }

    public function testRiwayatKerjaLengkapDenganAlasanKeluarDanGaji(): void
    {
        $aid = $this->fixture(['riwayat' => [[
            'jabatan' => 'Admin gudang', 'perusahaan' => 'PD ADANG SAPUTRA', 'periode' => 'Januari 2022 - Maret 2024',
            'alasan_keluar' => 'Kontrak selesai', 'gaji_terakhir' => 'Rp 6.200.000', 'deskripsi' => 'Mencatat stok masuk keluar',
        ]]]);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Admin gudang', $html);
        $this->assertStringContainsString('PD ADANG SAPUTRA', $html);
        $this->assertStringContainsString('Kontrak selesai', $html);
        $this->assertStringContainsString('Rp 6.200.000', $html);
    }

    public function testHasilInterviewTerisiDariLembarPenilaian(): void
    {
        $aid = $this->fixture();
        $this->nilai($aid, 4);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        foreach (L::HRD as $kompetensi) {
            $this->assertStringContainsString(esc($kompetensi), $html);
        }
        $this->assertStringContainsString('Above Average', $html);
        $this->assertStringContainsString('Ramah dan cekatan', $html);
        $this->assertStringContainsString('Recommended', $html);
    }

    /** Radar digambar sendiri dengan SVG, tanpa pustaka grafik. */
    public function testRadarDigambarDariNilaiYangTersimpan(): void
    {
        $aid = $this->fixture();
        $this->nilai($aid, 5);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<polygon', $html);
        $this->assertStringNotContainsString('<script src', $html, 'tidak boleh menarik pustaka luar');
    }

    public function testBelumDinilaiTidakMenggambarRadar(): void
    {
        $aid = $this->fixture();

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringNotContainsString('<polygon', $html);
    }

    public function testTombolProfilAdaDiTabelCompleted(): void
    {
        $aid = $this->fixture();
        $this->nilai($aid);

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringContainsString('recruiter/profil/' . $aid, $html);
    }

    /**
     * Satu tombol, bukan dua. Lembar profil sudah memuat biodata, riwayat kerja,
     * dan hasil interview jadi satu, jadi tidak ada lagi tombol terpisah yang
     * membuka halaman detail dari tabel ini.
     */
    public function testKolomSummaryMemuatSatuTombolViewKeLembarProfil(): void
    {
        $aid = $this->fixture();
        $this->nilai($aid);

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringContainsString('recruiter/profil/' . $aid, $html);
        $this->assertStringNotContainsString('recruiter/review/' . $aid, $html,
            'tombol detail terpisah sudah tidak ada di tabel tahap');
        $this->assertStringNotContainsString("segera('Summary')", $html,
            'kolom Summary tidak lagi sekadar modal segera hadir');
    }

    public function testKandidatTidakBisaMembukaLembarProfil(): void
    {
        $aid = $this->fixture();

        $this->withSession(['candidate_id' => 1, 'candidate_nama' => 'Sinta'])
            ->get("recruiter/profil/{$aid}")
            ->assertRedirectTo(site_url('recruiter/login'));
    }

    public function testLamaranTidakAdaDialihkanBukanError(): void
    {
        $this->withSession($this->sesiRec)->get('recruiter/profil/999999')
            ->assertRedirectTo(site_url('recruiter'));
    }
}
