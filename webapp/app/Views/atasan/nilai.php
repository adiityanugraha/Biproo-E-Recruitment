<?php

use App\Libraries\LembarPenilaian as L;

/**
 * Lembar Interview User: bahan bacaan dulu, baru penilaiannya (19 Agustus 2026).
 *
 * Atasan perlu tahu apa yang sudah terjadi di tahap HRD sebelum menilai -
 * riwayat kerja, transkrip wawancaranya, dan penilaian AI beserta alasannya.
 * Menyembunyikan itu membuatnya mewawancarai orang yang belum ia kenal sama
 * sekali, padahal datanya sudah ada di sistem.
 *
 * Skalanya 1-10, bukan 1-5 seperti lembar HRD. Bukan kekeliruan: formulir
 * Interview User BIPROO memang memakai skala itu (LembarPenilaian::MAKS_USER).
 */
$dariAi = array_filter($penilaian, static fn ($p) => ($p['sumber'] ?? '') === L::DARI_AI
    && ($p['kategori'] ?? '') === L::KAT_HRD);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?= $this->include('partials/head') ?>
<title><?= esc($judul) ?> - E-REQ BIPROO</title>
<style>
  body { background: #F4F6FA; margin: 0; }
  .atas { background: #F7941D; color: #fff; padding: 14px 24px; display: flex;
          align-items: center; justify-content: space-between; gap: 14px; }
  .atas .kiri { font-weight: 700; font-size: 16px; }
  .atas .kiri small { display: block; font-weight: 400; font-size: 12px; opacity: .9; }
  .atas a { color: #fff; font-size: 13px; text-decoration: underline; }
  .isi { max-width: 940px; margin: 22px auto; padding: 0 20px; }
  .kartu { background: #fff; border-radius: 12px; padding: 20px 22px; margin-bottom: 14px;
           box-shadow: 0 3px 12px rgba(0,0,0,.04); }
  .kartu h3 { margin: 0 0 12px; font-size: 15px; }
  .pesan { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
  .pesan-error { background: #FFE9E3; color: #a53a1c; }
  .ket { font-size: 12.5px; color: #666; line-height: 1.65; margin: 0 0 14px; }

  table.data { border-collapse: collapse; width: 100%; }
  table.data th, table.data td { border: 1px solid #eef0f5; padding: 8px 11px;
                                 font-size: 13px; text-align: left; vertical-align: top; }
  table.data th { background: #FAFBFD; font-weight: 600; width: 32%; }

  details { margin-top: 10px; }
  summary { cursor: pointer; font-size: 13px; font-weight: 600; color: #2F6FED; }
  .transkrip { white-space: pre-wrap; word-break: break-word; margin: 8px 0 0; padding: 12px 14px;
               background: #fafbfd; border: 1px solid #eef0f5; border-radius: 8px;
               font-size: 13px; line-height: 1.65; max-height: 300px; overflow-y: auto; }

  table.lembar { border-collapse: collapse; width: 100%; }
  table.lembar th, table.lembar td { border: 1px solid #e2e6ee; padding: 7px 6px;
                                     text-align: center; font-size: 12.5px; }
  table.lembar th { background: #FFF6E6; color: #8a6d1e; font-size: 11.5px; font-weight: 600; }
  table.lembar td.k { text-align: left; font-size: 13px; }
  table.lembar input { width: auto; margin: 0; }

  label.n { display: block; font-size: 12.5px; font-weight: 600; margin: 14px 0 5px; color: #444; }
  textarea { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #e2e6ee;
             border-radius: 8px; font-family: inherit; font-size: 13px; line-height: 1.6; }

  .putusan { margin-top: 18px; padding: 14px 16px; border: 1px solid #FFD9A0;
             background: #FFF9EF; border-radius: 10px; font-size: 13px; line-height: 1.6; }
  .putusan .tombol { display: flex; gap: 8px; margin-top: 12px; }
  .putusan button { padding: 9px 20px; border: none; border-radius: 8px; cursor: pointer;
                    font-family: inherit; font-weight: 600; font-size: 13px; color: #fff; }
  .b-lolos { background: #20A277; } .b-gagal { background: #C0392B; }
</style>
</head>
<body>

<div class="atas">
  <div class="kiri">
    <?= esc($app['nama']) ?>
    <small><?= esc($app['judul']) ?> &middot; <?= esc($app['email']) ?></small>
  </div>
  <a href="<?= site_url('atasan') ?>">Kembali</a>
</div>

<div class="isi">
  <?php if (session('error')): ?>
    <div class="pesan pesan-error">⚠️ <?= esc(session('error')) ?></div>
  <?php endif ?>

  <div class="kartu">
    <h3>Riwayat Kerja</h3>
    <?php if ($riwayat === []): ?>
      <p class="ket" style="margin:0">CV kandidat tidak mencantumkan riwayat kerja.</p>
    <?php else: ?>
      <table class="data">
        <?php foreach ($riwayat as $r): ?>
          <tr>
            <th>
              <?= esc(trim(($r['jabatan'] ?? '') . ' - ' . ($r['perusahaan'] ?? ''), ' -')) ?>
              <?php if (($r['periode'] ?? '') !== ''): ?>
                <br><span style="font-weight:400;color:#888"><?= esc($r['periode']) ?></span>
              <?php endif ?>
            </th>
            <td><?= esc($r['deskripsi'] ?? '-') ?></td>
          </tr>
        <?php endforeach ?>
      </table>
    <?php endif ?>
  </div>

  <div class="kartu">
    <h3>Hasil Wawancara HRD</h3>
    <?php // Penilaian AI ditampilkan sebagai BAHAN, bukan keputusan. Untuk
          // posisi ini yang memutuskan atasan, dan angka di bawah cuma
          // membantunya tahu apa yang sudah tergali di wawancara sebelumnya. ?>
    <?php if ($dariAi === []): ?>
      <p class="ket" style="margin:0">Belum ada penilaian dari wawancara HRD.</p>
    <?php else: ?>
      <table class="data">
        <?php foreach ($dariAi as $p): ?>
          <tr>
            <th><?= esc($p['kompetensi']) ?> - <?= esc($p['tingkat']) ?>/<?= L::MAKS_SKALA ?></th>
            <td><?= esc($p['catatan']) ?></td>
          </tr>
        <?php endforeach ?>
      </table>
    <?php endif ?>

    <?php if (trim((string) ($transkrip['teks'] ?? '')) !== ''): ?>
      <details>
        <summary>Transkrip wawancara HRD</summary>
        <pre class="transkrip"><?= esc($transkrip['teks']) ?></pre>
      </details>
    <?php endif ?>
  </div>

  <form method="post" action="<?= site_url('atasan/nilai/' . $app['id']) ?>">
    <?= csrf_field() ?>
    <div class="kartu">
      <h3>Penilaian Anda</h3>
      <p class="ket">
        Tujuh butir, skala 1 sampai <?= L::MAKS_USER ?>. Semuanya wajib diisi -
        lembar yang separuh terisi tidak bisa dipertanggungjawabkan kepada kandidat
        yang bertanya dasar keputusannya.
      </p>

      <table class="lembar">
        <tr>
          <th style="text-align:left">Kompetensi</th>
          <?php for ($n = 1; $n <= L::MAKS_USER; $n++): ?>
            <th><?= $n ?></th>
          <?php endfor ?>
        </tr>
        <?php foreach (L::USER as $i => $kompetensi): ?>
          <tr>
            <td class="k"><?= esc($kompetensi) ?></td>
            <?php for ($n = 1; $n <= L::MAKS_USER; $n++): ?>
              <td><input type="radio" name="nilai[<?= $i ?>]" value="<?= $n ?>" required></td>
            <?php endfor ?>
          </tr>
        <?php endforeach ?>
      </table>

      <?php foreach (L::NARASI_RECRUITER as $kunci): ?>
        <label class="n"><?= esc(L::NARASI[$kunci]) ?> <span style="font-weight:400;color:#888">(boleh dikosongkan)</span></label>
        <textarea name="narasi[<?= $kunci ?>]" rows="2" maxlength="<?= L::MAKS_CATATAN ?>"></textarea>
      <?php endforeach ?>

      <div class="putusan">
        <b>Keputusan akhir ada pada Anda.</b>
        <p style="margin:5px 0 0">
          Kandidat langsung dikabari lewat email begitu disimpan, dan keputusannya tidak bisa
          dibatalkan. Penilaian di atas ikut tersimpan sebagai dasarnya.
        </p>
        <div class="tombol">
          <button type="submit" name="keputusan" value="lolos" class="b-lolos"
                  onclick="return confirm('Terima kandidat ini? Kandidat akan dikabari via email.')">Terima</button>
          <button type="submit" name="keputusan" value="gagal" class="b-gagal"
                  onclick="return confirm('Tidak menerima kandidat ini? Kandidat akan dikabari via email.')">Tidak Terima</button>
        </div>
      </div>
    </div>
  </form>
</div>

</body>
</html>
