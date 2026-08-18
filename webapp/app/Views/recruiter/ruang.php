<?php

use App\Controllers\Recruiter;
use App\Libraries\LembarPenilaian as L;
use App\Libraries\PertanyaanKandidat as PK;
use App\Models\InterviewModel;

/**
 * Ruang interview satu kandidat (revisi 12 Agustus 2026).
 *
 * Dibuka berdampingan dengan jendela Zoom, jadi urutannya mengikuti urutan
 * kerja recruiter: masuk ruangan, tanya tiga hal, unggah rekamannya.
 *
 * Sama seperti halaman pertanyaan, bisa dibuka utuh atau di dalam jendela
 * pratinjau di atas tabel Interview HRD. Penanda bingkai ikut terbawa saat form
 * dikirim, kalau tidak halaman di dalam kotak berubah jadi versi utuh lengkap
 * dengan topbar dan sidebar yang terjepit.
 */
$bingkai = $bingkai ?? false;

// Dari mana pertanyaannya disusun. Recruiter perlu tahu ini: 'posisi' dan
// 'bank' sama-sama berisi pertanyaan umum, tapi yang satu memang seharusnya
// begitu (kandidat belum pernah bekerja) dan yang satu lagi tanda kuota LLM
// habis. Kalau keduanya terlihat sama, kegagalan tidak akan pernah ketahuan.
$sumber = $pertanyaan[0]['sumber'] ?? '';

// Pertanyaan dikunci begitu rekamannya ada, tidak menunggu keputusan Gate 2.
// Wawancaranya sudah terjadi dan ketiga pertanyaan inilah yang ditanyakan;
// menyuntingnya sesudah itu membuat lembar profil bercerita tentang wawancara
// yang tidak pernah berlangsung. Gate 2 bisa menggantung berhari-hari di
// 'flagged', dan selama itu kotaknya terbuka lebar.
$terkunci = $sudah || $transkrip !== null;

