<?php
// Modal "segera hadir" bersama untuk semua layout (kandidat, report, stage).
// Self-contained: style + markup + script jadi satu, tombol digaya eksplisit
// supaya konsisten meski tiap layout punya style <button> default berbeda.
?>
<style>
  #segeraModal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 100; }
  #segeraModal .box { background: #fff; border-radius: 16px; padding: 30px; max-width: 340px; text-align: center; }
  #segeraModal .ic { font-size: 40px; }
  #segeraModal h3 { margin: 10px 0 6px; }
  #segeraModal p { color: #666; font-size: 14px; margin: 0 0 16px; }
  /* height & line-height WAJIB disebut, bukan dibiarkan mewarisi: halaman tabel
     tahap menyetel button { height: 30px; line-height: 28px }, dan karena aturan
     di bawah ini tidak menyebutnya, tinggi 30px itu ikut terpakai sementara
     padding dan fontnya lebih besar - tulisan "Mengerti" jadi terpotong. */
  #segeraModal button { margin: 0; padding: 10px 20px; height: auto; line-height: normal;
                        border: none; border-radius: 10px; cursor: pointer; font-family: inherit;
                        font-weight: 700; font-size: 14px; background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; }
</style>
<div id="segeraModal" onclick="if(event.target===this)tutupSegera()">
  <div class="box">
    <div class="ic">🚧</div>
    <h3 id="segeraNama">Fitur ini</h3>
    <p>Segera hadir - fitur ini masih dalam pengembangan.</p>
    <button onclick="tutupSegera()">Mengerti</button>
  </div>
</div>
<script>
  function segera(nama){ document.getElementById('segeraNama').textContent = nama || 'Fitur ini'; document.getElementById('segeraModal').style.display = 'flex'; }
  function tutupSegera(){ document.getElementById('segeraModal').style.display = 'none'; }
</script>
