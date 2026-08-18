<?php
/**
 * Halaman antara: menutup jendela pratinjau lalu menyegarkan halaman induknya.
 *
 * Dipakai sesudah form di dalam bingkai berhasil disimpan. Tanpa ini, redirect
 * sesudah simpan mendarat DI DALAM bingkai - recruiter melihat daftar posisi
 * terjepit di jendela kecil dan harus menutupnya sendiri, sementara daftar di
 * belakangnya masih menampilkan alur yang lama.
 *
 * Menyegarkan induknya sudah sekaligus menutup jendelanya: seluruh halaman
 * termasuk elemen <dialog>-nya dimuat ulang. Jadi satu perintah, bukan dua yang
 * harus dijaga urutannya.
 *
 * Pesan suksesnya sengaja TIDAK ditampilkan di sini - halaman ini cuma terlihat
 * sepersekian detik. Controller menahannya (keepFlashdata) supaya muncul di
 * daftar posisi setelah tersegarkan, tempat recruiter benar-benar membacanya.
 */
$tujuan = site_url('recruiter/pengaturan');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Menyimpan...</title>
<style>
  body { font-family: system-ui, sans-serif; background: #fff; color: #888;
         font-size: 13px; padding: 24px; }
</style>
</head>
<body>
<p>Tersimpan. Menutup jendela...</p>

<?php // Jalan keluar bila JavaScript mati atau halaman ini dibuka langsung:
      // tautan biasa ke daftar posisi, bukan layar buntu. ?>
<p><a href="<?= esc($tujuan, 'attr') ?>">Kembali ke daftar posisi</a></p>

<script>
  if (window.parent && window.parent !== window) {
      window.parent.location.reload();
  } else {
      window.location.replace(<?= json_encode($tujuan) ?>);
  }
</script>
</body>
</html>
