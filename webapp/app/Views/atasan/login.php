<?php
/**
 * Masuk sebagai pewawancara Interview User (19 Agustus 2026).
 *
 * Memakai layout_auth yang sama dengan halaman masuk kandidat dan recruiter.
 * Versi pertama menulis tata letaknya sendiri, dan itu keliru dua kali: BIPROO
 * jadi punya dua wajah halaman masuk yang berbeda, dan perbaikan apa pun pada
 * yang bersama - warna, jarak, tampilan galat - tidak ikut ke sini.
 *
 * Yang membedakan cuma judul dan keterangannya. Akun ini tidak bisa mendaftar
 * sendiri: satu-satunya sumbernya email dari HRD, dan itu disebut terang-
 * terangan supaya atasan tidak mencari tautan "daftar" yang memang tidak ada.
 */
?>
<?= $this->extend('layout_auth') ?>
<?= $this->section('isi') ?>

<h2>Sign In - Interview User</h2>
<form method="post" action="<?= site_url('atasan/login') ?>">
  <?= csrf_field() ?>
  <label for="email">Email</label>
  <input id="email" name="email" type="email" placeholder="Email yang dikirim tim HRD" required autofocus>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" placeholder="Kata sandi dari email" required>
  <button type="submit">Login</button>
</form>
<p class="tautan">Belum menerima akun? Hubungi tim HRD - akun dikirim lewat email.</p>

<?= $this->endSection() ?>
