<?php
/**
 * Lencana status - satu bentuk untuk seluruh aplikasi.
 *
 * Ditaruh di <head> lewat partials/head, BUKAN di partials/gaya_isi: gaya_isi
 * cuma dipakai layout kandidat, sementara halaman recruiter memanggil
 * badge_status() juga. Akibatnya Peringkat Kandidat dan Semua Kandidat
 * mengeluarkan lencana sebagai teks polos - kelasnya menempel, aturannya tidak
 * pernah ikut. Di sini ia sampai ke semua layout sekaligus.
 *
 * TANPA IKON dengan sengaja. Kata "Lolos" dan "Tidak Lolos" sudah berbeda
 * sendiri, jadi warnanya menegaskan alih-alih jadi satu-satunya pembeda, dan
 * tidak ada emoji yang berganti bentuk tiap sistem operasi.
 */
?>
<style>
  .badge { display: inline-block; padding: 3px 12px; border-radius: 999px;
           font-size: 12px; font-weight: 600; line-height: 1.6; white-space: nowrap; }
  .badge-lolos  { background: #E8F7EE; color: #1d6b3d; }
  .badge-gagal  { background: #FDECEC; color: #a12734; }
  .badge-flag   { background: #FFF6E6; color: #a5771a; border: 1px solid #F3B94A; }
  .badge-netral { background: #F2F4F8; color: #555; }

  /* Di dalam baris tombol, lencananya setinggi tombol supaya barisnya rata. */
  .aksi .badge { height: 30px; line-height: 24px; padding: 3px 14px; }
</style>
