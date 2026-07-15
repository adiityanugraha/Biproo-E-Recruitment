<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2><?= esc($app['nama']) ?> - <?= esc($app['judul']) ?></h2>
  <p style="color:#666;font-size:13px;margin-top:-8px"><?= esc($app['email']) ?></p>

  <table>
    <tr><th>Tahap</th><th>Status</th><th>Catatan</th><th>Oleh</th><th>Waktu</th></tr>
    <?php foreach ($riwayat as $r): ?>
      <tr>
        <td><?= esc(Lamaran::STAGE_LABEL[$r['stage']] ?? $r['stage']) ?></td>
        <td><?= esc(Lamaran::STATUS_LABEL[$r['status']] ?? $r['status']) ?></td>
        <td><small><?= esc($r['note']) ?></small></td>
        <td><small><?= esc($r['actor']) ?></small></td>
        <td style="color:#666"><small><?= esc(substr($r['created_at'], 0, 16)) ?></small></td>
      </tr>
    <?php endforeach ?>
  </table>
</div>

<?php if ($flagged): ?>
<div class="kartu" style="border:1px solid #F3B94A;background:#FFF6E6">
  <h2>Keputusan Review (zona abu-abu)</h2>
  <p style="font-size:14px">Skor gabungan kandidat berada di zona tengah - sistem tidak memutus otomatis.
  Keputusan ada di tangan Anda dan akan tercatat atas nama Anda di riwayat.</p>
  <form method="post" action="<?= site_url('recruiter/review/' . $app['id']) ?>" style="display:flex;gap:12px">
    <?= csrf_field() ?>
    <button type="submit" name="keputusan" value="approve" style="background:#2E9E5B">Loloskan</button>
    <button type="submit" name="keputusan" value="reject" style="background:#E23B4E">Tidak Lolos</button>
  </form>
</div>
<?php endif ?>

<p class="tautan"><a href="<?= site_url('recruiter') ?>">Kembali ke dashboard</a></p>

<?= $this->endSection() ?>
