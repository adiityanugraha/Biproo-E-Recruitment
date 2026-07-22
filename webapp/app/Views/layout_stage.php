<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($stageTitle) ?> - E-REQ BIPROO</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Poppins', system-ui, sans-serif; background: #fff; color: #2B2B2B; }
  a { text-decoration: none; }
  .rtop { position: sticky; top: 0; z-index: 40; display: flex; align-items: center; gap: 16px; height: 58px; padding: 0 20px;
          background: linear-gradient(90deg, #FBA919, #F7941D); box-shadow: 0 2px 10px rgba(247,148,29,.35); color: #fff; }
  .rtop .ham { font-size: 22px; }
  .rtop .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 17px; color: #fff; }
  .rtop .rows { display: flex; align-items: center; gap: 6px; font-size: 13px; }
  .rtop .rows select { border: none; border-radius: 8px; padding: 6px 8px; font-family: inherit; }
  .rtop .sp { flex: 1; }
  .rtop .user { display: flex; align-items: center; gap: 8px; font-size: 14px; }
  .rtop .avatar { width: 30px; height: 30px; border-radius: 50%; background: #fff; color: #F7941D; display: flex; align-items: center; justify-content: center; font-weight: 700; }
  .rtop .user a { color: #fff; }

  .rshell { display: flex; gap: 18px; padding: 18px; align-items: flex-start; }
  .rside { width: 200px; flex-shrink: 0; }
  .rside a { display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; margin-bottom: 5px; border-radius: 12px; color: #555; font-size: 13px; font-weight: 500; }
  .rside a .l { display: flex; align-items: center; gap: 10px; }
  .rside a.on { background: #FFF3D6; color: #F7941D; font-weight: 700; }
  .rcontent { flex: 1; min-width: 0; }

  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid #eef0f5; white-space: nowrap; }
  th { color: #8a6d1e; background: #FFF6E6; font-size: 12px; }
  button { padding: 5px 14px; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; font-weight: 600; font-size: 12px; }
  .btn-view { background: #FDE9A9; color: #8a6d1e; }
  .btn-dl { background: #20A277; color: #fff; padding: 9px 16px; font-size: 13px; }
  .scroll-x { overflow-x: auto; border: 1px solid #eef0f5; border-radius: 12px; }
  .avatar-s { width: 30px; height: 30px; border-radius: 50%; background: #F7941D; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
  .foot { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; }
  .pager span { padding: 5px 9px; border: 1px solid #e2e6ee; border-radius: 6px; margin-right: 4px; font-size: 12px; cursor: pointer; }
  .pager .now { background: #F7941D; color: #fff; border-color: #F7941D; }

  @media (max-width: 820px) { .rshell { flex-direction: column; } .rside { width: 100%; } }
</style>
</head>
<body>
<header class="rtop">
  <a href="<?= site_url('recruiter') ?>" class="ham" style="color:#fff">☰</a>
  <a href="<?= site_url('recruiter') ?>" class="brand"><?= esc($stageTitle) ?></a>
  <span class="rows">Rows <select onchange="segera('Ubah Jumlah Baris')"><option>200</option><option>50</option></select></span>
  <span class="sp"></span>
  <div class="user"><span class="avatar"><?= esc(strtoupper(mb_substr((string) session('recruiter_nama'), 0, 1)) ?: 'R') ?></span>
    <span><?= esc(session('recruiter_nama')) ?></span><a href="<?= site_url('recruiter/logout') ?>">▾</a></div>
</header>

<div class="rshell">
  <aside class="rside">
    <?php
    $base = site_url('recruiter/tahap/' . $stage);
    $tabs = [
        'progress' => ['On Progress', '🔄', $base],
        'passed'   => ['Passed', '✅', $base . '?status=passed'],
    ];
    // tab Completed (keputusan Gate 2) hanya relevan di Interview HRD
    if (($stage ?? '') === 'interview_online') {
        $tabs['completed'] = ['Completed', '🏁', $base . '?status=completed'];
    }
    $tabs['failed'] = ['Failed', '❌', $base . '?status=failed'];
    foreach ($tabs as $k => [$lbl, $ic, $url]): ?>
      <a href="<?= $url ?>" class="<?= $status === $k ? 'on' : '' ?>">
        <span class="l"><span><?= $ic ?></span><span><?= $lbl ?></span></span><span><?= $status === $k ? '»' : '' ?></span></a>
    <?php endforeach ?>
    <a href="#" onclick="segera('Settings');return false"><span class="l"><span>⚙️</span><span>Settings</span></span></a>
    <a href="#" onclick="segera('Upload History');return false"><span class="l"><span>🕘</span><span>Upload History</span></span></a>
  </aside>
  <main class="rcontent"><?= $this->renderSection('isi') ?></main>
</div>

<?= $this->include('partials/segera_modal') ?>
</body>
</html>
