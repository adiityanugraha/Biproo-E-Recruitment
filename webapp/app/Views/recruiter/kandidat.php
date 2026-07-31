<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Semua Kandidat</h2>
  <?php if ($daftar === []): ?>
    <p>Belum ada pelamar.</p>
  <?php else: ?>
    <table>
      <tr><th>Nama</th><th>Posisi</th><th>Tahap Terkini</th><th>Skor CV</th><th>Gate 1</th><th>Interview</th><th></th></tr>
      <?php foreach ($daftar as $a): ?>
        <tr>
          <td><?= esc($a['nama']) ?><br><small style="color:#666"><?= esc($a['email']) ?></small></td>
          <td><span class="badge badge-netral"><?= esc($a['posisi']) ?></span></td>
          <td><?= esc(Lamaran::STAGE_LABEL[$a['stage_akhir']] ?? $a['stage_akhir']) ?>
              <?= badge_status($a['status_akhir']) ?></td>
          <td><?= badge_skor($a['skor_cv']) ?></td>
          <td><?= badge_status($a['gate1']) ?></td>
          <td>
            <?php $iv = $a['interview']; ?>
            <?php if ($iv && $iv['status'] === 'approved'): ?>
              <small>📅 <?= esc(date('d M Y H:i', strtotime($iv['scheduled_at']))) ?></small><br>
              <a href="<?= esc($iv['join_url'], 'attr') ?>" target="_blank" rel="noopener" style="color:#2F6FED">Link Zoom</a>
            <?php elseif ($iv && $iv['status'] === 'requested'): ?>
              <small>Ajuan: <?= esc(date('d M Y H:i', strtotime($iv['scheduled_at']))) ?></small><br>
              <form method="post" action="<?= site_url('recruiter/interview/acc/' . $a['id']) ?>" style="display:inline;margin:0">
                <?= csrf_field() ?><button style="margin:2px 0;padding:5px 12px;font-size:12px;background:#2E9E5B">Acc</button>
              </form>
              <form method="post" action="<?= site_url('recruiter/interview/tolak/' . $a['id']) ?>" style="display:inline;margin:0">
                <?= csrf_field() ?><button style="margin:2px 0;padding:5px 12px;font-size:12px;background:#E23B4E">Tolak</button>
              </form>
            <?php elseif ($a['gate1'] === 'passed'): ?>
              <small style="color:#999">menunggu ajuan kandidat</small>
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
