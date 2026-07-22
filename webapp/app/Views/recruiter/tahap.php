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
          <td style="white-space:nowrap">
            <a href="<?= site_url('recruiter/review/' . $a['id']) ?>"><button class="btn-view">View</button></a>
            <?php if ($stage === 'interview_online' && $status === 'progress'): ?>
              <form method="post" action="<?= site_url('recruiter/interview/acc/' . $a['id']) ?>" style="display:inline;margin:0">
                <?= csrf_field() ?><input type="hidden" name="kembali" value="interview_hrd">
                <button class="btn-view" style="background:#2E9E5B;color:#fff">Acc</button>
              </form>
              <form method="post" action="<?= site_url('recruiter/interview/tolak/' . $a['id']) ?>" style="display:inline;margin:0">
                <?= csrf_field() ?><input type="hidden" name="kembali" value="interview_hrd">
                <button class="btn-view" style="background:#E23B4E;color:#fff">Tolak</button>
              </form>
            <?php elseif ($stage === 'interview_online' && $status === 'passed' && ! empty($a['join_url'])): ?>
              <a href="<?= esc($a['join_url'], 'attr') ?>" target="_blank" rel="noopener"><button class="btn-view" style="background:#2F6FED;color:#fff">🎥 Link Zoom</button></a>
            <?php endif ?>
          </td>
          <td><button class="btn-view" style="background:#DCE9FF;color:#2F6FED" onclick="segera('Lihat File CV')">CV</button></td>
          <td><button class="btn-view" style="background:#f0f0f0;color:#888" onclick="segera('Summary')">-</button></td>
          <td>
            <?php if (! empty($a['jadwal'])): ?>
              <small>📅 <?= esc(date('d M Y H:i', strtotime($a['jadwal']))) ?></small>
            <?php else: ?>
              <span style="color:#bbb">-</span>
            <?php endif ?>
          </td>
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
