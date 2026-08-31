<?php
/**
 * Pengaturan jam Interview HRD (28 Agustus 2026, dipisah dari Interview User
 * pada 31 Agustus 2026).
 *
 * Halaman ini HANYA mengurus jam wawancara HRD. Jam Interview User diatur
 * atasan tiap posisi lewat akunnya sendiri - yang paling tahu kapan ia senggang
 * adalah dirinya, bukan recruiter yang menebak.
 */
?>
<?= $this->extend('layout_recruiter') ?>

<?= $this->section('sidebar') ?>
<?= $this->include('recruiter/sisi_tahap') ?>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu">
  <h2><?= esc($judul) ?></h2>

  <p class="slot-ket">
    Kandidat memilih jadwal wawancara HRD dari daftar ini. <b>Kuota</b> menentukan berapa orang
    yang boleh mengambil satu jam - jam 10.00 berkuota 2 berarti dua kandidat bisa
    mendaftar di jam itu sebelum penuh. Tiap kandidat tetap mendapat ruang Zoom sendiri.<br>
    Jam <b>Interview User</b> tidak diatur di sini: tiap atasan posisi mengatur jamnya sendiri.
  </p>

  <?= $this->include('partials/tabel_slot') ?>
</div>

<?= $this->endSection() ?>
