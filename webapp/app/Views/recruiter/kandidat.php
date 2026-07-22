<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Kandidat - <?= esc($job['judul']) ?></h2>
  <?php if ($daftar === []): ?>
    <p>Belum ada pelamar.</p>
  <?php else: ?>
    <table>
      <tr><th>Nama</th><th>Tahap Terkini</th><th>Skor</th><th>Gate 1</th><th>Interview</th><th></th></tr>
      <?php foreach ($daftar as $a): ?>
        <tr>
          <td><?= esc($a['nama']) ?><br><small style="color:#666"><?= esc($a['email']) ?></small></td>
          <td><?= esc(Lamaran::STAGE_LABEL[$a['stage_akhir']] ?? $a['stage_akhir']) ?>
              <?= badge_status($a['status_akhir']) ?></td>
          <td><small><?= esc($a['skor']) ?></small></td>
          <td><?= badge_status($a['gate1']) ?></td>
          <td>
            <?php if ($a['interview']): ?>
              <small>📅 <?= esc(date('d M Y H:i', strtotime($a['interview']['scheduled_at']))) ?></small><br>
              <a href="<?= esc($a['interview']['join_url'], 'attr') ?>" target="_blank" rel="noopener" style="color:#2F6FED">Link Zoom</a>
            <?php elseif ($a['bisa_jadwal']): ?>
              <form method="post" action="<?= site_url('recruiter/jadwalkan/' . $a['id']) ?>" style="display:flex;gap:6px;align-items:center;margin:0">
                <?= csrf_field() ?>
                <input type="datetime-local" name="jadwal" required style="width:auto;padding:6px;font-size:12px;margin:0">
                <button style="margin:0;padding:6px 12px;font-size:12px">Jadwalkan</button>
              </form>
            <?php else: ?>
              <small style="color:#999">-</small>
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
