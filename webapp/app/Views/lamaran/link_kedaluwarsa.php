<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Link Interview Tidak Aktif</h2>

  <?php if ($belum): ?>
    <div style="padding:14px;background:#FFF9EC;border:1px solid #F3B94A;border-radius:10px">
      <b style="color:#a5771a">Belum waktunya</b>
      <p style="margin:8px 0 0">Link interview untuk posisi <b><?= esc($judul) ?></b> baru bisa dibuka
        <b><?= esc($bukaMenit) ?> menit</b> sebelum jadwal.</p>
      <p style="margin:8px 0 0">Jadwal Anda: <b><?= esc(date('d M Y, H:i', strtotime($jadwal))) ?> WIB</b></p>
    </div>
  <?php else: ?>
    <div style="padding:14px;background:#FDECEC;border:1px solid #E23B4E;border-radius:10px">
      <b style="color:#a12734">Link sudah kedaluwarsa</b>
      <p style="margin:8px 0 0">Jadwal interview untuk posisi <b><?= esc($judul) ?></b> sudah terlewat, jadi
        link ini tidak bisa dipakai lagi.</p>
      <p style="margin:8px 0 0">Jadwal Anda: <b><?= esc(date('d M Y, H:i', strtotime($jadwal))) ?> WIB</b></p>
      <p style="margin:8px 0 0;font-size:14px">Bila Anda terlewat mengikuti sesi, hubungi tim rekrutmen untuk
        pengaturan ulang.</p>
    </div>
  <?php endif ?>

  <p class="tautan" style="margin-top:14px">
    <a href="<?= site_url('jadwal') ?>" style="color:#2F6FED">Kembali ke halaman jadwal</a>
  </p>
</div>

<?= $this->endSection() ?>
