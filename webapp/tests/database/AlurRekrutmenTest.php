<?php

use App\Libraries\AlurRekrutmen as A;
use App\Models\ApplicationModel;
use App\Libraries\StageLogger;
use App\Models\CandidateModel;
use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Alur rekrutmen per lowongan (18 Agustus 2026).
 *
 * Mengikuti web recruiter BIPROO: tiap posisi punya rangkaian tahapnya sendiri,
 * disunting di halaman Settings. Sebelum ini E-REQ memakai satu rangkaian tetap
 * untuk semua posisi.
 *
 * @internal
 */
final class AlurRekrutmenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];
    private int $urut      = 0;

    private function lowongan(?string $alurJson = null): int
    {
        return (int) (new JobModel())->insert([
            'judul'          => 'Sales Gadget ' . ++$this->urut,
            'req_skill'      => 'Penjualan', 'req_pendidikan' => 'SMA', 'req_pengalaman' => '1th',
            'alur_json'      => $alurJson,
        ]);
    }

    // --- aturan penyusunan alur ---

    /**
     * Lowongan tanpa setelan memakai rangkaian yang sama persis seperti sebelum
     * kolom alur_json ada.
     *
     * Kalau tidak, memasang migrasi ini diam-diam mengubah arti setiap lamaran
     * yang sedang berjalan.
     */
    public function testTanpaSetelanJatuhKeBawaan(): void
    {
        $this->assertSame([
            'upload_cv', 'online_assessment', 'gate_1',
            'penjadwalan', 'interview_online', 'gate_2', 'berkas_kontrak',
        ], A::untukLowongan(null));
    }

    /** JSON rusak tidak boleh mematikan halaman kandidat. */
    public function testJsonRusakJatuhKeBawaanBukanMeledak(): void
    {
        foreach (['{bukan json', '', 'null', '"teks"', '123'] as $rusak) {
            $this->assertSame(A::bawaan(), A::untukLowongan($rusak), $rusak);
        }
    }

    /**
     * Tahap pilihan bebas letaknya - INI inti fiturnya.
     *
     * Satu posisi mengerjakan D.I.S.C sebelum TIU 5, posisi lain sesudahnya.
     * Kalau urutannya dipaksa seragam, halaman Settings cuma jadi daftar centang
     * yang tidak menggambarkan alur siapa pun.
     */
    public function testTahapPilihanBisaDitaruhSebelumAtauSesudahTahapWajib(): void
    {
        $sebelum = A::untukLowongan(A::keJson(['upload_cv', 'disc', 'online_assessment']));
        $sesudah = A::untukLowongan(A::keJson(['upload_cv', 'online_assessment', 'disc']));

        $this->assertSame(['upload_cv', 'disc', 'online_assessment'], array_slice($sebelum, 0, 3));
        $this->assertSame(['upload_cv', 'online_assessment', 'disc'], array_slice($sesudah, 0, 3));
    }

    /**
     * Tahap wajib TIDAK bisa dicabut lewat pilihan.
     *
     * Mesinnya berjalan pada tahap-tahap itu: assessment memutus gate_1, ruang
     * interview mengisi interview_online, callback transkripsi menutup gate_2.
     * Mencabutnya berarti mematikan otomatisasi diam-diam, dan yang terlihat
     * cuma kandidat yang berhenti bergerak tanpa sebab.
     */
    public function testTahapWajibSelaluIkutWalauTidakDipilih(): void
    {
        $alur = A::untukLowongan(A::keJson(['disc']));

        foreach (A::wajib() as $kunci) {
            $this->assertContains($kunci, $alur, $kunci . ' tidak boleh hilang');
        }
    }

    /** Tahap wajib tidak bisa ditukar urutannya - alurnya akan jadi mustahil. */
    public function testUrutanTahapWajibTidakBisaDibalik(): void
    {
        $alur = A::untukLowongan(A::keJson(['gate_2', 'interview_online', 'upload_cv']));

        $this->assertLessThan(
            array_search('gate_2', $alur, true),
            array_search('interview_online', $alur, true),
            'Keputusan Akhir tidak mungkin mendahului Interview HRD'
        );
    }

    public function testKunciAsingDanKembarDibuang(): void
    {
        $alur = A::untukLowongan(A::keJson(['disc', 'hantu', 'disc', 42, null]));

        $this->assertSame(1, count(array_keys($alur, 'disc', true)));
        $this->assertNotContains('hantu', $alur);
    }

    /**
     * Interview HRD dan Interview User dua tahap yang BERBEDA.
     *
     * Yang pertama digerakkan sistem sampai Gate 2; yang kedua wawancara oleh
     * calon atasan, tidak selalu ada, dan hasilnya diketik manusia. Menyatukan
     * keduanya membuat posisi yang tidak memakai Interview User tetap
     * menampilkannya.
     */
    public function testInterviewHrdDanInterviewUserTerpisah(): void
    {
        $this->assertSame('Interview HRD', A::label('interview_online'));
        $this->assertSame('Interview User', A::label('interview_user'));
        $this->assertContains('interview_online', A::wajib());
        $this->assertContains('interview_user', A::opsional());

        $tanpaUser = A::untukLowongan(null);
        $this->assertNotContains('interview_user', $tanpaUser);
    }

    public function testDipecahKeDuaKelompok(): void
    {
        $grup = A::perKelompok(A::untukLowongan(A::keJson(['disc', 'interview_user'])));

        $this->assertContains('disc', array_column($grup[A::ASSESSMENT], 'kunci'));
        $this->assertContains('interview_user', array_column($grup[A::SELECTION], 'kunci'));
        $this->assertNotContains('interview_user', array_column($grup[A::ASSESSMENT], 'kunci'));
    }

    // --- halaman Settings ---

    public function testHalamanPengaturanMemuatSemuaPosisiDanAlurnya(): void
    {
        $a = $this->lowongan();
        $b = $this->lowongan(A::keJson(['excel_test', 'interview_user']));

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pengaturan')->getBody();

        $this->assertStringContainsString('recruiter/pengaturan/alur/' . $a, $html);
        $this->assertStringContainsString('recruiter/pengaturan/alur/' . $b, $html);
        $this->assertStringContainsString('Excel Test', $html);
        $this->assertStringContainsString('Interview User', $html);
    }

    /** Tombol Settings di dashboard harus benar-benar mengarah ke sana. */
    public function testTombolSettingsDiDashboardTerhubung(): void
    {
        $html = (string) $this->withSession($this->sesiRec)->get('recruiter')->getBody();

        $this->assertStringContainsString('recruiter/pengaturan', $html);
    }

    public function testFormSuntingMenandaiTahapYangSudahDipakai(): void
    {
        $id = $this->lowongan(A::keJson(['qleap']));

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $id)->getBody();

        // Dua kotak per kelompok, seperti sistem lama: kiri yang dipakai,
        // kanan yang tersedia. Yang diperiksa di kotak mana tahapnya berada.
        $pakai  = explode('kotak sedia', explode('kotak pakai', $html)[1])[0];
        $sedia  = explode('kotak sedia', $html)[1];

        $this->assertStringContainsString('data-kunci="qleap"', $pakai, 'QLEAP sudah dipakai');
        $this->assertStringNotContainsString('data-kunci="papikostik"', $pakai);
        $this->assertStringContainsString('data-kunci="papikostik"', $sedia, 'Papikostik masih tersedia');
    }

    /**
     * Tanpa JavaScript, menyimpan TIDAK menghapus setelan yang sudah ada.
     *
     * Kotak pilihannya dirakit JavaScript; kalau ia mati dan form tetap
     * terkirim, daftar yang sampai server akan kosong dan alur posisi ini
     * kembali ke bawaan tanpa pesan apa pun. Karena itu pilihan yang sekarang
     * ikut ditulis server sebagai input tersembunyi.
     */
    public function testFormMembawaPilihanSekarangSebagaiCadangan(): void
    {
        $id = $this->lowongan(A::keJson(['qleap', 'interview_user']));

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $id)->getBody();

        $this->assertStringContainsString('name="tahap[]" value="qleap"', $html);
        $this->assertStringContainsString('name="tahap[]" value="interview_user"', $html);
    }

    /**
     * Tahap inti tidak ikut bisa diseret, sisanya bisa.
     *
     * Penandanya dibaca skrip dari data-wajib, jadi yang diuji di sini bukan
     * seret-lepasnya - itu perilaku peramban - melainkan penanda yang jadi
     * dasarnya. Kalau penandanya salah, tahap inti bisa digeser dan urutan
     * mesin ikut bergeser tanpa ada yang menahan.
     */
    public function testHanyaTahapPilihanYangDitandaiBisaDipindah(): void
    {
        $id = $this->lowongan(A::keJson(['qleap']));

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $id)->getBody();

        $this->assertMatchesRegularExpression('/data-kunci="qleap"[^>]*data-wajib="0"/', $html);
        $this->assertMatchesRegularExpression('/data-kunci="interview_online"[^>]*data-wajib="1"/', $html);
        $this->assertMatchesRegularExpression('/data-kunci="gate_2"[^>]*data-wajib="1"/', $html);
    }

    public function testSimpanMengubahAlurLowongan(): void
    {
        $id = $this->lowongan();

        $this->withSession($this->sesiRec)->post('recruiter/pengaturan/alur/' . $id, [
            'tahap' => ['upload_cv', 'disc', 'online_assessment', 'gate_1',
                'penjadwalan', 'interview_online', 'interview_user', 'gate_2', 'berkas_kontrak'],
        ]);

        $alur = A::untukLowongan((new JobModel())->find($id)['alur_json']);
        $this->assertContains('disc', $alur);
        $this->assertContains('interview_user', $alur);
        $this->assertLessThan(array_search('online_assessment', $alur, true),
            array_search('disc', $alur, true), 'urutan kiriman form yang dipakai');
    }

    /** Kiriman kosong tidak menghapus tahap intinya. */
    public function testSimpanTanpaCentangApaPunTetapMenyisakanTahapInti(): void
    {
        $id = $this->lowongan(A::keJson(['disc']));

        $this->withSession($this->sesiRec)->post('recruiter/pengaturan/alur/' . $id, []);

        $alur = A::untukLowongan((new JobModel())->find($id)['alur_json']);
        $this->assertSame(A::bawaan(), $alur);
    }

    /**
     * Simpan DARI DALAM jendela pratinjau tidak mendarat di daftar posisi.
     *
     * Redirect ke daftar akan dirender di dalam bingkai: recruiter melihat
     * daftar terjepit di jendela kecil dan harus menutupnya sendiri, sementara
     * daftar di belakangnya masih menampilkan alur yang lama.
     */
    public function testSimpanDariJendelaMenujuHalamanPenutup(): void
    {
        $id = $this->lowongan();

        $this->withSession($this->sesiRec)->post('recruiter/pengaturan/alur/' . $id, [
            'bingkai' => '1', 'tahap' => ['upload_cv', 'disc'],
        ])->assertRedirectTo(site_url('recruiter/pengaturan/alur/' . $id) . '?bingkai=1&tutup=1');
    }

    /** Di luar jendela, tujuannya tetap daftar posisi seperti biasa. */
    public function testSimpanDiLuarJendelaTidakLewatHalamanPenutup(): void
    {
        $id = $this->lowongan();

        $this->withSession($this->sesiRec)->post('recruiter/pengaturan/alur/' . $id, [
            'tahap' => ['upload_cv', 'disc'],
        ])->assertRedirectTo(site_url('recruiter/pengaturan/alur/' . $id));
    }

    /** Halaman penutup menyegarkan induknya, dan tetap punya jalan keluar tanpa JavaScript. */
    public function testHalamanPenutupMenyegarkanInduknya(): void
    {
        $id = $this->lowongan();

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $id . '?bingkai=1&tutup=1')->getBody();

        $this->assertStringContainsString('parent.location.reload()', $html);
        $this->assertStringContainsString(site_url('recruiter/pengaturan'), $html);
        $this->assertStringNotContainsString('Recruitment Progress', $html, 'formnya tidak ikut dirender');
    }

    /** Tombol Close menutup jendelanya, bukan memuat daftar di dalam bingkai. */
    public function testTombolCloseMenutupJendela(): void
    {
        $id = $this->lowongan();

        $html = (string) $this->withSession($this->sesiRec)
            ->get('recruiter/pengaturan/alur/' . $id . '?bingkai=1')->getBody();

        $this->assertStringContainsString('tutupBingkai()', $html);
        $this->assertStringContainsString('parent.tutupJendela()', $html);
    }

    public function testLowonganTidakDikenalTidakMeledak(): void
    {
        $this->withSession($this->sesiRec)->get('recruiter/pengaturan/alur/999999')
            ->assertRedirect();
    }

    // --- stepper kandidat mengikuti alur lowongannya ---

    public function testStepperKandidatMengikutiAlurLowongan(): void
    {
        $cid = (int) (new CandidateModel())->insert([
            'nama' => 'Sinta', 'email' => 'sinta.alur@example.com', 'password_hash' => 'x',
        ]);
        $jid = $this->lowongan(A::keJson(['excel_test', 'interview_user']));
        (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);

        $html = (string) $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Sinta'])
            ->get('dashboard')->getBody();

        $this->assertStringContainsString('Excel Test', $html);
        $this->assertStringContainsString('Interview User', $html);
        $this->assertStringContainsString('Interview HRD', $html);
    }

    /** Posisi yang TIDAK memakainya tidak boleh menampilkannya. */
    public function testStepperTidakMenampilkanTahapYangTidakDipakai(): void
    {
        $cid = (int) (new CandidateModel())->insert([
            'nama' => 'Budi', 'email' => 'budi.alur@example.com', 'password_hash' => 'x',
        ]);
        $jid = $this->lowongan();   // bawaan: tanpa Interview User
        (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);

        $html = (string) $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Budi'])
            ->get('dashboard')->getBody();

        $this->assertStringNotContainsString('Interview User', $html);
        $this->assertStringNotContainsString('Excel Test', $html);
    }

    /**
     * Tahap pilihan yang belum ditandai TIDAK boleh tampil selesai.
     *
     * Stepper menyimpulkan 'done' dari posisi: tahap yang terlewati oleh tahap
     * berikutnya dianggap sudah dilalui. Benar untuk tahap yang digerakkan
     * mesin - mustahil lolos Gate 1 tanpa mengerjakan assessment - tapi salah
     * untuk Excel Test, yang dikerjakan di luar sistem. Membiarkannya hijau
     * berarti memberi tanda lulus pada tes yang belum tentu pernah diikuti.
     */
    public function testTahapPilihanTidakOtomatisDianggapSelesai(): void
    {
        $cid = (int) (new CandidateModel())->insert([
            'nama' => 'Dewi', 'email' => 'dewi.alur@example.com', 'password_hash' => 'x',
        ]);
        $jid = $this->lowongan(A::keJson(['excel_test']));
        $aid = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);
        // Kandidat sudah jauh melewati posisi Excel Test di rangkaiannya.
        (new StageLogger())->log($aid, 'upload_cv', 'entered', 'system');
        (new StageLogger())->log($aid, 'online_assessment', 'passed', 'system');
        (new StageLogger())->log($aid, 'gate_1', 'passed', 'system');

        $html = (string) $this->withSession(['candidate_id' => $cid, 'candidate_nama' => 'Dewi'])
            ->get('dashboard')->getBody();

        $blok = '';
        foreach (explode('class="step ', $html) as $b) {
            if (str_contains($b, '>Excel Test</span>')) {
                $blok = $b;
            }
        }
        $this->assertNotSame('', $blok, 'Excel Test harus tergambar');
        $this->assertStringNotContainsString('done', explode('"', $blok)[0],
            'tes yang belum ditandai tidak boleh tampil selesai');
    }

    /**
     * Screening CV tetap TIDAK ditampilkan.
     *
     * Proses latar yang tidak menuntut tindakan kandidat dan tidak lagi
     * menentukan kelolosan Gate 1. Riwayat lengkapnya tetap ada di halaman
     * Status Lamaran untuk audit.
     */
    public function testTahapInternalTidakIkutDigambar(): void
    {
        foreach (A::TERSEMBUNYI as $kunci) {
            $this->assertArrayNotHasKey($kunci, A::KATALOG);
        }
    }
}
