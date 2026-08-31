<?php

use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\SlotInterviewModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Pengaturan slot jadwal interview (28 Agustus 2026, permintaan atasan).
 *
 * Recruiter menambah, menutup, dan menghapus jam wawancara, serta menentukan
 * berapa kandidat yang boleh mengambil tiap jam.
 *
 * Yang paling dijaga di sini: slot yang SUDAH dipegang kandidat tidak boleh
 * lenyap. Menghapusnya menghilangkan jam yang sudah dijanjikan kepada orang
 * yang menunggu, sementara jadwalnya sendiri tetap ada di tabel interviews -
 * kandidat datang ke jam yang menurut sistem tidak pernah ada.
 *
 * @internal
 */
final class PengaturanJadwalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesi = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private function kirim(array $data)
    {
        return $this->withSession($this->sesi)->post('recruiter/pengaturan/jadwal', $data);
    }

    private function slot(string $waktu, int $kuota = 1): int
    {
        return (int) (new SlotInterviewModel())->insert([
            'scheduled_at' => $waktu, 'jenis' => InterviewModel::JENIS_HRD,
            'job_id' => SlotInterviewModel::TANPA_POSISI,
            'kuota' => $kuota, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<string> */
    private function waktuTersimpan(): array
    {
        return array_map(
            static fn (array $r): string => substr((string) $r['scheduled_at'], 0, 16),
            (new SlotInterviewModel())->semua(InterviewModel::JENIS_HRD),
        );
    }

    // --- tambah ---

    public function testTambahSatuSlot(): void
    {
        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-07', 'jam' => '10:00', 'kuota' => 2]);

        $this->assertSame(['2026-09-07 10:00'], $this->waktuTersimpan());
        $this->assertSame(2, (new SlotInterviewModel())->kuota('2026-09-07 10:00:00', InterviewModel::JENIS_HRD));
    }

    /**
     * Tombol ulangi memakai TANGGAL YANG DIPILIH sebagai titik mulai, bukan
     * hari ini: recruiter yang menyiapkan jadwal pekan depan ingin tujuh hari
     * kerja dari pekan depan.
     */
    public function testUlangiMengisiTujuhHariKerjaDariTanggalYangDipilih(): void
    {
        // 2026-09-07 adalah hari Senin
        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-07', 'jam' => '14:00',
            'kuota' => 1, 'ulangi' => '1']);

        $waktu = $this->waktuTersimpan();

        $this->assertCount(7, $waktu);
        $this->assertSame('2026-09-07 14:00', reset($waktu));
        // Senin 7 Sep sampai Selasa 15 Sep, akhir pekan 12-13 Sep dilompati
        $this->assertSame('2026-09-15 14:00', end($waktu));
    }

    public function testAkhirPekanTidakIkutSaatDiulangi(): void
    {
        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-07', 'jam' => '10:00',
            'kuota' => 1, 'ulangi' => '1']);

        foreach ($this->waktuTersimpan() as $w) {
            $n = (int) (new DateTimeImmutable(substr($w, 0, 10)))->format('N');
            $this->assertLessThanOrEqual(5, $n, "{$w} jatuh di akhir pekan");
        }
    }

    /**
     * Tanggal akhir pekan yang dipilih TIDAK boleh hilang gara-gara centang ulangi.
     *
     * hariKerja() melompati akhir pekan, jadi memilih Sabtu lalu mencentang
     * "ulangi" dulu menghasilkan tujuh hari kerja tanpa Sabtu itu sendiri -
     * slot yang justru diminta hilang tanpa sepatah kata, padahal tanpa centang
     * slotnya dibuat.
     */
    public function testTanggalAkhirPekanTetapDibuatWalauDiulangi(): void
    {
        // 2026-09-05 adalah hari Sabtu
        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-05', 'jam' => '10:00',
            'kuota' => 1, 'ulangi' => '1']);

        $waktu = $this->waktuTersimpan();

        $this->assertContains('2026-09-05 10:00', $waktu, 'Sabtu yang dipilih harus ikut dibuat');
        $this->assertContains('2026-09-07 10:00', $waktu, 'dan pengulangannya tetap hari kerja');
    }

    /** Jam yang sudah ada dilewati, bukan ditimpa - kuotanya bisa saja sudah diatur. */
    public function testJamYangSudahAdaTidakDitimpa(): void
    {
        $this->slot('2026-09-07 10:00:00', 5);

        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-07', 'jam' => '10:00', 'kuota' => 1]);

        $this->assertCount(1, $this->waktuTersimpan());
        $this->assertSame(5, (new SlotInterviewModel())->kuota('2026-09-07 10:00:00', InterviewModel::JENIS_HRD), 'kuota lama dipertahankan');
    }

    public function testTanggalNgawurDitolak(): void
    {
        $this->kirim(['aksi' => 'tambah', 'tanggal' => 'besok', 'jam' => '10:00', 'kuota' => 1]);

        $this->assertSame([], $this->waktuTersimpan());
    }

    public function testKuotaDiLuarBatasDitolak(): void
    {
        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-07', 'jam' => '10:00', 'kuota' => 0]);
        $this->kirim(['aksi' => 'tambah', 'tanggal' => '2026-09-07', 'jam' => '11:00',
            'kuota' => SlotInterviewModel::MAKS_KUOTA + 1]);

        $this->assertSame([], $this->waktuTersimpan());
    }

    // --- ubah kuota ---

    public function testKuotaBisaDiubah(): void
    {
        $id = $this->slot('2026-09-07 10:00:00');

        $this->kirim(['aksi' => 'kuota', 'id' => $id, 'kuota' => 3]);

        $this->assertSame(3, (new SlotInterviewModel())->kuota('2026-09-07 10:00:00', InterviewModel::JENIS_HRD));
    }

    /**
     * Kuota 0 = slot ditutup untuk pendaftar baru, tanpa menghapusnya.
     *
     * Inilah jalan keluar untuk slot yang sudah dipegang kandidat: yang sudah
     * masuk tetap masuk, yang belum tidak bisa lagi.
     */
    public function testKuotaNolMenutupSlotTanpaMenghapus(): void
    {
        $id = $this->slot('2026-09-07 10:00:00', 2);

        $this->kirim(['aksi' => 'kuota', 'id' => $id, 'kuota' => 0]);

        $this->assertSame(0, (new SlotInterviewModel())->kuota('2026-09-07 10:00:00', InterviewModel::JENIS_HRD));
        $this->assertCount(1, $this->waktuTersimpan(), 'slotnya tetap ada');
    }

    // --- hapus ---

    public function testSlotKosongBisaDihapus(): void
    {
        $id = $this->slot('2026-09-07 10:00:00');

        $this->kirim(['aksi' => 'hapus', 'id' => $id]);

        $this->assertSame([], $this->waktuTersimpan());
    }

    /**
     * INI yang paling penting. Slot yang sudah dipegang kandidat tidak boleh
     * lenyap dari sistem sementara jadwalnya masih berdiri di tabel interviews.
     */
    public function testSlotYangSudahDipegangKandidatTidakBisaDihapus(): void
    {
        $waktu = '2026-09-07 10:00:00';
        $id    = $this->slot($waktu);

        $jid = (int) (new JobModel())->insert([
            'judul' => 'Admin Gudang', 'req_skill' => 'Stok',
            'req_pendidikan' => 'D3', 'req_pengalaman' => '1 tahun',
        ]);
        $cid = (int) (new CandidateModel())->insert([
            'nama' => 'Reza', 'email' => 'reza@uji.test', 'password_hash' => 'x',
        ]);
        $aid = (int) (new ApplicationModel())->insert([
            'candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf',
        ]);
        (new InterviewModel())->insert([
            'application_id' => $aid, 'jenis' => 'hrd',
            'status' => 'approved', 'scheduled_at' => $waktu,
        ]);

        $this->kirim(['aksi' => 'hapus', 'id' => $id]);

        $this->assertCount(1, $this->waktuTersimpan(), 'slotnya harus bertahan');
    }

    // --- halaman ---

    public function testHalamanMenampilkanSlotDanJalanMasuknya(): void
    {
        $this->slot('2026-09-07 10:00:00', 2);

        $halaman = (string) $this->withSession($this->sesi)->get('recruiter/pengaturan/jadwal')->getBody();
        $this->assertStringContainsString('07 Sep 2026', $halaman);

        // Jalan masuknya lewat Settings tahap Interview HRD - dan HANYA di
        // situ. Jam Interview User diatur atasan tiap posisi lewat akunnya
        // sendiri, jadi tombol di tahap itu akan menjanjikan setelan yang bukan
        // milik recruiter.
        $hrd = (string) $this->withSession($this->sesi)->get('recruiter/tahap/interview_online')->getBody();
        $this->assertStringContainsString('pengaturan/jadwal', $hrd);

        foreach (['interview_user', 'upload_cv'] as $tahap) {
            $lain = (string) $this->withSession($this->sesi)->get('recruiter/tahap/' . $tahap)->getBody();
            $this->assertStringNotContainsString('pengaturan/jadwal', $lain, $tahap);
        }
    }

    /** Halaman recruiter TIDAK menampilkan slot Interview User milik posisi mana pun. */
    public function testSlotInterviewUserTidakIkutDiHalamanRecruiter(): void
    {
        (new SlotInterviewModel())->insert([
            'scheduled_at' => '2026-09-08 15:00:00', 'jenis' => InterviewModel::JENIS_USER,
            'job_id' => 7, 'kuota' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $halaman = (string) $this->withSession($this->sesi)->get('recruiter/pengaturan/jadwal')->getBody();

        $this->assertStringNotContainsString('08 Sep 2026', $halaman);
    }

    /** Tanpa slot sama sekali, halamannya menyebutkan akibatnya. */
    public function testTanpaSlotHalamanMemperingatkan(): void
    {
        $halaman = (string) $this->withSession($this->sesi)->get('recruiter/pengaturan/jadwal')->getBody();

        $this->assertStringContainsString('kandidat tidak bisa memilih jadwal', $halaman);
    }
}
