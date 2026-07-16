<?php
// menu sidebar per peran. item: [label, url|null, ikon, aktif, segera?]
if (session('recruiter_id')) {
    $brand = 'BIPROO';
    $menu  = [
        ['Dashboard', site_url('recruiter'), '🏠', url_is('recruiter') || url_is('recruiter/kandidat*') || url_is('recruiter/review*'), false],
        ['Lowongan (FPK)', site_url('recruiter/lowongan'), '📢', url_is('recruiter/lowongan'), false],
        ['Notification', null, '🔔', false, true],
        ['Message', null, '✉️', false, true],
    ];
    $bantuan = [['Privacy & Policy', '🛡️'], ['Contact', '📞']];
    $keluar  = site_url('recruiter/logout');
    $nama    = session('recruiter_nama');
    $peran   = 'Recruiter';
} else {
    $brand = 'My Dashboard';
    $menu  = [
        ['Dashboard', site_url('dashboard'), '🏠', url_is('dashboard'), false],
        ['Lowongan Kerja', site_url('lamar'), '💼', url_is('lamar'), false],
        ['Status Lamaran', site_url('status'), '📋', url_is('status') || url_is('assessment*'), false],
        ['Jadwal/Acara HRD', null, '📅', false, true],
        ['Notifikasi', null, '🔔', false, true],
        ['Pesan', null, '💬', false, true],
    ];
    $bantuan = [['Ketentuan', '📖'], ['Kontak', '📞']];
    $keluar  = site_url('logout');
    $nama    = session('candidate_nama');
    $peran   = 'Kandidat';
}
$inisial = strtoupper(mb_substr((string) $nama, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($judul ?? $brand) ?> - E-REQ BIPROO</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Poppins', system-ui, sans-serif; background: #F2F4F8; color: #2B2B2B; }
  a { text-decoration: none; }

  .topbar { position: sticky; top: 0; z-index: 40; display: flex; align-items: center; justify-content: space-between;
            height: 60px; padding: 0 22px; background: linear-gradient(90deg, #FBA919, #F7941D);
            box-shadow: 0 2px 10px rgba(247,148,29,.35); color: #fff; }
  .brand { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 18px; }
  .logo { display: flex; }
  .logo span { width: 30px; height: 30px; border-radius: 50%; color: #fff; display: flex; align-items: center;
               justify-content: center; font-weight: 700; font-size: 15px; box-shadow: 0 2px 6px rgba(0,0,0,.2); }
  .logo .b { background: #2F6FED; }
  .logo .d { background: #E23B4E; margin-left: -8px; }
  .user { display: flex; align-items: center; gap: 10px; font-size: 14px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #fff; color: #F7941D; display: flex;
            align-items: center; justify-content: center; font-weight: 700; border: 2px solid rgba(255,255,255,.6); }
  .user a { color: #fff; font-weight: 600; margin-left: 6px; }

  .shell { display: flex; gap: 18px; padding: 18px; align-items: flex-start; max-width: 1400px; margin: 0 auto; }
  .sidebar { width: 230px; flex-shrink: 0; align-self: stretch; position: sticky; top: 78px;
             background: linear-gradient(180deg, #F7941D 0%, #FBB63D 100%); border-radius: 22px; padding: 18px 12px;
             box-shadow: 0 10px 30px rgba(247,148,29,.35); }
  .nav-item { display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; margin: 4px 0;
              border-radius: 12px; color: #fff; font-size: 14px; font-weight: 500; cursor: pointer; }
  .nav-item .left { display: flex; align-items: center; gap: 12px; }
  .nav-item.aktif { background: #FFF6E6; color: #2F6FED; font-weight: 700; box-shadow: 0 2px 6px rgba(0,0,0,.08); }
  .nav-item.segera { opacity: .82; }
  .nav-label { margin: 18px 8px 6px; color: #fff; font-weight: 700; font-size: 11px; letter-spacing: 1px;
               border-bottom: 2px solid rgba(255,255,255,.5); padding-bottom: 6px; }
  .content { flex: 1; min-width: 0; }

  .kartu { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 4px 14px rgba(0,0,0,.05); margin-bottom: 18px; }
  h2 { margin-top: 0; font-size: 19px; }
  label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
  input, select { width: 100%; padding: 10px 12px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 14px; font-family: inherit; }
  button { margin-top: 18px; padding: 12px 20px; border: none; border-radius: 10px; cursor: pointer;
           background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; font-size: 15px; font-weight: 700; font-family: inherit; }
  .pesan-sukses { background: #E8F7EE; border: 1px solid #2E9E5B; color: #1d6b3d; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .pesan-error { background: #FDECEC; border: 1px solid #E23B4E; color: #a12734; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .tautan { font-size: 14px; text-align: center; margin-top: 14px; }
  .tautan a { color: #2F6FED; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid #eef0f5; }
  th { color: #8a6d1e; background: #FFF6E6; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .badge-lolos { background: #E8F7EE; color: #1d6b3d; }
  .badge-gagal { background: #FDECEC; color: #a12734; }
  .badge-flag { background: #FFF6E6; color: #a5771a; border: 1px solid #F3B94A; }
  .badge-netral { background: #F2F4F8; color: #555; }

  /* banner sambutan (gradient amber -> lavender, BIPROO) */
  .banner { position: relative; overflow: hidden; border-radius: 18px; padding: 22px 26px; margin-bottom: 18px;
            background: linear-gradient(100deg, #FCE3A8 0%, #F6D28C 38%, #D8CDF2 82%, #C9D6F5 100%); }
  .banner h2 { font-size: 22px; margin: 0; }
  .banner p { font-size: 13px; color: #5a5a5a; margin: 6px 0 0; }
  .banner .tgl { display: inline-flex; gap: 6px; margin-top: 12px; background: rgba(255,255,255,.7);
                 padding: 5px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; }
  .banner .b-c { position: absolute; border-radius: 50%; background: rgba(255,255,255,.28); }

  /* kartu statistik */
  .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 18px; }
  .stat { background: #fff; border-radius: 14px; padding: 16px 18px; box-shadow: 0 4px 14px rgba(0,0,0,.05); text-align: center; }
  .stat .t { font-size: 13px; color: #888; } .stat .v { font-size: 17px; font-weight: 700; margin: 4px 0 10px; }
  .stat .btn { display: inline-block; padding: 7px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; color: #fff; }

  /* KPI recruiter */
  .kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 18px; }
  .kpi { background: linear-gradient(160deg, #FEEFC3, #FBD97A); border: 1px solid #F3B94A; border-radius: 14px; padding: 14px; text-align: center; }
  .kpi .v { font-size: 24px; font-weight: 700; } .kpi .t { font-size: 11px; font-weight: 600; color: #8a6d1e; margin-top: 2px; }

  /* quick actions */
  .qa { display: flex; gap: 20px; flex-wrap: wrap; }
  .qa .item { text-align: center; cursor: pointer; width: 76px; }
  .qa .circ { width: 52px; height: 52px; border-radius: 50%; margin: 0 auto 6px; display: flex; align-items: center;
              justify-content: center; font-size: 22px; background: radial-gradient(circle at 30% 25%, #FFD24D, #F5B301);
              box-shadow: 0 4px 10px rgba(245,179,1,.4); }
  .qa .lbl { font-size: 12px; font-weight: 600; color: #3a3a3a; }

  /* tutorial banner */
  .tutbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; color: #fff; margin-bottom: 18px;
            background: linear-gradient(90deg, #FBA919, #F7941D); border-radius: 14px; padding: 14px 20px; }
  .tutbar .btn { background: #fff; color: #F7941D; padding: 8px 18px; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; }

  /* stepper dua kolom */
  .stepper-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
  .stepper-cols h3 { font-size: 15px; margin: 0 0 14px; }
  .step { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
  .step .dot { width: 13px; height: 13px; border-radius: 50%; flex-shrink: 0; background: #c9ccd4; }
  .step .card { flex: 1; display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 1px solid #eef0f5; border-radius: 12px; background: #FAFAFC; color: inherit; text-decoration: none; transition: border-color .15s, box-shadow .15s; }
  .step .card:hover { border-color: #F3B94A; box-shadow: 0 2px 8px rgba(245,179,1,.25); }
  .step .ic { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px;
              background: radial-gradient(circle at 30% 25%, #FFD24D, #F5B301); flex-shrink: 0; }
  .step .nm { font-size: 13px; font-weight: 600; }
  .step.done .dot { background: #2E9E5B; } .step.done .card { background: #F3FBF5; }
  .step.current .dot { background: #F5B301; } .step.current .card { background: #FFF9EC; border-color: #F3B94A; }
  .step.failed .dot { background: #E23B4E; } .step.failed .card { background: #FDECEC; }
  .step.locked .card { opacity: .65; } .step.locked .ic { background: #e6e8ee; filter: grayscale(1); }

  .dash { display: grid; grid-template-columns: 1fr 300px; gap: 18px; align-items: start; }
  .wide-btn { display: block; width: 100%; text-align: center; background: linear-gradient(90deg, #FBD97A, #F5B301);
              color: #5a3d00; border-radius: 12px; padding: 15px; font-weight: 700; cursor: pointer; }

  /* modal segera hadir */
  #segeraModal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 100; }
  #segeraModal .box { background: #fff; border-radius: 16px; padding: 30px; max-width: 340px; text-align: center; }
  #segeraModal .ic { font-size: 40px; } #segeraModal h3 { margin: 10px 0 6px; }
  #segeraModal p { color: #666; font-size: 14px; margin: 0 0 4px; }

  @media (max-width: 900px) { .dash { grid-template-columns: 1fr; } }
  @media (max-width: 780px) { .shell { flex-direction: column; } .sidebar { width: 100%; position: static; }
                              .kpis { grid-template-columns: repeat(2, 1fr); } .stats { grid-template-columns: 1fr; } }
  @media (max-width: 620px) { .stepper-cols { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="topbar">
  <div class="brand">
    <span class="logo"><span class="b">B</span><span class="d">D</span></span>
    <span><?= esc($brand) ?></span>
  </div>
  <div class="user">
    <span class="avatar"><?= esc($inisial ?: 'U') ?></span>
    <span><?= esc($nama) ?> <small style="opacity:.85">(<?= esc($peran) ?>)</small></span>
    <a href="<?= $keluar ?>">Keluar</a>
  </div>
</header>

<div class="shell">
  <aside class="sidebar">
    <?php foreach ($menu as [$label, $url, $ikon, $aktif, $segera]): ?>
      <?php if ($segera): ?>
        <div class="nav-item segera" onclick="segera('<?= esc($label, 'js') ?>')">
          <span class="left"><span><?= $ikon ?></span><span><?= esc($label) ?></span></span><span></span>
        </div>
      <?php else: ?>
        <a href="<?= $url ?>" class="nav-item <?= $aktif ? 'aktif' : '' ?>">
          <span class="left"><span><?= $ikon ?></span><span><?= esc($label) ?></span></span>
          <span><?= $aktif ? '›' : '' ?></span>
        </a>
      <?php endif ?>
    <?php endforeach ?>
    <div class="nav-label"><?= session('recruiter_id') ? 'HELP!' : 'BANTUAN!' ?></div>
    <?php foreach ($bantuan as [$label, $ikon]): ?>
      <div class="nav-item segera" onclick="segera('<?= esc($label, 'js') ?>')">
        <span class="left"><span><?= $ikon ?></span><span><?= esc($label) ?></span></span><span></span>
      </div>
    <?php endforeach ?>
    <a href="<?= $keluar ?>" class="nav-item"><span class="left"><span>🚪</span><span>Keluar</span></span><span></span></a>
  </aside>

  <main class="content">
    <?php if (session('sukses')): ?><div class="pesan-sukses"><?= esc(session('sukses')) ?></div><?php endif ?>
    <?php if (session('error')): ?><div class="pesan-error"><?= esc(session('error')) ?></div><?php endif ?>
    <?php if (! empty($errors)): ?>
      <div class="pesan-error"><?php foreach ($errors as $e): ?><div><?= esc($e) ?></div><?php endforeach ?></div>
    <?php endif ?>
    <?= $this->renderSection('isi') ?>
  </main>
</div>

<div id="segeraModal" onclick="if(event.target===this)tutupSegera()">
  <div class="box">
    <div class="ic">🚧</div>
    <h3 id="segeraNama">Fitur ini</h3>
    <p>Segera hadir - fitur ini masih dalam pengembangan.</p>
    <button onclick="tutupSegera()">Mengerti</button>
  </div>
</div>
<script>
  function segera(nama){ document.getElementById('segeraNama').textContent = nama || 'Fitur ini'; document.getElementById('segeraModal').style.display = 'flex'; }
  function tutupSegera(){ document.getElementById('segeraModal').style.display = 'none'; }
</script>
</body>
</html>
