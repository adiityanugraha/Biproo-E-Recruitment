<?php

use App\Libraries\AlurRekrutmen as A;

/**
 * Recruitment Progress: sunting alur satu posisi (18 Agustus 2026).
 *
 * Tata letaknya mengikuti web recruiter BIPROO: kepala kuning, dua bagian
 * berdampingan - Assessment Progress dan Selection Progress - dan tiap bagian
 * punya DUA kotak. Kiri yang dipakai, kanan yang tersedia. Klik memindahkan.
 *
 * URUTAN DIATUR DENGAN MENYERET. Tahap bisa dijatuhkan persis di antara dua
 * tahap lain, termasuk di antara dua tahap inti - dan justru itu gunanya:
 * satu posisi mengerjakan D.I.S.C sebelum TIU 5, posisi lain sesudahnya.
 * Klik tetap ada sebagai jalan cepat menambah atau mengeluarkan.
 *
 * TAHAP INTI TIDAK BISA DIKELUARKAN. Ia tetap tampil di kotak kiri - bukan
 * disembunyikan - karena recruiter perlu melihat rangkaian utuhnya untuk tahu
 * di mana tahap pilihannya jatuh. Klik padanya tidak melakukan apa-apa, dan
 * kuncinya disebutkan supaya penolakan itu tidak terbaca sebagai kerusakan.
 */
$bingkai = $bingkai ?? false;

