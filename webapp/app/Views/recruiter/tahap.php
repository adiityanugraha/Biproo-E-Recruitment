<?= $this->extend('layout_stage') ?>
<?= $this->section('isi') ?>

<div class="scroll-x">
  <table>
    <tr>
      <th>No</th><th>Select</th><th>Action</th><th>File</th><th>Summary</th><th>Remark</th>
      <th>Picture</th><th>FullName</th><th>Email</th><th>Company Code</th><th>Job Posting</th>
    </tr>
    <?php if ($daftar === []): ?>
      <tr><td colspan="11" style="text-align:center;color:#aaa;padding:26px">— Tidak ada kandidat pada tahap &amp; status ini —</td></tr>
    <?php else: ?>
      <?php foreach ($daftar as $i => $a): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><input type="checkbox" style="width:auto" onclick="segera('Pilih Kandidat')"></td>
          <td><a href="<?= site_url('recruiter/review/' . $a['id']) ?>"><button class="btn-view">View</button></a></td>
          <td><button class="btn-view" style="background:#DCE9FF;color:#2F6FED" onclick="segera('Lihat File CV')">CV</button></td>
          <td><button class="btn-view" style="background:#f0f0f0;color:#888" onclick="segera('Summary')">-</button></td>
          <td style="color:#bbb">-</td>
          <td><span class="avatar-s"><?= esc(strtoupper(mb_substr($a['nama'], 0, 1))) ?></span></td>
          <td><?= esc($a['nama']) ?></td>
          <td><?= esc($a['email']) ?></td>
          <td>DA</td>
          <td><?= esc($a['judul']) ?></td>
        </tr>
      <?php endforeach ?>
    <?php endif ?>
  </table>
</div>

<div class="foot">
  <div class="pager"><span>«</span><span>‹</span><span class="now">1</span><span>›</span><span>»</span></div>
  <button class="btn-dl" onclick="segera('Download Excel')">⬇ Download <?= count($daftar) ?> Row(s)</button>
</div>

<?= $this->endSection() ?>
