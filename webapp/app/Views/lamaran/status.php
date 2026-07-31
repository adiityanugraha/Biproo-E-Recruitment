<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<?php if ($aktif === null): ?>
<div class="kartu">
  <h2>Status Lamaran</h2>
  <p>Belum ada lamaran. <a href="<?= site_url('lamar') ?>" style="color:#2F6FED">Lamar posisi sekarang</a>.</p>
</div>
<?php else: ?>

<div class="kartu">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Status Lamaran</h2>
    <?php if (count($apps) > 1): ?>
      <select style="width:auto;margin-left:auto" onchange="location.href='<?= site_url('status') ?>?app='+this.value">
        <?php foreach ($apps as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $a['id'] === $aktif['id'] ? 'selected' : '' ?>><?= esc($a['judul']) ?></option>
        <?php endforeach ?>
      </select>
    <?php endif ?>
  </div>
  <p style="color:#666;font-size:14px;margin:6px 0 0">
    <?php if (count($apps) > 1): ?>
      Anda melamar <?= count($apps) ?> posisi. Riwayat di bawah ini khusus untuk posisi yang dipilih.
    <?php else: ?>
      Riwayat setiap tahap seleksi untuk lamaran Anda.
    <?php endif ?>
  </p>
</div>

<div class="kartu">
  <h2><?= esc($aktif['judul']) ?></h2>
  <p style="color:#666;font-size:13px;margin-top:-8px">Dilamar <?= esc(substr($aktif['created_at'], 0, 10)) ?></p>

  <table>
    <tr><th>Tahap</th><th>Status</th><th>Catatan</th><th>Waktu</th></tr>
    <?php foreach ($aktif['riwayat'] as $r): ?>
      <tr>
        <td><?= esc(Lamaran::STAGE_LABEL[$r['stage']] ?? $r['stage']) ?></td>
        <td><?= badge_status($r['status']) ?></td>
        <td style="color:#444;font-size:13px"><?= esc((string) $r['note']) ?></td>
        <td style="color:#666"><?= esc(substr($r['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach ?>
  </table>

  <?php if ($aktif['bisa_assessment']): ?>
    <a href="<?= site_url('assessment/' . $aktif['id']) ?>">
      <button type="button" style="margin-top:14px">Kerjakan Assessment</button>
    </a>
  <?php endif ?>
</div>

<?php endif ?>

<?= $this->endSection() ?>
