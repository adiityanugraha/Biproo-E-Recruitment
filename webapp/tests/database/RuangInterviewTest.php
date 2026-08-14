<?php

use App\Controllers\Recruiter;
use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use App\Libraries\LembarPenilaian as L;
use App\Libraries\StageLogger;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\InterviewPenilaianModel;
use App\Models\InterviewTranskripModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Ruang interview recruiter (revisi 12 Agustus 2026).
 *
 * Satu halaman untuk satu kandidat, dibuka berdampingan dengan jendela Zoom:
 * tautan ruangannya, tiga pertanyaan dari CV kandidat itu, dan tempat
 * mengunggah rekaman setelah wawancara selesai.
 *
 * Menggantikan tombol "Pertanyaan" yang dulu membuka daftar milik LOWONGAN dan
 * sama untuk semua pelamarnya.
 *
 * @internal
 */
final class RuangInterviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private const RIWAYAT = [[
        'jabatan' => 'Clerk Distribution Center', 'perusahaan' => 'PT. Indomarco Prismatama',
        'periode' => '2012 - 2015', 'deskripsi' => 'Menginput data barang masuk dan keluar.',
    ]];

    /** Berkas sementara yang dibuat uji ini, dibersihkan di tearDown. */
    private array $sampah = [];

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        foreach ($this->sampah as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    /** Nomor urut kandidat: email dan slot jadwal keduanya wajib unik. */
    private int $urut = 0;

    private function fixture(array $riwayat = self::RIWAYAT): int
    {
        $n   = ++$this->urut;
        $cid = (new CandidateModel())->insert([
            'nama' => 'Reza ' . $n, 'email' => "reza{$n}@example.com", 'password_hash' => 'x',
        ]);
        $jid = (int) (new JobModel())->insert([
            'judul'          => 'Admin Gudang',
            'req_skill'      => 'Administrasi stok, Excel',
            'req_pendidikan' => 'D3 semua jurusan',
            'req_pengalaman' => '1 tahun logistik',
            'deskripsi'      => 'Mengelola pencatatan stok masuk-keluar gudang.',
        ]);
        $aid = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        // Jam diturunkan dari $aid: interviews punya indeks unik terfilter pada
        // scheduled_at, jadi dua kandidat dengan jam yang sama menabraknya.
        (new InterviewModel())->insert([
            'application_id' => $aid, 'status' => 'approved',
            'scheduled_at'   => sprintf('2030-08-20 %02d:00:00', 8 + $aid % 10),
            'meeting_id'     => '9988', 'join_url' => 'https://us04web.zoom.us/j/9988',
        ]);

        if ($riwayat !== []) {
            (new ScreeningResultModel())->insert([
                'application_id'   => $aid,
                'screening_job_id' => 'uji-' . $aid,
                'status'           => 'success',
                'score_overall'    => 0.7,
                'extracted_json'   => json_encode(['riwayat' => $riwayat]),
                'provider'         => 'dummy',
                'model_version'    => 'uji',
            ]);
        }

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

    private function tigaPertanyaan(string $sumber = 'pengalaman'): void
    {
        $this->mockAi(['sumber' => $sumber, 'pertanyaan' => [
            'Saat Anda menjabat Clerk Distribution Center di PT. Indomarco Prismatama, ceritakan ...',
            'Bayangkan ada selisih stok, apa yang Anda lakukan?',
            'Bagaimana Anda memastikan ketelitian data sehari-hari?',
        ]]);
    }

    /**
     * Siapkan $_FILES seolah ada unggahan.
     *
     * Lewat superglobal, bukan lewat helper uji: FeatureTestTrait CI4 tidak
     * punya cara mengirim berkas. Yang BISA diuji begini hanya aturan
     * validasinya - CI4 memberi FileRules cabang khusus lingkungan uji. Langkah
     * memindahkan berkas tidak bisa: UploadedFile::move() memanggil
     * is_uploaded_file(), yang menurut definisinya palsu kecuali berkasnya
     * benar-benar datang lewat POST HTTP. Bagian itu diperiksa manual.
     */
    private function siapkanBerkas(string $namaAsli, int $ukuran = 1024): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rek');
        file_put_contents($tmp, $this->wav($ukuran));
        $this->sampah[] = $tmp;

        // Lewat layanan superglobals, bukan menulis $_FILES langsung: CI4
        // memotret superglobal itu saat layanannya dibuat, jadi penulisan
        // langsung tidak terlihat oleh FileCollection.
        service('superglobals')->setFilesArray(['rekaman' => [
            'name'     => $namaAsli,
            'type'     => 'audio/mp4',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => $ukuran,
        ]]);
    }

    /**
     * WAV senyap yang sah, seukuran yang diminta.
     *
     * Harus berkas audio SUNGGUHAN, bukan sekadar berakhiran .wav: yang
     * diperiksa isi berkasnya lewat finfo, bukan namanya. Itu memang yang
     * diinginkan - berkas palsu bernama .m4a tidak boleh lolos.
     */
    private function wav(int $ukuran): string
    {
        $n = max(0, $ukuran - 44);   // 44 byte kepala RIFF

        return 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            . 'data' . pack('V', $n) . str_repeat("\x00", $n);
    }

    /**
     * Wadah ISO-BMFF, merek bisa dipilih.
     *
     * M4A sungguhan datang dengan merek berbeda-beda, dan itu yang dulu
     * menentukan diterima atau tidak: hanya merek 'M4A ' terbaca audio/x-m4a,
     * sedangkan 'mp42' dan 'isom' - sama-sama m4a sah - terbaca video/mp4.
     */
    private function m4a(string $merek = 'M4A '): string
    {
        $b    = $merek . pack('N', 512) . 'M4A mp42isomiso2';
        $ftyp = pack('N', 8 + strlen($b)) . 'ftyp' . $b;

        return $ftyp . pack('N', 8 + 64) . 'mdat' . str_repeat("\x00", 64);
    }

    private function siapkanIsi(string $namaAsli, string $isi): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rek');
        file_put_contents($tmp, $isi);
        $this->sampah[] = $tmp;

        service('superglobals')->setFilesArray(['rekaman' => [
            'name' => $namaAsli, 'type' => 'audio/mp4', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => strlen($isi),
        ]]);
    }

    // --- halaman ---

    public function testHalamanMemuatZoomPertanyaanDanUnggahan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();

        $res = $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $res->assertStatus(200);
        $html = (string) $res->getBody();
        $this->assertStringContainsString('us04web.zoom.us/j/9988', $html);
        $this->assertStringContainsString('PT. Indomarco Prismatama', $html);
        $this->assertStringContainsString('name="rekaman"', $html);
        $this->assertSame(3, substr_count($html, 'name="pertanyaan[]"'));
    }

    /**
     * Pertanyaan disusun saat halaman DIBUKA, bukan saat Gate 1 lolos.
     *
     * Kalau kuota habis di detik Gate 1, kandidat tersangkut tanpa pertanyaan
     * dan tanpa jalan keluar. Di sini kegagalan masih bisa jatuh ke bank soal.
     */
    public function testPertanyaanDisusunSaatHalamanDibukaLaluTersimpan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();

        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $json = (new ApplicationModel())->find($aid)['pertanyaan_json'];
        $this->assertCount(3, json_decode($json, true));
    }

    /**
     * 'posisi' dan 'bank' sama-sama berisi pertanyaan umum, tapi yang satu
     * memang seharusnya begitu dan yang satu lagi tanda kuota LLM habis. Kalau
     * keduanya terlihat sama di layar, kegagalan tidak akan pernah ketahuan.
     */
    public function testSumberBankDitandaiBerbedaDariSumberPosisi(): void
    {
        $aid = $this->fixture([]);
        $this->tigaPertanyaan('posisi');
        $posisi = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('dari uraian lowongan', $posisi);
        $this->assertStringNotContainsString('kuota hariannya habis', $posisi);

        $lain = $this->fixture([]);
        (new JobModel())->update((new ApplicationModel())->find($lain)['job_id'], [
            'pertanyaan_json' => json_encode(['Pertanyaan cadangan dari bank soal.']),
        ]);
        $this->mockAiMati();
        $bank = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $lain)->getBody();

        $this->assertStringContainsString('cadangan: bank soal lowongan', $bank);
        $this->assertStringContainsString('kuota hariannya habis', $bank);
    }

    public function testLamaranTidakDikenalDitolak(): void
    {
        $this->withSession($this->sesiRec)->get('recruiter/ruang/9999')->assertRedirect();
    }

    // --- sunting pertanyaan ---

    public function testRecruiterBisaMenyuntingPertanyaan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/pertanyaan', [
            'pertanyaan' => ['Sudah disunting', 'Kedua', 'Ketiga'],
        ]);

        $tersimpan = json_decode((new ApplicationModel())->find($aid)['pertanyaan_json'], true);
        $this->assertSame('Sudah disunting', $tersimpan[0]['pertanyaan']);
        $this->assertSame('pengalaman', $tersimpan[0]['sumber'], 'sumber tidak ikut berubah');
    }

    public function testSusunUlangMenggantiPertanyaan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);

        $this->mockAi(['sumber' => 'pengalaman', 'pertanyaan' => ['Versi baru']]);
        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/pertanyaan', ['aksi' => 'buat']);

        $tersimpan = json_decode((new ApplicationModel())->find($aid)['pertanyaan_json'], true);
        $this->assertSame('Versi baru', $tersimpan[0]['pertanyaan']);
    }

    /**
     * Sesudah kandidat diputus, pertanyaannya jadi catatan: ia dasar penilaian
     * yang sudah terlanjur dipakai. Mengubahnya di belakang membuat lembar
     * profil bercerita tentang wawancara yang tidak pernah terjadi.
     */
    public function testPertanyaanTidakBisaDiubahSetelahKandidatDiputus(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);
        (new StageLogger())->log($aid, 'gate_2', 'passed', 'uji');

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/pertanyaan', [
            'pertanyaan' => ['Diselundupkan'],
        ]);

        $tersimpan = json_decode((new ApplicationModel())->find($aid)['pertanyaan_json'], true);
        $this->assertCount(3, $tersimpan);
        $this->assertStringNotContainsString('Diselundupkan', json_encode($tersimpan));
    }

    public function testHalamanKandidatYangSudahDiputusTanpaKotakIsian(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid);
        (new StageLogger())->log($aid, 'gate_2', 'failed', 'uji');

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('PT. Indomarco Prismatama', $html, 'pertanyaannya tetap terbaca');
        $this->assertStringNotContainsString('name="pertanyaan[]"', $html);
        $this->assertStringNotContainsString('name="rekaman"', $html);
    }

    // --- unggah rekaman ---

    /**
    /**
     * M4A diterima apa pun merek wadahnya, dan MP3 juga.
     *
     * Dulu tidak. Aturan ext_in DAN mime_in CI4 sama-sama menuntut nama
     * ekstensi PULANG-PERGI: keduanya menebak ekstensi dari tipe hasil finfo
     * lalu mengharuskannya sama persis dengan yang ditulis pemakai. Untuk audio
     * itu tidak pernah cocok - audio/mpeg ditebak 'mpga' bukan 'mp3', dan m4a
     * bermerek 'mp42' terbaca video/mp4 lalu ditebak 'mp4' bukan 'm4a'.
     * Akibatnya .mp3 SELALU ditolak dan .m4a ditolak tergantung merek wadahnya,
     * hal yang tidak bisa ditebak recruiter dari luar.
     *
     * Diperiksa di tingkat KEPUTUSANNYA, bukan lewat HTTP: unggahan yang lolos
     * lanjut ke pemindahan berkas, yang memang tidak bisa dijalankan di
     * lingkungan uji (lihat siapkanBerkas).
     *
     * @dataProvider wadahRekaman
     */
    public function testJenisRekamanDikenaliDariIsiBukanNama(string $isi, string $harapMime, string $harapExt): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rek');
        file_put_contents($tmp, $isi);
        $this->sampah[] = $tmp;

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

        $this->assertSame($harapMime, $mime, 'prasyarat: begini finfo membacanya');
        $this->assertArrayHasKey($mime, Recruiter::REKAMAN_MIME, 'jenis ini harus diterima');
        // Ekstensi simpanannya ikut ISI berkas, bukan nama kiriman: rekaman sah
        // bernama .exe tidak boleh tersimpan sebagai .exe.
        $this->assertSame($harapExt, Recruiter::REKAMAN_MIME[$mime]);
    }

    public static function wadahRekaman(): array
    {
        // pack(), bukan urutan escape: berkas uji ini pernah berubah jadi biner
        // karena byte mentah ikut tertulis ke dalam sumbernya.
        $nol = static fn (int $n): string => str_repeat(pack('C', 0), $n);
        $m4a = static function (string $merek) use ($nol): string {
            $b = $merek . pack('N', 512) . 'M4A mp42isomiso2';

            return pack('N', 8 + strlen($b)) . 'ftyp' . $b . pack('N', 72) . 'mdat' . $nol(64);
        };
        $n   = 800;
        $wav = 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
             . 'data' . pack('V', $n) . $nol($n);
        // tag ID3v2 lalu kepala bingkai MPEG-1 Layer III
        $mp3 = 'ID3' . pack('C*', 3, 0, 0, 0, 0, 0, 0) . pack('C*', 0xFF, 0xFB, 0x90, 0x00) . $nol(400);

        return [
            'wav'            => [$wav, 'audio/x-wav', 'wav'],
            'm4a merek M4A'  => [$m4a('M4A '), 'audio/x-m4a', 'm4a'],
            'm4a merek mp42' => [$m4a('mp42'), 'video/mp4', 'mp4'],
            'm4a merek isom' => [$m4a('isom'), 'video/mp4', 'mp4'],
            'mp3'            => [$mp3, 'audio/mpeg', 'mp3'],
        ];
    }

    /** Berkas lain yang disamarkan sebagai rekaman tetap tertolak. */
    public function testBerkasDisamarkanSebagaiRekamanTetapDitolak(): void
    {
        $aid = $this->fixture();
        $this->siapkanIsi('wawancara.m4a', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman', [
            'nilai' => [0 => 4, 1 => 4, 2 => 4],
        ]);

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('bukan rekaman', (string) session('error'));
    }

    /**
     * Yang dinilai ISI berkasnya, bukan namanya.
     *
     * Program yang disamarkan sebagai rekaman tertolak walau bernama .m4a;
     * sebaliknya rekaman sah tetap diterima apa pun namanya, karena berkasnya
     * toh disimpan dengan nama acak dan ekstensi hasil bacaan isinya.
     */
    public function testBerkasBukanRekamanDitolak(): void
    {
        $aid = $this->fixture();
        $this->siapkanIsi('wawancara.m4a', "MZ\x90\x00\x03\x00\x00\x00");   // header .exe

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('rekaman audio atau video', session('error'));
    }

    public function testRekamanTerlaluBesarDitolak(): void
    {
        $aid = $this->fixture();
        $this->siapkanBerkas('panjang.wav', (Recruiter::REKAMAN_MAKS_KB + 1024) * 1024);

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('maksimal', session('error'));
    }

    /**
     * Berkas yang dibuang PHP karena kebesaran diberi tahu apa adanya.
     *
     * Aturan uploaded[] CI4 menerjemahkan keadaan ini jadi "belum ada berkas
     * yang dipilih" - padahal berkasnya jelas dipilih, cuma terlalu besar.
     * Recruiter yang membaca itu akan mencoba memilih ulang berkas yang sama.
     */
    public function testBerkasYangDibuangPhpKarenaKebesaranDijelaskan(): void
    {
        $aid = $this->fixture();
        $tmp = tempnam(sys_get_temp_dir(), 'rek');
        file_put_contents($tmp, $this->wav(1024));
        $this->sampah[] = $tmp;

        service('superglobals')->setFilesArray(['rekaman' => [
            'name' => 'Voice_260710.m4a', 'type' => 'audio/mp4', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_INI_SIZE, 'size' => 62 * 1024 * 1024,
        ]]);

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $pesan = (string) session('error');
        $this->assertStringContainsString('terlalu besar', $pesan);
        $this->assertStringContainsString('62 MB', $pesan, 'ukurannya disebut supaya bisa ditindaklanjuti');
        $this->assertStringNotContainsString('Belum ada berkas', $pesan);
    }

    /** Jenis yang terbaca ikut disebut - tanpa itu penolakannya buntu. */
    public function testPenolakanJenisMenyebutkanApaYangTerbaca(): void
    {
        $aid = $this->fixture();
        $this->siapkanIsi('wawancara.m4a', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertStringContainsString('application/pdf', (string) session('error'));
    }

    public function testTanpaBerkasDitolak(): void
    {
        $aid = $this->fixture();

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
    }

    /**
     * Tiga kompetensi mata manusia WAJIB, dan diperiksa sebelum berkas dipindah.
     *
     * Gate 2 menutup sendiri begitu penilaian AI mendarat. Kalau ketiganya
     * boleh kosong, kandidat diputuskan dari lembar enam butir - dan enam butir
     * bagus bisa menutupi penampilan yang tidak pernah dinilai siapa pun.
     */
    public function testTigaNilaiMataManusiaWajibDiisi(): void
    {
        $aid = $this->fixture();
        $this->siapkanBerkas('wawancara.wav');

        // Dua dari tiga: separuh terisi bukan penilaian.
        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman', [
            'nilai' => [0 => 4, 1 => 3],
        ]);

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertSame([], (new InterviewPenilaianModel())->untukLamaran($aid));
        $this->assertStringContainsString('Personal Grooming', session('error'));
    }

    public function testNilaiDiLuarSkalaDitolak(): void
    {
        $aid = $this->fixture();
        $this->siapkanBerkas('wawancara.wav');

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman', [
            'nilai' => [0 => 4, 1 => 3, 2 => 9],
        ]);

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
    }

    /**
     * Form recruiter hanya memuat narasi yang MEMANG miliknya.
     *
     * Candidate's Strengths dan Weaknesses dirangkum AI dari riwayat kerja dan
     * transkrip - bahan yang sama dengan yang dipakai menilai kompetensi -
     * sehingga menuliskannya ulang dari ingatan cuma menghasilkan versi yang
     * lebih kabur. Additional Notes dan Other Remarks tetap milik recruiter:
     * itu pengamatannya sendiri, hal yang justru tidak ada di transkrip.
     */
    public function testFormUnggahHanyaMemuatNarasiMilikRecruiter(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        foreach (L::NARASI_RECRUITER as $kunci) {
            $this->assertStringContainsString('narasi[' . $kunci . ']', $html);
        }
        foreach (L::NARASI_AI as $kunci) {
            $this->assertStringNotContainsString('narasi[' . $kunci . ']', $html);
        }
    }

    /**
     * Pembagiannya menutup keempat kotak, tanpa tumpang tindih.
     *
     * Kalau ada kunci yang masuk dua-duanya, satu lembar bisa punya DUA baris
     * untuk kotak yang sama - satu dari recruiter, satu dari AI - dan tidak ada
     * yang tahu mana yang benar.
     */
    public function testPembagianNarasiAiDanRecruiterTidakTumpangTindih(): void
    {
        $this->assertSame([], array_intersect(L::NARASI_AI, L::NARASI_RECRUITER));
        $this->assertEqualsCanonicalizing(
            array_keys(L::NARASI),
            array_merge(L::NARASI_AI, L::NARASI_RECRUITER)
        );
    }

    /** Ruang interview milik recruiter. Kandidat tidak boleh masuk. */
    public function testKandidatTidakBisaMembukaRuangInterview(): void
    {
        $aid = $this->fixture();

        $this->withSession(['candidate_id' => 1, 'candidate_nama' => 'Sinta'])
            ->get('recruiter/ruang/' . $aid)
            ->assertRedirectTo(site_url('recruiter/login'));
    }

    public function testFormUnggahMemuatTigaKompetensiMataManusia(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        foreach (L::MATA_MANUSIA as $kompetensi) {
            $this->assertStringContainsString(esc($kompetensi), $html);
        }
        // Enam kompetensi lain TIDAK boleh ada di form: yang dinilai recruiter
        // hanya yang tidak bisa dibaca dari transkrip.
        $this->assertStringNotContainsString('Problem-Solving Ability', $html);
        // 3 kompetensi x 5 skala
        $this->assertSame(15, substr_count($html, 'type="radio" name="nilai['));
    }

    /**
     * Penjaga ini berjalan SEBELUM berkasnya diperiksa, jadi bisa diuji utuh.
     *
     * Form-nya memang disembunyikan setelah kandidat diputus, tapi
     * menyembunyikan form bukan penjagaan: kiriman ulang dari riwayat browser
     * tetap sampai ke controller.
     */
    public function testRekamanTidakBisaDiunggahSetelahKandidatDiputus(): void
    {
        $aid = $this->fixture();
        (new StageLogger())->log($aid, 'gate_2', 'passed', 'uji');
        $this->siapkanBerkas('telat.wav');

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        $this->assertNull((new InterviewTranskripModel())->terakhirUntuk($aid));
        $this->assertStringContainsString('sudah diputuskan', session('error'));
    }

    /**
     * Unggah ulang TIDAK menimpa baris lama.
     *
     * Transkrip adalah dasar penilaian, dan dasar penilaian harus bisa
     * ditelusuri ke belakang. Yang dipakai menilai selalu yang terbaru.
     *
     * Diuji lewat model, bukan lewat HTTP: langkah memindahkan berkas tidak
     * bisa dijalankan di lingkungan uji (lihat siapkanBerkas), sedangkan yang
     * dijaga di sini justru perilaku tabelnya.
     */
    public function testUnggahUlangMenambahBarisBaruBukanMenimpa(): void
    {
        $aid   = $this->fixture();
        $model = new InterviewTranskripModel();
        foreach (['uploads/rekaman/satu.m4a', 'uploads/rekaman/dua.m4a'] as $berkas) {
            $model->insert([
                'application_id' => $aid, 'sumber' => 'unggahan',
                'status' => 'antre', 'berkas' => $berkas,
            ]);
        }

        $this->assertCount(2, $model->where('application_id', $aid)->findAll());
        $this->assertSame('uploads/rekaman/dua.m4a', $model->terakhirUntuk($aid)['berkas']);
    }

    /**
     * Yang gagal atau masih diproses tidak boleh dipakai menilai.
     *
     * Penilaian dari transkrip separuh jadi lebih buruk daripada tidak menilai
     * sama sekali: hasilnya tetap berupa angka yang terlihat sah.
     */
    public function testHanyaTranskripSelesaiYangDipakaiMenilai(): void
    {
        $aid   = $this->fixture();
        $model = new InterviewTranskripModel();
        $model->insert(['application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'selesai',
            'berkas' => 'uploads/rekaman/lama.m4a', 'teks' => 'Halo, saya Reza.']);
        $model->insert(['application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'gagal',
            'berkas' => 'uploads/rekaman/baru.m4a', 'catatan' => 'audio tidak terbaca']);

        $this->assertSame('gagal', $model->terakhirUntuk($aid)['status']);
        $this->assertSame('Halo, saya Reza.', $model->selesaiUntuk($aid)['teks']);
    }

    /**
     * Transkrip dan alasan penilaian TAMPIL, bukan cuma tersimpan.
     *
     * Tanpa ini, aturan "setiap nilai wajib disertai kutipan" cuma berlaku di
     * dalam basis data - dan alasan yang tidak pernah dibaca siapa pun sama
     * saja dengan tidak beralasan.
     */
    public function testTranskripDanAlasanPenilaianTampilDiHalaman(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'selesai',
            'berkas' => 'uploads/rekaman/x.wav',
            'teks'   => "Pewawancara: Ceritakan pengalaman Anda.\nKandidat: Saya cek ulang surat jalan.",
        ]);
        (new InterviewPenilaianModel())->insert([
            'application_id' => $aid, 'kompetensi' => 'Communication Skills',
            'kategori' => L::KAT_HRD, 'sumber' => L::DARI_AI, 'bobot' => 1,
            'tingkat' => '4', 'catatan' => 'Menjelaskan runtut, mengutip surat jalan.',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('Saya cek ulang surat jalan', $html);
        $this->assertStringContainsString('Menjelaskan runtut', $html);
    }

    /**
     * Kompetensi yang tidak terjawab disebut TERANG-TERANGAN.
     *
     * Baris yang hilang diam-diam terbaca sebagai "tidak ada masalah", padahal
     * artinya kebalikan: wawancaranya tidak memuat bahan untuk menilai itu.
     */
    public function testKompetensiYangTidakTerjawabDisebutkan(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'selesai',
            'berkas' => 'uploads/rekaman/x.wav', 'teks' => 'Kandidat: Halo.',
        ]);
        (new InterviewPenilaianModel())->insert([
            'application_id' => $aid, 'kompetensi' => 'Communication Skills',
            'kategori' => L::KAT_HRD, 'sumber' => L::DARI_AI, 'bobot' => 1,
            'tingkat' => '4', 'catatan' => 'x',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('Tidak bisa dinilai dari transkrip ini', $html);
        $this->assertStringContainsString('Adaptability', $html);
    }

    /**
     * 'flagged' BUKAN "sudah diputus": rekaman masih bisa dikirim ulang.
     *
     * Terlihat saat e2e 13 Agustus 2026 - kuota LLM habis, transkripsi gagal,
     * Gate 2 jadi 'flagged', dan form unggah ikut hilang. Recruiter jadi tidak
     * bisa mencoba lagi besok saat kuotanya pulih, padahal rekamannya masih ada
     * dan itu satu-satunya jalan kembali ke penilaian otomatis.
     */
    public function testFlaggedMasihBolehMengunggahUlang(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        (new StageLogger())->log($aid, 'gate_2', 'flagged', 'system:transkrip', 'Transkripsi gagal');

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('name="rekaman"', $html);
        $this->assertStringNotContainsString('Kandidat ini sudah diputuskan', $html);
    }

    /** Penjaga di controller memakai syarat yang sama - bukan cuma tampilannya. */
    public function testFlaggedTidakDitolakPenjagaUnggahan(): void
    {
        $aid = $this->fixture();
        (new StageLogger())->log($aid, 'gate_2', 'flagged', 'system:transkrip', 'Transkripsi gagal');

        $this->withSession($this->sesiRec)->post('recruiter/ruang/' . $aid . '/rekaman');

        // Ditolak karena berkasnya memang tidak ada, BUKAN karena dianggap
        // sudah diputus. Kalau penjaganya salah lagi, pesannya akan berubah.
        $this->assertStringNotContainsString('sudah diputuskan', (string) session('error'));
    }

    public function testStatusRekamanTampilDiHalaman(): void
    {
        $aid = $this->fixture();
        $this->tigaPertanyaan();
        (new InterviewTranskripModel())->insert([
            'application_id' => $aid, 'sumber' => 'unggahan', 'status' => 'gagal',
            'berkas' => 'uploads/rekaman/x.m4a', 'catatan' => 'audio tidak terbaca',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/ruang/' . $aid)->getBody();

        $this->assertStringContainsString('transkripsi gagal', $html);
        $this->assertStringContainsString('audio tidak terbaca', $html);
    }

    // --- pintu masuknya ---

    /**
     * Tombolnya ada di DUA tab, dan itu bukan kelebihan.
     *
     * On Progress: recruiter membuka pertanyaan sebelum wawancara.
     * Completed:   recruiter mengunggah rekaman SESUDAH wawancara, dan saat itu
     *              kandidat sudah berpindah tab sendiri karena sesinya berakhir.
     */
    public function testTabelInterviewPunyaTombolRuangDiKeduaTab(): void
    {
        $akanDatang = $this->fixture();
        $sudah      = $this->fixture([]);
        (new InterviewModel())
            ->where('application_id', $sudah)
            ->set('scheduled_at', '2020-01-01 10:00:00')->update();

        $progress = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online')->getBody();
        $completed = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/tahap/interview_online?status=completed')->getBody();

        $this->assertStringContainsString('recruiter/ruang/' . $akanDatang, $progress);
        $this->assertStringContainsString('recruiter/ruang/' . $sudah, $completed);
    }
}
