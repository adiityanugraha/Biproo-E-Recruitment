<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Dashboard Recruiter</h2>
  <?php if ($jobs === []): ?>
    <p>Belum ada lowongan. <a href="<?= site_url('recruiter/lowongan') ?>" style="color:#2F6FED">Buat lowongan</a>.</p>
  <?php else: ?>
    <table>
      <tr><th>Lowongan</th><th>Pelamar</th><th>Menunggu Review</th><th></th></tr>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><?= esc($j['judul']) ?></td>
          <td><?= $j['jumlah_pelamar'] ?></td>
          <td><?= $j['jumlah_flagged'] > 0 ? '<b style="color:#E23B4E">' . $j['jumlah_flagged'] . '</b>' : '0' ?></td>
          <td><a href="<?= site_url('recruiter/kandidat/' . $j['id']) ?>" style="color:#2F6FED">Lihat kandidat</a></td>
        </tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
