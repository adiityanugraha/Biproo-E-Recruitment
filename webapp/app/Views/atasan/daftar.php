<?php
/**
 * Kandidat yang menunggu Interview User (19 Agustus 2026).
 *
 * Hanya lowongan milik akun ini - disaring di controller lewat job_id dari sesi,
 * bukan dari tautan. Yang tampil sudah lolos wawancara HRD; kandidat yang masih
 * diproses HRD sengaja tidak muncul, karena menampilkannya mengundang atasan
 * menilai orang yang belum tentu diteruskan kepadanya.
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title><?= esc($judul) ?> - E-REQ BIPROO</title>
<style>
  body { background: #F4F6FA; margin: 0; }
  .atas { background: #F7941D; color: #fff; padding: 14px 24px; display: flex;
          align-items: center; justify-content: space-between; gap: 14px; }
  .atas .kiri { font-weight: 700; font-size: 16px; }
  .atas .kiri small { display: block; font-weight: 400; font-size: 12px; opacity: .9; }
  .atas a { color: #fff; font-size: 13px; text-decoration: underline; }
  .isi { max-width: 1000px; margin: 22px auto; padding: 0 20px; }
  .kartu { background: #fff; border-radius: 12px; padding: 20px 22px; box-shadow: 0 3px 12px rgba(0,0,0,.04); }
  .pesan { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
  .pesan-sukses { background: #E8F7EE; color: #1d6b3d; }
  .pesan-error { background: #FFE9E3; color: #a53a1c; }

  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #eef0f5; padding: 10px 12px; font-size: 13px; text-align: left; }
  th { background: #FAFBFD; font-weight: 600; }
  .btn { padding: 6px 16px; border: none; border-radius: 7px; cursor: pointer;
         font-family: inherit; font-weight: 600; font-size: 12.5px; background: #2F6FED; color: #fff; }
  .sudah { font-weight: 700; font-size: 12.5px; }
  .s-passed { color: #1d6b3d; } .s-failed { color: #a12734; }
  .kosong { color: #999; padding: 26px 0; text-align: center; font-size: 13px; }
</style>
</head>
<body>

<div class="atas">
  <div class="kiri">
    Interview User
    <small><?= esc(session('atasan_posisi')) ?> &middot; <?= esc(session('atasan_nama')) ?></small>
  </div>
  <a href="<?= site_url('atasan/logout') ?>">Keluar</a>
</div>

<div class="isi">
  <?php if (session('sukses')): ?>
    <div class="pesan pesan-sukses">✅ <?= esc(session('sukses')) ?></div>
  <?php endif ?>
  <?php if (session('error')): ?>
    <div class="pesan pesan-error">⚠️ <?= esc(session('error')) ?></div>
  <?php endif ?>

  <div class="kartu">
    <h3 style="margin:0 0 14px;font-size:15px">Kandidat yang menunggu wawancara Anda</h3>

    <?php if ($daftar === []): ?>
      <p class="kosong">
        Belum ada kandidat yang sampai ke tahap ini.<br>
        Kandidat muncul di sini setelah lolos wawancara dengan tim HRD.
      </p>
    <?php else: ?>
      <table>
        <tr>
          <th style="width:46px">No</th>
          <th>Nama</th>
          <th>Email</th>
          <th style="width:190px">Tindakan</th>
        </tr>
        <?php foreach ($daftar as $i => $a): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= esc($a['nama']) ?></td>
            <td><?= esc($a['email']) ?></td>
            <td>
              <?php // Yang sudah diputus tidak menampilkan tombol menilai:
                    // keputusannya sudah dikirim ke kandidat lewat email dan
                    // tidak punya jalur pembatalan. ?>
              <?php if ($a['diputus'] === 'passed'): ?>
                <span class="sudah s-passed">✅ Diterima</span>
              <?php elseif ($a['diputus'] === 'failed'): ?>
                <span class="sudah s-failed">❌ Tidak diterima</span>
              <?php else: ?>
                <a href="<?= site_url('atasan/nilai/' . $a['id']) ?>">
                  <button class="btn">Wawancara &amp; Nilai</button></a>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </table>
    <?php endif ?>
  </div>
</div>

</body>
</html>
