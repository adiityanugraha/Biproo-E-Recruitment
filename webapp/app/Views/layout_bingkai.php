<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title><?= esc($judul ?? 'BIPROO') ?> - E-REQ BIPROO</title>
<style>
  /*
   * Layout untuk halaman yang dibuka DI DALAM jendela pratinjau (lihat
   * partials/jendela_modal.php). Tanpa topbar dan tanpa sidebar: keduanya sudah
   * ada di halaman induk, dan menampilkannya lagi di dalam bingkai kecil cuma
   * memakan ruang yang justru dibutuhkan isinya.
   *
   * Pesan flash TETAP dirender. Halaman di dalam bingkai ini bisa menyimpan data
   * (form pertanyaan), dan tanpa pesan, menekan Simpan terlihat seperti tidak
   * terjadi apa-apa - persis kekeliruan yang dulu terjadi di layout_recruiter.
   */
  body { background: #fff; padding: 18px; }

  .pesan { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; line-height: 1.5; }
</style>
<?= $this->include('partials/gaya_isi') ?>
<?= $this->renderSection('gaya') ?>
</head>
<body>
<?php if (session('sukses')): ?>
  <div class="pesan pesan-sukses">✅ <?= esc(session('sukses')) ?></div>
<?php endif ?>
<?php if (session('error')): ?>
  <div class="pesan pesan-error">⚠️ <?= esc(session('error')) ?></div>
<?php endif ?>

<?= $this->renderSection('isi') ?>

<?= $this->include('partials/segera_modal') ?>
</body>
</html>
