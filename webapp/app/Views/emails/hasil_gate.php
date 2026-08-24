<p>Halo <?= esc($nama ?? 'Kandidat') ?>,</p>

<?php if (($status ?? '') === 'passed'): ?>
<p>Selamat! Anda <b>lolos</b> tahap seleksi untuk posisi
<b><?= esc($posisi ?? '-') ?></b>. Tim kami akan menghubungi Anda untuk
tahap berikutnya - pantau terus portal kandidat dan email Anda.</p>
<?php else: ?>
<p>Terima kasih atas partisipasi Anda dalam proses seleksi posisi
<b><?= esc($posisi ?? '-') ?></b>. Setelah evaluasi menyeluruh, kami belum
dapat melanjutkan lamaran Anda ke tahap berikutnya.</p>

<p>Jangan berkecil hati - profil Anda tetap tersimpan dan Anda dapat
melamar kembali untuk posisi lain yang sesuai.</p>

<?php
  /*
   * Saran posisi cuma terisi bila kandidat gugur karena SALAH POSISI, dan
   * hanya memuat posisi yang kecocokannya tidak lebih rendah daripada posisi
   * yang baru saja menolaknya. Kosong = memang tidak ada yang lebih cocok, dan
   * bagian ini tidak muncul sama sekali - lebih baik daripada tiga posisi asal
   * yang dikirim ke orang yang sedang kecewa.
   *
   * Kata-katanya sengaja "mungkin cocok", bukan "kami merekomendasikan":
   * diukur atas 195 kandidat berlabel, posisi yang benar-benar menerima orang
   * masuk tiga besar sekitar separuh kali (docs/kalibrasi-saran-posisi.md).
   * Separuh lagi meleset, dan janji yang lebih besar dari itu tidak jujur.
   */
  $saran = $saran ?? [];
?>
<?php if ($saran !== []): ?>
<p><b>Posisi lain yang mungkin cocok untuk Anda</b><br>
<span style="color:#666;font-size:13px">Dipilih dari pengalaman kerja pada CV Anda.</span></p>
<ol>
  <?php foreach ($saran as $s): ?>
  <li><?= esc((string) ($s['judul'] ?? '')) ?></li>
  <?php endforeach ?>
</ol>
<p style="font-size:13px;color:#666">Ini saran, bukan jaminan diterima. Silakan masuk ke
portal kandidat bila ingin melamar salah satunya.</p>
<?php endif ?>
<?php endif ?>

<p>Salam,<br>Tim Rekrutmen BIPROO</p>
