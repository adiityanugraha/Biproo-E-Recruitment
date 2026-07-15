<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Login Kandidat</h2>
  <form method="post" action="<?= site_url('login') ?>">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required value="<?= old('email') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <button type="submit">Login</button>
  </form>
  <p class="tautan">Belum punya akun? <a href="<?= site_url('daftar') ?>">Daftar</a></p>
</div>

<?= $this->endSection() ?>
