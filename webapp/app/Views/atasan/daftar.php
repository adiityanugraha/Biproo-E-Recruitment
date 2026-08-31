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

  /* Tata letak tabel disamakan dengan tabel tahap di dashboard recruiter,
     yang mengikuti halaman Interview HRD BIPROO yang asli: kepala biru, garis
     kisi penuh, isi rata tengah. Kolom dan isinya tidak berubah. */
  table { white-space: nowrap; border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #cfd8e3; padding: 7px 12px; font-size: 13px;
           text-align: center; vertical-align: middle; }
  th { background: #1E88E5; color: #fff; font-weight: 700; font-size: 12px;
       letter-spacing: .2px; border-color: #1877cc; }
  tbody tr:hover td { background: #f7fafd; }
  .scroll-x { overflow-x: auto; border: 1px solid #cfd8e3; border-radius: 8px; }

  /* Semua tombol dalam sel bertinggi sama, supaya barisnya rata. */
  .btn { padding: 0 14px; height: 30px; border: 1px solid transparent; border-radius: 6px;
         cursor: pointer; font-family: inherit; font-weight: 600; font-size: 12px;
         line-height: 28px; background: #1E88E5; color: #fff; }
  .b-file { background: #E8F2FE; color: #1E88E5; border-color: #A8CFF5; }
  .b-zoom { background: #2D8CFF; color: #fff; }
  /* nowrap: kolom Tindakan memuat sampai tiga tombol, dan kalau dibiarkan
     membungkus, barisnya jadi jauh lebih tinggi daripada baris lain. */
  .aksi { display: flex; flex-wrap: nowrap; gap: 5px; justify-content: center; }
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
      <div class="scroll-x">
      <table>
        <tr>
          <th style="width:46px">No</th>
          <th>Nama</th>
          <th>Email</th>
          <th style="width:180px">Jadwal</th>
          <th style="width:290px">Tindakan</th>
        </tr>
        <?php foreach ($daftar as $i => $a): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= esc($a['nama']) ?></td>
            <td><?= esc($a['email']) ?></td>
            <td>
              <?php if (($a['jadwal']['scheduled_at'] ?? null) !== null): ?>
                <?= esc(date('d M Y, H:i', strtotime($a['jadwal']['scheduled_at']))) ?> WIB
              <?php else: ?>
                <span style="color:#a5771a">menunggu kandidat memilih</span>
              <?php endif ?>
            </td>
            <td>
              <div class="aksi">
              <?php /*
                * CV dibuka di tab baru, bukan menggantikan halaman ini: atasan
                * membacanya sambil menyiapkan pertanyaan, lalu kembali ke sini
                * untuk masuk Zoom. Menutup daftar kandidat di tengah persiapan
                * cuma memaksanya menekan tombol kembali.
                */ ?>
              <a href="<?= site_url('atasan/cv/' . $a['id']) ?>" target="_blank" rel="noopener">
                <button class="btn b-file">CV</button></a>
              <?php if (! empty($a['jadwal']['join_url'])): ?>
                <a href="<?= esc($a['jadwal']['join_url'], 'attr') ?>" target="_blank" rel="noopener">
                  <button class="btn b-zoom">Zoom</button></a>
              <?php endif ?>
              <?php // Yang sudah diputus tidak menampilkan tombol menilai:
                    // keputusannya sudah dikirim ke kandidat lewat email dan
                    // tidak punya jalur pembatalan. ?>
              <?php if ($a['diputus'] === 'passed'): ?>
                <span class="badge badge-lolos">Diterima</span>
              <?php elseif ($a['diputus'] === 'failed'): ?>
                <span class="badge badge-gagal">Tidak diterima</span>
              <?php else: ?>
                <a href="<?= site_url('atasan/nilai/' . $a['id']) ?>">
                  <button class="btn">Wawancara &amp; Nilai</button></a>
              <?php endif ?>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
      </table>
      </div>
    <?php endif ?>
  </div>
</div>

</body>
</html>
