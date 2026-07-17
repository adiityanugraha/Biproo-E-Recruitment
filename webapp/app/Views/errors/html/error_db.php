<!DOCTYPE html>
<!-- Statis penuh, tanpa helper CI4: dirender justru saat DB down, jadi tak boleh
     bergantung pada apa pun yang bisa ikut gagal. -->
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Layanan Sedang Gangguan - E-REQ BIPROO</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
         font-family: system-ui, 'Segoe UI', sans-serif; background: #F2F4F8; color: #2B2B2B; padding: 20px; }
  .box { background: #fff; border-radius: 16px; padding: 40px; max-width: 440px; text-align: center;
         box-shadow: 0 10px 30px rgba(0,0,0,.08); border-top: 6px solid #F7941D; }
  .ic { font-size: 52px; }
  h1 { font-size: 22px; margin: 14px 0 8px; }
  p { color: #666; font-size: 15px; line-height: 1.6; margin: 0 0 6px; }
  .small { font-size: 13px; color: #999; margin-top: 18px; }
</style>
</head>
<body>
  <div class="box">
    <div class="ic">🔌</div>
    <h1>Layanan sedang gangguan</h1>
    <p>Sistem sedang tidak dapat terhubung ke basis data. Ini masalah sementara di sisi kami, bukan dari Anda.</p>
    <p>Silakan coba lagi beberapa saat lagi.</p>
    <p class="small">E-REQ BIPROO</p>
  </div>
</body>
</html>
