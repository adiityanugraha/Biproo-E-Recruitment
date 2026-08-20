<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title><?= esc($judul ?? 'Masuk') ?> - E-REQ BIPROO</title>
<style>
  body { background: #F2F4F8; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .auth-wrap { display: flex; width: 100%; max-width: 900px; min-height: 480px; background: #fff;
               border-radius: 20px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,.12); }

  /* panel kiri oranye + lingkaran dekoratif (BIPROO) */
  .auth-left { position: relative; flex: 1; overflow: hidden; color: #fff;
               background: linear-gradient(150deg, #F5851B 0%, #F7941D 45%, #FBC24A 100%);
               display: flex; flex-direction: column; justify-content: center; padding: 40px; }
  .auth-left .bulat { position: absolute; border-radius: 50%; background: rgba(255,255,255,.18); }
  .auth-left .b1 { width: 150px; height: 150px; top: -40px; right: -30px; }
  .auth-left .b2 { width: 90px; height: 90px; top: 90px; left: -30px; }
  .auth-left .b3 { width: 180px; height: 180px; bottom: -70px; right: 40px; }
  .auth-left h1 { position: relative; font-size: 30px; margin: 0; }
  .auth-left h1 b { background: #fff; color: #F7941D; padding: 2px 12px; border-radius: 10px; }
  .auth-left p { position: relative; font-size: 14px; margin-top: 10px; opacity: .95; }

  /* panel kanan form */
  .auth-right { flex: 1.05; padding: 44px 40px; display: flex; flex-direction: column; justify-content: center; }
  .auth-right h2 { color: #F7941D; margin: 0 0 20px; font-size: 24px; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; }
  input { width: 100%; padding: 11px 13px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 14px; font-family: inherit; }
  button { width: 100%; margin-top: 20px; padding: 12px; border: none; border-radius: 10px; cursor: pointer;
           background: #1a1a1a; color: #fff; font-size: 15px; font-weight: 600; font-family: inherit; }
  .syarat-sandi { font-size: 12px; color: #777; margin: 6px 0 0; }
  .tautan { font-size: 13px; text-align: center; margin-top: 16px; color: #555; }
  .tautan a { color: #F7941D; font-weight: 600; }
  .pesan-error { background: #FDECEC; border: 1px solid #E23B4E; color: #a12734; padding: 10px 12px; border-radius: 10px; margin-bottom: 8px; font-size: 13px; }

  @media (max-width: 720px) { .auth-left { display: none; } .auth-wrap { max-width: 420px; } }
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-left">
    <span class="bulat b1"></span><span class="bulat b2"></span><span class="bulat b3"></span>
    <h1>Welcome to <b>Biproo</b></h1>
    <p>Sistem rekrutmen berbasis digital. Masuk untuk melanjutkan proses seleksi Anda.</p>
  </div>
  <div class="auth-right">
    <?php if (! empty($errors)): ?>
      <?php foreach ($errors as $e): ?><div class="pesan-error"><?= esc($e) ?></div><?php endforeach ?>
    <?php endif ?>
    <?php if (session('error')): ?><div class="pesan-error"><?= esc(session('error')) ?></div><?php endif ?>
    <?= $this->renderSection('isi') ?>
  </div>
</div>
</body>
</html>
