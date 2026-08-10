<?php
/**
 * Gaya untuk ISI halaman: kartu, tabel, lencana, tombol, pesan flash.
 *
 * Dipisah dari layout.php supaya layout_bingkai bisa memakainya juga. Halaman
 * seperti review kandidat dirender di dua tempat - sebagai halaman penuh dan di
 * dalam jendela pratinjau - dan tanpa berkas ini yang di dalam jendela tampil
 * tanpa gaya sama sekali.
 *
 * Yang TIDAK masuk sini: gaya cangkang (topbar, sidebar, banner, stat, stepper).
 * Semua itu cuma ada di halaman penuh.
 */
?>
<style>
  .kartu { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 4px 14px rgba(0,0,0,.05); margin-bottom: 18px; }
  h2 { margin-top: 0; font-size: 19px; }
  label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
  input, select { width: 100%; padding: 10px 12px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 14px; font-family: inherit; }
  button { margin-top: 18px; padding: 12px 20px; border: none; border-radius: 10px; cursor: pointer;
           background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; font-size: 15px; font-weight: 700; font-family: inherit; }
  .pesan-sukses { background: #E8F7EE; border: 1px solid #2E9E5B; color: #1d6b3d; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .pesan-error { background: #FDECEC; border: 1px solid #E23B4E; color: #a12734; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .tautan { font-size: 14px; text-align: center; margin-top: 14px; }
  .tautan a { color: #2F6FED; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid #eef0f5; }
  th { color: #8a6d1e; background: #FFF6E6; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .badge-lolos { background: #E8F7EE; color: #1d6b3d; }
  .badge-gagal { background: #FDECEC; color: #a12734; }
  .badge-flag { background: #FFF6E6; color: #a5771a; border: 1px solid #F3B94A; }
  .badge-netral { background: #F2F4F8; color: #555; }
</style>
