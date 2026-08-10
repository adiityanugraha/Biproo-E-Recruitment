<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Semua Kandidat</h2>
  <?php if ($daftar === []): ?>
    <p>Belum ada pelamar.</p>
  <?php else: ?>
    <table>
      <tr><th>Nama</th><th>Posisi</th><th>Tahap Terkini</th><th>Kemiripan CV</th><th>Gate 1</th><th>Interview</th><th></th></tr>
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
            <?php elseif ($iv && $iv['status'] === 'rescheduled'): ?>
              <small style="color:#a5771a">🔁 menunggu kandidat pilih slot baru</small>
            <?php elseif ($iv && $iv['status'] === 'requested'): ?>
              <?php // sisa alur lama sebelum slot otomatis disetujui; tidak ada aksi lagi ?>
              <small style="color:#999">ajuan lama: <?= esc(date('d M Y H:i', strtotime($iv['scheduled_at']))) ?></small>
            <?php elseif ($a['gate1'] === 'passed'): ?>
              <small style="color:#999">menunggu kandidat memilih slot</small>
            <?php else: ?>
              <small style="color:#999">-</small>
            <?php endif ?>
          </td>
          <td style="white-space:nowrap">
            <?php $pdf = str_ends_with(strtolower($a['cv_path'] ?? ''), '.pdf'); ?>
            <a href="<?= site_url('recruiter/cv/' . $a['id']) ?>" target="_blank" rel="noopener"
               <?php if ($pdf): ?>onclick="return bukaJendela(this.href, <?= esc(json_encode('CV ' . $a['nama']), 'attr') ?>)"<?php endif ?>
               style="color:#2F6FED" title="<?= $pdf ? 'Lihat CV' : 'Unduh CV' ?>">CV</a>
            &nbsp;
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
