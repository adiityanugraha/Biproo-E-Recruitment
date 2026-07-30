<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  /* khas halaman tabel tahap: tombol kecil, kolom tak membungkus, pager */
  table { white-space: nowrap; }
  button { padding: 5px 14px; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; font-weight: 600; font-size: 12px; }
  .btn-view { background: #FDE9A9; color: #8a6d1e; }
  .btn-dl { background: #20A277; color: #fff; padding: 9px 16px; font-size: 13px; }
  .scroll-x { overflow-x: auto; border: 1px solid #eef0f5; border-radius: 12px; }
  .avatar-s { width: 30px; height: 30px; border-radius: 50%; background: #F7941D; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
  .foot { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; }
  .pager span { padding: 5px 9px; border: 1px solid #e2e6ee; border-radius: 6px; margin-right: 4px; font-size: 12px; cursor: pointer; }
  .pager .now { background: #F7941D; color: #fff; border-color: #F7941D; }
  .rows select { border: none; border-radius: 8px; padding: 6px 8px; font-family: inherit; }
</style>
<?= $this->endSection() ?>

<?= $this->section('topbar') ?>
<span class="rows" style="display:flex;align-items:center;gap:6px;font-size:13px">Rows
  <select onchange="segera('Ubah Jumlah Baris')"><option>200</option><option>50</option></select>
</span>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<?php
$base = site_url('recruiter/tahap/' . $stage);
$tabs = [
    'progress' => ['On Progress', '🔄', $base],
    'passed'   => ['Passed', '✅', $base . '?status=passed'],
];
// tab Completed (keputusan Gate 2) hanya relevan di Interview HRD
if (($stage ?? '') === 'interview_online') {
    $tabs['completed'] = ['Completed', '🏁', $base . '?status=completed'];
}
$tabs['failed'] = ['Failed', '❌', $base . '?status=failed'];
foreach ($tabs as $k => [$lbl, $ic, $url]): ?>
  <a href="<?= $url ?>" class="<?= $status === $k ? 'on' : '' ?>">
    <span class="l"><span><?= $ic ?></span><span><?= $lbl ?></span></span><span><?= $status === $k ? '»' : '' ?></span></a>
<?php endforeach ?>
<a href="#" onclick="segera('Settings');return false"><span class="l"><span>⚙️</span><span>Settings</span></span></a>
<a href="#" onclick="segera('Upload History');return false"><span class="l"><span>🕘</span><span>Upload History</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="scroll-x">
  <table>
    <tr>
      <th>No</th><th>Select</th><th>Action</th><th>File</th><th>Summary</th><th>Remark</th>
      <th>Picture</th><th>FullName</th><th>Email</th><th>Company Code</th><th>Job Posting</th>
    </tr>
    <?php if ($daftar === []): ?>
      <tr><td colspan="11" style="text-align:center;color:#aaa;padding:26px">- Tidak ada kandidat pada tahap &amp; status ini -</td></tr>
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
            <?php elseif ($stage === 'interview_online' && $status === 'completed'): ?>
              <?php if ($a['gate2'] === 'passed'): ?>
                <span style="color:#1d6b3d;font-weight:700">✅ Lolos</span>
              <?php elseif ($a['gate2'] === 'failed'): ?>
                <span style="color:#a12734;font-weight:700">❌ Tidak Lolos</span>
              <?php else: ?>
                <form method="post" action="<?= site_url('recruiter/interview/putus/' . $a['id']) ?>" style="margin:6px 0 0">
                  <?= csrf_field() ?>
                  <div style="font-size:11px;color:#666">Skor interview: <b id="sv<?= $a['id'] ?>">70</b></div>
                  <input type="range" name="skor" min="0" max="100" value="70" style="width:130px"
                         oninput="document.getElementById('sv<?= $a['id'] ?>').textContent=this.value">
                  <div style="margin-top:4px">
                    <button class="btn-view" style="background:#2F6FED;color:#fff">OK</button>
                    <div style="font-size:10px;color:#999;margin-top:2px">hasil dihitung dari skor interview + skor CV</div>
                  </div>
                </form>
              <?php endif ?>
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
