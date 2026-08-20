<?= $this->extend('layout_auth') ?>
<?= $this->section('isi') ?>

<h2>Daftar Akun</h2>
<form method="post" action="<?= site_url('daftar') ?>">
  <?= csrf_field() ?>
  <label for="nama">Nama lengkap</label>
  <input id="nama" name="nama" placeholder="Nama lengkap Anda" required minlength="3" value="<?= old('nama') ?>">
  <label for="email">Email</label>
  <input id="email" name="email" type="email" placeholder="Email aktif" required value="<?= old('email') ?>">
  <label for="password">Password</label>
  <input id="password" name="password" type="password" placeholder="Buat password" required minlength="8"
         pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}"
         title="Minimal 8 karakter, memuat huruf besar, huruf kecil, dan angka.">
  <p class="syarat-sandi">Minimal 8 karakter, memuat huruf besar, huruf kecil, dan angka.</p>
  <button type="submit">Daftar</button>
</form>
<p class="tautan">Sudah punya akun? <a href="<?= site_url('login') ?>">Login</a></p>

<?= $this->endSection() ?>
