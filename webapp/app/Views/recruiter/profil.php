<?php

use App\Libraries\LembarPenilaian as L;

/**
 * Lembar profil kandidat, tiga halaman, mengikuti dokumen BIPROO yang asli.
 *
 * Dibuka di jendela pratinjau dari kolom Summary tabel tahap.
 *
 * Aturan cetaknya tetap ditulis (@media print: satu lembar per halaman, tanpa
 * bayangan dan latar) walau tidak ada tombol cetak di layar. Recruiter yang
 * butuh mencetak memakai Ctrl+P browser, dan tanpa aturan itu hasilnya
 * terpotong sembarangan.
 *
 * SETIAP BAGIAN HANYA MUNCUL KALAU DATANYA ADA. Kandidat yang baru sampai
 * assessment tidak punya bagian Interview sama sekali - bukan bagian kosong
 * bertuliskan "belum dinilai". Lembar ini ringkasan yang dibaca orang lain,
 * dan kotak kosong selalu memancing pertanyaan yang tidak perlu.
 *
 * Akibatnya jumlah halaman ikut menyusut, dan itu memang disengaja.
 */

// --- rakit baris penilaian jadi bentuk yang mudah dibaca template ---
$hrd = $user = $narasi = $asalNarasi = [];
// Siapa yang memberi nilai, per kompetensi. Sejak penilaian dibaca dari
// transkrip (revisi 12 Agustus 2026), satu lembar diisi DUA pihak: AI membaca
// apa yang terucap, recruiter menilai apa yang hanya bisa dilihat mata.
// Pembaca lembar ini - user departemen, manajemen - berhak tahu yang mana.
$asal = $alasan = [];
foreach ($penilaian as $p) {
    $kat = $p['kategori'] ?? '';
    if ($kat === L::KAT_HRD) {
        $hrd[$p['kompetensi']]    = (int) $p['tingkat'];
        $asal[$p['kompetensi']]   = (string) ($p['sumber'] ?? '');
        $alasan[$p['kompetensi']] = (string) ($p['catatan'] ?? '');
    } elseif ($kat === L::KAT_USER) {
        $user[$p['kompetensi']] = (int) $p['tingkat'];
    } elseif ($kat === L::KAT_NARASI) {
        $narasi[$p['kompetensi']]     = (string) $p['catatan'];
        $asalNarasi[$p['kompetensi']] = (string) ($p['sumber'] ?? '');
    }
}

// Lembar lama tidak punya kolom sumber, jadi barisnya kosong. Ditandai
// tersendiri, bukan dianggap dinilai recruiter: yang tidak diketahui tidak
// boleh diaku-akui, dan lembar cetak inilah yang paling lama dipercaya orang.
$adaSumber = array_filter($asal) !== [];

// Interview Result mengikuti keputusan Gate 2, bukan isian tersendiri: lolos
// berarti Recommended. Selama keputusannya belum ada (termasuk saat kandidat
// masih 'flagged' menunggu recruiter), barisnya kosong.
$hasilAkhir = L::hasil($gate2);

$pribadi = $bukti['pribadi'] ?? [];
$kosong  = '<span class="kosong">-</span>';

/**
 * Titik poligon radar untuk sembilan kompetensi.
 *
 * SVG apa adanya, tanpa pustaka grafik: sembilan titik pada lingkaran, jari-jari
 * sebanding nilainya. Menambah pustaka chart demi satu gambar berarti menambah
 * berkas yang harus diunduh pembaca hanya untuk mencetak selembar dokumen.
 */
