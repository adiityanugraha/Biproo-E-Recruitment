<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Daftar Akun Kandidat</h2>
  <form method="post" action="<?= site_url('daftar') ?>">
    <?= csrf_field() ?>
    <label for="nama">Nama lengkap</label>
    <input id="nama" name="nama" required minlength="3" value="<?= old('nama') ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required value="<?= old('email') ?>">
    <label for="password">Password (minimal 8 karakter)</label>
    <input id="password" name="password" type="password" required minlength="8">
    <button type="submit">Daftar</button>
  </form>
  <p class="tautan">Sudah punya akun? <a href="<?= site_url('login') ?>">Login</a></p>
</div>

<?= $this->endSection() ?>
