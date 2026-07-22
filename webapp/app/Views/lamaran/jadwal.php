<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Pendaftaran Jadwal Interview</h2>
  <p style="color:#666;font-size:14px;margin-top:-6px">Ajukan jadwal interview untuk lamaran yang sudah lolos Tahap 1. Recruiter akan menyetujui atau menolak ajuan Anda.</p>
</div>

<?php if ($apps === []): ?>
  <div class="kartu">
    <p>Belum ada lamaran yang lolos Tahap 1. Halaman ini akan aktif setelah Anda dinyatakan lolos.</p>
    <p class="tautan"><a href="<?= site_url('status') ?>" style="color:#2F6FED">Lihat status lamaran</a></p>
  </div>
<?php endif ?>

<?php foreach ($apps as $app): ?>
  <?php $iv = $app['interview']; ?>
  <div class="kartu">
    <h2 style="font-size:17px"><?= esc($app['judul']) ?></h2>

    <?php if ($iv && $iv['status'] === 'approved'): ?>
      <div style="padding:14px;background:#F3FBF5;border:1px solid #2E9E5B;border-radius:10px">
        <b style="color:#1d6b3d">✅ Ajuan diterima - Interview dijadwalkan</b>
        <p style="margin:8px 0 0">🗓️ <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b></p>
        <a href="<?= esc($iv['join_url'], 'attr') ?>" target="_blank" rel="noopener">
          <button type="button" style="margin-top:12px">Gabung via Zoom</button>
        </a>
      </div>

    <?php elseif ($iv && $iv['status'] === 'requested'): ?>
      <div style="padding:14px;background:#FFF9EC;border:1px solid #F3B94A;border-radius:10px">
        <b style="color:#a5771a">⏳ Menunggu persetujuan recruiter</b>
        <p style="margin:8px 0 0">Jadwal yang Anda ajukan: <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b></p>
      </div>

    <?php else: ?>
      <?php if ($iv && $iv['status'] === 'rejected'): ?>
        <div style="padding:12px 14px;background:#FDECEC;border:1px solid #E23B4E;border-radius:10px;margin-bottom:14px">
          <b style="color:#a12734">❌ Ajuan ditolak recruiter</b>
          <p style="margin:6px 0 0;font-size:14px">Jadwal <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b> tidak disetujui. Silakan ajukan jadwal lain di bawah ini.</p>
        </div>
      <?php endif ?>

      <form method="post" action="<?= site_url('interview/ajukan/' . $app['id']) ?>">
        <?= csrf_field() ?>
        <label>Pilih tanggal &amp; jam interview</label>
        <input type="datetime-local" name="jadwal" required>
        <button style="margin-top:14px">Ajukan Jadwal</button>
      </form>
    <?php endif ?>
  </div>
<?php endforeach ?>

<?= $this->endSection() ?>
