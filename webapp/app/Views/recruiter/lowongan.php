<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Buat Lowongan (FPK)</h2>
  <form method="post" action="<?= site_url('recruiter/lowongan') ?>">
    <?= csrf_field() ?>
    <label for="judul">Judul posisi</label>
    <input id="judul" name="judul" required value="<?= old('judul') ?>">
    <label for="req_skill">Requirement skill</label>
    <input id="req_skill" name="req_skill" required value="<?= old('req_skill') ?>">
    <label for="req_pendidikan">Pendidikan minimum</label>
    <input id="req_pendidikan" name="req_pendidikan" required value="<?= old('req_pendidikan') ?>">
    <label for="req_pengalaman">Pengalaman minimum</label>
    <input id="req_pengalaman" name="req_pengalaman" required value="<?= old('req_pengalaman') ?>">
    <label for="deskripsi">Deskripsi (opsional)</label>
    <input id="deskripsi" name="deskripsi" value="<?= old('deskripsi') ?>">
    <button type="submit">Simpan Lowongan</button>
  </form>
</div>

<div class="kartu">
  <h2>Lowongan Aktif</h2>
  <table>
    <tr><th>Judul</th><th>Skill</th><th>Pendidikan</th><th>Pengalaman</th></tr>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= esc($j['judul']) ?></td>
        <td><?= esc($j['req_skill']) ?></td>
        <td><?= esc($j['req_pendidikan']) ?></td>
        <td><?= esc($j['req_pengalaman']) ?></td>
      </tr>
    <?php endforeach ?>
  </table>
</div>

<?= $this->endSection() ?>
