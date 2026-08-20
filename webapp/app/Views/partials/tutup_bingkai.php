<?php
/**
 * Tombol Close untuk halaman yang bisa dibuka DI DALAM jendela pratinjau.
 *
 * Dipakai dua halaman yang berangkat dari daftar posisi: Recruitment Progress
 * dan form data lowongan. Dijadikan partial supaya keduanya menutup jendela
 * dengan cara yang sama - yang satu berubah tanpa yang lain adalah jenis
 * ketimpangan yang baru ketahuan saat salah satunya berhenti menutup.
 *
 * Aman dimuat di luar bingkai: cabang keduanya membiarkan tautannya berpindah
 * seperti tautan biasa.
 */
?>
<script>
  function tutupBingkai() {
      if (window.parent && window.parent !== window && window.parent.tutupJendela) {
          window.parent.tutupJendela();

          return false;
      }

      return true;   // bukan di dalam bingkai: biarkan tautannya berpindah
  }
</script>