$titik = static function (array $nilai, float $skala) use ($hrd): string {
    $n   = count(L::HRD);
    $out = [];
    foreach (array_values(L::HRD) as $i => $kompetensi) {
        $v     = $nilai[$kompetensi] ?? 0;
        $sudut = -M_PI / 2 + 2 * M_PI * $i / $n;   // mulai dari atas
        $r     = $skala * $v / L::MAKS_SKALA;
        $out[] = round(110 + $r * cos($sudut), 1) . ',' . round(110 + $r * sin($sudut), 1);
    }

    return implode(' ', $out);
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title>Lembar Profil - <?= esc($app['nama']) ?></title>
<style>
  body { background: #eceff3; margin: 0; padding: 20px; }
  .lembar { width: 794px; min-height: 1080px; margin: 0 auto 20px; background: #fff; padding: 0 0 40px;
            box-shadow: 0 4px 14px rgba(0,0,0,.08); position: relative; }
  .kop { background: linear-gradient(100deg, #F7941D, #FBB63D); color: #fff; padding: 22px 32px; }
  .kop .merek { font-size: 12px; opacity: .9; }
  .kop h1 { margin: 6px 0 2px; font-size: 22px; }
  .kop .sub { font-size: 13px; opacity: .95; }
  .kop .tgl { position: absolute; right: 32px; top: 26px; font-size: 12px; }
  .bar { background: #F7941D; color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .6px;
         padding: 6px 32px; margin: 26px 0 14px; }
  .isi { padding: 0 32px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .data th { width: 210px; text-align: left; background: #FDF3E4; color: #7a5a13; font-weight: 600;
             padding: 6px 10px; border: 1px solid #f2e2c9; }
  .data td { padding: 6px 10px; border: 1px solid #f2e2c9; }
  .kosong { color: #b9b9b9; }
  /* Riwayat kerja, tata letak dokumen BIPROO: kolom kiri rata kanan berisi
     periode dan perusahaan, garis waktu di tengah, rincian jabatan di kanan. */
  .kerja { display: flex; gap: 0; font-size: 11px; margin-bottom: 18px; }
  .kerja .kiri { width: 190px; flex-shrink: 0; text-align: right; padding-right: 12px; }
  .kerja .periode { color: #444; }
  .kerja .firma { font-weight: 700; color: #222; }
  .kerja .bidang { color: #888; }
  /* Garis waktu: batang tipis setinggi entri, dengan bulatan tepat di sumbunya.
     Bulatannya BUKAN karakter teks lagi. Sebelumnya dipakai glyph "&#9679;",
     dan lebar glyph berbeda-beda antar font sehingga pusatnya meleset dari
     sumbu garis (terukur 456,5 versus 457,8) dan tingginya ikut line-height.
     Bentuk CSS bisa ditaruh persis: left -6px dari kotak padding = tepat di
     tengah border 2px, dan top 3px menyejajarkannya dengan baris jabatan. */
  .kerja .garis { width: 14px; flex-shrink: 0; position: relative; border-left: 2px solid #F7941D; }
  .kerja .garis::before { content: ''; position: absolute; left: -6px; top: 3px;
                          width: 10px; height: 10px; border-radius: 50%;
                          background: #F7941D; box-shadow: 0 0 0 2px #fff; }
  .kerja .kanan { flex: 1; padding-left: 6px; }
  .kerja .jabatan { font-weight: 700; text-decoration: underline; color: #222; margin-bottom: 3px; }
  .kerja .rinci { width: auto; }
  .kerja .rinci td { border: none; padding: 0 0 1px; vertical-align: top; }
  .kerja .rinci td.l { width: 120px; }
  .kerja .rinci td.p { width: 10px; }
  .kerja .deskripsi-l { font-weight: 700; margin-top: 3px; }
  .kerja .deskripsi { padding-left: 14px; line-height: 1.5; }
  .nilai th, .nilai td { border: 1px solid #f2e2c9; padding: 6px 10px; }
  .nilai th { background: #FDF3E4; color: #7a5a13; text-align: left; font-weight: 600; }
  .nilai td.v { text-align: right; width: 150px; }
  .nilai td.s { width: 74px; text-align: center; }

  /* Penanda siapa yang menilai. Warnanya sengaja lembut: ini keterangan asal
     usul, bukan penilaian bahwa yang satu lebih sahih daripada yang lain. */
  .tag { display: inline-block; font-size: 9px; font-weight: 700; letter-spacing: .3px;
         padding: 1px 6px; border-radius: 10px; white-space: nowrap; }
  .t-ai  { background: #E8EEFA; color: #33518c; }
  .t-org { background: #EDF6EE; color: #2f6b3c; }
  .ket-sumber { font-size: 10px; color: #777; line-height: 1.7; margin: 8px 0 0; }
  .sub-bar { background: #FDF3E4; color: #7a5a13; font-weight: 700; font-size: 11px;
             padding: 4px 10px; border: 1px solid #f2e2c9; border-bottom: none; }
  .alasan td { font-size: 10.5px; line-height: 1.55; color: #444; }
  .alasan th { width: 34%; }
  .narasi { border: 1px solid #f2e2c9; margin-bottom: 8px; font-size: 12px; }
  .narasi .l { background: #FDF3E4; color: #7a5a13; font-weight: 600; padding: 5px 10px; }
  .narasi .t { padding: 8px 10px; min-height: 24px; }
  .belum { border: 1px dashed #F3B94A; background: #FFF9EE; color: #8a6d1e; border-radius: 8px;
           padding: 22px 20px; text-align: center; font-size: 13px; }
  .belum b { display: block; margin-bottom: 4px; }
  /* Lembarnya 794px. Di dalam jendela pratinjau yang lebih sempit, biarkan
     menyusut mengikuti lebar yang ada daripada memaksa gulir mendatar. */
  @media (max-width: 850px) {
    body { padding: 10px; }
    .lembar { width: 100%; }
  }
  /* Di bawah 620px, dua kolom riwayat kerja tidak lagi muat: kolom kiri
     mempertahankan 190px-nya dan menggencet kolom kanan sampai ~90px, sehingga
     "Reason for Leaving" terpotong jadi "Reas". Jadi ditumpuk: periode dan
     perusahaan di atas, rincian jabatan di bawah, garis waktu jadi garis tepi
     satu blok. Tampilan cetak A4 TIDAK terpengaruh - kertasnya selalu lebih
     lebar dari ambang ini. */
  @media (max-width: 620px) {
    .kerja { display: block; border-left: 2px solid #F7941D; padding-left: 12px; position: relative; }
    /* bulatan penanda ikut pindah ke garis tepi blok, sumbunya tetap sama */
    .kerja::before { content: ''; position: absolute; left: -6px; top: 3px;
                     width: 10px; height: 10px; border-radius: 50%;
                     background: #F7941D; box-shadow: 0 0 0 2px #fff; }
    .kerja .kiri { width: auto; text-align: left; padding: 0 0 5px; }
    .kerja .bidang:empty { display: none; }
    .kerja .garis { display: none; }
    .kerja .kanan { padding-left: 0; }
    .kerja .rinci td.l { width: auto; padding-right: 8px; }
  }
  /* Kotak keputusan manual - muncul hanya saat sistem tidak memutus. */
  .putusan { margin-top: 14px; padding: 12px 14px; border: 1px solid #FFD9A0;
             border-radius: 8px; background: #FFF9EF; font-size: 12px; line-height: 1.6; }
  .putusan p { margin: 5px 0 0; }
  .putusan .ingat { color: #a53a1c; }
  .putusan form { margin-top: 10px; display: flex; gap: 8px; }
  .putusan button { padding: 7px 15px; border: none; border-radius: 7px; cursor: pointer;
                    font-family: inherit; font-weight: 600; font-size: 12px; color: #fff; }
  .putusan .b-lolos { background: #20A277; }
  .putusan .b-gagal { background: #C0392B; }

  @media print {
    body { background: #fff; padding: 0; }
    .lembar { width: auto; min-height: 0; margin: 0; box-shadow: none; page-break-after: always; }
    .lembar:last-of-type { page-break-after: auto; }
    /* Lembar ini dicetak dan diarsipkan. Tombol yang ikut tercetak jadi kotak
       abu-abu tanpa arti di dokumen yang dibaca orang setahun lagi. */
    .putusan { display: none; }
  }
</style>
</head>
<body>

<!-- ================= HALAMAN 1: data pribadi & riwayat kerja ================= -->
<div class="lembar">
  <div class="kop">
    <div class="merek">Power By Biproo.com</div>
    <h1><?= esc($pribadi['nama'] ?? $app['nama']) ?></h1>
    <div class="sub"><?= esc($app['email']) ?> &middot; melamar <?= esc($app['judul']) ?></div>
    <div class="tgl"><?= date('d F Y') ?></div>
  </div>

  <div class="bar">PERSONAL DATA</div>
  <div class="isi">
    <table class="data">
      <?php
        // Usia dihitung dari tanggal lahir, dan tanggal lahir yang menang bila
        // keduanya ada: angka usia yang tertulis di CV adalah usia kandidat pada
        // hari CV itu dibuat, bukan hari ini. CV yang dipakai melamar sering
        // berumur satu-dua tahun, dan lembar ini dibaca sebagai keadaan sekarang.
        $usia = usia_dari_dob($pribadi['tanggal_lahir'] ?? null) ?? ($pribadi['usia'] ?? '');

        $baris = [
            'Full Name'         => $pribadi['nama'] ?? $app['nama'],
            'Current Address'   => $pribadi['alamat'] ?? '',
            'Place, DOB'        => trim(($pribadi['tempat_lahir'] ?? '') . ', ' . ($pribadi['tanggal_lahir'] ?? ''), ', '),
            'Age'               => $usia,
            'Gender'            => $pribadi['jenis_kelamin'] ?? '',
            'Marital Status'    => $pribadi['status_kawin'] ?? '',
            'Number of Children' => $pribadi['jumlah_anak'] ?? '',
            'Languages'         => $pribadi['bahasa'] ?? '',
            'Religion'          => $pribadi['agama'] ?? '',
            'Phone'             => $pribadi['telepon'] ?? '',
            'Email'             => $pribadi['email'] ?? $app['email'],
        ];
      ?>
      <?php foreach ($baris as $label => $isi): ?>
        <tr>
          <th><?= esc($label) ?></th>
          <td><?= $isi !== '' ? esc($isi) : $kosong ?></td>
        </tr>
      <?php endforeach ?>
    </table>
    <p style="font-size:11px;color:#999;margin:8px 0 0">
      Dibaca otomatis dari CV yang diunggah kandidat. Kolom kosong berarti CV-nya memang tidak
      mencantumkannya, bukan gagal dibaca. Tidak satu pun isian di sini mempengaruhi skor.
    </p>
  </div>

  <?php // Berbeda dari bagian lain: judulnya TETAP muncul walau kosong.
        // "Tidak ada riwayat kerja terbaca" adalah temuan, bukan tahap yang
        // belum dilewati - dan itu justru yang perlu dilihat pembaca. ?>
  <div class="bar">WORK EXPERIENCES</div>
  <div class="isi">
    <?php if (($bukti['riwayat'] ?? []) === []): ?>
      <div class="belum">
        <b>Tidak ada riwayat kerja terbaca</b>
        Tidak satu pun posisi di CV ini disertai nama tempat kerja atau rentang waktu.
      </div>
    <?php else: ?>
      <?php
        // Tata letak dua kolom mengikuti dokumen BIPROO: periode dan perusahaan
        // rata kanan di kiri, penanda garis waktu di tengah, rincian jabatan di
        // kanan. Bidang yang CV-nya tidak menuliskan tetap muncul dengan ":  -",
        // sama seperti dokumen aslinya, supaya barisnya tidak hilang berpindah.
        $rincian = [
            'Reason for Leaving' => 'alasan_keluar',
            'Last Salary'        => 'gaji_terakhir',
        ];
      ?>
      <?php foreach ($bukti['riwayat'] as $r): ?>
        <div class="kerja">
          <div class="kiri">
            <div class="periode"><?= esc($r['periode'] ?? '') ?: '-' ?></div>
            <div class="firma"><?= esc($r['perusahaan'] ?? '') ?: '-' ?></div>
            <div class="bidang"><?= esc($r['bidang_usaha'] ?? '') ?: '&nbsp;' ?></div>
          </div>

          <div class="garis"></div>

          <div class="kanan">
            <div class="jabatan"><?= esc($r['jabatan'] ?? '') ?: '-' ?></div>
            <table class="rinci">
              <?php foreach ($rincian as $label => $kunci): ?>
                <tr>
                  <td class="l"><?= esc($label) ?></td>
                  <td class="p">:</td>
                  <td><?= esc($r[$kunci] ?? '') ?: '-' ?></td>
                </tr>
              <?php endforeach ?>
            </table>
            <div class="deskripsi-l">Description</div>
            <div class="deskripsi"><?= esc($r['deskripsi'] ?? '') ?: '-' ?></div>
          </div>
        </div>
      <?php endforeach ?>
    <?php endif ?>
  </div>
</div>

<?php
// HALAMAN 2: hasil assessment.
//
// Berisi fase assessment yang MEMANG ADA di sistem ini: screening CV oleh AI
// dan hasil online assessment. T.I.U 5 dan DISC tidak dicantumkan sama sekali
// karena tesnya belum dibangun, jadi tidak ada yang bisa dilaporkan.
//
// Ditulis sebagai komentar PHP, bukan komentar HTML: komentar HTML ikut
// terkirim ke browser, dan penjelasan untuk pengembang tidak punya urusan
// di dokumen yang dibaca orang lain.
?>
<?php if ($skorCv !== null || $assessment !== null): ?>
  <div class="lembar">
    <div class="bar" style="margin-top:0">ASSESSMENT RESULT</div>
    <div class="isi">
      <table class="nilai">
        <?php if ($skorCv !== null): ?>
          <tr>
            <th>Screening CV (AI)</th>
            <td class="v"><?= esc(kemiripan_teks($skorCv)) ?></td>
          </tr>
        <?php endif ?>
        <?php if ($assessment !== null): ?>
          <tr>
            <th>Online Assessment</th>
            <td class="v"><b><?= $assessment === 'passed' ? 'Lulus' : 'Tidak Lulus' ?></b></td>
          </tr>
        <?php endif ?>
      </table>
      <?php if ($skorCv !== null): ?>
        <p style="font-size:11px;color:#999;margin:8px 0 0">
          Skor screening mengukur tumpang tindih makna antara CV dan uraian lowongan,
          bukan kompetensi kandidat. Ia tidak menentukan kelulusan tahap mana pun sendirian.
        </p>
      <?php endif ?>
    </div>
  </div>
<?php endif ?>

<!-- ================= HALAMAN 3: hasil interview ================= -->
<?php if ($hrd !== [] || $user !== []): ?>
  <div class="lembar">
    <div class="bar" style="margin-top:0">INTERVIEW RESULT</div>
    <div class="isi">
    <?php if ($hrd !== []): ?>
    <h3 style="font-size:14px;margin:0 0 12px">Interview HRD</h3>
      <div style="display:flex;gap:18px;align-items:flex-start">
        <svg width="220" height="220" viewBox="0 0 220 220" style="flex-shrink:0">
          <?php for ($ring = 1; $ring <= L::MAKS_SKALA; $ring++): ?>
            <polygon points="<?= $titik(array_fill_keys(L::HRD, $ring), 92) ?>"
                     fill="none" stroke="#eee2cd" stroke-width="1"/>
          <?php endfor ?>
          <polygon points="<?= $titik($hrd, 92) ?>" fill="#F7941D" fill-opacity=".45" stroke="#F7941D" stroke-width="2"/>
        </svg>

        <table class="nilai">
          <?php foreach (L::HRD as $kompetensi): ?>
            <tr>
              <th><?= esc($kompetensi) ?></th>
              <td class="v">
                <?= isset($hrd[$kompetensi]) ? esc(L::SKALA[$hrd[$kompetensi]] ?? '?') : $kosong ?>
              </td>
              <?php if ($adaSumber): ?>
                <td class="s">
                  <?php // Tanda per baris, bukan cuma keterangan di bawah tabel:
                        // yang perlu diketahui pembaca adalah nilai INI datang
                        // dari mana, dan itu tidak terbaca kalau keterangannya
                        // menggantung terpisah dari angkanya. ?>
                  <?php if (isset($hrd[$kompetensi])): ?>
                    <span class="tag <?= ($asal[$kompetensi] ?? '') === L::DARI_AI ? 't-ai' : 't-org' ?>">
                      <?= ($asal[$kompetensi] ?? '') === L::DARI_AI ? 'AI' : 'recruiter' ?></span>
                  <?php endif ?>
                </td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
        </table>
      </div>

      <?php if ($adaSumber): ?>
        <p class="ket-sumber">
          <span class="tag t-ai">AI</span> dinilai otomatis dari transkrip rekaman wawancara, dengan
          alasan yang mengutip ucapan kandidat.
          <span class="tag t-org">recruiter</span> dinilai langsung oleh pewawancara; ketiganya tidak
          bisa dibaca dari transkrip.
          Butir bertanda &ldquo;-&rdquo; tidak cukup bahannya di wawancara ini dan tidak ikut dihitung.
        </p>
      <?php endif ?>

      <?php
        // Alasan penilaian AI ikut dicetak. Inilah yang membedakan skor yang
        // bisa dipertanggungjawabkan dari angka yang muncul entah dari mana -
        // dan lembar ini yang dibawa ke rapat, bukan layar recruiter.
        $beralasan = array_filter(
            $alasan,
            static fn (string $t, string $k): bool => $t !== '' && ($asal[$k] ?? '') === L::DARI_AI,
            ARRAY_FILTER_USE_BOTH
        );
      ?>
      <?php if ($beralasan !== []): ?>
        <div style="margin-top:14px">
          <div class="sub-bar">Dasar penilaian dari transkrip</div>
          <table class="nilai alasan">
            <?php foreach ($beralasan as $kompetensi => $teks): ?>
              <tr>
                <th><?= esc($kompetensi) ?></th>
                <td><?= esc($teks) ?></td>
              </tr>
            <?php endforeach ?>
          </table>
        </div>
      <?php endif ?>

      <div style="margin-top:14px">
        <?php foreach (L::NARASI as $kunci => $label): ?>
          <div class="narasi">
            <div class="l">
              <?= esc($label) ?>
              <?php // Ditandai seperti kompetensi: pembaca lembar ini berhak
                    // tahu kalimat mana yang dirangkum mesin dari transkrip dan
                    // mana yang ditulis pewawancara dari pengamatannya sendiri. ?>
              <?php if (($narasi[$kunci] ?? '') !== '' && isset($asalNarasi[$kunci])): ?>
                <span class="tag <?= $asalNarasi[$kunci] === L::DARI_AI ? 't-ai' : 't-org' ?>">
                  <?= $asalNarasi[$kunci] === L::DARI_AI ? 'AI' : 'recruiter' ?></span>
              <?php endif ?>
            </div>
            <div class="t"><?= ($narasi[$kunci] ?? '') !== '' ? esc($narasi[$kunci]) : $kosong ?></div>
          </div>
        <?php endforeach ?>
      </div>

      <table class="nilai" style="margin-top:6px">
        <tr>
          <th>Interview Result</th>
          <td class="v"><b><?= $hasilAkhir !== '' ? esc($hasilAkhir) : $kosong ?></b></td>
        </tr>
      </table>

      <?php // JALAN KELUAR MANUSIA saat sistem tidak memutus.
            //
            // Ada juga di tabel Interview HRD, tapi di sana recruiter belum
            // membaca apa-apa. DI SINI-lah transkrip, alasan tiap nilai, dan
            // kekuatan/kelemahan kandidat terpampang - tempat keputusan itu
            // sebenarnya terbentuk. Menyuruhnya kembali ke tabel untuk menekan
            // tombol berarti ia memutuskan dari ingatan atas apa yang baru saja
            // dibacanya di tab lain.
            //
            // Syaratnya sama persis dengan penjaga di Recruiter::putusGate2 -
            // null (rekaman belum diunggah) atau 'flagged' (transkripsi gagal,
            // AI tidak memberi rekomendasi, atau skor CV tidak ada). Yang sudah
            // diputus tidak menampilkan apa pun, dan itu disengaja: keputusan
            // yang sudah dikirim lewat email tidak punya jalur pembatalan. ?>
      <?php if ($gate2 === null || $gate2 === 'flagged'): ?>
        <div class="putusan">
          <b>Sistem tidak memutuskan kandidat ini.</b>
          <p><?= $sebabGate2 !== ''
              ? esc($sebabGate2)
              : 'Rekaman wawancaranya belum pernah diunggah, jadi belum ada yang bisa dinilai otomatis.' ?></p>
          <p class="ingat">Keputusan di bawah ini langsung dikirim ke kandidat lewat email dan tidak bisa dibatalkan.</p>
          <form method="post" action="<?= site_url('recruiter/gate2/' . $app['id']) ?>">
            <?= csrf_field() ?>
            <button name="keputusan" value="lolos" class="b-lolos"
                    onclick="return confirm('Loloskan kandidat ini? Kandidat akan dikabari via email.')">Loloskan</button>
            <button name="keputusan" value="gagal" class="b-gagal"
                    onclick="return confirm('Tidak meloloskan kandidat ini? Kandidat akan dikabari via email.')">Tidak Lolos</button>
          </form>
        </div>
      <?php endif ?>
    <?php endif ?>

    <?php if ($user !== []): ?>
      <h3 style="font-size:14px;margin:22px 0 12px">Interview User</h3>
      <table class="nilai">
        <?php foreach (L::USER as $butir): ?>
          <tr>
            <th><?= esc($butir) ?></th>
            <td class="v"><?= isset($user[$butir]) ? (int) $user[$butir] . ' / ' . L::MAKS_USER : $kosong ?></td>
          </tr>
        <?php endforeach ?>
      </table>
    <?php endif ?>
    </div>
  </div>
<?php endif ?>

</body>
</html>
