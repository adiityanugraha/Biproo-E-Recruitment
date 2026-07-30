<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title><?= esc($judul ?? 'BIPROO') ?> - E-REQ BIPROO</title>
<style>
  /*
   * Shell halaman kerja recruiter (tabel per tahap dan Report). Ukurannya
   * disamakan dengan layout.php - 60px topbar, sidebar 230px - supaya tidak
   * ada halaman yang jadi anak tiri secara visual.
   */
  body { background: #fff; }

  .rtop { position: sticky; top: 0; z-index: 40; display: flex; align-items: center; gap: 18px; height: 60px; padding: 0 20px;
          background: linear-gradient(90deg, #FBA919, #F7941D); box-shadow: 0 2px 10px rgba(247,148,29,.35); color: #fff; }
  .rtop .ham { font-size: 22px; cursor: pointer; color: #fff; }
  .rtop .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: #fff; }
  .rtop .sp { flex: 1; }
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

  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid #eef0f5; }
  th { color: #8a6d1e; background: #FFF6E6; font-size: 12px; }

  @media (max-width: 820px) { .rshell { flex-direction: column; } .rside { width: 100%; } }
</style>
<?= $this->renderSection('gaya') ?>
</head>
<body>
<header class="rtop">
  <a href="<?= site_url('recruiter') ?>" class="ham" title="Kembali ke dashboard">&#9776;</a>
  <a href="<?= site_url('recruiter') ?>" class="brand"><?= esc($judul ?? 'BIPROO') ?></a>
  <?= $this->renderSection('topbar') ?>
  <span class="sp"></span>
  <?= $this->renderSection('topbarKanan') ?>
  <div class="user">
    <span class="avatar"><?= esc(strtoupper(mb_substr((string) session('recruiter_nama'), 0, 1)) ?: 'R') ?></span>
    <span><?= esc(session('recruiter_nama')) ?></span>
    <a href="<?= site_url('recruiter/logout') ?>">&#9662;</a>
  </div>
</header>

<div class="rshell">
  <aside class="rside"><?= $this->renderSection('sidebar') ?></aside>
  <main class="rcontent"><?= $this->renderSection('isi') ?></main>
</div>

<?= $this->include('partials/segera_modal') ?>
</body>
</html>
