<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Login Recruiter</h2>
  <form method="post" action="<?= site_url('recruiter/login') ?>">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <button type="submit">Login</button>
  </form>
  <p class="tautan">Kandidat? <a href="<?= site_url('login') ?>">Login kandidat di sini</a></p>
</div>

<?= $this->endSection() ?>
