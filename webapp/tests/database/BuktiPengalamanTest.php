<?php

use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Bukti pengalaman di halaman review recruiter.
 *
 * Skor kemiripan mengukur tumpang tindih makna, bukan kompetensi. Terukur pada
 * embedding produksi: CV yang cuma menyalin kata dari iklan lowongan mendapat
 * 0,9592 - lebih tinggi daripada backend sungguhan berpengalaman 3 tahun
 * (0,9042). Lewat jalur produksi penuh angkanya bahkan 1,0000, karena LLM
 * mengosongkan bidang pengalaman penyalin sehingga bobotnya dinormalkan ke
 * skill dan pendidikan - dua bidang yang persis disalin dari iklan.
 * Yang membedakan keduanya bukan makna, tapi ada tidaknya nama tempat kerja
 * dan rentang waktu.
 *
 * ai-service mengeluarkan riwayat itu di panggilan LLM yang sama (structure.py);
 * di sini diuji bahwa CI4 benar-benar menampilkannya. Sebelum ini flags_json
 * sudah tersimpan bertahun-tahun tapi tidak pernah dilihat siapa pun.
 *
 * @internal
 */
final class BuktiPengalamanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private function fixture(): int
    {
        $cid = (new CandidateModel())->insert(['nama' => 'Sinta', 'email' => 'sinta@example.com', 'password_hash' => 'x']);
        $jid = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');

        return $aid;
    }

    private function simpanHasil(int $aid, array $riwayat, array $flags, ?float $skor = 0.9592): void
    {
        (new ScreeningResultModel())->insert([
            'application_id'   => $aid,
            'screening_job_id' => bin2hex(random_bytes(8)),
            'status'           => 'success',
            'score_overall'    => $skor,
            'extracted_json'   => json_encode(['riwayat' => $riwayat], JSON_UNESCAPED_UNICODE),
            'flags_json'       => json_encode($flags, JSON_UNESCAPED_UNICODE),
            'provider'         => 'ai-service',
            'model_version'    => 'uji',
        ]);
    }

    private function review(int $aid): string
    {
        return (string) $this->withSession($this->sesiRec)->get("recruiter/review/{$aid}")->getBody();
    }

    public function testRiwayatKerjaTampilDiHalamanReview(): void
    {
        $aid = $this->fixture();
        $this->simpanHasil($aid, [
            ['jabatan' => 'Backend Developer', 'perusahaan' => 'PT Sinar Digital', 'periode' => 'Maret 2022 - Januari 2025'],
            ['jabatan' => 'Asisten Praktikum', 'perusahaan' => 'Universitas Diponegoro', 'periode' => '2021 - 2022'],
        ], []);

        $html = $this->review($aid);

        $this->assertStringContainsString('PT Sinar Digital', $html);
        $this->assertStringContainsString('Maret 2022 - Januari 2025', $html);
        $this->assertStringContainsString('Universitas Diponegoro', $html);
    }

    /**
     * Skor 1,0000 bukan angka karangan di uji ini: itu yang benar-benar keluar
     * saat LLM mengosongkan bidang pengalaman penyalin, sehingga bobotnya
     * dinormalkan ke skill dan pendidikan - dua bidang yang persis disalin dari
     * iklan. Backend sungguhan di lowongan yang sama cuma dapat 0,8917.
     */
    public function testPenyalinKataKunciDiperingatkanMeskiSkornyaTinggi(): void
    {
        $aid = $this->fixture();
        $this->simpanHasil($aid, [], ['kontekstual', 'tanpa_riwayat_kerja'], 1.0);

        $html = $this->review($aid);

        $this->assertStringContainsString('Tidak ada riwayat kerja terbaca', $html);
        // skornya TIDAK diturunkan - menghukum lewat angka berarti menebak
        $this->assertStringContainsString('tinggi', $html);
    }

    public function testTanpaFlagTidakAdaPeringatan(): void
    {
        $aid = $this->fixture();
        $this->simpanHasil($aid, [['jabatan' => 'Kasir', 'perusahaan' => 'Indomaret', 'periode' => '2020-2023']], ['kontekstual']);

        $html = $this->review($aid);

        $this->assertStringNotContainsString('Tidak ada riwayat kerja terbaca', $html);
        $this->assertStringContainsString('Indomaret', $html);
    }

    /**
     * Kandidat yang screening-nya belum jalan tidak boleh terlihat seperti
     * kandidat yang terbukti tidak punya pengalaman - itu dua hal berbeda.
     */
    public function testBelumAdaHasilScreeningTidakDituduhTanpaBukti(): void
    {
        $aid = $this->fixture();

        $html = $this->review($aid);

        $this->assertStringNotContainsString('Tidak ada riwayat kerja terbaca', $html);
        $this->assertStringContainsString('screening CV belum selesai', $html);
    }

    public function testExtractedJsonRusakTidakMenjatuhkanHalaman(): void
    {
        $aid = $this->fixture();
        (new ScreeningResultModel())->insert([
            'application_id'   => $aid,
            'screening_job_id' => 'x',
            'status'           => 'success',
            'score_overall'    => 0.7,
            'extracted_json'   => 'bukan json',
            'flags_json'       => 'bukan json juga',
            'provider'         => 'ai-service',
            'model_version'    => 'uji',
        ]);

        $res = $this->withSession($this->sesiRec)->get("recruiter/review/{$aid}");

        $res->assertStatus(200);
        $this->assertStringNotContainsString('Tidak ada riwayat kerja terbaca', (string) $res->getBody());
    }


    /** Riwayat datang dari LLM yang membaca berkas unggahan kandidat. */
    public function testRiwayatDiEscapeSebelumDitampilkan(): void
    {
        $aid = $this->fixture();
        $this->simpanHasil($aid, [
            ['jabatan' => '<script>alert(1)</script>', 'perusahaan' => 'PT X', 'periode' => '2020'],
        ], []);

        $html = $this->review($aid);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Penanda "pembacaan kasar" dihapus atas permintaan 4 Agustus 2026. Uji ini
     * menjaga agar ia tidak kembali diam-diam, termasuk saat LLM benar-benar
     * gagal - kondisi yang dulu memunculkannya.
     */
    public function testPenandaPembacaanKasarSudahTidakAdaLagi(): void
    {
        $aid = $this->fixture();
        $this->simpanHasil($aid, [], ['llm_gagal', 'skill_kosong'], 0.7034);

        $html = $this->review($aid);

        $this->assertStringNotContainsString('pembacaan kasar', $html);
        $this->assertStringNotContainsString('Jangan bandingkan angka ini', $html);
        // skornya sendiri tetap tampil seperti biasa
        $this->assertStringContainsString('sedang', $html);
    }

    /**
     * Beda penting: LLM mati bukan bukti kandidat tanpa pengalaman. Pesan lama
     * mengatakan "screening belum selesai atau CV tidak memuat riwayat kerja",
     * dan saat kuota habis keduanya bohong.
     */
    public function testRiwayatKosongKarenaLlmMatiTidakDisalahartikan(): void
    {
        $aid = $this->fixture();
        $this->simpanHasil($aid, [], ['llm_gagal'], 0.66);

        $html = $this->review($aid);

        $this->assertStringContainsString('bukan berarti kandidat ini tanpa pengalaman kerja', $html);
        $this->assertStringNotContainsString('Tidak ada riwayat kerja terbaca', $html);
    }
}
