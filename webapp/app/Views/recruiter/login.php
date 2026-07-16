<?= $this->extend('layout_auth') ?>
<?= $this->section('isi') ?>

<h2>Sign In - Recruiter</h2>
<form method="post" action="<?= site_url('recruiter/login') ?>">
  <?= csrf_field() ?>
  <label for="email">Email</label>
  <input id="email" name="email" type="email" placeholder="Email recruiter" required>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" placeholder="Masukkan password" required>
  <button type="submit">Login</button>
</form>
<p class="tautan">Kandidat? <a href="<?= site_url('login') ?>">Login di sini</a></p>

<?= $this->endSection() ?>