// Yang belum dipakai, dikelompokkan seperti kotak kanan di sistem lama.
$tersedia = [];
foreach (array_keys(A::KATALOG) as $kunci) {
    if (! in_array($kunci, $terpilih, true)) {
        $tersedia[] = $kunci;
    }
}
?>
<?= $this->extend($bingkai ? 'layout_bingkai' : 'layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  .rp { border-radius: 8px; overflow: hidden; border: 1px solid #eef0f5; background: #fff; }
  .rp .kepala { background: #F7C22E; color: #4a3a00; font-weight: 700; font-size: 15px; padding: 13px 18px; }
  .rp .badan { padding: 18px; }
  .rp .kaki { background: #FFF9E6; padding: 12px 18px; display: flex; justify-content: flex-end; gap: 8px; }

  .baris-posisi { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
  .baris-posisi label { font-size: 13px; color: #444; white-space: nowrap; margin: 0; }
  .baris-posisi .nilai { flex: 1; border: 1px solid #e2e6ee; border-radius: 6px; padding: 9px 13px;
                         font-size: 13px; background: #fff; color: #333; }

  .dua { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
  @media (max-width: 900px) { .dua { grid-template-columns: 1fr; } }

  .bagian h4 { margin: 0 0 4px; font-size: 13.5px; font-weight: 600; color: #333;
               border-bottom: 1px solid #e8ebf1; padding-bottom: 8px; }
  .bagian .sub { font-size: 11px; color: #999; margin: 6px 0 10px; }
  .kotak2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .kotak { border: 1px solid #e2e6ee; border-radius: 6px; padding: 9px; min-height: 190px;
           background: #FCFDFE; align-content: start; }

  .opsi { display: block; padding: 10px 12px; margin-bottom: 8px; border-radius: 5px;
          font-size: 12.5px; line-height: 1.35; cursor: pointer; user-select: none;
          border: 1px solid transparent; }
  .opsi:last-child { margin-bottom: 0; }
  .kotak.sedia .opsi { background: #EFF1F4; color: #6b7280; }
  .kotak.sedia .opsi:hover { background: #E4E7EB; }
  .assessment .kotak.pakai .opsi { background: #DCEAFB; color: #1f4f86; }
  .selection  .kotak.pakai .opsi { background: #D5EDDF; color: #1d6b3d; }
  .opsi[draggable=true] { cursor: grab; }
  .opsi[draggable=true]:active { cursor: grabbing; }
  /* Yang sedang diseret disamarkan, bukan disembunyikan: menghilangkannya
     membuat kotak melompat tinggi dan titik jatuhnya ikut bergeser. */
  .opsi.diseret { opacity: .35; }
  .kotak.tujuan { border-color: #7FB3E8; background: #F4F9FF; }
  .opsi.inti { cursor: default; opacity: .95; }
  .opsi.inti::after { content: 'inti'; float: right; font-size: 9.5px; font-weight: 700;
                      letter-spacing: .04em; opacity: .55; text-transform: uppercase; }

  .rp .kaki button { padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer;
                     font-family: inherit; font-weight: 600; font-size: 13px; }
  .btn-save { background: #1E8E5A; color: #fff; }
  .btn-close { background: #6C757D; color: #fff; }
  .atasan { border: 1px solid #FFD9A0; background: #FFF9EF; border-radius: 8px;
            padding: 12px 14px; margin-bottom: 16px; font-size: 12.5px; }
  /* Belum dipakai posisi ini: tetap terlihat, cuma diredupkan supaya tidak
     merebut perhatian dari pilihan tahapnya. */
  .atasan.tidur { background: #FAFBFD; border-color: #e2e6ee; }
  .atasan .isian label { flex: 1; min-width: 190px; display: block;
                         font-size: 11.5px; font-weight: 600; color: #555; }
  .atasan .isian label input { width: 100%; box-sizing: border-box; margin-top: 4px; }
  .atasan p { margin: 5px 0 10px; color: #666; line-height: 1.6; }
  .atasan .isian { display: flex; gap: 8px; flex-wrap: wrap; }
  .atasan input { flex: 1; min-width: 190px; margin: 0; padding: 8px 11px; font-size: 13px;
                  border: 1px solid #e2e6ee; border-radius: 6px; font-family: inherit; }
  .ket { font-size: 12px; color: #666; line-height: 1.6; margin: 0 0 14px; }
</style>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<form method="post" action="<?= site_url('recruiter/pengaturan/alur/' . $job['id']) ?>" id="formAlur">
  <?= csrf_field() ?>
  <input type="hidden" name="bingkai" value="<?= $bingkai ? '1' : '0' ?>">

  <div class="rp">
    <div class="kepala">Recruitment Progress</div>

    <div class="badan">
      <div class="baris-posisi">
        <label>Position Category :</label>
        <div class="nilai"><?= esc($job['judul']) ?></div>
      </div>

      <p class="ket">
        Seret tahap untuk menaruhnya persis di tempat yang Anda mau, termasuk di antara dua tahap lain.
        Atau klik saja: dari kanan untuk memakainya, dari kiri untuk mengeluarkannya.
        Tahap bertanda <b>INTI</b> digerakkan sistem dan tidak bisa dikeluarkan: Keputusan Tahap 1
        diputus dari hasil assessment, Interview HRD mengisi transkrip dan penilaiannya, Keputusan
        Akhir ditutup mesin penilai. Urutan mengikuti urutan memasukkan.
      </p>

      <?php // Muncul hanya bila posisi ini memakai Interview User. Ditaruh di
            // ATAS pilihan tahapnya, bukan di bawah: begitu recruiter menarik
            // Interview User ke kiri, matanya sudah lewat kotak ini dan tahu
            // ada yang harus diisi. JavaScript menyalakannya seketika, tapi
            // nilainya tetap terkirim walau JavaScript mati - yang tersembunyi
            // cuma tampilannya. ?>
      <?php // SELALU TAMPIL, bukan disembunyikan sampai Interview User dipakai.
            //
            // Versi pertama menyembunyikannya, dan itu keliru: recruiter yang
            // hendak MENYIAPKAN Interview User membuka jendela ini lalu tidak
            // menemukan tempat mengisi nama atasannya - fiturnya seolah tidak
            // ada. Yang tersembunyi tidak bisa ditemukan orang yang belum tahu
            // ia ada.
            //
            // Yang berubah keterangannya, bukan keberadaannya: saat tahapnya
            // belum dipakai, kotak ini menyebut dirinya belum berlaku. ?>
      <?php $pakaiUser = in_array('interview_user', $terpilih, true); ?>
      <div id="kotakAtasan" class="atasan<?= $pakaiUser ? '' : ' tidur' ?>">
        <b>Pewawancara Interview User</b>
        <p>
          <span id="ketAtasanAktif"<?= $pakaiUser ? '' : ' hidden' ?>>
            Atasan yang akan mewawancarai kandidat posisi ini. Menyimpan akan menerbitkan akunnya dan
            mengirim kata sandi ke alamat di bawah. <b>Sandinya tidak pernah ditampilkan di layar ini</b> -
            termasuk kepada Anda.
          </span>
          <span id="ketAtasanTidur"<?= $pakaiUser ? ' hidden' : '' ?>>
            Posisi ini belum memakai <b>Interview User</b>. Isian di bawah boleh diisi sekarang, tapi
            akunnya baru diterbitkan setelah tahap Interview User dipindahkan ke kotak kiri.
          </span>
          <?php if (($atasan['dikirim_at'] ?? null) !== null): ?>
            <br>Terakhir dikirim <?= esc(date('d M Y, H:i', strtotime($atasan['dikirim_at']))) ?> WIB.
          <?php endif ?>
        </p>
        <div class="isian">
          <label>
            Nama atasan
            <input type="text" name="atasan_nama" placeholder="mis. Budi Santoso"
                   maxlength="160" value="<?= esc($atasan['nama'] ?? '', 'attr') ?>">
          </label>
          <label>
            Email atasan
            <input type="email" name="atasan_email" placeholder="mis. budi@biproo.com"
                   maxlength="255" value="<?= esc($atasan['email'] ?? '', 'attr') ?>">
          </label>
        </div>
      </div>

      <div class="dua">
        <?php foreach ([A::ASSESSMENT => 'Assessment Progress', A::SELECTION => 'Selection Progress'] as $grup => $judulGrup): ?>
          <div class="bagian <?= esc($grup) ?>">
            <h4><?= esc($judulGrup) ?></h4>
            <p class="sub">Kiri: dipakai posisi ini &middot; Kanan: tersedia</p>
            <div class="kotak2">
              <div class="kotak pakai" data-grup="<?= esc($grup) ?>">
                <?php foreach ($terpilih as $kunci): ?>
                  <?php [$label, $g, $wajib] = A::KATALOG[$kunci]; ?>
                  <?php if ($g !== $grup) {
                      continue;
                  } ?>
                  <span class="opsi<?= $wajib ? ' inti' : '' ?>"
                        data-kunci="<?= esc($kunci, 'attr') ?>"
                        data-wajib="<?= $wajib ? '1' : '0' ?>"><?= esc($label) ?></span>
                <?php endforeach ?>
              </div>
              <div class="kotak sedia">
                <?php foreach ($tersedia as $kunci): ?>
                  <?php [$label, $g] = A::KATALOG[$kunci]; ?>
                  <?php if ($g !== $grup) {
                      continue;
                  } ?>
                  <span class="opsi" data-kunci="<?= esc($kunci, 'attr') ?>" data-wajib="0"><?= esc($label) ?></span>
                <?php endforeach ?>
              </div>
            </div>
          </div>
        <?php endforeach ?>
      </div>
    </div>

    <div class="kaki">
      <button type="submit" class="btn-save">Save</button>
      <?php // Menutup jendela pratinjau bila memang sedang di dalamnya, dan
            // TIDAK menyegarkan induknya - tidak ada yang berubah untuk
            // ditampilkan. Di luar bingkai, tautannya bekerja seperti biasa. ?>
      <a href="<?= site_url('recruiter/pengaturan') ?>" onclick="return tutupBingkai()">
        <button type="button" class="btn-close">Close</button></a>
    </div>
  </div>

  <?php // Ditulis server DULU berisi pilihan yang sekarang, lalu ditimpa
        // JavaScript saat dikirim. Bukan dibiarkan kosong: tanpa JavaScript
        // formnya akan mengirim daftar kosong, dan alur posisi ini diam-diam
        // kembali ke bawaan - kehilangan setelan tanpa ada pesan apa pun.
        // Dengan cara ini, yang terburuk cuma "tidak berubah". ?>
  <div id="kiriman">
    <?php foreach ($terpilih as $kunci): ?>
      <input type="hidden" name="tahap[]" value="<?= esc($kunci, 'attr') ?>">
    <?php endforeach ?>
  </div>
</form>

<script>
  function tutupBingkai() {
      if (window.parent && window.parent !== window && window.parent.tutupJendela) {
          window.parent.tutupJendela();

          return false;
      }

      return true;   // bukan di dalam bingkai: biarkan tautannya berpindah
  }

  // Seret-lepas memakai API bawaan peramban, tanpa pustaka apa pun.
  //
  // Elemennya benar-benar DIPINDAHKAN saat kursor melintas, bukan digambar
  // bayangannya lalu dipindahkan saat dilepas. Dengan begitu yang terlihat
  // selama menyeret sudah persis hasil akhirnya, dan urutan yang dikirim form
  // tinggal dibaca dari urutan elemen di kotak kiri.
  var seret = null;      // yang sedang diseret
  var baruSeret = false; // menahan klik yang menyusul sesudah lepas

  document.querySelectorAll('.opsi').forEach(function (opsi) {
      // Tahap inti tidak bisa dipindahkan - urutannya urutan mesin. Ia tetap
      // jadi patokan: tahap pilihan boleh dijatuhkan di atas atau di bawahnya,
      // dan itulah yang membedakan posisi yang mengerjakan D.I.S.C sebelum
      // TIU 5 dari yang sesudahnya.
      if (opsi.dataset.wajib === '1') { return; }

      opsi.draggable = true;
      opsi.addEventListener('dragstart', function (e) {
          seret = opsi;
          baruSeret = true;
          opsi.classList.add('diseret');
          e.dataTransfer.effectAllowed = 'move';
          // Firefox tidak memulai seret tanpa data apa pun di dataTransfer.
          e.dataTransfer.setData('text/plain', opsi.dataset.kunci);
      });
      opsi.addEventListener('dragend', function () {
          opsi.classList.remove('diseret');
          document.querySelectorAll('.kotak.tujuan').forEach(function (k) { k.classList.remove('tujuan'); });
          segarkanKotakAtasan();
          seret = null;
          // Klik yang menyusul sesudah lepas jangan sampai memindahkannya lagi.
          setTimeout(function () { baruSeret = false; }, 0);
      });
  });

  /** Elemen yang harus berada SESUDAH titik jatuh, atau null bila di paling bawah. */
  function titikSisip(kotak, y) {
      var anak = Array.prototype.slice.call(kotak.querySelectorAll('.opsi:not(.diseret)'));
      for (var i = 0; i < anak.length; i++) {
          var r = anak[i].getBoundingClientRect();
          if (y < r.top + r.height / 2) { return anak[i]; }
      }

      return null;
  }

  document.querySelectorAll('.kotak').forEach(function (kotak) {
      kotak.addEventListener('dragover', function (e) {
          // Antar kelompok tidak boleh: tahap Assessment tidak punya arti di
          // rangkaian Selection, dan sisi server memang mengelompokkannya
          // sendiri - membiarkannya cuma membuat barang lenyap dari pandangan.
          if (!seret || kotak.closest('.bagian') !== seret.closest('.bagian')) { return; }

          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          kotak.classList.add('tujuan');

          var setelah = titikSisip(kotak, e.clientY);
          if (setelah === null) { kotak.appendChild(seret); } else { kotak.insertBefore(seret, setelah); }
      });

      kotak.addEventListener('dragleave', function (e) {
          if (!kotak.contains(e.relatedTarget)) { kotak.classList.remove('tujuan'); }
      });

      // Tanpa ini sebagian peramban memperlakukan lepasnya sebagai navigasi.
      kotak.addEventListener('drop', function (e) {
          e.preventDefault();
          kotak.classList.remove('tujuan');
      });

      // Klik tetap ada sebagai jalan cepat: menyeret melintasi layar cuma untuk
      // menambah satu tahap ke bawah daftar bukan kemajuan.
      kotak.addEventListener('click', function (e) {
          if (baruSeret) { return; }
          var opsi = e.target.closest('.opsi');
          if (!opsi || opsi.dataset.wajib === '1') { return; }

          var bagian = kotak.closest('.bagian');
          var tujuan = kotak.classList.contains('pakai')
              ? bagian.querySelector('.kotak.sedia')
              : bagian.querySelector('.kotak.pakai');
          tujuan.appendChild(opsi);
          segarkanKotakAtasan();
      });
  });

  // Kotak pewawancara ikut keadaan Interview User. Dipanggil sesudah tiap
  // perpindahan - seret maupun klik - supaya isiannya muncul tepat saat tahapnya
  // dipakai, bukan setelah halaman dimuat ulang.
  function segarkanKotakAtasan() {
      var kotak = document.getElementById('kotakAtasan');
      if (!kotak) { return; }
      var dipakai = !!document.querySelector('.bagian .kotak.pakai .opsi[data-kunci="interview_user"]');

      // Kotaknya TIDAK disembunyikan - yang berganti cuma keterangannya, supaya
      // recruiter yang belum memakai Interview User tetap melihat isian ini ada.
      kotak.classList.toggle('tidur', !dipakai);
      document.getElementById('ketAtasanAktif').hidden = !dipakai;
      document.getElementById('ketAtasanTidur').hidden = dipakai;
  }

  // Assessment dulu baru Selection: urutan itulah yang dibaca AlurRekrutmen.
  document.getElementById('formAlur').addEventListener('submit', function () {
      var wadah = document.getElementById('kiriman');
      wadah.innerHTML = '';
      document.querySelectorAll('.bagian .kotak.pakai .opsi').forEach(function (opsi) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'tahap[]';
          input.value = opsi.dataset.kunci;
          wadah.appendChild(input);
      });
  });
</script>

<?= $this->endSection() ?>
