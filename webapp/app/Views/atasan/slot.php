<?php
/**
 * Atasan mengatur jam wawancaranya sendiri (31 Agustus 2026).
 *
 * Sebelum ini jam Interview User dibuka recruiter, memakai daftar yang sama
 * dengan wawancara HRD. Dua hal yang salah di situ: recruiter menebak kapan
 * atasan tiap unit senggang, dan jam yang dibuka berlaku untuk semua posisi
 * sekaligus - padahal tiap posisi punya atasannya sendiri.
 *
 * Yang tampil di sini HANYA jam posisi milik akun ini, disaring job_id dari
 * SESI di PengaturanSlot, bukan dari parameter URL.
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title><?= esc($judul) ?> - E-REQ BIPROO</title>
<style>
  body { background: #F4F6FA; margin: 0; }
  .atas { background: #F7941D; color: #fff; padding: 14px 24px; display: flex;
          align-items: center; justify-content: space-between; gap: 14px; }
  .atas .kiri { font-weight: 700; font-size: 16px; }
  .atas .kiri small { display: block; font-weight: 400; font-size: 12px; opacity: .9; }
  .atas a { color: #fff; font-size: 13px; text-decoration: underline; }
  .atas .kanan { display: flex; gap: 16px; align-items: center; }
  .isi { max-width: 1000px; margin: 22px auto; padding: 0 20px; }
  .kartu { background: #fff; border-radius: 12px; padding: 20px 22px; box-shadow: 0 3px 12px rgba(0,0,0,.04); }
  .pesan { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
  .pesan-sukses { background: #E8F7EE; color: #1d6b3d; }
  .pesan-error { background: #FFE9E3; color: #a53a1c; }
</style>
</head>
<body>

<div class="atas">
  <div class="kiri">
    Jadwal Interview User
    <small><?= esc(session('atasan_posisi')) ?> &middot; <?= esc(session('atasan_nama')) ?></small>
  </div>
  <div class="kanan">
    <a href="<?= site_url('atasan') ?>">Daftar kandidat</a>
    <a href="<?= site_url('atasan/logout') ?>">Keluar</a>
  </div>
</div>

<div class="isi">
  <?php if (session('sukses')): ?>
    <div class="pesan pesan-sukses">✅ <?= esc(session('sukses')) ?></div>
  <?php endif ?>
  <?php if (session('error')): ?>
    <div class="pesan pesan-error">⚠️ <?= esc(session('error')) ?></div>
  <?php endif ?>

  <div class="kartu">
    <h3 style="margin:0 0 14px;font-size:15px">Jam wawancara untuk <?= esc(session('atasan_posisi')) ?></h3>

    <p class="slot-ket">
      Kandidat posisi ini memilih jam wawancaranya dari daftar Anda. Selama daftarnya kosong,
      <b>tidak ada kandidat yang bisa menjadwalkan wawancara dengan Anda</b>.<br>
      <b>Kuota</b> menentukan berapa orang yang boleh mengambil satu jam. Jam ini milik posisi
      Anda sendiri - jadwal posisi lain dan jadwal wawancara HRD tidak terpengaruh.
    </p>

    <?= $this->include('partials/tabel_slot') ?>
  </div>
</div>

</body>
</html>
