<?php

use App\Libraries\LembarPenilaian as L;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewPenilaianModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Candidate Ranking (28 Agustus 2026, permintaan atasan).
 *
 * Kandidat diperingkat menurut skor akhir rumus Gate 2 - kemiripan CV 40%
 * ditambah skor interview 60% - dan bisa disaring per posisi.
 *
 * Yang paling dijaga di sini BUKAN urutannya, melainkan dua hal yang mudah
 * rusak tanpa terlihat: kandidat yang datanya belum lengkap tidak boleh jatuh
 * ke dasar peringkat seolah-olah ia yang terburuk, dan bobot yang dipakai
 * harus milik lowongannya sendiri.
 *
 * @internal
 */
final class PeringkatKandidatTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesi = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];
    private int $urut   = 0;

    private function lowongan(string $judul = 'Admin Gudang', ?string $bobot = null): int
    {
        return (int) (new JobModel())->insert([
            'judul'      => $judul,
            'req_skill'  => 'Stok', 'req_pendidikan' => 'D3', 'req_pengalaman' => '1 tahun',
            'bobot_json' => $bobot,
        ]);
    }

    /**
     * Satu kandidat beserta komponen skornya.
     *
     * @param float|null $skorCv    null = screening belum menghasilkan skor
     * @param int|null   $interview null = wawancaranya belum dinilai
     */
    private function kandidat(int $jobId, string $nama, ?float $skorCv, ?int $interview): int
    {
        $n   = ++$this->urut;
        $cid = (int) (new CandidateModel())->insert([
            'nama' => $nama, 'email' => "orang{$n}@uji.test", 'password_hash' => 'x',
        ]);
        $aid = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jobId, 'cv_path' => 'uploads/cv/x.pdf',
        ]);

        if ($skorCv !== null) {
            (new ScreeningResultModel())->insert([
                'application_id' => $aid, 'screening_job_id' => 'uji-' . $aid, 'status' => 'success',
                'score_overall'  => $skorCv, 'provider' => 'dummy', 'model_version' => 'uji',
            ]);
        }

        if ($interview !== null) {
            // Nilai 1-5 yang rata-ratanya memberi skor 0-100 yang diminta:
            // skor = (rata - 1) / 4 * 100, jadi rata = interview/25 + 1.
            $tingkat = (string) (int) round($interview / 25 + 1);
            $model   = new InterviewPenilaianModel();
            foreach (L::HRD as $kompetensi) {
                $model->insert([
                    'application_id' => $aid, 'kompetensi' => $kompetensi,
                    'kategori' => L::KAT_HRD, 'sumber' => L::DARI_AI,
                    'bobot' => 1, 'tingkat' => $tingkat, 'catatan' => '',
                ]);
            }
        }

        return $aid;
    }

    private function buka(int $jobId = 0): string
    {
        $url = 'recruiter/peringkat' . ($jobId > 0 ? '?job=' . $jobId : '');

        return (string) $this->withSession($this->sesi)->get($url)->getBody();
    }

    /** Urutan nama sesuai kemunculannya di halaman. */
    private function urutan(string $html, array $nama): array
    {
        $posisi = [];
        foreach ($nama as $n) {
            $p = strpos($html, $n);
            if ($p !== false) {
                $posisi[$n] = $p;
            }
        }
        asort($posisi);

        return array_keys($posisi);
    }

    // --- peringkat ---

    public function testDiurutkanDariSkorTertinggi(): void
    {
        $job = $this->lowongan();
        $this->kandidat($job, 'Sedang', 0.6, 60);
        $this->kandidat($job, 'Tertinggi', 0.9, 100);
        $this->kandidat($job, 'Terendah', 0.3, 25);

        $html = $this->buka();

        $this->assertSame(
            ['Tertinggi', 'Sedang', 'Terendah'],
            $this->urutan($html, ['Tertinggi', 'Sedang', 'Terendah']),
        );
    }

    /**
     * Yang datanya belum lengkap ada di BAWAH, bukan di dasar peringkat.
     *
     * Mengisi komponen yang kosong dengan nol akan menempatkan kandidat yang
     * belum diwawancarai di urutan terbawah seolah-olah ia yang terburuk,
     * padahal ia belum diuji sama sekali.
     */
    public function testYangBelumBerskorAdaDiBawahTanpaPeringkat(): void
    {
        $job = $this->lowongan();
        $this->kandidat($job, 'Belum Interview', 0.9, null);
        $this->kandidat($job, 'Sudah Lengkap', 0.4, 40);

        $html = $this->buka();

        $this->assertSame(
            ['Sudah Lengkap', 'Belum Interview'],
            $this->urutan($html, ['Sudah Lengkap', 'Belum Interview']),
            'yang berskor harus di atas, walau skor CV-nya lebih rendah',
        );
        $this->assertStringContainsString('belum punya skor akhir', $html);
    }

    public function testTanpaSkorCvJugaBelumBerperingkat(): void
    {
        $job = $this->lowongan();
        $this->kandidat($job, 'Tanpa CV', null, 90);
        $this->kandidat($job, 'Lengkap', 0.5, 50);

        $html = $this->buka();

        $this->assertSame(['Lengkap', 'Tanpa CV'], $this->urutan($html, ['Lengkap', 'Tanpa CV']));
    }

    // --- penyaring posisi ---

    public function testDisaringPerPosisi(): void
    {
        $gudang = $this->lowongan('Admin Gudang');
        $sales  = $this->lowongan('Sales Gadget');
        $this->kandidat($gudang, 'Orang Gudang', 0.7, 70);
        $this->kandidat($sales, 'Orang Sales', 0.8, 80);

        $html = $this->buka($gudang);

        $this->assertStringContainsString('Orang Gudang', $html);
        $this->assertStringNotContainsString('Orang Sales', $html);
    }

    public function testTanpaPenyaringMenampilkanSemuaPosisi(): void
    {
        $gudang = $this->lowongan('Admin Gudang');
        $sales  = $this->lowongan('Sales Gadget');
        $this->kandidat($gudang, 'Orang Gudang', 0.7, 70);
        $this->kandidat($sales, 'Orang Sales', 0.8, 80);

        $html = $this->buka();

        $this->assertStringContainsString('Orang Gudang', $html);
        $this->assertStringContainsString('Orang Sales', $html);
    }

    // --- rumusnya ---

    /**
     * Bobot yang dipakai MILIK LOWONGANNYA SENDIRI, bukan bawaan.
     *
     * Tiap lowongan boleh menyetel bobot Gate 2-nya sendiri lewat
     * jobs.bobot_json. Kalau halaman ini memakai bawaan untuk semua, peringkat
     * yang ditampilkan berbeda dari skor akhir yang tercatat di riwayat tahap
     * kandidat yang sama - dan tidak ada yang tahu mana yang benar.
     */
    public function testMemakaiBobotMilikLowongannya(): void
    {
        // Interview 100%, CV diabaikan. Kandidat dengan CV jelek tapi interview
        // sempurna harus menang atas CV sempurna dengan interview jelek.
        $job = $this->lowongan('Khusus', json_encode(['gate2' => ['cv' => 0.0, 'interview' => 1.0]]));
        $this->kandidat($job, 'Interview Bagus', 0.1, 100);
        $this->kandidat($job, 'CV Bagus', 1.0, 0);

        $html = $this->buka($job);

        $this->assertSame(
            ['Interview Bagus', 'CV Bagus'],
            $this->urutan($html, ['Interview Bagus', 'CV Bagus']),
        );
    }

    // --- keterangan yang wajib tampil ---

    /**
     * Halaman ini menyusun orang dari atas ke bawah, dan susunan seperti itu
     * terbaca sebagai urutan siapa yang diterima. Dua keterangan yang meluruskan
     * itu tidak boleh hilang tanpa sengaja saat tampilannya dirapikan.
     */
    public function testKeteranganBatasnyaIkutTampil(): void
    {
        $job = $this->lowongan();
        $this->kandidat($job, 'Seseorang', 0.7, 70);

        $html = $this->buka();

        $this->assertStringContainsString('bukan urutan siapa yang diterima', $html);
        $this->assertStringContainsString('Bandingkan hanya dalam satu posisi', $html);
    }

    /**
     * Lencana statusnya benar-benar BERWARNA di halaman recruiter.
     *
     * badge_status() sudah lama dipakai di sini, tapi aturan .badge dulu hanya
     * ikut lewat partials/gaya_isi - yang cuma dipasang layout kandidat.
     * Hasilnya kelasnya menempel di HTML sementara halamannya menampilkan teks
     * polos, dan tidak ada satu pun uji yang menyadarinya karena semua memeriksa
     * teksnya saja.
     */
    public function testLencanaStatusIkutGayanya(): void
    {
        $job = $this->lowongan();
        $aid = $this->kandidat($job, 'Seseorang', 0.7, 70);
        (new \App\Models\StageHistoryModel())->insert([
            'application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed', 'actor' => 'uji',
        ]);

        $html = $this->buka();

        $this->assertStringContainsString('badge badge-lolos', $html, 'lencananya dipakai');
        $this->assertStringContainsString('.badge-lolos', $html, 'dan aturannya ikut terkirim');
    }

    public function testTombolnyaAdaDiHalamanSemuaKandidat(): void
    {
        $html = (string) $this->withSession($this->sesi)->get('recruiter/kandidat')->getBody();

        $this->assertStringContainsString('Candidate Ranking', $html);
        $this->assertStringContainsString('recruiter/peringkat', $html);
    }
}
