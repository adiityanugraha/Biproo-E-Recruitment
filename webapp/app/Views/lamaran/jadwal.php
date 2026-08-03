<?php use App\Models\InterviewModel; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<?php
// Judul halaman mengikuti keadaan kandidat: selama masih mengajukan, ini halaman
// pendaftaran. Begitu ada ruang Zoom yang benar-benar bisa dimasuki, tidak ada
// lagi yang perlu didaftarkan - yang dibutuhkan kandidat cuma jalan masuknya.
$adaSesiTerbuka = (bool) array_filter($apps, static fn (array $a): bool => $a['link_aktif']);
?>
<div class="kartu">
  <?php if ($adaSesiTerbuka): ?>
    <h2>Penjadwalan Berhasil</h2>
    <p style="color:#666;font-size:14px;margin-top:-6px">Jadwal interview Anda sudah disetujui recruiter dan ruang Zoom-nya sudah dibuka. Silakan masuk lewat tombol di bawah.</p>
  <?php else: ?>
    <h2>Pilih Jadwal Interview</h2>
    <p style="color:#666;font-size:14px;margin-top:-6px">Pilih slot interview untuk lamaran yang sudah lolos Tahap 1.
      Slot yang Anda pilih langsung terkunci dan ruang Zoom dibuatkan saat itu juga.</p>
  <?php endif ?>
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
        <b style="color:#1d6b3d">✅ Jadwal terkunci - Interview dijadwalkan</b>
        <p style="margin:8px 0 0">🗓️ <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b></p>
        <?php if ($app['link_aktif']): ?>
          <?php
          // Batas tutup dihitung dari konstanta yang sama dengan penjaganya,
          // jadi keterangan ini tidak bisa melenceng dari perilaku sebenarnya.
          $tutup = date('H:i', strtotime($iv['scheduled_at']) + InterviewModel::TUTUP_MENIT * 60);
          ?>
          <p style="margin:10px 0 0;font-size:13px;color:#1d6b3d">🎥 <b>Ruang interview sudah dibuka.</b>
            Tautan ini berlaku sampai <b><?= esc($tutup) ?> WIB</b>, setelah itu tidak bisa dipakai lagi.</p>
          <p style="margin:6px 0 0;font-size:13px;color:#666">Masuk memakai nama lengkap Anda, pastikan kamera dan mikrofon berfungsi.
            Tautan ini khusus untuk Anda dan tidak berguna bila diteruskan ke orang lain.</p>
          <a href="<?= site_url('interview/masuk/' . $app['id']) ?>" target="_blank" rel="noopener">
            <button type="button" style="margin-top:12px">Gabung via Zoom</button>
          </a>
        <?php else: ?>
          <p style="margin:10px 0 0;font-size:13px;color:#666">Link Zoom aktif mulai 15 menit sebelum jadwal,
            dan mati 30 menit setelah jam mulai (durasi satu sesi).</p>
        <?php endif ?>
      </div>

    <?php elseif ($iv && $iv['status'] === 'requested'): ?>
      <div style="padding:14px;background:#FFF9EC;border:1px solid #F3B94A;border-radius:10px">
        <b style="color:#a5771a">⏳ Menunggu persetujuan recruiter</b>
        <p style="margin:8px 0 0">Jadwal yang Anda ajukan: <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b></p>
      </div>

    <?php else: ?>
      <?php if ($iv && $iv['status'] === 'rescheduled'): ?>
        <?php // Warna kuning, bukan merah: kandidat TIDAK gugur, hanya jamnya
              // berubah. Merah akan membuatnya mengira lamarannya berakhir. ?>
        <div style="padding:12px 14px;background:#FFF9EC;border:1px solid #F3B94A;border-radius:10px;margin-bottom:14px">
          <b style="color:#a5771a">🔁 Jadwal perlu diatur ulang</b>
          <p style="margin:6px 0 0;font-size:14px">Jadwal <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b>
            dilepas oleh recruiter. <b>Lamaran Anda tetap berjalan</b> - silakan pilih slot lain di bawah ini.</p>
        </div>
      <?php elseif ($iv && $iv['status'] === 'rejected'): ?>
        <div style="padding:12px 14px;background:#FDECEC;border:1px solid #E23B4E;border-radius:10px;margin-bottom:14px">
          <b style="color:#a12734">❌ Ajuan ditolak recruiter</b>
          <p style="margin:6px 0 0;font-size:14px">Jadwal <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b> tidak disetujui. Silakan pilih jadwal lain di bawah ini.</p>
        </div>
      <?php endif ?>

      <?php $adaSlot = false; foreach ($slot as $isi) { foreach ($isi as $s) { if (! $s['terpakai']) { $adaSlot = true; break 2; } } } ?>

      <?php if (! $adaSlot): ?>
        <p style="color:#666;font-size:14px">Semua slot dalam 7 hari kerja ke depan sudah terisi. Silakan cek lagi besok.</p>
      <?php else: ?>
        <p style="color:#666;font-size:14px;margin:0 0 10px">Pilih satu slot. Sesi berlangsung 30 menit, dan slot yang sudah
          dipilih kandidat lain tidak bisa diambil lagi.</p>

        <form method="post" action="<?= site_url('interview/ajukan/' . $app['id']) ?>">
          <?= csrf_field() ?>
          <?php foreach ($slot as $tanggal => $jamJam): ?>
            <div style="margin-bottom:12px">
              <div style="font-size:13px;font-weight:600;margin-bottom:6px">
                <?= esc(date('l, d M Y', strtotime($tanggal))) ?>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach ($jamJam as $s): ?>
                  <?php if ($s['terpakai']): ?>
                    <span title="Sudah dipilih kandidat lain"
                          style="padding:8px 14px;border:1px solid #eef0f5;border-radius:10px;background:#f4f4f6;color:#aaa;font-size:13px;text-decoration:line-through">
                      <?= esc($s['jam']) ?>
                    </span>
                  <?php else: ?>
                    <label style="padding:8px 14px;border:1px solid #e2e6ee;border-radius:10px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px">
                      <?php // esc() konteks html, bukan 'attr': nilainya cuma angka, strip,
                            // titik dua, dan spasi. 'attr' mengubahnya jadi &#x20;&#x3A;
                            // sehingga HTML-nya tidak terbaca tanpa menambah keamanan ?>
                      <input type="radio" name="jadwal" value="<?= esc($s['waktu']) ?>" required style="width:auto;margin:0">
                      <?= esc($s['jam']) ?>
                    </label>
                  <?php endif ?>
                <?php endforeach ?>
              </div>
            </div>
          <?php endforeach ?>
          <button style="margin-top:6px">Kunci Jadwal Ini</button>
        </form>
      <?php endif ?>
    <?php endif ?>
  </div>
<?php endforeach ?>

<?= $this->endSection() ?>
