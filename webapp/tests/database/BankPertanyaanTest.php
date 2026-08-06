<?php

use App\Models\JobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * Bank pertanyaan interview dari tim DS (interview_softskill_hardskill.csv).
 *
 * Menggantikan generasi LLM untuk posisi yang tercakup. Alasannya bukan selera:
 *   - kuota tier gratis 20 panggilan generateContent per hari, bank ini nol;
 *   - tiap soal membawa kompetensi, indikator jawaban baik, red flag, dan bobot.
 *     Rubrik itu TIDAK bisa dikarang LLM tanpa mengarang standar penilaian -
 *     pola yang sama dengan skor karangan yang sudah kita buang dari pipeline.
 *
 * @internal
 */
final class BankPertanyaanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use StreamFilterTrait;   // menangkap keluaran CLI

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private array $sesiRec = ['recruiter_id' => 1, 'recruiter_nama' => 'Irpan'];

    private string $csv = '';

    protected function tearDown(): void
    {
        if ($this->csv !== '' && is_file($this->csv)) {
            unlink($this->csv);
        }
        parent::tearDown();
    }

    /**
     * Cari satu soal berdasarkan kompetensinya.
     *
     * Uji ini SENGAJA tidak memakai indeks: sejak pertanyaan pembuka umum ikut
     * disimpan di depan, indeks soal khusus posisi bergeser tiap kali daftar
     * umum berubah. Mencari lewat kompetensi membuat uji ini menyatakan maksud,
     * bukan tata letak.
     *
     * @param list<mixed> $soal
     *
     * @return array<string, mixed>|null
     */
    private function cari(array $soal, string $kompetensi): ?array
    {
        foreach ($soal as $x) {
            if (is_array($x) && ($x['kompetensi'] ?? '') === $kompetensi) {
                return $x;
            }
        }

        return null;
    }

    /** @return list<mixed> */
    private function soalJob(string $judul): array
    {
        return json_decode((new JobModel())->where('judul', $judul)->first()['pertanyaan_json'], true);
    }

    /** Direktori sementara dengan pemisah garis miring, aman untuk helper command(). */
    private function tmp(): string
    {
        return str_replace('\\', '/', sys_get_temp_dir());
    }

    /** CSV tiruan berbentuk sama dengan berkas tim DS. */
    private function csv(string $isi = ''): string
    {
        // Dua syarat dari helper command() milik CI4: argumen dipecah pada SPASI
        // (WRITEPATH proyek ini ada di bawah folder "Tugas Kuliah"), dan
        // BACKSLASH dibuang sebagai escape - "E:\Temp\x" sampai sebagai
        // "E:Tempx". Maka: temp dir tanpa spasi, dipisah garis miring biasa.
        $this->csv = $this->tmp() . '/uji-bank-' . bin2hex(random_bytes(4)) . '.csv';
        $baku      = <<<'CSV'
            id,posisi,posisi_id,job_family,level,brand,kategori_skill,kompetensi,pertanyaan,indikator_jawaban_baik,red_flag,bobot
            F1-01-S01,Retail Gadget Erafone,retail_erafone,Retail Gadget Sales,Entry,Erafone,Soft Skill,Komunikasi Persuasif,Coba jelaskan satu produk kepada saya.,Menggali kebutuhan lebih dulu.,Langsung menyebut spesifikasi.,5
            F1-01-H01,Retail Gadget Erafone,retail_erafone,Retail Gadget Sales,Entry,Erafone,Hard Skill,Perbandingan Spesifikasi,Dua HP harga sama beda unggulan. Bagaimana memilihkan?,Menggali pola pakai dulu.,Hanya membandingkan angka.,4
            F2-01-S01,Kurir Gudang,kurir_gudang,Warehouse & Logistik,Entry,Gudang umum,Soft Skill,Ketelitian,Bagaimana memastikan barang tidak tertukar?,Mengecek ulang label.,Mengandalkan ingatan.,5
            CSV;
        file_put_contents($this->csv, $isi !== '' ? $isi : preg_replace('/^\s+/m', '', $baku));

        return $this->csv;
    }

    private function impor(array $opsi = []): string
    {
        command('lowongan:impor --berkas ' . $this->csv() . (($opsi['kering'] ?? false) ? ' --kering' : ''));

        return $this->getStreamFilterBuffer();
    }

    public function testImporMembuatLowonganDenganBankSoalnya(): void
    {
        $this->impor();

        $job = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first();
        $this->assertNotNull($job);
        $soal = json_decode($job['pertanyaan_json'], true);

        // 6 pembuka umum + 2 khusus posisi
        $this->assertCount(8, $soal);
        $this->assertSame(
            'Coba jelaskan satu produk kepada saya.',
            $this->cari($soal, 'Komunikasi Persuasif')['pertanyaan']
        );
    }

    /** Yang membuat bank ini lebih berguna dari daftar pertanyaan biasa. */
    public function testRubrikPenilaianIkutTersimpan(): void
    {
        $this->impor();

        $r = $this->cari($this->soalJob('Retail Gadget Erafone'), 'Komunikasi Persuasif');
        $this->assertNotNull($r);
        $this->assertSame('Soft Skill', $r['kategori']);
        $this->assertSame(5, $r['bobot']);
        $this->assertStringContainsString('Menggali kebutuhan', $r['indikator']);
        $this->assertStringContainsString('Langsung menyebut', $r['red_flag']);
    }

    /**
     * Syarat keahlian dirakit dari kompetensi HARD SKILL saja. Soft skill
     * seperti "Ketelitian" muncul di hampir semua posisi, jadi memasukkannya
     * membuat tiap lowongan terlihat mirip satu sama lain di mata scorer
     * kemiripan CV - persis daya beda yang kita coba pertahankan.
     */
    public function testSyaratKeahlianDiambilDariKompetensiHardSkill(): void
    {
        $this->impor();

        $job = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first();
        $this->assertStringContainsString('Perbandingan Spesifikasi', $job['req_skill']);
        $this->assertStringNotContainsString('Komunikasi Persuasif', $job['req_skill']);
    }

    /** Berkas ini tidak memuat pendidikan minimal. Dikosongkan, bukan dikarang. */
    public function testPendidikanDikosongkanBukanDikarang(): void
    {
        $this->impor();

        $this->assertSame('', (string) (new JobModel())->where('judul', 'Kurir Gudang')->first()['req_pendidikan']);
    }

    public function testLevelDiterjemahkanJadiSyaratPengalaman(): void
    {
        $this->impor();

        $this->assertStringContainsString(
            'fresh graduate',
            (new JobModel())->where('judul', 'Kurir Gudang')->first()['req_pengalaman']
        );
    }

    public function testImporUlangMemperbaruiBukanMenggandakan(): void
    {
        $this->impor();
        $this->csv = '';
        $this->impor();

        $this->assertSame(1, (new JobModel())->where('judul', 'Retail Gadget Erafone')->countAllResults());
        $this->assertSame(2, (new JobModel())->countAllResults());
    }

    public function testModeKeringTidakMenulisApaPun(): void
    {
        $this->impor(['kering' => true]);

        $this->assertSame(0, (new JobModel())->countAllResults());
    }

    public function testBerkasTanpaKolomWajibDitolakBukanDiimporSeparuh(): void
    {
        $rusak = $this->tmp() . '/uji-rusak.csv';
        file_put_contents($rusak, "posisi,pertanyaan\nAda,Tanya\n");

        command('lowongan:impor --berkas ' . $rusak);
        $keluar = $this->getStreamFilterBuffer();
        unlink($rusak);

        $this->assertStringContainsString('Kolom wajib tidak ada', $keluar);
        $this->assertSame(0, (new JobModel())->countAllResults());
    }

    public function testBerkasTidakAdaDilaporkanBukanCrash(): void
    {
        command('lowongan:impor --berkas Z:/tidak/ada.csv');

        $this->assertStringContainsString('tidak ditemukan', $this->getStreamFilterBuffer());
    }

    // --- Tampilan dan penyuntingan ---

    public function testHalamanPertanyaanMenampilkanRubrik(): void
    {
        $this->impor();
        $jid = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first()['id'];

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Komunikasi Persuasif', $html);
        $this->assertStringContainsString('Menggali kebutuhan', $html);
        $this->assertStringContainsString('Langsung menyebut', $html);
        $this->assertStringContainsString('bobot 5', $html);
    }

    /**
     * Form cuma mengirim teks pertanyaan. Rubriknya digabungkan kembali dari
     * yang tersimpan, bukan dititipkan lewat field tersembunyi - standar
     * penilaian tidak boleh bisa diubah dari browser.
     */
    public function testMenyuntingTeksTidakMenghapusRubriknya(): void
    {
        $this->impor();
        $jid = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first()['id'];

        // 6 baris pertama adalah pembuka umum; yang disunting baris ke-7 dan ke-8
        $teks = array_fill(0, 6, 'Pembuka tetap');
        $teks[] = 'Pertanyaan yang sudah disunting';
        $teks[] = 'Yang kedua juga';

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, [
            'aksi' => 'simpan', 'pertanyaan' => $teks,
        ]);

        $soal = json_decode((new JobModel())->find($jid)['pertanyaan_json'], true);
        $r    = $this->cari($soal, 'Komunikasi Persuasif');
        $this->assertSame('Pertanyaan yang sudah disunting', $r['pertanyaan']);
        $this->assertSame(5, $r['bobot']);
        $this->assertStringContainsString('Menggali kebutuhan', $r['indikator']);
        $this->assertNotNull($this->cari($soal, 'Perbandingan Spesifikasi'));
    }

    /**
     * Rubrik hanya boleh datang dari bank. Bahkan bila form dikirim ulang dengan
     * jumlah baris yang lebih banyak, baris tambahan tidak boleh mewarisi rubrik
     * milik baris lain.
     */
    public function testBarisTambahanTidakMewarisiRubrikBarisLain(): void
    {
        $this->impor();
        $jid = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first()['id'];

        $teks   = array_fill(0, 8, 'Baris lama');   // 6 umum + 2 khusus posisi
        $teks[] = 'Sisipan baru di ujung';

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, [
            'aksi' => 'simpan', 'pertanyaan' => $teks,
        ]);

        $soal = json_decode((new JobModel())->find($jid)['pertanyaan_json'], true);
        $this->assertNotNull($this->cari($soal, 'Komunikasi Persuasif'));
        $this->assertNotNull($this->cari($soal, 'Perbandingan Spesifikasi'));
        // baris ke-9 tidak punya padanan tersimpan -> teks biasa, bukan mewarisi rubrik
        $this->assertSame('Sisipan baru di ujung', $soal[8]);
    }

    public function testPertanyaanDariLlmTetapBerbentukTeksBiasa(): void
    {
        $jid = (new JobModel())->insert([
            'judul' => 'Backend Developer', 'req_skill' => 'PHP',
            'req_pendidikan' => 'S1', 'req_pengalaman' => '2th',
        ]);
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['Pertanyaan hasil AI'])]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Pertanyaan hasil AI', $html);
        $this->assertStringNotContainsString('Red flag', $html);
    }

    // --- Gagasan yang diadaptasi dari kode tim DS ---

    /**
     * Bank CSV tidak memuat satu pun pertanyaan pembuka - isinya seluruhnya
     * spesifik posisi. Padahal wawancara sungguhan selalu dimulai dari sana.
     */
    public function testPertanyaanPembukaUmumIkutDiTiapPosisi(): void
    {
        $this->impor();

        foreach (['Retail Gadget Erafone', 'Kurir Gudang'] as $judul) {
            $soal = $this->soalJob($judul);
            $this->assertNotNull($this->cari($soal, 'Pembuka'), $judul);
            $this->assertNotNull($this->cari($soal, 'Ekspektasi'), $judul);
        }
    }

    /**
     * Gaji dan ketersediaan memang ditanyakan, tapi BUKAN penilaian skill.
     * Kalau kelak skor interview dihitung dari rubrik, pertanyaan gaji tidak
     * boleh ikut menentukan nilai kandidat.
     */
    public function testPertanyaanGajiDitandaiLainnyaBukanSkill(): void
    {
        $this->impor();

        $r = $this->cari($this->soalJob('Kurir Gudang'), 'Ekspektasi');
        $this->assertSame('Lainnya', $r['kategori']);
        $this->assertArrayNotHasKey('bobot', $r);
    }

    public function testRumpunPekerjaanTersimpanDiLowongan(): void
    {
        $this->impor();

        $this->assertSame('Warehouse & Logistik', (new JobModel())->where('judul', 'Kurir Gudang')->first()['kategori']);
    }

    /**
     * Lowongan di luar bank meminjam dari lowongan serumpun, DAN halaman
     * mengatakan bahwa itu pinjaman. Inti gagasan tim DS: jangan menyajikan
     * pertanyaan orang lain diam-diam.
     */
    public function testLowonganDiLuarBankMeminjamDanMengakuinya(): void
    {
        $this->impor();
        $jid = (new JobModel())->insert([
            'judul' => 'Operator Gudang Cikarang', 'req_skill' => '-',
            'req_pendidikan' => '-', 'req_pengalaman' => '-',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Ini pinjaman', $html);
        $this->assertStringContainsString('Kurir Gudang', $html);
        $this->assertStringContainsString('Bagaimana memastikan barang tidak tertukar?', $html);
    }

    /** Pinjaman TIDAK ikut tersimpan sampai recruiter menekan simpan. */
    public function testPinjamanTidakTersimpanDiamDiam(): void
    {
        $this->impor();
        $jid = (new JobModel())->insert([
            'judul' => 'Operator Gudang Cikarang', 'req_skill' => '-',
            'req_pendidikan' => '-', 'req_pengalaman' => '-',
        ]);

        $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid);

        $this->assertNull((new JobModel())->find($jid)['pertanyaan_json']);
    }

    /** Posisi yang tidak tertebak rumpunnya tidak boleh dipinjami sembarangan. */
    public function testPosisiTakDikenaliTidakDipinjamiApaPun(): void
    {
        $this->impor();
        $jid = (new JobModel())->insert([
            'judul' => 'Backend Developer', 'req_skill' => 'PHP',
            'req_pendidikan' => 'S1', 'req_pengalaman' => '2th',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringNotContainsString('Ini pinjaman', $html);
        $this->assertStringContainsString('Belum ada pertanyaan', $html);
        $this->assertStringContainsString('Buat dengan AI', $html);
    }

    /**
     * Posisi yang sudah memakai bank tidak menawarkan pembuatan ulang lewat AI.
     * Menekannya berarti menukar rubrik kurasi manusia dengan teks polos, dan
     * memakai jatah 20 panggilan per hari untuk hasil yang lebih miskin.
     */
    public function testPosisiBerbankTidakMenawarkanTombolAi(): void
    {
        $this->impor();
        $jid = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first()['id'];

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringNotContainsString('Buat Ulang dengan AI', $html);
        $this->assertStringNotContainsString('Buat dengan AI', $html);
        $this->assertStringContainsString('bank soal yang disusun tim rekrutmen', $html);
    }

    /** Menyembunyikan tombol bukan penjagaan - POST kiriman ulang harus ditolak juga. */
    public function testPostBuatUlangDitolakPadaPosisiBerbank(): void
    {
        $this->impor();
        $jid    = (new JobModel())->where('judul', 'Retail Gadget Erafone')->first()['id'];
        $sebelum = (new JobModel())->find($jid)['pertanyaan_json'];

        $this->withSession($this->sesiRec)->post('recruiter/pertanyaan/' . $jid, ['aksi' => 'buat']);

        $this->assertSame($sebelum, (new JobModel())->find($jid)['pertanyaan_json']);
    }

    /** Posisi di luar bank tetap boleh - di situlah AI memang berguna. */
    public function testPosisiTanpaBankTetapMenawarkanAi(): void
    {
        $jid = (new JobModel())->insert([
            'judul' => 'Backend Developer', 'req_skill' => 'PHP',
            'req_pendidikan' => 'S1', 'req_pengalaman' => '2th',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Buat dengan AI', $html);
    }

    /** Saat sedang meminjam, recruiter boleh memilih menyusun yang khusus lewat AI. */
    public function testHalamanPinjamanTetapMenawarkanAi(): void
    {
        $this->impor();
        $jid = (new JobModel())->insert([
            'judul' => 'Operator Gudang Cikarang', 'req_skill' => '-',
            'req_pendidikan' => '-', 'req_pengalaman' => '-',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Ini pinjaman', $html);
        $this->assertStringContainsString('dengan AI', $html);
    }

    /**
     * Buat ulang kini berupa ikon, bukan tombol berteks. Ikon tanpa label adalah
     * tombol misterius bagi pembaca layar dan bagi orang yang belum pernah
     * memakainya, jadi title dan aria-label WAJIB ikut.
     */
    public function testTombolBuatUlangBerupaIkonYangTetapPunyaLabel(): void
    {
        $jid = (new JobModel())->insert([
            'judul' => 'Backend Developer', 'req_skill' => 'PHP',
            'req_pendidikan' => 'S1', 'req_pengalaman' => '2th',
        ]);
        (new JobModel())->update($jid, ['pertanyaan_json' => json_encode(['Pertanyaan hasil AI'])]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('class="btn-ulang"', $html);
        $this->assertStringContainsString('aria-label="Buat ulang seluruh pertanyaan dengan AI"', $html);
        $this->assertStringContainsString('title="Buat ulang seluruh pertanyaan dengan AI"', $html);
        // teks panjangnya tidak lagi memenuhi tombol
        $this->assertStringNotContainsString('>✨ Buat Ulang', $html);
        // konfirmasi tetap ada: sekali tekan menimpa semuanya
        $this->assertStringContainsString('MENGGANTI seluruh pertanyaan', $html);
    }

    /** Saat masih kosong, membuat pertanyaan adalah aksi utama - tetap tombol berteks. */
    public function testSaatKosongTetapTombolBerteks(): void
    {
        $jid = (new JobModel())->insert([
            'judul' => 'Backend Developer', 'req_skill' => 'PHP',
            'req_pendidikan' => 'S1', 'req_pengalaman' => '2th',
        ]);

        $html = (string) $this->withSession($this->sesiRec)->get('recruiter/pertanyaan/' . $jid)->getBody();

        $this->assertStringContainsString('Buat dengan AI', $html);
        // nama kelasnya ikut di blok <style>; yang dicek atribut pada tombolnya
        $this->assertStringNotContainsString('class="btn-ulang"', $html);
    }
}
