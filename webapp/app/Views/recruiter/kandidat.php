<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Kandidat - <?= esc($job['judul']) ?></h2>
  <?php if ($daftar === []): ?>
    <p>Belum ada pelamar.</p>
  <?php else: ?>
    <table>
      <tr><th>Nama</th><th>Tahap Terkini</th><th>Skor</th><th>Gate 1</th><th></th></tr>
      <?php foreach ($daftar as $a): ?>
        <tr>
          <td><?= esc($a['nama']) ?><br><small style="color:#666"><?= esc($a['email']) ?></small></td>
          <td><?= esc(Lamaran::STAGE_LABEL[$a['stage_akhir']] ?? $a['stage_akhir']) ?>
              (<?= esc(Lamaran::STATUS_LABEL[$a['status_akhir']] ?? $a['status_akhir']) ?>)</td>
          <td><small><?= esc($a['skor']) ?></small></td>
          <td>
            <?php if ($a['gate1'] === 'flagged'): ?>
              <b style="color:#E23B4E">menunggu review</b>
            <?php else: ?>
              <?= esc(Lamaran::STATUS_LABEL[$a['gate1']] ?? '-') ?>
            <?php endif ?>
          </td>
          <td>
            <?php if ($a['gate1'] === 'flagged'): ?>
              <a href="<?= site_url('recruiter/review/' . $a['id']) ?>" style="color:#2F6FED"><b>Review</b></a>
            <?php else: ?>
              <a href="<?= site_url('recruiter/review/' . $a['id']) ?>" style="color:#2F6FED">Detail</a>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>
  <p class="tautan"><a href="<?= site_url('recruiter') ?>">Kembali ke dashboard</a></p>
</div>

<?= $this->endSection() ?>
