<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="banner">
  <span class="b-c" style="width:150px;height:150px;left:-40px;top:-30px"></span>
  <span class="b-c" style="width:120px;height:120px;left:30px;bottom:-60px"></span>
  <h2>Selamat datang, <?= esc(session('candidate_nama')) ?>!</h2>
  <p>Tetap buka BIPROO untuk mendapatkan informasi terbaru mengenai proses seleksi dan tugas yang perlu kamu selesaikan!</p>
  <span class="tgl">📅 <?= date('d M, Y') ?></span>
</div>

<div class="stats">
  <div class="stat">
    <div class="t">Pekerjaan Dilamar</div>
    <div class="v"><?= $jumlahLamaran ?> Lamaran <small style="color:#aaa">(Max. 3)</small></div>
    <a class="btn" style="background:linear-gradient(90deg,#FBD97A,#F5B301);color:#5a3d00" href="<?= site_url('status') ?>">Lihat</a>
  </div>
  <div class="stat">
    <div class="t">Notifikasi</div>
    <div class="v">0 Notifikasi</div>
    <span class="btn" style="background:#1E73E8" onclick="segera('Notifikasi')">Baca</span>
  </div>
  <div class="stat">
    <div class="t">Status Data</div>
    <div class="v">Belum Lengkap</div>
    <span class="btn" style="background:#2E9E5B" onclick="segera('Edit Profil')">Edit</span>
  </div>
</div>

<div class="tutbar">
  <span>💡 Masih bingung? Yuk cek tutorialnya!</span>
  <span class="btn" onclick="segera('Tutorial')">Tutorial ▾</span>
</div>

<div class="dash">
  <div>
    <div class="kartu">
      <?php if ($aktif === null): ?>
        <h2>Proses Seleksi</h2>
        <p>Belum ada lamaran. <a href="<?= site_url('lamar') ?>" style="color:#2F6FED">Lamar posisi sekarang</a> untuk memulai proses seleksi.</p>
      <?php else: ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
          <span style="font-weight:600;font-size:14px">Proses Seleksi:</span>
          <?php if (count($apps) > 1): ?>
            <select style="width:auto" onchange="location.href='<?= site_url('dashboard') ?>?app='+this.value">
              <?php foreach ($apps as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $a['id'] === $aktif['id'] ? 'selected' : '' ?>><?= esc($a['judul']) ?></option>
              <?php endforeach ?>
            </select>
          <?php else: ?>
            <b style="color:#2F6FED"><?= esc($aktif['judul']) ?></b>
          <?php endif ?>
        </div>

        <div class="stepper-cols">
          <?php
          $stepCard = static function (array $s): string {
              $isi = '<span class="ic">' . $s['icon'] . '</span><span class="nm">' . esc($s['label']) . '</span>';
              // mati = tahapnya nyata tapi belum waktunya; jangan jadi tautan sama
              // sekali, supaya tidak memancing klik yang tidak menghasilkan apa-apa
              if (! empty($s['mati'])) {
                  return '<span class="card mati" title="Sesi belum dibuka">' . $isi . '</span>';
              }
              if ($s['url'] !== null) {
                  return '<a class="card" href="' . $s['url'] . '">' . $isi . '</a>';
              }

              return '<a class="card" href="#" onclick="segera(\'' . esc($s['label'], 'js') . '\');return false">' . $isi . '</a>';
          };
          ?>
          <div>
            <h3>I. Assessment Process</h3>
            <?php foreach ($assessmentSteps as $s): ?>
              <div class="step <?= $s['st'] ?>">
                <span class="dot"></span><?= $stepCard($s) ?>
              </div>
            <?php endforeach ?>
          </div>
          <div>
            <h3>II. Selection Process</h3>
            <?php foreach ($selectionSteps as $s): ?>
              <div class="step <?= $s['st'] ?>">
                <span class="dot"></span><?= $stepCard($s) ?>
              </div>
            <?php endforeach ?>
          </div>
        </div>
      <?php endif ?>
    </div>

    <div class="kartu">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="margin:0">Pengalaman Kerja</h2>
        <span style="width:34px;height:34px;border-radius:10px;background:#F5B301;color:#fff;font-size:20px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer" onclick="segera('Tambah Pengalaman Kerja')">+</span>
      </div>
      <div style="text-align:center;color:#aaa;font-size:13px;padding:20px 0;border:1px dashed #e2e6ee;border-radius:12px">-- Belum ada pengalaman --</div>
    </div>

    <div class="wide-btn" onclick="segera('Lengkapi Whatsapp, Domisili & CV')">🧑‍💼 Lengkapi Whatsapp, Domisili &amp; CV</div>
  </div>

  <div>
    <div class="kartu" style="padding:16px">
      <h2 style="font-size:15px;border-bottom:1px solid #eef0f5;padding-bottom:10px">Feed(s)</h2>
      <div style="border:1px solid #eef0f5;border-radius:12px;padding:14px">
        <div style="display:flex;align-items:center;gap:10px">
          <span style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#FBA919,#F7941D);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">B</span>
          <div><b style="font-size:13px">Admin Biproo</b> <span style="color:#2F6FED">✔</span><br><small style="color:#999"><?= date('d M, Y') ?></small></div>
        </div>
        <p style="font-size:13px;color:#444;line-height:1.6;margin-top:10px">Semangat menjalani setiap tahap seleksi! Persiapkan dirimu dengan baik, tetap tenang, dan tunjukkan versi terbaik dari dirimu.</p>
        <div style="display:flex;gap:16px;font-size:13px;color:#666;border-top:1px solid #f0f0f0;padding-top:10px;cursor:pointer">
          <span onclick="segera('Feed')">👍 <b>1.253</b></span><span onclick="segera('Feed')">↗ <b>323</b></span>
        </div>
      </div>
    </div>
  </div>
</div>

<?= $this->endSection() ?>
