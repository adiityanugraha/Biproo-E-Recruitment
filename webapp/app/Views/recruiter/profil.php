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
$hrd = $user = $narasi = [];
$hasilAkhir = '';
foreach ($penilaian as $p) {
    $kat = $p['kategori'] ?? '';
    if ($kat === L::KAT_HRD) {
        $hrd[$p['kompetensi']] = (int) $p['tingkat'];
    } elseif ($kat === L::KAT_USER) {
        $user[$p['kompetensi']] = (int) $p['tingkat'];
    } elseif ($kat === L::KAT_NARASI) {
        $narasi[$p['kompetensi']] = (string) $p['catatan'];
    } elseif ($kat === L::KAT_HASIL) {
        $hasilAkhir = (string) $p['tingkat'];
    }
}

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
  .kerja { border: 1px solid #f2e2c9; border-radius: 6px; padding: 12px 14px; margin-bottom: 10px; font-size: 12px; }
  .kerja .judul { font-weight: 700; }
  .kerja .meta { color: #777; margin: 2px 0 6px; }
  .nilai th, .nilai td { border: 1px solid #f2e2c9; padding: 6px 10px; }
  .nilai th { background: #FDF3E4; color: #7a5a13; text-align: left; font-weight: 600; }
  .nilai td.v { text-align: right; width: 150px; }
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
  @media print {
    body { background: #fff; padding: 0; }
    .lembar { width: auto; min-height: 0; margin: 0; box-shadow: none; page-break-after: always; }
    .lembar:last-of-type { page-break-after: auto; }
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
        $baris = [
            'Full Name'         => $pribadi['nama'] ?? $app['nama'],
            'Current Address'   => $pribadi['alamat'] ?? '',
            'Place, DOB'        => trim(($pribadi['tempat_lahir'] ?? '') . ', ' . ($pribadi['tanggal_lahir'] ?? ''), ', '),
            'Age'               => $pribadi['usia'] ?? '',
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
      <?php foreach ($bukti['riwayat'] as $r): ?>
        <div class="kerja">
          <div class="judul"><?= esc($r['jabatan'] ?? '') ?: $kosong ?></div>
          <div class="meta">
            <?= esc($r['perusahaan'] ?? '') ?: $kosong ?> &middot; <?= esc($r['periode'] ?? '') ?: $kosong ?>
          </div>
          <?php if (! empty($r['deskripsi'])): ?>
            <div><?= esc($r['deskripsi']) ?></div>
          <?php endif ?>
          <?php if (! empty($r['alasan_keluar']) || ! empty($r['gaji_terakhir'])): ?>
            <div style="color:#777;margin-top:5px">
              <?php if (! empty($r['alasan_keluar'])): ?>
                Reason for Leaving: <?= esc($r['alasan_keluar']) ?><br>
              <?php endif ?>
              <?php if (! empty($r['gaji_terakhir'])): ?>
                Last Salary: <?= esc($r['gaji_terakhir']) ?>
              <?php endif ?>
            </div>
          <?php endif ?>
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
            </tr>
          <?php endforeach ?>
        </table>
      </div>

      <div style="margin-top:14px">
        <?php foreach (L::NARASI as $kunci => $label): ?>
          <div class="narasi">
            <div class="l"><?= esc($label) ?></div>
            <div class="t"><?= ($narasi[$kunci] ?? '') !== '' ? esc($narasi[$kunci]) : $kosong ?></div>
          </div>
        <?php endforeach ?>
      </div>

      <table class="nilai" style="margin-top:6px">
        <tr>
          <th>Interview Result</th>
          <td class="v"><b><?= $hasilAkhir !== '' ? esc(L::HASIL[$hasilAkhir] ?? $hasilAkhir) : $kosong ?></b></td>
        </tr>
      </table>
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
