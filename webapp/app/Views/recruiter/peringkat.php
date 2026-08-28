<?php

use App\Controllers\Lamaran;

/**
 * Candidate Ranking (28 Agustus 2026, permintaan atasan).
 *
 * Peringkat kandidat menurut skor akhir rumus Gate 2, bisa disaring per posisi.
 *
 * DUA KETERANGAN DI ATAS TABEL BUKAN HIASAN. Halaman ini menyusun orang dari
 * atas ke bawah, dan susunan seperti itu terbaca sebagai urutan siapa yang
 * diterima. Padahal rumusnya tidak lagi memutuskan apa pun, dan skornya tidak
 * sebanding antar-posisi. Yang membacanya harus tahu keduanya sebelum memakai
 * daftar ini untuk apa pun.
 */
?>
<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  /* Tata letak tabel sama dengan tabel tahap: kepala biru, garis kisi penuh,
     isi rata tengah. */
  table { white-space: nowrap; border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #cfd8e3; padding: 7px 12px; font-size: 13px;
           text-align: center; vertical-align: middle; }
  th { background: #1E88E5; color: #fff; font-weight: 700; font-size: 12px;
       letter-spacing: .2px; border-color: #1877cc; }
  tbody tr:hover td { background: #f7fafd; }
  .scroll-x { overflow-x: auto; border: 1px solid #cfd8e3; border-radius: 8px; }

  .alat { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
  .alat label { font-size: 13px; color: #555; margin: 0; }
  .alat select { width: auto; padding: 7px 10px; border: 1px solid #cfd8e3; border-radius: 7px;
                 font-family: inherit; font-size: 13px; }

  /* Tiga besar ditandai, sisanya biasa saja. Penandanya tipis - ini peringkat
     bahan pertimbangan, bukan podium juara. */
  .no-1 td { background: #FFFBEB; }
  .rank { font-weight: 700; width: 70px; }
  .rank-kosong { color: #b0b8c4; font-weight: 400; }
  .skor-akhir { font-weight: 700; font-size: 14px; }
  .kosong-sel { color: #b0b8c4; }

  .catatan { border: 1px solid #FFD9A0; background: #FFF9EF; border-radius: 8px;
             padding: 12px 14px; margin-bottom: 16px; font-size: 12.5px;
             color: #6b5626; line-height: 1.7; }
  .catatan b { color: #5a4718; }
  .kaki-ket { font-size: 12.5px; color: #777; margin: 12px 0 0; }
</style>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu">
  <h2><?= esc($judul) ?></h2>

  <div class="catatan">
    <b>Ini bahan pertimbangan, bukan urutan siapa yang diterima.</b> Skor akhir
    di sini rumus Gate 2 - kemiripan CV 40% ditambah skor interview 60% - dan
    sejak 24 Agustus 2026 rumus itu tidak lagi memutuskan kelulusan. Yang
    memutuskan rekomendasi AI, dan angkanya bisa berbeda dari urutan di bawah.<br>
    <b>Bandingkan hanya dalam satu posisi.</b> Komponen CV diukur terhadap teks
    lowongan yang berbeda-beda, jadi 0,80 di satu posisi bukan hal yang sama
    dengan 0,80 di posisi lain. Pakai penyaring di bawah ini.
  </div>

  <form method="get" action="<?= site_url('recruiter/peringkat') ?>" class="alat">
    <label for="job">Posisi</label>
    <select id="job" name="job" onchange="this.form.submit()">
      <option value="0">Semua posisi</option>
      <?php foreach ($lowongan as $l): ?>
        <option value="<?= (int) $l['id'] ?>"<?= (int) $l['id'] === $jobId ? ' selected' : '' ?>>
          <?= esc($l['judul']) ?></option>
      <?php endforeach ?>
    </select>
    <noscript><button type="submit">Tampilkan</button></noscript>
  </form>

  <?php if ($daftar === []): ?>
    <p class="kaki-ket">Belum ada kandidat pada pilihan ini.</p>
  <?php else: ?>
    <div class="scroll-x">
      <table>
        <thead>
          <tr>
            <th style="width:70px">Peringkat</th>
            <th>Nama</th>
            <th>Posisi</th>
            <th style="width:150px">Kemiripan CV</th>
            <th style="width:130px">Skor Interview</th>
            <th style="width:120px">Skor Akhir</th>
            <th style="width:170px">Tahap Terkini</th>
            <th style="width:130px">Keputusan</th>
          </tr>
        </thead>
        <tbody>
          <?php $urut = 0 ?>
          <?php foreach ($daftar as $a): ?>
            <?php $adaSkor = $a['skor_akhir'] !== null ?>
            <?php if ($adaSkor) { $urut++; } ?>
            <tr class="<?= $adaSkor && $urut === 1 ? 'no-1' : '' ?>">
              <td class="rank <?= $adaSkor ? '' : 'rank-kosong' ?>">
                <?= $adaSkor ? $urut : '-' ?>
              </td>
              <td style="text-align:left"><?= esc($a['nama']) ?></td>
              <td style="text-align:left"><?= esc($a['posisi']) ?></td>
              <td>
                <?php // Pita, bukan angka telanjang: skor CV berdaya beda lemah
                      // dan menampilkannya sebagai desimal memberi kesan
                      // ketelitian yang tidak dimilikinya. ?>
                <?= $a['skor_cv'] === null
                    ? '<span class="kosong-sel">belum ada</span>'
                    : esc(kemiripan_teks($a['skor_cv'])) ?>
              </td>
              <td>
                <?= $a['skor_interview'] === null
                    ? '<span class="kosong-sel">belum ada</span>'
                    : (int) $a['skor_interview'] . '/100' ?>
              </td>
              <td class="skor-akhir">
                <?= $adaSkor
                    ? esc(skor_100($a['skor_akhir'], 1)) . '/100'
                    : '<span class="kosong-sel" style="font-weight:400">-</span>' ?>
              </td>
              <td><?= esc(Lamaran::STAGE_LABEL[$a['tahap']] ?? $a['tahap']) ?></td>
              <td><?= $a['gate2'] === null ? '<span class="kosong-sel">-</span>' : badge_status($a['gate2']) ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <?php
      $belum = count(array_filter($daftar, static fn (array $a): bool => $a['skor_akhir'] === null));
    ?>
    <?php if ($belum > 0): ?>
      <p class="kaki-ket">
        <?= $belum ?> kandidat belum punya skor akhir dan berkumpul di bagian bawah tanpa peringkat.
        Skor akhir baru bisa dihitung setelah CV-nya berhasil dibaca <b>dan</b> wawancaranya dinilai.
      </p>
    <?php endif ?>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
