<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="banner">
  <span class="b-c" style="width:150px;height:150px;left:-40px;top:-30px"></span>
  <span class="b-c" style="width:120px;height:120px;left:30px;bottom:-60px"></span>
  <h2>Welcome to the E-Recruitment System!</h2>
  <p>E-Recruitment is a digital-based employee recruitment system.</p>
  <span class="tgl">📅 <?= date('d M, Y') ?></span>
</div>

<div class="kpis">
  <?php foreach ($kpi as $k): ?>
    <div class="kpi"><div class="v"><?= esc($k['v']) ?></div><div class="t"><?= esc($k['t']) ?></div></div>
  <?php endforeach ?>
</div>

<div class="kartu">
  <div class="qa">
    <?php
    $aksi = [
        ['FPK', '📄', site_url('recruiter/lowongan')],
        ['SK Posting', '📝', null],
        ['Job Posting', '📢', site_url('recruiter/lowongan')],
        ['Upload Candidat', '📤', null],
        ['Summary', '📊', null],
        ['Settings', '⚙️', null],
    ];
    foreach ($aksi as [$lbl, $ic, $url]): ?>
      <?php if ($url): ?>
        <a class="item" href="<?= $url ?>"><span class="circ"><?= $ic ?></span><span class="lbl"><?= esc($lbl) ?></span></a>
      <?php else: ?>
        <div class="item" onclick="segera('<?= esc($lbl, 'js') ?>')"><span class="circ"><?= $ic ?></span><span class="lbl"><?= esc($lbl) ?></span></div>
      <?php endif ?>
    <?php endforeach ?>
  </div>
</div>

<div class="kartu">
  <h2>Daftar Lowongan</h2>
  <?php if ($jobs === []): ?>
    <p>Belum ada lowongan. <a href="<?= site_url('recruiter/lowongan') ?>" style="color:#2F6FED">Buat lowongan</a>.</p>
  <?php else: ?>
    <table>
      <tr><th>Lowongan</th><th>Pelamar</th><th>Menunggu Review</th><th></th></tr>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><?= esc($j['judul']) ?></td>
          <td><?= $j['jumlah_pelamar'] ?></td>
          <td><?= $j['jumlah_flagged'] > 0 ? '<span class="badge badge-flag">' . $j['jumlah_flagged'] . ' menunggu</span>' : '0' ?></td>
          <td><a href="<?= site_url('recruiter/kandidat/' . $j['id']) ?>" style="color:#2F6FED">Lihat kandidat</a></td>
        </tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
