<?php

/**
 * Form data lowongan: membuat baru atau mengubah yang sudah ada.
 *
 * Tata letaknya mengikuti Recruitment Progress supaya dua halaman yang
 * berangkat dari daftar posisi yang sama terasa satu keluarga: kepala kuning,
 * badan putih, kaki berisi Save dan Close.
 *
 * Tiap kolom syarat diberi contoh, bukan cuma label. Contohnya diambil dari
 * lowongan impor tim DS yang sudah ada, dan itu disengaja: yang menentukan mutu
 * penilaian otomatis bukan kolomnya terisi atau tidak, melainkan terisi dengan
 * bentuk yang sama seperti data yang sudah dipakai mesin selama ini.
 */
$bingkai = $bingkai ?? false;
$job     = $job ?? [];
$errors  = $errors ?? [];

$nilai = static fn (string $k): string => (string) ($job[$k] ?? '');
?>
<?= $this->extend($bingkai ? 'layout_bingkai' : 'layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  .rp { border-radius: 8px; overflow: hidden; border: 1px solid #eef0f5; background: #fff; }
  .rp .kepala { background: #F7C22E; color: #4a3a00; font-weight: 700; font-size: 15px; padding: 13px 18px; }
  .rp .badan { padding: 18px; }
  .rp .kaki { background: #FFF9E6; padding: 12px 18px; display: flex; justify-content: flex-end; gap: 8px; }
  .rp .kaki button { padding: 8px 26px; border: none; border-radius: 6px; cursor: pointer;
                     font-family: inherit; font-weight: 600; font-size: 13px; }
  .btn-save { background: #1E8E5A; color: #fff; }
  .btn-close { background: #6C757D; color: #fff; }

  .isian { margin-bottom: 16px; }
  .isian label { display: block; font-size: 12.5px; font-weight: 600; color: #444; margin-bottom: 5px; }
  .isian input, .isian select, .isian textarea {
      width: 100%; box-sizing: border-box; border: 1px solid #e2e6ee; border-radius: 6px;
      padding: 9px 12px; font-size: 13px; font-family: inherit; color: #333; background: #fff; }
  .isian textarea { resize: vertical; line-height: 1.55; }
  .isian .bantu { font-size: 11.5px; color: #888; margin: 5px 0 0; line-height: 1.55; }
  .isian .opsional { font-weight: 500; color: #999; }
  .isian.salah input, .isian.salah select, .isian.salah textarea { border-color: #E23B4E; background: #FFFAFA; }
  .isian .galat { font-size: 11.5px; color: #a12734; margin: 5px 0 0; }

  .dua-kolom { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  @media (max-width: 720px) { .dua-kolom { grid-template-columns: 1fr; } }

  .catatan-ai { border: 1px solid #FFD9A0; background: #FFF9EF; border-radius: 8px;
                padding: 12px 14px; margin-bottom: 18px; font-size: 12.5px; color: #6b5626; line-height: 1.65; }
</style>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter') ?>"><span class="l"><span>🏠</span><span>Dashboard</span></span></a>
<a href="<?= site_url('recruiter/pengaturan') ?>"><span class="l"><span>⚙️</span><span>Pengaturan</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<form method="post" action="<?= site_url('recruiter/pengaturan/lowongan' . ($jobId === null ? '' : '/' . $jobId)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="bingkai" value="<?= $bingkai ? '1' : '0' ?>">

  <div class="rp">
    <div class="kepala"><?= $jobId === null ? 'Lowongan Baru' : 'Ubah Lowongan' ?></div>
    <div class="badan">

      <p class="catatan-ai">
        Yang Anda tulis di kolom syarat dibaca tiga bagian sistem: skor kemiripan CV,
        penyusun pertanyaan interview, dan penilai kecocokan posisi yang memutuskan boleh
        tidaknya AI meloloskan kandidat. Tulis keahlian yang benar-benar diuji, bukan sifat
        umum seperti "rajin" atau "jujur" - keduanya tidak bisa dibandingkan dengan apa pun
        di CV maupun di transkrip wawancara.
      </p>

      <div class="isian<?= isset($errors['judul']) ? ' salah' : '' ?>">
        <label for="judul">Nama posisi</label>
        <input id="judul" name="judul" required maxlength="160"
               placeholder="mis. Sales Assistant - Retail Gadget (Ibox)"
               value="<?= esc($nilai('judul'), 'attr') ?>">
        <?php if (isset($errors['judul'])): ?><p class="galat"><?= esc($errors['judul']) ?></p><?php endif ?>
      </div>

      <div class="isian<?= isset($errors['kategori']) ? ' salah' : '' ?>">
        <label for="kategori">Rumpun posisi</label>
        <select id="kategori" name="kategori" required>
          <option value="">- pilih rumpun -</option>
          <?php foreach ($kategori as $k): ?>
            <option value="<?= esc($k, 'attr') ?>"<?= $nilai('kategori') === $k ? ' selected' : '' ?>><?= esc($k) ?></option>
          <?php endforeach ?>
        </select>
        <p class="bantu">
          Menentukan bank soal mana yang bisa dipinjam posisi ini saat pembuatan pertanyaan
          dengan AI gagal atau kuotanya habis. Posisi tanpa rumpun berdiri sendirian tanpa cadangan.
        </p>
        <?php if (isset($errors['kategori'])): ?><p class="galat"><?= esc($errors['kategori']) ?></p><?php endif ?>
      </div>

      <div class="isian<?= isset($errors['req_skill']) ? ' salah' : '' ?>">
        <label for="req_skill">Keahlian yang dibutuhkan</label>
        <textarea id="req_skill" name="req_skill" rows="3" required
                  placeholder="mis. Perbandingan Spesifikasi, Istilah Teknis Dasar, Garansi &amp; After-sales, Cicilan &amp; Program Penjualan"><?= esc($nilai('req_skill')) ?></textarea>
        <p class="bantu">Dipisah koma. Inilah kolom yang paling menentukan hasil penilaian otomatis.</p>
        <?php if (isset($errors['req_skill'])): ?><p class="galat"><?= esc($errors['req_skill']) ?></p><?php endif ?>
      </div>

      <div class="dua-kolom">
        <div class="isian<?= isset($errors['req_pengalaman']) ? ' salah' : '' ?>">
          <label for="req_pengalaman">Pengalaman</label>
          <input id="req_pengalaman" name="req_pengalaman" required maxlength="160"
                 placeholder="mis. Entry level, terbuka untuk fresh graduate"
                 value="<?= esc($nilai('req_pengalaman'), 'attr') ?>">
          <?php if (isset($errors['req_pengalaman'])): ?><p class="galat"><?= esc($errors['req_pengalaman']) ?></p><?php endif ?>
        </div>

        <div class="isian">
          <label for="req_pendidikan">Pendidikan <span class="opsional">(boleh kosong)</span></label>
          <input id="req_pendidikan" name="req_pendidikan" maxlength="160"
                 placeholder="mis. SMA/SMK sederajat"
                 value="<?= esc($nilai('req_pendidikan'), 'attr') ?>">
        </div>
      </div>

      <div class="isian">
        <label for="deskripsi">Uraian pekerjaan <span class="opsional">(boleh kosong)</span></label>
        <textarea id="deskripsi" name="deskripsi" rows="3" maxlength="2000"
                  placeholder="mis. Retail Gadget Sales. Brand: Gadget umum. Level: Entry"><?= esc($nilai('deskripsi')) ?></textarea>
      </div>

    </div>
    <div class="kaki">
      <button type="submit" class="btn-save">Save</button>
      <a href="<?= site_url('recruiter/pengaturan') ?>" onclick="return tutupBingkai()">
        <button type="button" class="btn-close">Close</button></a>
    </div>
  </div>
</form>

<?= $this->include('partials/tutup_bingkai') ?>

<?= $this->endSection() ?>
