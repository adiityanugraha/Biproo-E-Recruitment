<?php

use App\Libraries\LembarPenilaian as L;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use App\Models\StageHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Callback transkripsi: penilaian dari transkrip lalu Gate 2 ditutup sendiri
 * (revisi 12 Agustus 2026).
 *
 * Inilah ujung rantainya. Recruiter mengunggah rekaman dan menilai tiga
 * kompetensi yang butuh mata; sisanya dinilai dari transkrip, dan begitu
 * hasilnya mendarat di sini lembarnya lengkap sehingga keputusannya bisa
 * dihitung tanpa menunggu siapa pun lagi.
 *
 * @internal
 */
final class TranskripCallbackTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private string $token = 'token-uji-transkrip';
    private int $urut     = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config('AiService')->sharedToken = $this->token;
    }

    /**
     * @param float|null $skorCv null = screening belum menghasilkan skor
     * @param bool       $mataManusia sertakan tiga nilai dari recruiter
     */
    private function fixture(?float $skorCv = 0.8, bool $mataManusia = true): int
    {
        $n   = ++$this->urut;
        $cid = (new CandidateModel())->insert([
            'nama' => 'Reza ' . $n, 'email' => "reza{$n}@example.com", 'password_hash' => 'x',
        ]);
        $jid = (int) (new JobModel())->insert([
            'judul' => 'Admin Gudang', 'req_skill' => 'Stok', 'req_pendidikan' => 'D3', 'req_pengalaman' => '1th',
        ]);
        $aid = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);

        if ($skorCv !== null) {
            (new ScreeningResultModel())->insert([
                'application_id' => $aid, 'screening_job_id' => 'uji-' . $aid, 'status' => 'success',
                'score_overall'  => $skorCv, 'provider' => 'dummy', 'model_version' => 'uji',
            ]);
        }

        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'proses',
            'berkas'         => 'uploads/rekaman/x.wav',
        ]);

        if ($mataManusia) {
            $model = new InterviewPenilaianModel();
            foreach (L::MATA_MANUSIA as $kompetensi) {
                $model->insert([
                    'application_id' => $aid, 'kompetensi' => $kompetensi,
                    'kategori' => L::KAT_HRD, 'sumber' => L::DARI_RECRUITER,
                    'bobot' => 1, 'tingkat' => '4', 'catatan' => '',
                ]);
            }
        }

        return $aid;
    }

    /**
     * @param list<array<string, mixed>>|null $penilaian   null = pakai nilai bagus untuk enam kompetensi
     * @param string|null                     $rekomendasi INILAH yang menutup Gate 2 sejak 14 Agustus 2026.
     *                                                     null = AI tidak memutuskan -> 'flagged'.
     */
    private function kirim(int $aid, string $status = 'selesai', ?array $penilaian = null, string $catatan = '',
        ?string $token = null, ?string $rekomendasi = 'recommended')
    {
        $penilaian ??= array_map(
            static fn (string $k): array => ['kompetensi' => $k, 'nilai' => 4, 'alasan' => 'Kandidat berkata "saya cek ulang stoknya".'],
            L::dariTranskrip()
        );

        return $this->withHeaders(['X-Token' => $token ?? $this->token])
            ->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id'     => $aid,
                'status'             => $status,
                'teks'               => "Pewawancara: Selamat pagi.\nKandidat: Selamat pagi, saya Reza.",
                'penilaian'          => $penilaian,
                'catatan'            => $catatan,
                'rekomendasi'        => $rekomendasi,
                'alasan_rekomendasi' => 'Menimbang jawaban di transkrip dan kecocokan riwayat kerjanya.',
            ]);
    }

    private function gate2(int $aid): ?string
    {
        return (new StageHistoryModel())->latestStatus($aid, 'gate_2');
    }

    // --- penjagaan ---

    public function testTanpaTokenDitolak(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid, token: 'salah')->assertStatus(403);
        $this->assertNull($this->gate2($aid));
    }

    /**
     * Token kosong menolak SEMUANYA, bukan menerima semuanya.
     *
     * Instalasi yang belum dikonfigurasi tidak boleh diam-diam menyerahkan
     * rekaman wawancara ke siapa pun yang menebak URL-nya.
     */
    public function testTokenKosongMenolakSemuanya(): void
    {
        config('AiService')->sharedToken = '';
        $aid = $this->fixture();

        $this->kirim($aid, token: '')->assertStatus(403);
    }

    public function testLamaranTidakDikenalDitolak(): void
    {
        $this->kirim(9999)->assertStatus(422);
    }

    public function testStatusTidakDikenalDitolak(): void
    {
        $this->kirim($this->fixture(), 'entah')->assertStatus(422);
    }

    // --- jalur normal ---

    public function testPenilaianTersimpanBesertaAlasannya(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid)->assertStatus(200);

        $baris = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll();

        $this->assertCount(count(L::dariTranskrip()), $baris);
        // Alasan bukan hiasan: tanpa kutipan transkrip, penilaian otomatis cuma
        // angka yang tidak bisa dibantah siapa pun, termasuk kandidat yang
        // bertanya kenapa ia gugur.
        $this->assertStringContainsString('saya cek ulang stoknya', $baris[0]['catatan']);
    }

    public function testTranskripTersimpanDanStatusnyaSelesai(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid);

        $t = (new InterviewTranskripModel())->terakhirUntuk($aid);
        $this->assertSame('selesai', $t['status']);
        $this->assertStringContainsString('saya Reza', $t['teks']);
    }

    /** Sembilan kompetensi: enam dari transkrip, tiga dari recruiter. */
    public function testLembarLengkapDariDuaSumber(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid);

        $baris = (new InterviewPenilaianModel())->untukLamaran($aid);
        $this->assertCount(count(L::HRD), $baris);
        $this->assertCount(3, array_filter($baris, static fn ($b) => $b['sumber'] === L::DARI_RECRUITER));
        $this->assertCount(6, array_filter($baris, static fn ($b) => $b['sumber'] === L::DARI_AI));
    }

    public function testGateDuaDitutupOtomatisSaatKandidatBagus(): void
    {
        $aid = $this->fixture(0.85);

        $this->kirim($aid);

        $this->assertSame('passed', $this->gate2($aid));
        $this->assertSame('entered', (new StageHistoryModel())->latestStatus($aid, 'berkas_kontrak'));
    }

    public function testGateDuaMenggugurkanKandidatBernilaiRendah(): void
    {
        $aid = $this->fixture(0.55);
        $buruk = array_map(
            static fn (string $k): array => ['kompetensi' => $k, 'nilai' => 1, 'alasan' => 'Jawaban tidak menyentuh pertanyaan.'],
            L::dariTranskrip()
        );

        $this->kirim($aid, penilaian: $buruk, rekomendasi: 'not_recommended');

        $this->assertSame('failed', $this->gate2($aid));
    }

    public function testCatatanGateDuaMemuatSkorDanKompetensiTerlemah(): void
    {
        $aid   = $this->fixture(0.85);
        $nilai = array_map(
            static fn (string $k): array => ['kompetensi' => $k, 'nilai' => 4, 'alasan' => 'baik'],
            L::dariTranskrip()
        );
        $nilai[0]['nilai'] = 1;   // satu kompetensi jeblok

        $this->kirim($aid, penilaian: $nilai);

        $catatan = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])->orderBy('id', 'DESC')->first()['note'];
        $this->assertStringContainsString('Skor interview', $catatan);
        $this->assertStringContainsString('terlemah: ' . L::dariTranskrip()[0], $catatan);
    }

    /**
     * Candidate's Strengths dan Weaknesses tersimpan sebagai narasi bersumber AI.
     *
     * Keduanya dirangkum dari bahan yang sama dengan penilaian kompetensi -
     * riwayat kerja dan transkrip - jadi tidak menambah panggilan LLM.
     */
    public function testKekuatanDanKelemahanTersimpanSebagaiNarasiAi(): void
    {
        $aid = $this->fixture();

        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid,
                'status'         => 'selesai',
                'teks'           => 'Kandidat: Saya cek ulang surat jalan satu per satu.',
                'penilaian'      => [['kompetensi' => 'Communication Skills', 'nilai' => 4, 'alasan' => 'jelas']],
                'kekuatan'       => 'Terbiasa menelusuri dokumen sampai sebabnya ketemu.',
                'kelemahan'      => 'Belum pernah memakai sistem WMS berbasis aplikasi.',
            ])->assertStatus(200);

        $narasi = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'kategori' => L::KAT_NARASI])->findAll();

        $this->assertCount(2, $narasi);
        foreach ($narasi as $n) {
            $this->assertSame(L::DARI_AI, $n['sumber']);
            $this->assertSame(0, (int) $n['bobot'], 'narasi tidak pernah ikut dihitung jadi skor');
        }
        $this->assertSame(['strengths', 'weaknesses'], array_column($narasi, 'kompetensi'));
    }

    /**
     * Yang kosong tidak disimpan.
     *
     * "Tidak cukup bahan" adalah jawaban yang sah, dan baris kosong di lembar
     * profil hanya akan terbaca sebagai kolom yang gagal terisi.
     */
    public function testNarasiKosongTidakDisimpan(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid);   // tanpa kekuatan/kelemahan sama sekali

        $this->assertCount(0, (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'kategori' => L::KAT_NARASI])->findAll());
    }

    /**
     * Kunci narasi di luar milik AI DIBUANG.
     *
     * Additional Notes dan Other Remarks tetap punya recruiter, dan endpoint ini
     * terbuka bagi siapa pun yang memegang token bersama.
     */
    public function testKunciNarasiMilikRecruiterTidakBisaDitulisAi(): void
    {
        $aid = $this->fixture();

        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid, 'status' => 'selesai',
                'teks'      => 'Kandidat: halo.',
                'penilaian' => [['kompetensi' => 'Adaptability', 'nilai' => 3, 'alasan' => 'x']],
                'kekuatan'  => 'Sah.',
                'notes'     => 'Diselundupkan.',
                'remarks'   => 'Diselundupkan juga.',
            ]);

        $narasi = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'kategori' => L::KAT_NARASI])->findAll();
        $this->assertSame(['strengths'], array_column($narasi, 'kompetensi'));
    }

    /**
     * Mesin transkripsinya dicatat apa adanya dari ai-service.
     *
     * Sejak 14 Agustus 2026 ada dua - Whisper lokal dan Gemini sebagai cadangan
     * - dan hanya ai-service yang tahu mana yang akhirnya dipakai. Yang lokal
     * tidak memberi penanda pembicara, jadi bedanya terbaca di transkripnya dan
     * harus bisa dilacak saat membandingkan transkrip lama dengan yang baru.
     */
    public function testMesinTranskripsiTercatat(): void
    {
        $aid = $this->fixture();

        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid, 'status' => 'selesai',
                'teks'      => 'Kandidat: saya cek ulang surat jalannya.',
                'penilaian' => [['kompetensi' => 'Adaptability', 'nilai' => 3, 'alasan' => 'x']],
                'mesin'     => 'faster-whisper:small',
            ])->assertStatus(200);

        $this->assertSame('faster-whisper:small',
            (new InterviewTranskripModel())->terakhirUntuk($aid)['model_version']);
    }

    /** Pekerjaan dari antrian versi lama tidak membawa 'mesin' - jangan jadi kosong. */
    public function testTanpaMesinTetapAdaPenandanya(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid);

        $this->assertNotEmpty((new InterviewTranskripModel())->terakhirUntuk($aid)['model_version']);
    }

    /**
     * Sebab yang dicatat Gate 2 harus menyebut langkah yang benar-benar gagal.
     *
     * Sebabnya yang dibaca recruiter besok. Menyebut "transkripsi gagal" untuk
     * transkrip yang lengkap membuatnya mencari-cari masalah yang tidak ada.
     */
    public function testSebabFlaggedMembedakanTranskripsiDariPenilaian(): void
    {
        $adaTeks = $this->fixture();
        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $adaTeks, 'status' => 'gagal',
                'teks'    => 'Kandidat: saya cek ulang surat jalannya satu per satu.',
                'catatan' => "Client error '429 Too Many Requests'",
            ]);

        $kosong = $this->fixture();
        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $kosong, 'status' => 'gagal',
                'teks'    => '',
                'catatan' => 'Rekaman tidak memuat suara orang berbicara.',
            ]);

        $model = new StageHistoryModel();
        $this->assertStringContainsString('penilaiannya yang gagal',
            $model->where(['application_id' => $adaTeks, 'stage' => 'gate_2'])->first()['note']);
        $this->assertStringContainsString('Transkripsi tidak menghasilkan',
            $model->where(['application_id' => $kosong, 'stage' => 'gate_2'])->first()['note']);
    }

    /**
     * Callback yang datang DUA KALI mengganti lembarnya, bukan menumpuk.
     *
     * Bisa terjadi sungguhan: skor CV tidak ada membuat Gate 2 jadi 'flagged'
     * alih-alih diputus, dan penjaga "sudah diputuskan" tidak menahan apa pun.
     * `transkrip:resend` lalu mengirim ulang lamaran yang sama. Tanpa
     * penggantian, kandidat punya dua set nilai dan LembarPenilaian::skor()
     * merata-ratakan keduanya.
     */
    public function testCallbackDuaKaliTidakMenggandakanPenilaian(): void
    {
        $aid = $this->fixture(skorCv: null);   // tanpa skor CV -> flagged, bukan diputus

        $this->kirim($aid, penilaian: [['kompetensi' => 'Adaptability', 'nilai' => 2, 'alasan' => 'a']]);
        $this->kirim($aid, penilaian: [['kompetensi' => 'Adaptability', 'nilai' => 5, 'alasan' => 'b']]);

        $ai = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll();

        $this->assertCount(1, $ai, 'dua kiriman tidak boleh meninggalkan dua baris');
        $this->assertSame('5', $ai[0]['tingkat'], 'yang tersimpan kiriman terakhir');
    }

    /** Yang dihapus hanya milik AI - tiga nilai recruiter datang dari orang lain. */
    public function testPenilaianRecruiterTidakIkutTerhapus(): void
    {
        $aid = $this->fixture(skorCv: null);   // fixture menyertakan tiga nilai recruiter

        $this->kirim($aid);
        $this->kirim($aid);

        $this->assertCount(count(L::MATA_MANUSIA), (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_RECRUITER])->findAll());
    }

    /**
     * Nilai dan narasi harus masuk lewat SATU panggilan ganti().
     *
     * Keduanya bersumber 'ai'. Dua panggilan berturut membuat yang kedua
     * menghapus hasil yang pertama - kegagalan yang cuma terlihat kalau
     * keduanya diperiksa bersamaan.
     */
    public function testNilaiDanNarasiAiSamaSamaBertahan(): void
    {
        $aid = $this->fixture();

        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid, 'status' => 'selesai',
                'teks'      => 'Kandidat: saya cek ulang surat jalannya.',
                'penilaian' => [['kompetensi' => 'Adaptability', 'nilai' => 4, 'alasan' => 'a']],
                'kekuatan'  => 'Terbiasa menelusuri dokumen.',
            ]);

        $ai = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll();

        $this->assertSame(['Adaptability', 'strengths'], array_column($ai, 'kompetensi'));
    }

    /**
     * Skor yang dicatat tidak boleh terbaca sama dengan ambang saat DITOLAK.
     *
     * Ambangnya 0,7. Skor 0,6988 dibulatkan ke bilangan bulat jadi "70/100",
     * dan barisnya lalu berbunyi "Skor akhir 70/100 -> failed" - tidak bisa
     * dijelaskan kepada siapa pun, termasuk kandidat yang bertanya kenapa ia
     * gugur di angka yang sama dengan ambangnya. Terlihat pada uji e2e
     * 14 Agustus 2026, bukan oleh satu pun tes sebelum ini.
     */
    public function testSkorDiPerbatasanTidakTerbacaSamaDenganAmbang(): void
    {
        // Angka yang benar-benar keluar dari uji e2e: enam kompetensi bernilai
        // 4,4,4,4,3,3 -> skor interview 67/100, lalu
        // 0,4 x 0,742 + 0,6 x 0,67 = 0,6988 - tepat di bawah ambang 0,7.
        $aid   = $this->fixture(skorCv: 0.742, mataManusia: false);
        $nilai = [4, 4, 4, 4, 3, 3];
        $this->kirim($aid, penilaian: array_map(
            static fn (string $k, int $n): array => ['kompetensi' => $k, 'nilai' => $n, 'alasan' => 'a'],
            L::dariTranskrip(),
            $nilai
        ), rekomendasi: 'not_recommended');

        $baris = (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])->first();

        $this->assertSame('failed', $baris['status']);
        $this->assertStringNotContainsString('70/100', $baris['note'],
            'skor yang ditolak tidak boleh tercatat persis seangka dengan ambangnya');
        $this->assertStringContainsString('69,9/100', $baris['note']);
    }

    // --- keadaan yang TIDAK boleh diputus mesin ---

    /**
     * Transkripsi gagal berarti datanya tidak ada, bukan kandidatnya buruk.
     *
     * Memutus dengan data yang kurang bukan otomatisasi melainkan tebakan yang
     * dikirim lewat email.
     */
    public function testTranskripsiGagalDiserahkanKeRecruiter(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid, 'gagal', [], 'Rekaman tidak memuat suara orang berbicara.');

        $this->assertSame('flagged', $this->gate2($aid));
        $this->assertSame('gagal', (new InterviewTranskripModel())->terakhirUntuk($aid)['status']);
        $this->assertCount(0, (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll());
    }

    /** Sebabnya ikut disimpan: kolom kosong tanpa keterangan tidak bisa ditindaklanjuti. */
    public function testSebabKegagalanTersimpanUntukDibacaRecruiter(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid, 'gagal', [], 'Audio senyap sepanjang rekaman.');

        $this->assertStringContainsString('Audio senyap',
            (new InterviewTranskripModel())->terakhirUntuk($aid)['catatan']);
    }

    /**
     * Tanpa skor CV, sistem tidak memutus - sama seperti jalur lama.
     *
     * Mengalihkan bobot CV ke interview diam-diam mengubah rumusnya, sehingga
     * kandidat yang CV-nya gagal terbaca dinilai dengan aturan lain dari
     * kandidat sebelahnya tanpa ada yang tahu.
     */
    public function testTanpaSkorCvKeputusanDiserahkanKeRecruiter(): void
    {
        $aid = $this->fixture(null);

        $this->kirim($aid);

        $this->assertSame('flagged', $this->gate2($aid));
        // Penilaiannya tetap tersimpan: yang kurang skor CV, bukan interviewnya.
        $this->assertCount(6, (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll());
    }

    /** Tak satu pun kompetensi bisa dinilai: sama saja transkripnya tidak berguna. */
    public function testSemuaKompetensiNullDiserahkanKeRecruiter(): void
    {
        $aid  = $this->fixture(0.85, mataManusia: false);
        $null = array_map(
            static fn (string $k): array => ['kompetensi' => $k, 'nilai' => null, 'alasan' => 'Tidak cukup bahan.'],
            L::dariTranskrip()
        );

        $this->kirim($aid, penilaian: $null);

        $this->assertSame('flagged', $this->gate2($aid));
    }

    /**
     * Hasil mendarat di BARIS YANG BENAR, bukan di baris terbaru.
     *
     * Satu lamaran bisa punya beberapa rekaman - unggah ulang menambah baris,
     * tidak menimpa. Sebelum 14 Agustus 2026 callback menebak lewat baris
     * terbaru, sehingga hasil rekaman lama mendarat di baris rekaman baru.
     * Terlihat saat dua rekaman lamaran #72 dikirim ulang bersamaan.
     */
    public function testHasilMendaratDiBarisYangDikerjakanBukanYangTerbaru(): void
    {
        $aid   = $this->fixture();
        $model = new InterviewTranskripModel();
        $lama  = $model->terakhirUntuk($aid)['id'];
        $baru  = (int) $model->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'proses',
            'berkas'         => 'uploads/rekaman/baru.wav',
        ]);

        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $aid,
                'transkrip_id'   => $lama,
                'status'         => 'gagal',
                'teks'           => '',
                'penilaian'      => [],
                'catatan'        => 'audio rusak',
            ])->assertStatus(200);

        $this->assertSame('gagal', $model->find($lama)['status']);
        $this->assertSame('proses', $model->find($baru)['status'], 'baris terbaru tidak boleh tersentuh');
    }

    /** Id milik lamaran LAIN ditolak - endpoint ini terbuka bagi pemegang token. */
    public function testTranskripIdMilikLamaranLainDitolak(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $idB = (new InterviewTranskripModel())->terakhirUntuk($b)['id'];

        $this->withHeaders(['X-Token' => $this->token])->withBodyFormat('json')
            ->post('interview/callback', [
                'application_id' => $a, 'transkrip_id' => $idB, 'status' => 'gagal',
                'teks' => '', 'penilaian' => [], 'catatan' => 'x',
            ])->assertStatus(422);

        $this->assertNull($this->gate2($a));
    }

    // --- kekokohan ---

    /**
     * Callback yang datang dua kali tidak boleh menilai dua kali.
     *
     * Tabel penilaian tambah-saja dan tidak punya jalur perbaikan, jadi yang
     * menjaga di sini keputusan Gate 2: sekali ada, berhenti.
     */
    public function testCallbackKeduaTidakMenilaiUlang(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid);
        $this->kirim($aid)->assertStatus(200);

        $this->assertCount(count(L::HRD), (new InterviewPenilaianModel())->untukLamaran($aid));
        $this->assertCount(1, (new StageHistoryModel())
            ->where(['application_id' => $aid, 'stage' => 'gate_2'])->findAll());
    }

    /** Keputusan recruiter yang sudah dibuat lebih dulu tidak boleh ditimpa mesin. */
    public function testKeputusanRecruiterTidakDitimpa(): void
    {
        $aid = $this->fixture();
        (new StageLogger())->log($aid, 'gate_2', 'failed', 'recruiter:Irpan', 'Keputusan manual');

        $this->kirim($aid)->assertStatus(200);

        $this->assertSame('failed', $this->gate2($aid));
        $this->assertCount(0, (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll());
    }

    /**
     * Kompetensi di luar daftar yang diminta DIBUANG.
     *
     * Yang boleh masuk tabel penilaian ditentukan LembarPenilaian, bukan oleh
     * apa pun yang dikirim ke endpoint ini - dan endpoint ini terbuka bagi siapa
     * saja yang memegang token bersama.
     */
    public function testKompetensiAsingDibuang(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid, penilaian: [
            ['kompetensi' => 'Communication Skills', 'nilai' => 4, 'alasan' => 'jelas'],
            ['kompetensi' => 'Appearance', 'nilai' => 5, 'alasan' => 'tidak sah: butuh mata'],
            ['kompetensi' => 'Kesetiaan Pada Perusahaan', 'nilai' => 5, 'alasan' => 'karangan'],
        ]);

        $ai = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll();
        $this->assertCount(1, $ai);
        $this->assertSame('Communication Skills', $ai[0]['kompetensi']);
    }

    public function testNilaiDiLuarSkalaDibuang(): void
    {
        $aid = $this->fixture();

        $this->kirim($aid, penilaian: [
            ['kompetensi' => 'Communication Skills', 'nilai' => 9, 'alasan' => 'x'],
            ['kompetensi' => 'Adaptability', 'nilai' => 0, 'alasan' => 'x'],
            ['kompetensi' => 'Service Orientation', 'nilai' => 3, 'alasan' => 'x'],
        ]);

        $ai = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll();
        $this->assertCount(1, $ai);
        $this->assertSame('Service Orientation', $ai[0]['kompetensi']);
    }

    /**
     * Butir yang tidak bisa dinilai DILEWATI, bukan disimpan bernilai nol.
     *
     * Butir kosong tidak ikut dihitung LembarPenilaian::skor(); nol akan
     * menyeret rata-ratanya turun dan menggugurkan kandidat karena bahannya
     * kurang, bukan karena jawabannya.
     */
    public function testButirTanpaNilaiTidakDisimpanSebagaiNol(): void
    {
        $aid   = $this->fixture();
        $nilai = array_map(
            static fn (string $k): array => ['kompetensi' => $k, 'nilai' => 5, 'alasan' => 'baik'],
            L::dariTranskrip()
        );
        $nilai[0]['nilai'] = null;

        $this->kirim($aid, penilaian: $nilai);

        $ai = (new InterviewPenilaianModel())
            ->where(['application_id' => $aid, 'sumber' => L::DARI_AI])->findAll();
        $this->assertCount(count(L::dariTranskrip()) - 1, $ai);
        $this->assertSame([], array_filter($ai, static fn ($b) => $b['tingkat'] === '0'));
    }

    // --- unduhan rekaman untuk ai-service ---

    public function testRekamanHanyaBisaDiunduhDenganToken(): void
    {
        $aid = $this->fixture();
        $id  = (new InterviewTranskripModel())->terakhirUntuk($aid)['id'];

        $this->withHeaders(['X-Token' => 'salah'])->get('internal/rekaman/' . $id)->assertStatus(403);
    }

    public function testRekamanYangBerkasnyaHilangJadi404(): void
    {
        $aid = $this->fixture();
        $id  = (new InterviewTranskripModel())->terakhirUntuk($aid)['id'];

        $this->withHeaders(['X-Token' => $this->token])->get('internal/rekaman/' . $id)->assertStatus(404);
    }
}
