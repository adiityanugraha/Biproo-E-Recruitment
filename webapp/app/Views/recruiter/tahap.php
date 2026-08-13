<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  /* Tata letak tabel mengikuti halaman Interview HRD BIPROO yang asli:
     kepala biru, garis kisi penuh, isi rata tengah. */
  table { white-space: nowrap; border-collapse: collapse; }
  th, td { border: 1px solid #cfd8e3; padding: 7px 12px; text-align: center; vertical-align: middle; }
  th { background: #1E88E5; color: #fff; font-weight: 700; font-size: 12px; letter-spacing: .2px; border-color: #1877cc; }
  tbody tr:hover td { background: #f7fafd; }

  /* Semua tombol dalam sel bertinggi sama, supaya barisnya rata. */
  button { padding: 0 14px; height: 30px; border: 1px solid transparent; border-radius: 6px;
           cursor: pointer; font-family: inherit; font-weight: 600; font-size: 12px; line-height: 28px; }
  .b-aksi  { background: #1E88E5; color: #fff; }                                  /* aksi utama */
  .b-file  { background: #E8F2FE; color: #1E88E5; border-color: #A8CFF5; }        /* CV */
  .b-lihat { background: #FFF3C4; color: #8a6d1e; border-color: #EBD48A; }        /* Summary */
  .b-mati  { background: #ECEFF1; color: #90a4ae; border-color: #d7dee2; }
  .b-warn  { background: #F5B301; color: #5a3d00; }
  .b-tanya { background: #EDE4FF; color: #5B3FA8; border-color: #cdbaf2; }
  .b-lolos { background: #2E9E5B; color: #fff; }
  .b-gagal { background: #E23B4E; color: #fff; }
  /* penanda kenapa barisnya minta keputusan manual, bukan sekadar dua tombol
     yang muncul entah dari mana */
  .tanpa-cv { display: inline-block; height: 30px; line-height: 28px; padding: 0 10px; margin-right: 4px;
              border: 1px dashed #F3B94A; border-radius: 6px; background: #FFF6E6; color: #8a6d1e;
              font-size: 11px; font-weight: 600; vertical-align: top; }
  /* nowrap: kolom Action memuat sampai empat tombol, dan kalau dibiarkan
     membungkus, barisnya jadi setinggi 150px sementara baris lain 46px.
     Tabelnya sudah bisa digeser mendatar, jadi kolom lebar tidak masalah. */
  .aksi { display: flex; flex-wrap: nowrap; gap: 5px; justify-content: center; }
  .aksi form { margin: 0; display: inline; }

  .scroll-x { overflow-x: auto; border: 1px solid #cfd8e3; border-radius: 8px; }
  .alat { display: flex; gap: 8px; margin-bottom: 12px; }
  .b-pilih { background: #22A45D; color: #fff; }
  .b-undang { background: #fff; color: #1E88E5; border-color: #A8CFF5; }
  .avatar-s { width: 30px; height: 30px; border-radius: 50%; background: #F7941D; color: #fff;
              display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
  .foot { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; }
  .pager span { padding: 5px 9px; border: 1px solid #cfd8e3; border-radius: 6px; margin-right: 4px; font-size: 12px; cursor: pointer; }
  .pager .now { background: #1E88E5; color: #fff; border-color: #1E88E5; }
  .btn-dl { background: #22A45D; color: #fff; height: 36px; padding: 0 18px; font-size: 13px; line-height: 34px; }
  .rows select { border: none; border-radius: 8px; padding: 6px 8px; font-family: inherit; }
</style>
<script>
  // Alasan reschedule ditanyakan lewat prompt, bukan kotak teks di dalam sel:
  // input di dalam tabel membuat tinggi barisnya tidak rata dan kolom Action
  // jadi jauh lebih lebar daripada isinya.
  function alasanReschedule(tombol) {
      const alasan = prompt('Lepas jadwal ini? Kandidat akan diminta memilih slot lain.\n\nAlasan (boleh dikosongkan):');
      if (alasan === null) { return false; }
      tombol.form.alasan.value = alasan;

      return true;
  }
</script>
<?= $this->endSection() ?>

<?= $this->section('topbar') ?>
<span class="rows" style="display:flex;align-items:center;gap:6px;font-size:13px">Rows
  <select onchange="segera('Ubah Jumlah Baris')"><option>200</option><option>50</option></select>
</span>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<?php
$base = site_url('recruiter/tahap/' . $stage);
if (($stage ?? '') === 'upload_cv') {
    // Tidak ada keputusan lolos/gagal di tahap unggah - satu tab saja
    $tabs = ['uploaded' => ['Uploaded', '📥', $base]];
} else {
    if (($stage ?? '') === 'interview_online') {
        // Interview HRD punya alurnya sendiri: terjadwal -> (dilepas) -> selesai.
        // Tidak ada "Passed"/"Failed" di sini, karena interview yang terjadwal
        // belum lolos apa-apa dan jadwal yang dilepas bukan kandidat yang gugur.
        $tabs = [
            'progress'    => ['On Progress', '🔄', $base],
            'rescheduled' => ['Rescheduled', '🔁', $base . '?status=rescheduled'],
            'completed'   => ['Completed', '🏁', $base . '?status=completed'],
        ];
    } else {
        $tabs = [
            'progress' => ['On Progress', '🔄', $base],
            'passed'   => ['Passed', '✅', $base . '?status=passed'],
            'failed'   => ['Failed', '❌', $base . '?status=failed'],
        ];
    }
}
foreach ($tabs as $k => [$lbl, $ic, $url]): ?>
  <a href="<?= $url ?>" class="<?= $status === $k ? 'on' : '' ?>">
    <span class="l"><span><?= $ic ?></span><span><?= $lbl ?></span></span><span><?= $status === $k ? '»' : '' ?></span></a>
<?php endforeach ?>
<a href="#" onclick="segera('Settings');return false"><span class="l"><span>⚙️</span><span>Settings</span></span></a>
<a href="#" onclick="segera('Upload History');return false"><span class="l"><span>🕘</span><span>Upload History</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="alat">
  <button class="b-pilih" onclick="segera('Select All')">☑ Select All</button>
  <button class="b-undang" onclick="segera('Invite')">➤ Invite</button>
</div>

<div class="scroll-x">
  <table>
    <thead>
      <tr>
        <th>No</th><th>Select</th><th>Action</th><th>File</th><th>Summary</th><th>Remark</th>
        <th>Picture</th><th>FullName</th><th>Email</th><th>Company Code</th><th>Job Posting</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($daftar === []): ?>
      <tr><td colspan="11" style="color:#aaa;padding:26px">- Tidak ada kandidat pada tahap &amp; status ini -</td></tr>
    <?php else: ?>
      <?php foreach ($daftar as $i => $a): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><input type="checkbox" style="width:auto" onclick="segera('Pilih Kandidat')"></td>
          <td>
            <?php // Kolom Action berisi TINDAKAN tahap ini saja. Melihat kandidat
                  // pindah ke kolom Summary, mengikuti tabel BIPROO yang asli. ?>
            <div class="aksi">
              <?php if ($stage === 'interview_online' && $status === 'progress'): ?>
                <?php // Satu tombol, bukan tiga. Ruang interview sudah memuat tautan
                      // Zoom, tiga pertanyaan milik kandidat INI (bukan milik
                      // lowongan seperti dulu), dan tempat mengunggah rekamannya. ?>
                <?php // ?bingkai=1 membuat halamannya dirender tanpa topbar dan sidebar
                      // (layout_bingkai), karena keduanya sudah ada di halaman ini. ?>
                <a href="<?= site_url('recruiter/ruang/' . $a['id']) ?>?bingkai=1"
                   onclick="return bukaJendela(this.href, <?= esc(json_encode('Ruang Interview - ' . $a['nama']), 'attr') ?>)">
                  <button class="b-tanya">Ruang Interview</button></a>
                <?php // Melepas jadwal: slot kembali ke daftar, kandidat memilih ulang.
                      // Bukan menggugurkan kandidat, jadi warnanya netral bukan merah.
                      // Alasannya ditanyakan lewat prompt (lihat alasanReschedule). ?>
                <form method="post" action="<?= site_url('recruiter/interview/reschedule/' . $a['id']) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="alasan" maxlength="200">
                  <button class="b-warn" onclick="return alasanReschedule(this)">Reschedule</button>
                </form>
              <?php elseif ($stage === 'interview_online' && $status === 'rescheduled'): ?>
                <span style="font-size:11px;color:#a5771a">menunggu kandidat memilih slot baru</span>
              <?php elseif ($stage === 'interview_online' && $status === 'completed'): ?>
                <?php // Ruang interview ikut di sini, bukan cuma di On Progress:
                      // rekaman diunggah SESUDAH wawancara, dan saat itu kandidat
                      // sudah berpindah ke tab ini. ?>
                <a href="<?= site_url('recruiter/ruang/' . $a['id']) ?>?bingkai=1"
                   onclick="return bukaJendela(this.href, <?= esc(json_encode('Ruang Interview - ' . $a['nama']), 'attr') ?>)">
                  <button class="b-tanya">Ruang Interview</button></a>
                <?php if ($a['gate2'] === 'passed'): ?>
                  <span style="color:#1d6b3d;font-weight:700">✅ Lolos</span>
                <?php elseif ($a['gate2'] === 'failed'): ?>
                  <span style="color:#a12734;font-weight:700">❌ Tidak Lolos</span>
                <?php else: ?>
                  <?php // Belum diputus. Dua sebab, keduanya berarti datanya kurang:
                        // rekamannya belum diunggah (gate2 null), atau sudah tapi
                        // transkripsi/skor CV-nya tidak menghasilkan apa-apa (flagged).
                        //
                        // Yang normal BUKAN dua tombol ini: kandidat yang rekamannya
                        // sudah ditranskripsi diputuskan sistem sendiri dan langsung
                        // muncul sebagai Lolos / Tidak Lolos. Dua tombol ini jalan
                        // keluar, dan sengaja terlihat begitu. ?>
                  <form method="post" action="<?= site_url('recruiter/gate2/' . $a['id']) ?>">
                    <?= csrf_field() ?>
                    <span class="tanpa-cv" title="Sistem tidak memutus karena datanya kurang. Buka Ruang Interview untuk melihat sebabnya.">perlu keputusan</span>
                    <button name="keputusan" value="lolos" class="b-lolos"
                            onclick="return confirm('Loloskan kandidat ini? Kandidat akan dikabari via email.')">Loloskan</button>
                    <button name="keputusan" value="gagal" class="b-gagal"
                            onclick="return confirm('Tidak meloloskan kandidat ini? Kandidat akan dikabari via email.')">Tidak Lolos</button>
                  </form>
                <?php endif ?>
              <?php endif ?>
            </div>
          </td>
          <td>
            <?php // PDF dibuka di jendela pratinjau; DOCX tidak bisa dirender browser
                  // jadi tetap tautan biasa yang mengunduh.
                  $pdf = str_ends_with(strtolower($a['cv_path'] ?? ''), '.pdf'); ?>
            <a href="<?= site_url('recruiter/cv/' . $a['id']) ?>" target="_blank" rel="noopener"
               <?php if ($pdf): ?>onclick="return bukaJendela(this.href, <?= esc(json_encode('CV ' . $a['nama']), 'attr') ?>)"<?php endif ?>
               title="<?= $pdf ? 'Lihat CV ' : 'Unduh CV ' ?><?= esc($a['nama'], 'attr') ?>">
              <button class="b-file">CV</button>
            </a>
          </td>
          <td>
            <?php // Satu tombol, bukan dua: lembar profil SUDAH memuat biodata,
                  // riwayat kerja, hasil assessment, dan hasil interview jadi satu.
                  //
                  // target="_blank" tetap ditulis sebagai cadangan kalau JavaScript
                  // mati; selama hidup, onclick membukanya di jendela. ?>
            <a href="<?= site_url('recruiter/profil/' . $a['id']) ?>" target="_blank" rel="noopener"
               onclick="return bukaJendela(this.href, <?= esc(json_encode('Lembar Profil - ' . $a['nama']), 'attr') ?>)"
               title="Lembar profil <?= esc($a['nama'], 'attr') ?>">
              <button class="b-lihat">View</button></a>
          </td>
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
    </tbody>
  </table>
</div>

<div class="foot">
  <div class="pager"><span>«</span><span>‹</span><span class="now">1</span><span>›</span><span>»</span></div>
  <button class="btn-dl" onclick="segera('Download Excel')">⬇ Download <?= count($daftar) ?> Row(s)</button>
</div>

<?= $this->endSection() ?>
