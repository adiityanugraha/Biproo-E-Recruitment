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

    /**
     * Lembar penilaian terisi lewat jalur yang sebenarnya (revisi 12 Agustus 2026).
     *
     * Dua sumber, seperti di produksi: tiga kompetensi mata manusia beserta
     * narasinya ditulis recruiter saat mengunggah rekaman, sisanya dinilai dari
     * transkrip dan mendarat lewat callback ai-service. Callback itu juga yang
     * menutup Gate 2, jadi memanggilnya di sini sekaligus menyiapkan keadaan
     * yang dibaca baris Interview Result di lembar profil.
     */
    private function nilai(int $aid, int $n = 4): void
    {
        (new InterviewModel())->insert([
            'application_id' => $aid, 'status' => 'approved',
            'scheduled_at' => '2020-01-01 10:00:00', 'meeting_id' => '1', 'join_url' => 'https://zoom.us/j/1',
        ]);

        $model = new InterviewPenilaianModel();
        foreach (L::MATA_MANUSIA as $kompetensi) {
            $model->insert([
                'application_id' => $aid, 'kompetensi' => $kompetensi, 'kategori' => L::KAT_HRD,
                'sumber' => L::DARI_RECRUITER, 'bobot' => 1, 'tingkat' => (string) $n, 'catatan' => '',
            ]);
        }
        $model->insert([
            'application_id' => $aid, 'kompetensi' => 'strengths', 'kategori' => L::KAT_NARASI,
            'sumber' => L::DARI_RECRUITER, 'bobot' => 0, 'tingkat' => '', 'catatan' => 'Ramah dan cekatan',
        ]);

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
     * Age terisi walau CV cuma menuliskan tanggal lahir.
     *
     * Kasus nyata: CV Reza Rahmansyah mencantumkan "Medan/01 Mei 1991" tanpa
     * pernah menyebut angka usia, dan model bahasa memang dilarang menghitung
     * sendiri (structure.py aturan 8). Perhitungannya di PHP, dijalankan ulang
     * tiap lembar dibuka - jadi tidak pernah basi.
     */
    public function testUsiaDihitungDariTanggalLahir(): void
    {
        $aid = $this->fixture(['data_pribadi' => [
            'nama' => 'Reza Rahmansyah', 'tempat_lahir' => 'Medan', 'tanggal_lahir' => '01 Mei 1991',
        ]]);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        // Dihitung terpisah, tidak lewat helper yang sedang diuji.
        $harap = (new DateTimeImmutable('1991-05-01'))->diff(new DateTimeImmutable())->y;
        $this->assertMatchesRegularExpression(
            '#<th>Age</th>\s*<td>' . $harap . '</td>#',
            $html,
            'Age harus terisi dari tanggal lahir, bukan "-"'
        );
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
            'bidang_usaha' => 'pengadaan barang',
            'alasan_keluar' => 'Kontrak selesai', 'gaji_terakhir' => 'Rp 6.200.000', 'deskripsi' => 'Mencatat stok masuk keluar',
        ]]]);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Admin gudang', $html);
        $this->assertStringContainsString('PD ADANG SAPUTRA', $html);
        $this->assertStringContainsString('pengadaan barang', $html);
        $this->assertStringContainsString('Kontrak selesai', $html);
        $this->assertStringContainsString('Rp 6.200.000', $html);
        $this->assertStringContainsString('Reason for Leaving', $html);
        $this->assertStringContainsString('Last Salary', $html);
        $this->assertStringContainsString('Description', $html);
    }

    /**
     * Barisnya TETAP muncul walau CV tidak menuliskannya, diisi "-".
     *
     * Kalau baris kosong dihilangkan, tata letaknya berpindah-pindah antar
     * kandidat dan dokumen jadi sulit dibandingkan. Dokumen aslinya juga
     * menulis ":  -" untuk Reason for Leaving yang tidak diisi.
     */
    public function testBarisRincianTetapAdaWalauKosong(): void
    {
        $aid = $this->fixture(['riwayat' => [[
            'jabatan' => 'Kasir', 'perusahaan' => 'Toko Maju', 'periode' => '2020-2022',
        ]]]);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Reason for Leaving', $html);
        $this->assertStringContainsString('Last Salary', $html);
        $this->assertStringContainsString('Description', $html);
    }

    public function testHasilInterviewTerisiDariLembarPenilaian(): void
    {
        $aid = $this->fixture(['pengalaman' => 'Sales 2 tahun']);
        $this->nilai($aid, 4);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        foreach (L::HRD as $kompetensi) {
            $this->assertStringContainsString(esc($kompetensi), $html);
        }
        $this->assertStringContainsString('Above Average', $html);
        $this->assertStringContainsString('Ramah dan cekatan', $html);
        // Skor CV 0,7 + interview 75/100 melewati ambang: Gate 2 lolos, dan
        // Interview Result mengikutinya tanpa recruiter memilih apa pun.
        $this->assertStringContainsString('Recommended', $html);
    }

    /**
     * Interview Result TIDAK BISA bertentangan dengan keputusan Gate 2.
     *
     * Dulu recruiter memilih Recommended / Not Recommended sendiri di form,
     * sebelum kelulusan dihitung. Sekarang nilainya turunan: nilai serendah
     * apa pun yang berakhir tidak lolos akan berbunyi Not Recommended.
     */
    public function testHasilMengikutiKeputusanGateDua(): void
    {
        $aid = $this->fixture(['pengalaman' => 'Sales 2 tahun']);
        $this->nilai($aid, 1);   // semua Poor: skor interview 0

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Not Recommended', $html);
    }

    /** Belum diputus (skor CV tidak ada, Gate 2 diserahkan ke recruiter): belum ada hasil. */
    public function testBelumDiputusTidakMenampilkanHasil(): void
    {
        $aid = $this->fixture();
        $this->nilai($aid, 4);

        $html = (string) $this->withSession($this->sesiRec)->get("recruiter/profil/{$aid}")->getBody();

        $this->assertStringContainsString('Above Average', $html, 'penilaiannya tetap tampil');
        $this->assertStringNotContainsString('Recommended', $html);
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
