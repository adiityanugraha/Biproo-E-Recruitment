<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($judul ?? 'E-REQ') ?> - E-REQ BIPROO</title>
<style>
  /* tema BIPROO (Blueprint A2.6) - vanilla CSS, tanpa build step */
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
         background: #F2F4F8; color: #2B2B2B; }
  header { display: flex; align-items: center; gap: 10px; padding: 0 22px; height: 64px;
           background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; }
  header .logo { display: flex; }
  header .logo span { width: 30px; height: 30px; border-radius: 50%; color: #fff;
                      display: flex; align-items: center; justify-content: center; font-weight: 700; }
  header .logo .b { background: #2F6FED; }
  header .logo .d { background: #E23B4E; margin-left: -8px; }
  header h1 { font-size: 18px; margin: 0; flex: 1; }
  header a { color: #fff; font-size: 14px; text-decoration: none; font-weight: 600; }
  main { max-width: 680px; margin: 32px auto; padding: 0 16px; }
  .kartu { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 4px 14px rgba(0,0,0,.05); margin-bottom: 18px; }
  h2 { margin-top: 0; }
  label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
  input, select { width: 100%; padding: 10px 12px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 14px; font-family: inherit; }
  button { margin-top: 18px; width: 100%; padding: 12px; border: none; border-radius: 10px; cursor: pointer;
           background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; font-size: 15px; font-weight: 700; }
  .pesan-sukses { background: #E8F7EE; border: 1px solid #2E9E5B; color: #1d6b3d; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .pesan-error { background: #FDECEC; border: 1px solid #E23B4E; color: #a12734; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .tautan { font-size: 14px; text-align: center; margin-top: 14px; }
  .tautan a { color: #2F6FED; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef0f5; }
</style>
</head>
<body>
<header>
  <div class="logo"><span class="b">B</span><span class="d">D</span></div>
  <h1>E-REQ BIPROO</h1>
  <?php if (session('candidate_id')): ?>
    <span style="font-size:14px;margin-right:14px"><?= esc(session('candidate_nama')) ?></span>
    <a href="<?= site_url('logout') ?>">Keluar</a>
  <?php endif ?>
</header>
<main>
  <?php if (session('sukses')): ?><div class="pesan-sukses"><?= esc(session('sukses')) ?></div><?php endif ?>
  <?php if (session('error')): ?><div class="pesan-error"><?= esc(session('error')) ?></div><?php endif ?>
  <?php if (! empty($errors)): ?>
    <div class="pesan-error"><?php foreach ($errors as $e): ?><div><?= esc($e) ?></div><?php endforeach ?></div>
  <?php endif ?>
  <?= $this->renderSection('isi') ?>
</main>
</body>
</html>
