<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<?php if ($apps === []): ?>
<div class="kartu">
  <h2>Status Lamaran</h2>
  <p>Belum ada lamaran. <a href="<?= site_url('lamar') ?>" style="color:#2F6FED">Lamar posisi sekarang</a>.</p>
</div>
<?php endif ?>

<?php foreach ($apps as $app): ?>
<div class="kartu">
  <h2><?= esc($app['judul']) ?></h2>
  <p style="color:#666;font-size:13px;margin-top:-8px">Dilamar <?= esc(substr($app['created_at'], 0, 10)) ?></p>

  <table>
    <tr><th>Tahap</th><th>Status</th><th>Waktu</th></tr>
    <?php foreach ($app['riwayat'] as $r): ?>
      <tr>
        <td><?= esc(Lamaran::STAGE_LABEL[$r['stage']] ?? $r['stage']) ?></td>
        <td><?= badge_status($r['status']) ?></td>
        <td style="color:#666"><?= esc(substr($r['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach ?>
  </table>

  <?php if ($app['bisa_assessment']): ?>
    <a href="<?= site_url('assessment/' . $app['id']) ?>">
      <button type="button" style="margin-top:14px">Kerjakan Assessment</button>
    </a>
  <?php endif ?>
</div>
<?php endforeach ?>

<?= $this->endSection() ?>
