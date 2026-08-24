<?php use App\Controllers\Lamaran; ?>
<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<style>
  .saran { margin-top: 8px; border: 1px solid #FFD9A0; background: #FFF9EF;
           border-radius: 8px; padding: 8px 12px; }
  .saran summary { cursor: pointer; font-weight: 600; color: #8a6d1e; font-size: 13px; }
  .saran .ket { color: #6b5626; font-size: 12px; line-height: 1.6; margin: 8px 0 4px; }
  .saran ol { margin: 4px 0 10px; padding-left: 20px; color: #333; }
  .saran li { margin-bottom: 3px; }
  .saran button { padding: 6px 14px; font-size: 12.5px; }
</style>

<?php if ($aktif === null): ?>
<div class="kartu">
  <h2>Status Lamaran</h2>
  <p>Belum ada lamaran. <a href="<?= site_url('lamar') ?>" style="color:#2F6FED">Lamar posisi sekarang</a>.</p>
</div>
<?php else: ?>

<div class="kartu">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Status Lamaran</h2>
    <?php if (count($apps) > 1): ?>
      <select style="width:auto;margin-left:auto" onchange="location.href='<?= site_url('status') ?>?app='+this.value">
        <?php foreach ($apps as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $a['id'] === $aktif['id'] ? 'selected' : '' ?>><?= esc($a['judul']) ?></option>
        <?php endforeach ?>
      </select>
    <?php endif ?>
  </div>
  <p style="color:#666;font-size:14px;margin:6px 0 0">
    <?php if (count($apps) > 1): ?>
      Anda melamar <?= count($apps) ?> posisi. Riwayat di bawah ini khusus untuk posisi yang dipilih.
    <?php else: ?>
      Riwayat setiap tahap seleksi untuk lamaran Anda.
    <?php endif ?>
  </p>
</div>

<div class="kartu">
  <h2><?= esc($aktif['judul']) ?></h2>
  <p style="color:#666;font-size:13px;margin-top:-8px">Dilamar <?= esc(substr($aktif['created_at'], 0, 10)) ?></p>

  <table>
    <tr><th>Tahap</th><th>Status</th><th>Catatan</th><th>Waktu</th></tr>
    <?php foreach ($aktif['riwayat'] as $r): ?>
      <tr>
        <td><?= esc(Lamaran::STAGE_LABEL[$r['stage']] ?? $r['stage']) ?></td>
        <td><?= badge_status($r['status']) ?></td>
        <td style="color:#444;font-size:13px">
          <?= esc((string) $r['note']) ?>
          <?php
            /*
             * Tombolnya menempel pada baris penolakan, bukan berdiri sebagai
             * kartu tersendiri di bawah tabel: yang membacanya baru saja
             * membaca kalimat "belum dapat melanjutkan", dan di situlah
             * pertanyaan "lalu saya harus apa" muncul.
             *
             * <details> - bukan tombol berJavaScript. Ia bawaan peramban,
             * bekerja tanpa satu baris skrip pun, dan tetap terbuka-tutup di
             * peramban lama maupun pembaca layar.
             */
            $tampilkanSaran = $r['stage'] === 'gate_2' && $r['status'] === 'failed'
                && ($aktif['saran'] ?? []) !== [];
          ?>
          <?php if ($tampilkanSaran): ?>
            <details class="saran">
              <summary>Lihat posisi lain yang mungkin cocok</summary>
              <p class="ket">
                Dipilih dari pengalaman kerja di CV Anda. Ini <b>saran</b>, bukan jaminan
                diterima - Anda tetap melamar seperti biasa.
              </p>
              <ol>
                <?php foreach ($aktif['saran'] as $sr): ?>
                  <li><?= esc((string) ($sr['judul'] ?? '')) ?></li>
                <?php endforeach ?>
              </ol>
              <a href="<?= site_url('lamar') ?>"><button type="button">Lamar posisi lain</button></a>
            </details>
          <?php endif ?>
        </td>
        <td style="color:#666"><?= esc(substr($r['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach ?>
  </table>

  <?php if ($aktif['bisa_assessment']): ?>
    <a href="<?= site_url('assessment/' . $aktif['id']) ?>">
      <button type="button" style="margin-top:14px">Kerjakan Assessment</button>
    </a>
  <?php endif ?>
</div>

<?php endif ?>

<?= $this->endSection() ?>
