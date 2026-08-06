<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2><?= esc($app['nama']) ?> - <?= esc($app['judul']) ?></h2>
  <p style="color:#666;font-size:13px;margin-top:-8px"><?= esc($app['email']) ?></p>

  <?php
    // $llmMati tetap dipakai, tapi hanya untuk menerangkan kotak riwayat kerja
    // di bawah. Penanda "pembacaan kasar" pada skor dihapus atas permintaan
    // 4 Agustus 2026. Flag llm_gagal / llm_json_invalid tetap tersimpan di
    // flags_json, jadi keputusan ini bisa dibalik tanpa kehilangan data.
    $bukti   = $bukti ?? ['riwayat' => [], 'flags' => []];
    $llmMati = (bool) array_intersect(['llm_gagal', 'llm_json_invalid'], $bukti['flags']);
  ?>
  <p style="margin:14px 0 4px">Kemiripan CV terhadap lowongan: <?= badge_skor($skorCv ?? null) ?></p>
  <p style="color:#888;font-size:12px;margin:0 0 12px">Skor ini tidak menentukan Tahap 1 - ia dipakai
    bersama skor interview untuk keputusan akhir di Tahap 2.</p>

  <p style="margin:0 0 16px">
    <a href="<?= site_url('recruiter/cv/' . $app['id']) ?>" target="_blank" rel="noopener">
      <button type="button" style="background:#DCE9FF;color:#2F6FED">📄 Buka CV Kandidat</button>
    </a>
    <small style="color:#888;margin-left:8px">terbuka di tab baru</small>
  </p>

  <?php
    // Bukti pengalaman: penyeimbang skor kemiripan. Skor tinggi bisa datang dari
    // CV yang cuma menyalin kata iklan lowongan; yang membedakannya dari CV asli
    // adalah ada tidaknya nama tempat kerja dan rentang waktu.
    $tanpaRiwayat = in_array('tanpa_riwayat_kerja', $bukti['flags'], true);
  ?>
  <?php if ($tanpaRiwayat): ?>
    <div style="border:1px solid #F3B94A;background:#FFF6E6;border-radius:6px;padding:12px 14px;margin-bottom:14px">
      <b style="color:#8A5D00">⚠ Tidak ada riwayat kerja terbaca</b>
      <p style="font-size:13px;margin:6px 0 0;color:#6b5320">
        Tidak ada satu pun posisi di CV ini yang disertai nama tempat kerja atau rentang waktu.
        Dua kemungkinan: pelamar memang belum pernah bekerja, atau CV-nya menyalin kata dari
        iklan lowongan sehingga skor kemiripan tinggi tanpa pengalaman nyata di baliknya.
        Skornya tidak diturunkan - mohon buka CV-nya untuk memastikan yang mana.
      </p>
    </div>
  <?php endif ?>

  <p style="margin:14px 0 6px"><b>Riwayat kerja terbaca</b>
    <small style="color:#888;font-weight:normal">diambil dari CV, bukan penilaian sistem</small></p>
  <?php if ($bukti['riwayat'] === []): ?>
    <p style="color:#888;font-size:13px;margin:0 0 16px">
      <?php if ($tanpaRiwayat): ?>
        Tidak ada posisi dengan tempat kerja atau periode yang bisa dibaca.
      <?php elseif ($llmMati): ?>
        Tidak terbaca karena pembaca CV berbasis AI sedang tidak dapat dihubungi -
        bukan berarti kandidat ini tanpa pengalaman kerja.
      <?php else: ?>
        Belum ada data - screening CV belum selesai atau CV tidak memuat riwayat kerja.
      <?php endif ?>
    </p>
  <?php else: ?>
    <table style="margin-bottom:16px">
      <tr><th>Jabatan</th><th>Tempat</th><th>Periode</th></tr>
      <?php foreach ($bukti['riwayat'] as $r): ?>
        <tr>
          <td><?= esc($r['jabatan'] ?? '') ?: '<small style="color:#999">-</small>' ?></td>
          <td><?= esc($r['perusahaan'] ?? '') ?: '<small style="color:#999">-</small>' ?></td>
          <td><?= esc($r['periode'] ?? '') ?: '<small style="color:#999">-</small>' ?></td>
        </tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>

  <table>
    <tr><th>Tahap</th><th>Status</th><th>Catatan</th><th>Oleh</th><th>Waktu</th></tr>
    <?php foreach ($riwayat as $r): ?>
      <tr>
        <td><?= esc(Lamaran::STAGE_LABEL[$r['stage']] ?? $r['stage']) ?></td>
        <td><?= badge_status($r['status']) ?></td>
        <td><small><?= esc($r['note']) ?></small></td>
        <td><small><?= esc($r['actor']) ?></small></td>
        <td style="color:#666"><small><?= esc(substr($r['created_at'], 0, 16)) ?></small></td>
      </tr>
    <?php endforeach ?>
  </table>
</div>

<?php if ($flagged): ?>
<div class="kartu" style="border:1px solid #F3B94A;background:#FFF6E6">
  <h2>Keputusan Review</h2>
  <p style="font-size:14px">Kandidat ini menunggu keputusan manusia - sistem tidak memutus otomatis.
  Keputusan ada di tangan Anda dan akan tercatat atas nama Anda di riwayat.</p>
  <form method="post" action="<?= site_url('recruiter/review/' . $app['id']) ?>" style="display:flex;gap:12px">
    <?= csrf_field() ?>
    <button type="submit" name="keputusan" value="approve" style="background:#2E9E5B">Loloskan</button>
    <button type="submit" name="keputusan" value="reject" style="background:#E23B4E">Tidak Lolos</button>
  </form>
</div>
<?php endif ?>

<p class="tautan"><a href="<?= site_url('recruiter') ?>">Kembali ke dashboard</a></p>

<?= $this->endSection() ?>