// Nilai dan catatan yang SUDAH tersimpan, dipakai mengisi ulang formnya.
// Kotak kosong di atas nilai yang sudah ada adalah undangan mengisi berbeda,
// dan yang terbaca di layar lalu bukan yang dipakai menilai kandidat.
$milikRec = [];
foreach ($penilaian as $p) {
    if (($p['sumber'] ?? '') === L::DARI_RECRUITER) {
        $milikRec[$p['kompetensi']] = $p;
    }
}
$LENCANA = [
    PK::SUMBER_PENGALAMAN => ['l-baik', 'dari pengalaman kerja kandidat',
        'Disusun dari riwayat kerja di CV kandidat ini.'],
    PK::SUMBER_POSISI => ['l-netral', 'dari uraian lowongan',
        'CV kandidat tidak mencantumkan riwayat kerja, jadi pertanyaannya disusun dari lowongan.'],
    PK::SUMBER_BANK => ['l-awas', 'cadangan: bank soal lowongan',
        'Layanan AI tidak menjawab atau kuota hariannya habis. Pertanyaan ini dipinjam dari bank '
        . 'soal lowongan dan TIDAK disesuaikan dengan kandidat ini.'],
];
?>
<?= $this->extend($bingkai ? 'layout_bingkai' : 'layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  button { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer;
           font-family: inherit; font-weight: 600; font-size: 13px; }
  .btn-zoom { background: #2D8CFF; color: #fff; }
  .btn-simpan { background: #20A277; color: #fff; }
  .btn-ulang { background: #f0f2f7; color: #555; }
  .btn-unggah { background: #2F6FED; color: #fff; }
  .kartu { background: #fff; border: 1px solid #eef0f5; border-radius: 12px;
           padding: 20px 22px; margin-bottom: 14px; }
  .kartu h3 { font-size: 14px; margin: 0 0 12px; }
  .kepala { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
  .kepala .sub { color: #888; font-size: 13px; }

  .lencana { font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600; }
  .l-baik { background: #E8F7EE; color: #1d6b3d; }
  .l-netral { background: #f0f2f7; color: #555; }
  .l-awas { background: #FFE9E3; color: #a53a1c; }
  .ket { font-size: 12px; color: #666; line-height: 1.55; margin: 8px 0 14px; }

  .baris { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
  .baris .no { width: 26px; height: 26px; flex-shrink: 0; border-radius: 50%; background: #FFF6E6;
               color: #8a6d1e; display: flex; align-items: center; justify-content: center;
               font-size: 12px; font-weight: 700; margin-top: 7px; }
  .baris textarea { display: block; width: 100%; box-sizing: border-box;
                    padding: 10px 13px; border: 1px solid #e2e6ee; border-radius: 8px;
                    font-family: inherit; font-size: 14px; line-height: 1.6;
                    resize: vertical; min-height: 74px; }
  .baris textarea:focus { outline: none; border-color: #2F6FED; box-shadow: 0 0 0 3px rgba(47,111,237,.12); }
  .baris .isi { flex: 1; min-width: 0; }
  .baris .teks { padding: 9px 0; font-size: 14px; line-height: 1.6; }

  .status { display: inline-block; font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600; }
  .s-antre { background: #FFF6E6; color: #8a6d1e; }
  .s-proses { background: #DCE9FF; color: #2b4d8a; }
  .s-selesai { background: #E8F7EE; color: #1d6b3d; }
  .s-gagal { background: #FFE9E3; color: #a53a1c; }
  input[type=file] { font-family: inherit; font-size: 13px; }
  .kosong { color: #999; padding: 22px 0; text-align: center; }

  details { margin-top: 10px; }
  summary { cursor: pointer; font-size: 13px; font-weight: 600; color: #2F6FED; }
  .transkrip { white-space: pre-wrap; word-break: break-word; margin: 8px 0 0;
               padding: 12px 14px; background: #fafbfd; border: 1px solid #eef0f5;
               border-radius: 8px; font-family: inherit; font-size: 13px; line-height: 1.65;
               max-height: 320px; overflow-y: auto; }

  /* nilai + alasannya, satu baris per kompetensi */
  .alasan { border-collapse: collapse; width: 100%; }
  .alasan th, .alasan td { border: 1px solid #eef0f5; padding: 7px 10px;
                           font-size: 13px; text-align: left; vertical-align: top; }
  .alasan th { background: #fafbfd; font-weight: 600; width: 30%; }
  .alasan td.n { width: 15%; white-space: nowrap; font-weight: 600; }

  /* matriks kompetensi x skala, sama dengan halaman penilaian */
  .lembar { border-collapse: collapse; }
  .lembar th, .lembar td { border: 1px solid #e2e6ee; padding: 7px 10px; text-align: center; font-size: 13px; }
  .lembar th { background: #FFF6E6; color: #8a6d1e; font-size: 12px; font-weight: 600; }
  .lembar th small { font-weight: 400; font-size: 10px; }
</style>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter/tahap/interview_online') ?>"><span class="l"><span>👔</span><span>Interview HRD</span></span></a>
<a href="<?= site_url('recruiter/profil/' . $app['id']) ?>" target="_blank" rel="noopener"><span class="l"><span>📋</span><span>Lembar Profil</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu">
  <div class="kepala">
    <h2 style="margin:0"><?= esc($app['nama']) ?></h2>
    <span class="sub"><?= esc($app['judul']) ?> &middot; <?= esc($app['email']) ?></span>
  </div>
  <?php if (! empty($iv['scheduled_at'])): ?>
    <p class="ket" style="margin:8px 0 0">📅 <?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</p>
  <?php endif ?>
  <?php if (! empty($iv['join_url'])): ?>
    <p style="margin:12px 0 0">
      <a href="<?= esc($iv['join_url'], 'attr') ?>" target="_blank" rel="noopener">
        <button class="btn-zoom">Masuk Ruang Zoom</button></a>
    </p>
  <?php endif ?>
</div>

<div class="kartu">
  <div class="kepala">
    <h3 style="margin:0">Tiga Pertanyaan</h3>
    <?php if ($sumber !== '' && isset($LENCANA[$sumber])): ?>
      <span class="lencana <?= $LENCANA[$sumber][0] ?>"><?= esc($LENCANA[$sumber][1]) ?></span>
    <?php endif ?>
  </div>
  <?php if ($sumber !== '' && isset($LENCANA[$sumber])): ?>
    <p class="ket"><?= esc($LENCANA[$sumber][2]) ?></p>
  <?php endif ?>

  <?php if ($disusunUlang ?? false): ?>
    <?php // Disebutkan, bukan dikerjakan diam-diam: recruiter yang membuka
          // halaman ini sedetik lalu mungkin sudah membaca daftar yang lama. ?>
    <p class="ket" style="color:#a53a1c">
      Pertanyaan disusun ulang otomatis. Yang tersimpan sebelumnya dibuat saat CV kandidat
      belum selesai dibaca, sehingga belum memakai riwayat kerjanya.
    </p>
  <?php endif ?>

  <?php if ($pertanyaan === []): ?>
    <p class="kosong">Pertanyaan belum berhasil disusun.</p>
  <?php endif ?>

  <?php if ($terkunci): ?>
    <?php // Sesudah wawancaranya direkam, pertanyaannya jadi catatan: ia dasar
          // penilaian yang sudah terlanjur dipakai. Ditampilkan sebagai teks,
          // tanpa kotak isian yang mengundang orang mengubahnya. ?>
    <?php foreach ($pertanyaan as $i => $p): ?>
      <div class="baris">
        <span class="no"><?= $i + 1 ?></span>
        <div class="isi"><div class="teks"><?= esc($p['pertanyaan']) ?></div></div>
      </div>
    <?php endforeach ?>
    <p class="ket" style="margin-bottom:0"><?= $sudah
        ? 'Kandidat ini sudah diputuskan, pertanyaannya tidak bisa diubah lagi.'
        : 'Rekaman wawancara sudah diunggah, jadi pertanyaannya terkunci - ketiganya inilah yang ditanyakan.' ?></p>
  <?php else: ?>
    <form method="post" action="<?= site_url('recruiter/ruang/' . $app['id'] . '/pertanyaan') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="bingkai" value="<?= $bingkai ? '1' : '0' ?>">
      <?php foreach ($pertanyaan as $i => $p): ?>
        <div class="baris">
          <span class="no"><?= $i + 1 ?></span>
          <div class="isi">
            <textarea name="pertanyaan[]" rows="2"
                      maxlength="<?= PK::MAKS_PANJANG ?>"><?= esc($p['pertanyaan']) ?></textarea>
          </div>
        </div>
      <?php endforeach ?>
      <div style="display:flex;gap:8px;margin-top:4px">
        <?php if ($pertanyaan !== []): ?>
          <button type="submit" class="btn-simpan">Simpan Pertanyaan</button>
        <?php endif ?>
        <button type="submit" name="aksi" value="buat" class="btn-ulang"
                onclick="return confirm('Susun ulang tiga pertanyaan dari CV kandidat? Pertanyaan yang sekarang akan diganti.')">
          <?= $pertanyaan === [] ? 'Coba Susun Lagi' : 'Susun Ulang' ?>
        </button>
      </div>
    </form>
  <?php endif ?>
</div>

<div class="kartu">
  <h3>Rekaman Wawancara</h3>
  <?php if ($transkrip !== null): ?>
    <?php $s = $transkrip['status']; ?>
    <?php // Status barisnya menandai SELURUH pekerjaan - transkripsi lalu
          // penilaian - jadi 'gagal' saja tidak cukup menerangkan yang mana.
          // Sejak transkripsinya jalan lokal (14 Agustus 2026) keduanya kerap
          // berbeda nasib: transkrip lengkap 2.000 karakter terpampang di bawah
          // sementara labelnya berkata "transkripsi gagal", padahal yang habis
          // kuotanya cuma penilaian. Adanya teks yang membedakan. ?>
    <?php $adaTeks = trim((string) ($transkrip['teks'] ?? '')) !== ''; ?>
    <p style="margin:0 0 10px">
      <span class="status s-<?= esc($s) ?>"><?= esc([
          'antre'   => 'menunggu ditranskripsi',
          'proses'  => 'sedang ditranskripsi',
          'selesai' => 'transkrip siap',
          'gagal'   => $adaTeks ? 'penilaian gagal, transkrip tersedia' : 'transkripsi gagal',
      ][$s] ?? $s) ?></span>
      <small style="color:#999;margin-left:8px">diunggah <?= esc(date('d M Y H:i', strtotime($transkrip['created_at']))) ?></small>
    </p>
    <?php if (! empty($transkrip['catatan'])): ?>
      <p class="ket" style="margin-top:0"><?= esc($transkrip['catatan']) ?></p>
    <?php endif ?>

    <?php if (trim((string) ($transkrip['teks'] ?? '')) !== ''): ?>
      <?php // Transkrip lengkap, bukan cuma keterangan bahwa ia ada. Inilah
            // satu-satunya cara membuktikan skornya masuk akal; menyembunyikannya
            // membuat penilaian otomatis jadi angka yang tidak bisa dibantah
            // siapa pun, termasuk kandidat yang bertanya kenapa ia gugur. ?>
      <details<?= $sudah ? ' open' : '' ?>>
        <summary>Transkrip wawancara</summary>
        <pre class="transkrip"><?= esc($transkrip['teks']) ?></pre>
      </details>
    <?php endif ?>
  <?php endif ?>

<?php
// Enam kompetensi dari transkrip, tiga dari recruiter. Dipisah supaya pembaca
// tahu mana yang dibaca mesin dan mana yang dilihat manusia - keduanya masuk
// tabel yang sama dan tanpa pemisahan ini tampak seolah satu orang menilai
// sembilan-sembilanya.
$dariAi = array_filter($penilaian, static fn ($p) => ($p['sumber'] ?? '') === L::DARI_AI);
?>
<?php if ($dariAi !== []): ?>
  <p style="margin:18px 0 6px"><b>Penilaian dari transkrip</b></p>
  <table class="alasan">
    <?php foreach ($dariAi as $p): ?>
      <tr>
        <th><?= esc($p['kompetensi']) ?></th>
        <td class="n"><?= esc($p['tingkat']) ?> - <?= esc(L::SKALA[(int) $p['tingkat']] ?? '?') ?></td>
        <td><?= esc($p['catatan']) ?></td>
      </tr>
    <?php endforeach ?>
  </table>
  <?php
    // Kompetensi yang TIDAK terjawab disebut terang-terangan. Baris yang hilang
    // diam-diam terbaca sebagai "tidak ada masalah", padahal artinya kebalikan:
    // wawancaranya tidak memuat bahan untuk menilai hal itu.
    $tak = array_diff(L::dariTranskrip(), array_column($dariAi, 'kompetensi'));
  ?>
  <?php if ($tak !== []): ?>
    <p class="ket">
      Tidak bisa dinilai dari transkrip ini: <b><?= esc(implode(', ', $tak)) ?></b>.
      Wawancaranya tidak memuat bahan untuk butir itu, dan butir kosong tidak ikut dihitung.
    </p>
  <?php endif ?>
<?php endif ?>

  <?php if ($belumWaktunya): ?>
    <?php // Wawancaranya belum selesai. Kotak nilai yang terbuka di saat ini
          // mengundang orang menilai kandidat yang belum ditemuinya, dan lembar
          // yang terisi sebelum wawancara tidak bisa dibedakan lagi dari yang
          // diisi sesudahnya. Tiga pertanyaan di atas tetap terbuka - justru
          // itu yang dibutuhkan sekarang. ?>
    <?php $buka = strtotime($iv['scheduled_at']) + InterviewModel::TUTUP_MENIT * 60; ?>
    <p class="ket" style="margin-bottom:0">
      <span class="status s-antre">belum waktunya dinilai</span><br>
      Wawancaranya dijadwalkan <b><?= esc(date('d M Y, H:i', strtotime($iv['scheduled_at']))) ?> WIB</b>.
      Unggahan rekaman dan lembar penilaian terbuka pukul <b><?= esc(date('H:i', $buka)) ?></b>,
      yaitu <?= InterviewModel::TUTUP_MENIT ?> menit setelah jam mulai - sama dengan matinya tautan Zoom kandidat.
      Sampai saat itu yang bisa Anda kerjakan tiga pertanyaan di atas.
    </p>
  <?php elseif ($sudah): ?>
    <p class="ket" style="margin-bottom:0">Kandidat ini sudah diputuskan.</p>
  <?php else: ?>
    <p class="ket">
      Rekam wawancara lewat Zoom (Record on this Computer), lalu unggah berkasnya di sini.
      Pilih rekaman <b>audio saja</b> bila tersedia - berkasnya jauh lebih kecil dan yang
      dibutuhkan memang cuma suaranya. Maksimal <?= round(Recruiter::REKAMAN_MAKS_KB / 1024) ?> MB.
    </p>
    <form method="post" action="<?= site_url('recruiter/ruang/' . $app['id'] . '/rekaman') ?>"
          enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="bingkai" value="<?= $bingkai ? '1' : '0' ?>">
      <input type="file" name="rekaman" required
             accept=".<?= str_replace(',', ',.', Recruiter::REKAMAN_EKSTENSI) ?>">

      <?php // Tiga kompetensi ini dinilai DI SINI, bukan di halaman terpisah,
            // dan bukan demi kerapian: Gate 2 menutup sendiri begitu penilaian
            // AI mendarat. Kalau ketiganya dikirim terpisah, callback bisa tiba
            // lebih dulu dan kandidat diputuskan dari lembar yang belum lengkap.
            // Satu form membuat urutannya tidak mungkin terbalik. ?>
      <p style="margin:18px 0 6px"><b>Penilaian Anda</b></p>
      <p class="ket" style="margin-top:0">
        Ketiganya tidak bisa dibaca dari transkrip - yang tersimpan di sana cuma kata-kata
        yang terucap. Enam kompetensi lain dinilai otomatis dari transkrip beserta
        kutipan yang mendasarinya.
        <?php if ($milikRec !== []): ?>
          <br><b>Terisi dari penilaian Anda yang tersimpan.</b> Mengirim ulang menggantinya,
          bukan menambah - yang dipakai menilai selalu yang terakhir.
        <?php endif ?>
      </p>
      <table class="lembar">
        <tr>
          <th style="text-align:left">Kompetensi</th>
          <?php foreach (L::SKALA as $n => $label): ?>
            <th><?= $n ?><br><small><?= esc($label) ?></small></th>
          <?php endforeach ?>
        </tr>
        <?php foreach (L::MATA_MANUSIA as $i => $kompetensi): ?>
          <?php $tersimpan = (int) ($milikRec[$kompetensi]['tingkat'] ?? 0); ?>
          <tr>
            <td style="text-align:left"><?= esc($kompetensi) ?></td>
            <?php foreach (array_keys(L::SKALA) as $n): ?>
              <td><input type="radio" name="nilai[<?= $i ?>]" value="<?= $n ?>" required style="width:auto"
                         <?= $n === $tersimpan ? 'checked' : '' ?>></td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </table>

      <?php // Dua kotak narasi milik recruiter. Candidate's Strengths dan
            // Weaknesses tidak ada di sini: keduanya dirangkum AI dari riwayat
            // kerja dan transkrip - bahan yang sama dengan yang dipakai menilai
            // kompetensi - sehingga menuliskannya ulang dari ingatan cuma
            // menghasilkan versi yang lebih kabur. Dua yang tersisa justru
            // pengamatan recruiter sendiri, hal yang tidak ada di transkrip. ?>
      <p style="margin:18px 0 4px"><b>Catatan Anda</b> <span style="font-weight:400;color:#888;font-size:12px">(boleh dikosongkan)</span></p>
      <?php foreach (L::NARASI_RECRUITER as $kunci): $label = L::NARASI[$kunci]; ?>
        <label style="margin-top:12px"><?= esc($label) ?></label>
        <textarea name="narasi[<?= $kunci ?>]" rows="2" maxlength="<?= L::MAKS_CATATAN ?>"
                  style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e2e6ee;border-radius:8px;font-family:inherit;font-size:13px"><?= esc($milikRec[$kunci]['catatan'] ?? '') ?></textarea>
      <?php endforeach ?>

      <button type="submit" class="btn-unggah" style="margin-top:14px"
              onclick="return confirm('Kirim rekaman dan penilaian? Keputusan akhir kandidat dihitung otomatis setelah transkripsi selesai, dan kandidat dikabari lewat email.')">
        <?= $transkrip === null ? 'Unggah &amp; Nilai' : 'Unggah Ulang &amp; Nilai' ?>
      </button>
    </form>
    <?php if ($transkrip !== null): ?>
      <p class="ket" style="margin-bottom:0">
        Mengunggah ulang tidak menghapus berkas rekaman sebelumnya, tapi penilaian di atas
        <b>diganti</b>, bukan ditambah - yang dipakai menilai selalu kiriman terakhir.
      </p>
    <?php endif ?>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
