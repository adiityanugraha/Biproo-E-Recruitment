<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <?php /*
    * Tombol Candidate Ranking di sebelah judul, bukan di sidebar: halaman
    * peringkat membaca kandidat yang sama dengan tabel di bawah ini, cuma
    * disusun menurut skor. Menaruhnya jauh dari sini membuat keduanya terbaca
    * seperti dua daftar yang berbeda isinya.
    */ ?>
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap">
    <h2 style="margin:0">Semua Kandidat</h2>
    <a href="<?= site_url('recruiter/peringkat') ?>" style="margin-left:auto">
      <button type="button" style="height:34px;padding:0 16px;border:none;border-radius:7px;
        background:#1E88E5;color:#fff;font-family:inherit;font-weight:600;font-size:13px;cursor:pointer">
        🏆 Candidate Ranking</button></a>
  </div>
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
            <?php // Dibuka di jendela di atas daftar, supaya posisi gulir daftar
                  // yang panjang ini tidak hilang setiap kali membuka satu kandidat. ?>
            <a href="<?= site_url('recruiter/review/' . $a['id']) ?>?bingkai=1" style="color:#2F6FED"
               onclick="return bukaJendela(this.href, <?= esc(json_encode('Detail - ' . $a['nama']), 'attr') ?>)">
              <?= $a['gate1'] === 'flagged' ? '<b>Review</b>' : 'Detail' ?></a>
          </td>
        </tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>
  <p class="tautan"><a href="<?= site_url('recruiter') ?>">Kembali ke dashboard</a></p>
</div>

<?= $this->endSection() ?>
