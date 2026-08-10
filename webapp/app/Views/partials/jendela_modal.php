<?php
/**
 * Jendela pratinjau, mengikuti BIPROO asli: isi dibuka di atas tabel, bukan di
 * tab baru atau halaman lain, supaya recruiter tidak kehilangan posisi daftarnya.
 *
 * Dipakai dua tempat:
 *   - CV kandidat (PDF, dirender penampil PDF bawaan browser)
 *   - Pertanyaan interview (halaman form, memakai layout_bingkai)
 *
 * Memakai <dialog> bawaan browser, bukan div + overlay buatan sendiri: latar
 * gelap, tumpukan di atas segalanya, dan fokus yang terkurung di dalam jendela
 * sudah disediakan platform.
 */
?>
<dialog id="jendelaModal" class="jendela">
  <div class="jd-kepala">
    <span id="jendelaJudul"></span>
    <button type="button" class="jd-tutup" onclick="tutupJendela()">Close</button>
  </div>
  <iframe id="jendelaBingkai" title="Pratinjau"></iframe>
</dialog>

<style>
  .jendela { width: min(1000px, 92vw); height: min(88vh, 900px); padding: 0; border: none;
             border-radius: 12px; overflow: hidden; }
  .jendela::backdrop { background: rgba(0, 0, 0, .55); }
  .jendela .jd-kepala { display: flex; align-items: center; justify-content: space-between;
                        gap: 12px; padding: 10px 14px; background: #1E88E5; color: #fff;
                        font-weight: 600; font-size: 14px; }
  /* margin & line-height sengaja disebut: partial ini dipakai di dua layout yang
     punya aturan `button { ... }` sendiri (layout.php memberi margin-top 18px,
     tahap.php memberi line-height 28px). Properti yang tidak disebut di sini
     akan diwarisi dari sana dan merusak tata letaknya. */
  .jendela .jd-tutup { background: #F5B301; color: #5a3d00; border: none; border-radius: 6px;
                       margin: 0; height: 30px; padding: 0 18px; line-height: normal;
                       font-family: inherit; font-weight: 600; font-size: 12px; cursor: pointer; }
  /* iframe mengisi sisa tinggi jendela; 50px = tinggi baris kepala */
  .jendela iframe { width: 100%; height: calc(100% - 50px); border: none; display: block; background: #525659; }
</style>

<script>
  // Dipanggil dari onclick tautan. Mengembalikan false supaya tautannya tidak
  // ikut berpindah halaman; kalau JavaScript mati, href-nya tetap bekerja.
  function bukaJendela(url, judul) {
      document.getElementById('jendelaJudul').textContent = judul;
      document.getElementById('jendelaBingkai').src = url;
      document.getElementById('jendelaModal').showModal();

      return false;
  }

  function tutupJendela() {
      document.getElementById('jendelaModal').close();
      // src dikosongkan supaya isinya benar-benar dilepas, bukan terus dimuat
      // di balik layar setiap kali jendela ditutup.
      document.getElementById('jendelaBingkai').src = '';
  }

  // Klik di area gelap ikut menutup. Pada <dialog> tanpa padding, klik yang
  // mendarat di latar melaporkan target berupa elemen dialog itu sendiri.
  //
  // Ini bukan sekadar kemudahan. Tombol Escape TIDAK menutup jendela ini begitu
  // isinya termuat: fokus berpindah ke dalam bingkai, dan dokumen di dalamnya
  // yang menangkap tombolnya. Sudah diuji, memang tidak sampai ke dialog.
  // Karena itu jalan keluarnya harus terlihat: tombol Close, atau klik di latar.
  document.getElementById('jendelaModal').addEventListener('click', function (e) {
      if (e.target === this) { tutupJendela(); }
  });
</script>
