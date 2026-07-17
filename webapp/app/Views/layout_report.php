<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report - E-REQ BIPROO</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Poppins', system-ui, sans-serif; background: #fff; color: #2B2B2B; }
  a { text-decoration: none; }

  /* topbar Report: filter tanggal inline (BIPROO) */
  .rtop { position: sticky; top: 0; z-index: 40; display: flex; align-items: center; gap: 18px; height: 60px; padding: 0 20px;
          background: linear-gradient(90deg, #FBA919, #F7941D); box-shadow: 0 2px 10px rgba(247,148,29,.35); color: #fff; }
  .rtop .ham { font-size: 22px; cursor: pointer; }
  .rtop .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; }
  .rtop .dates { display: flex; align-items: center; gap: 8px; font-size: 13px; }
  .rtop .dates input { border: none; border-radius: 8px; padding: 7px 10px; font-size: 13px; font-family: inherit; }
  .rtop .dates button { margin: 0; padding: 7px 16px; border: none; border-radius: 8px; background: #fff; color: #2F6FED; font-weight: 700; font-size: 13px; cursor: pointer; }
  .rtop .sp { flex: 1; }
  .rtop .bell { position: relative; font-size: 18px; }
  .rtop .bell::after { content: ''; position: absolute; top: -2px; right: -2px; width: 8px; height: 8px; background: #E23B4E; border-radius: 50%; }
  .rtop .user { display: flex; align-items: center; gap: 8px; font-size: 14px; }
  .rtop .avatar { width: 32px; height: 32px; border-radius: 50%; background: #fff; color: #F7941D; display: flex; align-items: center; justify-content: center; font-weight: 700; }
  .rtop .user a { color: #fff; font-weight: 600; }

  .rshell { display: flex; gap: 18px; padding: 18px; align-items: flex-start; }
  .rside { width: 230px; flex-shrink: 0; }
  .rside a { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; margin-bottom: 6px; border-radius: 12px;
             color: #444; font-size: 14px; font-weight: 500; }
  .rside a .l { display: flex; align-items: center; gap: 10px; }
  .rside a.on { background: #FFF3D6; color: #F7941D; font-weight: 700; box-shadow: 0 2px 6px rgba(0,0,0,.05); }
  .rcontent { flex: 1; min-width: 0; }

  h2 { margin-top: 0; }
  input, select { padding: 10px 12px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 14px; font-family: inherit; }
  button { padding: 12px 20px; border: none; border-radius: 10px; cursor: pointer; background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; font-size: 15px; font-weight: 700; font-family: inherit; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid #eef0f5; }
  th { color: #8a6d1e; background: #FFF6E6; }

  @media (max-width: 820px) { .rtop .dates { display: none; } .rshell { flex-direction: column; } .rside { width: 100%; } }
</style>
</head>
<body>
<header class="rtop">
  <a href="<?= site_url('recruiter') ?>" class="ham" title="Kembali ke dashboard">☰</a>
  <a href="<?= site_url('recruiter') ?>" class="brand" style="color:#fff">
    Report
  </a>
  <div class="dates">
    <span>Start Date:</span><input type="date" value="2026-01-01">
    <span>End Date:</span><input type="date" value="2026-07-16">
    <button onclick="segera('Filter Tanggal')">View</button>
  </div>
  <span class="sp"></span>
  <span class="bell" onclick="segera('Notifikasi')">🔔</span>
  <div class="user">
    <span class="avatar"><?= esc(strtoupper(mb_substr((string) session('recruiter_nama'), 0, 1)) ?: 'R') ?></span>
    <span><?= esc(session('recruiter_nama')) ?></span>
    <a href="<?= site_url('recruiter/logout') ?>">▾</a>
  </div>
</header>

<div class="rshell">
  <aside class="rside">
    <a href="<?= site_url('recruiter/report') ?>" class="<?= ($tab ?? 'summary') === 'summary' ? 'on' : '' ?>">
      <span class="l"><span>📊</span><span>Summary</span></span><span><?= ($tab ?? 'summary') === 'summary' ? '»' : '' ?></span>
    </a>
    <a href="<?= site_url('recruiter/report') ?>?tab=fpk" class="<?= ($tab ?? '') === 'fpk' ? 'on' : '' ?>">
      <span class="l"><span>🗃️</span><span>Data FPK</span></span><span><?= ($tab ?? '') === 'fpk' ? '»' : '' ?></span>
    </a>
  </aside>
  <main class="rcontent">
    <?= $this->renderSection('isi') ?>
  </main>
</div>

<?= $this->include('partials/segera_modal') ?>
</body>
</html>
