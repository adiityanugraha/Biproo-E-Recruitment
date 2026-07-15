<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<?php if ($lamaran !== []): ?>
<div class="kartu">
  <h2>Lamaran Anda (<?= count($lamaran) ?>/<?= \App\Models\ApplicationModel::MAX_LAMARAN ?>)</h2>
  <table>
    <tr><th>Posisi</th><th>Tanggal</th></tr>
    <?php foreach ($lamaran as $l): ?>
      <tr><td><?= esc($l['judul']) ?></td><td><?= esc(substr($l['created_at'], 0, 10)) ?></td></tr>
    <?php endforeach ?>
  </table>
</div>
<?php endif ?>

<div class="kartu">
  <h2>Lamar Posisi</h2>
  <form method="post" action="<?= site_url('lamar') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label for="job_id">Posisi yang dilamar</label>
    <select id="job_id" name="job_id" required>
      <option value="">-- pilih posisi --</option>
      <?php foreach ($jobs as $j): ?>
        <option value="<?= $j['id'] ?>"><?= esc($j['judul']) ?></option>
      <?php endforeach ?>
    </select>
    <label for="cv">CV (PDF/DOCX, maks 2 MB)</label>
    <input id="cv" name="cv" type="file" accept=".pdf,.docx" required>
    <button type="submit">Kirim Lamaran</button>
  </form>
</div>

<?= $this->endSection() ?>
