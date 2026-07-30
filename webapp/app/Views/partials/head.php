<?php
/**
 * Isi <head> yang sama di semua layout: meta, font Poppins, reset dan gaya dasar.
 *
 * Sengaja TIDAK memuat <title> maupun warna latar body - keduanya berbeda per
 * layout, dan menitipkannya lewat variabel cuma menambah kaitan tanpa faedah.
 * Tiap layout menulis <title> sendiri dan menimpa body{background} bila perlu.
 */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Poppins', system-ui, sans-serif; color: #2B2B2B; }
  a { text-decoration: none; }
</style>
