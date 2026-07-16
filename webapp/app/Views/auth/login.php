<?= $this->extend('layout_auth') ?>
<?= $this->section('isi') ?>

<h2>Sign In</h2>
<form method="post" action="<?= site_url('login') ?>">
  <?= csrf_field() ?>
  <label for="email">Email</label>
  <input id="email" name="email" type="email" placeholder="Masukkan email Anda" required value="<?= old('email') ?>">
  <label for="password">Password</label>
  <input id="password" name="password" type="password" placeholder="Masukkan password" required>
  <button type="submit">Login</button>
</form>
<p class="tautan">Belum punya akun? <a href="<?= site_url('daftar') ?>">Daftar</a></p>
<p class="tautan">Recruiter? <a href="<?= site_url('recruiter/login') ?>">Login di sini</a></p>

<?= $this->endSection() ?>
