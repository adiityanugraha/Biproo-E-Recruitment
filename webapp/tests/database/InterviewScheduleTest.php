<?php

use App\Libraries\SlotJadwal;
use App\Libraries\LembarPenilaian as L;
use App\Libraries\StageLogger;
use App\Libraries\ZoomException;
use App\Libraries\ZoomService;
use App\Models\ApplicationModel;
use App\Models\CandidateModel;
use App\Models\EmailQueueModel;
use App\Models\InterviewModel;
use App\Models\JobModel;
use App\Models\ScreeningResultModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Alur interview sejak 3 Agustus 2026: kandidat memilih SLOT tetap dan
 * pilihannya langsung terjadwal (meeting Zoom dibuat seketika, di-mock di sini).
 * Recruiter tidak lagi menyetujui; yang bisa ia lakukan adalah melepas jadwal
 * (reschedule) supaya kandidat memilih slot lain.
 *
 * Tab Interview HRD: On Progress -> Rescheduled -> Completed.
 *
 * @internal
 */
final class InterviewScheduleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    /**
     * Lembar penilaian penuh dengan satu nilai seragam 1-5.
     *
     * Menggantikan slider 0-100 yang dipakai uji ini sebelum 12 Agustus 2026.
     * Skornya: 1 -> 0, 2 -> 25, 3 -> 50, 4 -> 75, 5 -> 100.
     *
     * @return array<string, mixed>
     */
    private function lembar(int $n): array
    {
        return ['nilai' => array_fill(0, count(L::HRD), $n)];
    }

    /**
     * Slot sah ke-$ke. Diambil dari sumber yang sama dengan yang dipakai
     * controller, jadi uji ini tetap benar hari apa pun ia dijalankan.
     */
    private function slot(int $ke = 0): string
    {
        return SlotJadwal::tersedia()[$ke];
    }

    /** Kandidat memilih slot lewat HTTP, seperti menekan tombol di halaman jadwal. */
    private function pilihSlot(int $cid, int $aid, ?string $slot = null)
    {
        return $this->withSession($this->sesiKandidat($cid))
            ->post("interview/ajukan/{$aid}", ['jadwal' => $slot ?? $this->slot()]);
    }

    /** Recruiter melepas jadwal supaya kandidat memilih slot lain. */
    private function reschedule(int $aid, string $alasan = 'bentrok rapat direksi')
    {
        return $this->withSession($this->sesiRec)
            ->post("recruiter/interview/reschedule/{$aid}", ['alasan' => $alasan]);
    }

    /** id meeting yang diminta dihapus, diisi mock Zoom. */
    private ?object $jejakZoom = null;

    private function fakeZoom(bool $hapusGagal = false): void
    {
        $this->jejakZoom = new stdClass();
        $this->jejakZoom->dihapus = [];

        Services::injectMock('zoomService', new class ($this->jejakZoom, $hapusGagal) extends ZoomService {
            public function __construct(private object $jejak, private bool $hapusGagal) {}

            public function createMeeting(string $topic, ?string $startAt = null): array
            {
                return [
                    'meeting_id' => '99999999999',
                    'join_url'   => 'https://zoom.us/j/99999999999',
                    'start_url'  => 'https://zoom.us/s/99999999999?zak=x',
                ];
            }

            public function hapusMeeting(string $meetingId): void
            {
                if ($this->hapusGagal) {
                    throw new ZoomException('Zoom membalas status 500');
                }
                $this->jejak->dihapus[] = $meetingId;
            }
        });
    }

    /**
     * @param string $email beda email = kandidat kedua, untuk menguji rebutan slot
     *
     * @return array{0:int,1:int} [candidateId, applicationId]
     */
    private function fixture(string $gate1, string $email = 'sinta@example.com'): array
    {
        $nama = $email === 'sinta@example.com' ? 'Sinta' : 'Budi';
        $cid  = (new CandidateModel())->insert(['nama' => $nama, 'email' => $email, 'password_hash' => 'x']);
        $jid  = (new JobModel())->insert(['judul' => 'Backend Developer', 'req_skill' => 'PHP', 'req_pendidikan' => 'S1', 'req_pengalaman' => '2th']);
        $aid  = (int) (new ApplicationModel())->insert(['candidate_id' => $cid, 'job_id' => $jid, 'cv_path' => 'uploads/cv/x.pdf']);

        (new StageLogger())->log($aid, 'gate_1', $gate1, 'system', 'skor=0.8');

        return [(int) $cid, $aid];
    }

    private function sesiKandidat(int $cid): array
    {
        return ['candidate_id' => $cid, 'candidate_nama' => 'Sinta'];
    }

    public function testKandidatPilihSlotLangsungTerjadwalTanpaMenungguAcc(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');

        $this->pilihSlot($cid, $aid);

        // langsung approved + meeting Zoom sudah ada, tidak mampir ke 'requested'
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'approved', 'meeting_id' => '99999999999']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'penjadwalan', 'status' => 'entered']);
        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'undangan_interview']);
    }

    public function testKandidatBelumLolosTidakBisaAjukan(): void
    {
        [$cid, $aid] = $this->fixture('flagged');

        $this->pilihSlot($cid, $aid);

        $this->assertSame(0, (new InterviewModel())->where('application_id', $aid)->countAllResults());
    }

    public function testSlotDiLuarDaftarDitolak(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $hari = substr($this->slot(), 0, 10);

        // jam di luar 10.00-16.00, menit tidak bulat, tanggal lampau, format ngawur
        foreach (["{$hari} 09:00:00", "{$hari} 17:00:00", "{$hari} 10:30:00", '2020-01-06 10:00:00', 'besok pagi'] as $ngawur) {
            $this->pilihSlot($cid, $aid, $ngawur);
        }

        $this->assertSame(0, (new InterviewModel())->where('application_id', $aid)->countAllResults(),
            'hanya slot dari daftar yang boleh diterima, termasuk untuk POST langsung tanpa lewat halaman');
    }

    public function testSlotYangSudahDiambilTidakBisaDipilihKandidatLain(): void
    {
        $this->fakeZoom();
        [$cid, $aid]   = $this->fixture('passed');
        [$cid2, $aid2] = $this->fixture('passed', 'budi@example.com');
        $slot = $this->slot();

        $this->pilihSlot($cid, $aid, $slot);
        $this->pilihSlot($cid2, $aid2, $slot);   // kandidat kedua mengincar jam yang sama

        $this->assertSame(1, (new InterviewModel())->where('scheduled_at', $slot)->countAllResults(),
            'satu slot hanya boleh dipegang satu kandidat');
        $this->assertSame(0, (new InterviewModel())->where('application_id', $aid2)->countAllResults());
    }

    public function testSlotTerpakaiDitampilkanTapiTidakBisaDiklik(): void
    {
        $this->fakeZoom();
        [$cid, $aid]   = $this->fixture('passed');
        [$cid2, $aid2] = $this->fixture('passed', 'budi@example.com');
        $slot = $this->slot();
        $this->pilihSlot($cid, $aid, $slot);

        $html = (string) $this->withSession($this->sesiKandidat($cid2))->get('jadwal')->getBody();

        // jamnya tetap terlihat (biar kandidat tahu memang terisi) tapi tanpa radio
        $this->assertStringContainsString('Sudah dipilih kandidat lain', $html);
        $this->assertStringNotContainsString('value="' . $slot . '"', $html);
    }

    public function testIndeksUnikMenolakSlotGandaWalaupunPengecekanDilewati(): void
    {
        // Pengecekan controller punya celah antara "dicek kosong" dan "disimpan".
        // Uji ini melewati controller dan menulis langsung ke tabel, memastikan
        // database sendiri yang menolak, bukan cuma sopan santun aplikasi.
        [, $aid]  = $this->fixture('passed');
        [, $aid2] = $this->fixture('passed', 'budi@example.com');
        $slot = $this->slot();
        $iv   = new InterviewModel();
        $iv->insert(['application_id' => $aid, 'status' => 'approved', 'scheduled_at' => $slot]);

        $this->expectException(DatabaseException::class);
        $iv->insert(['application_id' => $aid2, 'status' => 'approved', 'scheduled_at' => $slot]);
    }

    /** interview yang sudah di-acc & jadwalnya lewat (siap dinilai di tab Completed). */
    private function pastApprovedInterview(int $aid): void
    {
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'approved',
            'scheduled_at'   => '2020-01-01 10:00:00',
            'meeting_id'     => '111',
            'join_url'       => 'https://zoom.us/j/111',
        ]);
    }

    public function testTabCompletedTampilkanInterviewLewatWaktu(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')->assertSee('Sinta');
    }

    /**
     * Skor CV wajib ada supaya Gate 2 diputus otomatis. Tanpa itu kandidat
     * ditandai 'flagged' dan keputusannya diserahkan ke recruiter - perilaku
     * yang diuji tersendiri di ScreeningScoreTest.
     */
    private function skorCv(int $aid, float $skor): void
    {
        (new ScreeningResultModel())->insert([
            'application_id'   => $aid,
            'screening_job_id' => 'uji-' . $aid,
            'status'           => 'success',
            'score_overall'    => $skor,
            'provider'         => 'dummy',
            'model_version'    => 'uji',
        ]);
    }

    public function testSkorTinggiDihitungLolosCatatGate2BerkasDanEmail(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);
        $this->skorCv($aid, 0.80);

        // 0.4*0.80 + 0.6*0.90 = 0.86 -> di atas ambang -> LOLOS otomatis
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", $this->lembar(5));

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'passed']);
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'berkas_kontrak', 'status' => 'entered']);
        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'hasil_gate']);
    }

    public function testSkorRendahDihitungTidakLolos(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);
        $this->skorCv($aid, 0.80);

        // 0.4*0.80 + 0.6*0.20 = 0.44 -> di bawah ambang -> TIDAK LOLOS otomatis
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", $this->lembar(2));

        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'gate_2', 'status' => 'failed']);
        $this->dontSeeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'berkas_kontrak']);
    }

    public function testTabOnProgressBerisiInterviewTerjadwalBesertaLinkZoom(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $res = $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online');

        $res->assertSee('Sinta');
        $res->assertSee('Link Zoom');
        $res->assertSee('Reschedule');   // tombol pelepas jadwal ikut di sini
    }

    public function testTabInterviewHrdHanyaTigaTanpaPassedDanFailed(): void
    {
        [$cid] = $this->fixture('passed');

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        $this->assertStringContainsString('On Progress', $html);
        $this->assertStringContainsString('Rescheduled', $html);
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringNotContainsString('>Passed<', $html);
        $this->assertStringNotContainsString('>Failed<', $html);
        // tombol Acc/Tolak sudah dicabut bersama alur persetujuan manual
        $this->assertStringNotContainsString('interview/acc/', $html);
        $this->assertStringNotContainsString('interview/tolak/', $html);
    }

    public function testTautanTabLamaDiarahkanKePenggantinya(): void
    {
        // bookmark lama ?status=passed / ?status=failed tidak boleh jatuh diam-diam
        // ke daftar yang salah
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=passed')
            ->assertSee('Sinta');   // diarahkan ke On Progress

        $this->reschedule($aid);
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=failed')
            ->assertSee('Sinta');   // diarahkan ke Rescheduled
    }

    public function testHalamanJadwalMenampilkanDaftarSlot(): void
    {
        [$cid] = $this->fixture('passed');

        $res = $this->withSession($this->sesiKandidat($cid))->get('jadwal');

        $res->assertStatus(200);
        $res->assertSee('Backend Developer');   // posisi yang lolos muncul
        $res->assertSee('Kunci Jadwal Ini');    // tombol pilih slot
        $res->assertDontSee('datetime-local');  // tidak ada lagi input waktu bebas
    }

    public function testSemuaSlotYangDirenderMemangSah(): void
    {
        [$cid] = $this->fixture('passed');

        $html = (string) $this->withSession($this->sesiKandidat($cid))->get('jadwal')->getBody();

        preg_match_all('/name="jadwal" value="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1], 'harus ada slot yang bisa dipilih');
        foreach ($m[1] as $slot) {
            $this->assertTrue(SlotJadwal::sah($slot), "slot {$slot} dirender padahal tidak sah");
        }
    }

    // --- Reschedule: recruiter melepas jadwal yang sudah terkunci ---

    public function testRescheduleMelepasSlotDanKandidatDikabari(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'rescheduled']);
        // riwayat append-only: baris penjadwalan lama tetap, koreksinya menyusul
        $this->seeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'penjadwalan', 'status' => 'failed',
            'note'           => 'Diminta jadwal ulang: bentrok rapat direksi',
        ]);
        $this->seeInDatabase('email_queue', ['to_email' => 'sinta@example.com', 'template' => 'jadwal_reschedule']);
    }

    public function testSlotYangDilepasBisaDipakaiKandidatLain(): void
    {
        $this->fakeZoom();
        [$cid, $aid]   = $this->fixture('passed');
        [$cid2, $aid2] = $this->fixture('passed', 'budi@example.com');
        $slot = $this->slot();
        $this->pilihSlot($cid, $aid, $slot);

        $this->reschedule($aid);

        // inti fiturnya: slot kembali ke daftar tanpa kode pelepasan khusus
        $this->pilihSlot($cid2, $aid2, $slot);
        $this->seeInDatabase('interviews', ['application_id' => $aid2, 'status' => 'approved', 'scheduled_at' => $slot]);
    }

    public function testKandidatBisaMemilihSlotLainSetelahDireschedule(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid, $this->slot(0));
        $this->reschedule($aid);

        // halaman kandidat menjelaskan sebabnya, bukan cuma menampilkan daftar lagi
        $html = (string) $this->withSession($this->sesiKandidat($cid))->get('jadwal')->getBody();
        $this->assertStringContainsString('Jadwal perlu diatur ulang', $html);
        $this->assertStringContainsString('Lamaran Anda tetap berjalan', $html);

        $this->pilihSlot($cid, $aid, $this->slot(1));

        $this->assertSame(1, (new InterviewModel())->where('application_id', $aid)->countAllResults(),
            'baris yang sama dipakai ulang, tidak menumpuk');
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'approved', 'scheduled_at' => $this->slot(1)]);
    }

    public function testKandidatYangDireschedulePindahKeTabRescheduled(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=rescheduled')->assertSee('Sinta');
        // dan TIDAK ikut tertinggal di On Progress
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->assertDontSee('Sinta');
    }

    public function testRescheduleDitolakBilaSesinyaSudahLewat(): void
    {
        // wawancaranya mungkin benar-benar terjadi; mengubahnya akan mengacaukan
        // tab Completed dan penilaian Gate 2
        [, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        $this->reschedule($aid);

        $this->seeInDatabase('interviews', ['application_id' => $aid, 'status' => 'approved']);
        $this->dontSeeInDatabase('candidate_stage_history', [
            'application_id' => $aid, 'stage' => 'penjadwalan', 'status' => 'failed',
        ]);
    }

    public function testRescheduleMenutupGerbangZoom(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');   // jendela sedang terbuka

        // dilepas lewat model karena guard controller menolak jadwal yang sudah tiba
        $iv = (new InterviewModel())->forApplication($aid);
        (new InterviewModel())->update($iv['id'], ['status' => 'rescheduled']);

        $res = $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}");

        $res->assertRedirectTo(site_url('jadwal'));
        $this->assertStringNotContainsString('zoom.us', (string) $res->getBody());
    }

    public function testBarisRequestedPeninggalanTetapTerlihatDiOnProgress(): void
    {
        // Alur sekarang tidak pernah membuat 'requested' lagi, tapi kalau ada
        // baris lama ia tidak boleh hilang dari pandangan recruiter.
        [, $aid] = $this->fixture('passed');
        (new InterviewModel())->insert([
            'application_id' => $aid, 'status' => 'requested', 'scheduled_at' => $this->slot(),
        ]);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->assertSee('Sinta');
    }

    /** interview approved dengan jadwal relatif ke sekarang, untuk uji gerbang link. */
    private function approvedInterviewPada(int $aid, string $offset): void
    {
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'approved',
            'scheduled_at'   => (new DateTime($offset))->format('Y-m-d H:i:s'),
            'meeting_id'     => '222',
            'join_url'       => 'https://zoom.us/j/222',
        ]);
    }

    public function testLinkDalamJendelaRedirectKeZoom(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');

        $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}")
            ->assertRedirectTo('https://zoom.us/j/222');
    }

    public function testLinkSetelahJendelaTutupTampilKedaluwarsaTanpaBocorkanUrlZoom(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '-3 hours');

        $res = $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}");

        $res->assertStatus(200);
        $res->assertSee('kedaluwarsa');
        $res->assertDontSee('zoom.us'); // URL Zoom asli tidak pernah sampai ke browser
    }

    public function testLinkSebelumJendelaBukaBelumBisaDipakai(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '+2 days');

        $res = $this->withSession($this->sesiKandidat($cid))->get("interview/masuk/{$aid}");

        $res->assertStatus(200);
        $res->assertSee('Belum waktunya');
        $res->assertDontSee('zoom.us');
    }

    public function testKandidatLainTidakBisaPakaiLinkOrang(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');
        $lain = (new CandidateModel())->insert(['nama' => 'Budi', 'email' => 'budi@example.com', 'password_hash' => 'x']);

        $this->withSession($this->sesiKandidat((int) $lain))->get("interview/masuk/{$aid}")
            ->assertRedirectTo(site_url('jadwal'));
    }

    /**
     * Stepper dashboard: sesi Interview mati sampai ruangnya buka.
     * Dijaga karena stepper dan halaman jadwal memakai sumber kebenaran yang
     * sama - kalau salah satu bergeser, kandidat melihat sesi menyala padahal
     * tombol Zoom-nya belum ada (atau sebaliknya).
     */
    /**
     * Potongan HTML satu langkah stepper, dipilih lewat labelnya.
     * Diperlukan karena beberapa langkah bisa mati bersamaan - memeriksa
     * seluruh halaman akan lolos/gagal karena langkah yang salah.
     */
    private function langkah(string $html, string $label): string
    {
        foreach (explode('class="step ', $html) as $blok) {
            if (str_contains($blok, '>' . $label . '</span>')) {
                return $blok;
            }
        }

        return '';
    }

    private function stepperDashboard(int $cid, int $aid): string
    {
        return (string) $this->withSession($this->sesiKandidat($cid))
            ->get('dashboard?app=' . $aid)->getBody();
    }

    public function testSesiInterviewMatiSebelumJadwalnyaTiba(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '+3 hours'); // di luar jendela 15 menit

        $html = $this->stepperDashboard($cid, $aid);

        $this->assertStringContainsString('card mati', $this->langkah($html, 'Interview'),
            'sesi Interview harus mati sebelum waktunya');
        // mati berarti benar-benar tidak bisa diklik: bukan tautan, bukan modal
        $this->assertStringNotContainsString("segera('Interview')", $html);
    }

    public function testSesiInterviewMenyalaDanMengarahKeHalamanJadwalSaatRuangBuka(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');

        $langkah = $this->langkah($this->stepperDashboard($cid, $aid), 'Interview');

        $this->assertStringNotContainsString('card mati', $langkah);
        // menyala = tautan ke halaman jadwal, tempat tombol "Gabung via Zoom" berada
        $this->assertStringContainsString('href="' . site_url('jadwal') . '"', $langkah,
            'sesi Interview harus menautkan ke halaman jadwal saat ruangnya buka');
    }

    public function testSesiInterviewMatiLagiSetelahJendelaTutup(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '-3 hours'); // jendela 1 jam sudah lewat

        $this->assertStringContainsString('card mati',
            $this->langkah($this->stepperDashboard($cid, $aid), 'Interview'));
    }

    public function testKeputusanAkhirMatiSelamaSkorInterviewBelumKeluar(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');

        $this->assertStringContainsString('card mati',
            $this->langkah($this->stepperDashboard($cid, $aid), 'Keputusan Akhir'));
    }

    public function testKeputusanAkhirMenyalaKeStatusLamaranSetelahSkorInterviewKeluar(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        // jadwal yang sudah jelas lewat: putusInterview membandingkan lewat SQL
        // CURRENT_TIMESTAMP, dan di SQLite (test) itu UTC sementara aplikasi WIB
        $this->pastApprovedInterview($aid);

        // recruiter memasukkan skor interview -> Keputusan Akhir terbuka
        $this->withSession($this->sesiRec)->post("recruiter/interview/putus/{$aid}", $this->lembar(4));
        $this->seeInDatabase('candidate_stage_history', ['application_id' => $aid, 'stage' => 'interview_online']);

        $langkah = $this->langkah($this->stepperDashboard($cid, $aid), 'Keputusan Akhir');
        $this->assertStringNotContainsString('card mati', $langkah);
        // membawa ?app= supaya kandidat mendarat di lamaran yang sedang dilihat,
        // bukan di lamaran lain miliknya
        $this->assertStringContainsString('href="' . site_url('status') . '?app=' . $aid . '"', $langkah,
            'Keputusan Akhir harus terhubung ke Status Lamaran untuk lamaran ini');
    }

    public function testUrutanTabMengikutiAlurInterview(): void
    {
        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')->getBody();

        // On Progress -> Rescheduled -> Completed, mengikuti perjalanan kandidat
        $this->assertLessThan(strpos($html, '>Rescheduled<'), strpos($html, '>On Progress<'));
        $this->assertLessThan(strpos($html, '>Completed<'), strpos($html, '>Rescheduled<'));
    }

    /**
     * Jadwal yang dilepas berhenti di Rescheduled walaupun tanggalnya sudah lewat.
     * Kandidatnya tidak pernah diwawancarai, jadi tidak ada yang bisa dinilai
     * di Gate 2 dan ia tidak boleh muncul di Completed.
     */
    public function testJadwalDilepasTidakIkutKeCompletedWalaupunTanggalnyaLewat(): void
    {
        [, $aid] = $this->fixture('passed');
        (new InterviewModel())->insert([
            'application_id' => $aid,
            'status'         => 'rescheduled',
            'scheduled_at'   => '2020-01-01 10:00:00', // jelas sudah lewat
        ]);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')
            ->assertDontSee('Sinta');
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=rescheduled')
            ->assertSee('Sinta');
    }

    public function testAjuanDisetujuiYangTanggalnyaLewatMasukCompleted(): void
    {
        // pasangan uji di atas: pembedanya status, bukan tanggal
        [$cid, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')
            ->assertSee('Sinta');
    }

    /**
     * Ketiga tab Interview HRD saling lepas: satu kandidat hanya boleh muncul di
     * satu tab, kalau tidak recruiter mengerjakan orang yang sama dua kali.
     */
    public function testSesiSelesaiPindahDariOnProgressKeCompleted(): void
    {
        [, $aid] = $this->fixture('passed');
        $this->pastApprovedInterview($aid);

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')
            ->assertDontSee('Sinta');
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')
            ->assertSee('Sinta');
    }

    public function testSesiBelumSelesaiTetapDiOnProgress(): void
    {
        [, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '+2 days');

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')
            ->assertSee('Sinta');
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')
            ->assertDontSee('Sinta');
    }

    public function testSesiYangSedangBerlangsungMasihDiOnProgress(): void
    {
        // Batasnya akhir sesi, bukan jam mulai: slot 10.00 baru pindah ke
        // Completed pukul 10.30, supaya recruiter tidak melihat slider penilaian
        // untuk wawancara yang sedang berjalan.
        [, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '-10 minutes');   // mulai 10 menit lalu

        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online')
            ->assertSee('Sinta');
        $this->withSession($this->sesiRec)->get('recruiter/tahap/interview_online?status=completed')
            ->assertDontSee('Sinta');
    }

    public function testStepperTidakLagiMenampilkanVerifikasiCvAi(): void
    {
        [$cid, $aid] = $this->fixture('passed');

        // proses internal, tidak butuh tindakan kandidat - tapi riwayatnya tetap
        // bisa dilihat di Status Lamaran
        $this->assertStringNotContainsString('Verifikasi CV', $this->stepperDashboard($cid, $aid));
    }

    public function testHalamanJadwalJadiPenjadwalanBerhasilSaatRuangTerbuka(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');

        $res = $this->withSession($this->sesiKandidat($cid))->get('jadwal');

        $res->assertSee('Penjadwalan Berhasil');
        $res->assertDontSee('Pilih Jadwal Interview');
    }

    public function testHalamanJadwalTetapPendaftaranSelamaRuangBelumTerbuka(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, '+3 hours');

        $res = $this->withSession($this->sesiKandidat($cid))->get('jadwal');

        $res->assertSee('Pilih Jadwal Interview');
        $res->assertDontSee('Penjadwalan Berhasil');
    }

    public function testKartuMenyebutBatasTutupSesuaiKonstantaPenjaganya(): void
    {
        [$cid, $aid] = $this->fixture('passed');
        $this->approvedInterviewPada($aid, 'now');

        $html = (string) $this->withSession($this->sesiKandidat($cid))->get('jadwal')->getBody();

        // keterangan waktu tutup harus ikut TUTUP_MENIT, bukan angka yang ditulis
        // tangan di view - kalau jendelanya diubah, kalimatnya ikut berubah
        $tutup = (new DateTime('+' . InterviewModel::TUTUP_MENIT . ' minutes'))->format('H:i');
        $this->assertStringContainsString('Ruang interview sudah dibuka', $html);
        $this->assertStringContainsString($tutup . ' WIB', $html);
    }

    public function testUndanganEmailMemuatGerbangBukanUrlZoomMentah(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');

        $this->pilihSlot($cid, $aid);

        $baris   = (new EmailQueueModel())->where('template', 'undangan_interview')->first();
        $payload = json_decode($baris['payload_json'], true);
        $this->assertStringContainsString("interview/masuk/{$aid}", $payload['join_url']);
        $this->assertStringNotContainsString('zoom.us', $payload['join_url']);
        // join_url asli tetap tersimpan di DB untuk dipakai redirect
        $this->seeInDatabase('interviews', ['application_id' => $aid, 'join_url' => 'https://zoom.us/j/99999999999']);
    }

    // --- Pencabutan ruang Zoom saat reschedule ---

    /**
     * Mengubah status di basis data TIDAK mematikan ruangannya. Tanpa panggilan
     * hapus ke Zoom, meeting tetap hidup dan siapa pun yang sempat menyimpan
     * join_url masih bisa masuk - gerbang aplikasi cuma menjaga pintu depan.
     */
    public function testRescheduleMenghapusRuangZoomnya(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $this->assertSame(['99999999999'], $this->jejakZoom->dihapus);
    }

    public function testTautanDikosongkanSetelahRuangnyaBenarBenarDihapus(): void
    {
        $this->fakeZoom();
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $iv = (new InterviewModel())->forApplication($aid);
        $this->assertNull($iv['join_url']);
        $this->assertNull($iv['meeting_id']);
    }

    /**
     * Zoom tidak menjawab bukan alasan membatalkan reschedule: jadwalnya sudah
     * dilepas dan kandidat sudah dikabari, jadi menggagalkan semuanya justru
     * meninggalkan keadaan setengah jadi.
     */
    public function testZoomGagalTidakMenjatuhkanReschedule(): void
    {
        $this->fakeZoom(hapusGagal: true);
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $iv = (new InterviewModel())->forApplication($aid);
        $this->assertSame('rescheduled', $iv['status']);
        $this->assertSame(1, (new EmailQueueModel())->where('template', 'jadwal_reschedule')->countAllResults());
    }

    /** Tautan yang gagal dihapus TIDAK dikosongkan - jejak ruangan yang masih hidup jangan ikut hilang. */
    public function testTautanDipertahankanBilaPenghapusanGagal(): void
    {
        $this->fakeZoom(hapusGagal: true);
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $this->assertNotNull((new InterviewModel())->forApplication($aid)['meeting_id']);
    }

    /** Recruiter harus tahu, bukan cuma berkas log: ruangan lama masih bisa dimasuki. */
    public function testRecruiterDiberiTahuBilaRuangLamaMasihHidup(): void
    {
        $this->fakeZoom(hapusGagal: true);
        [$cid, $aid] = $this->fixture('passed');
        $this->pilihSlot($cid, $aid);

        $this->reschedule($aid);

        $this->assertStringContainsString('masih bisa dimasuki', (string) session('error'));
    }
}
