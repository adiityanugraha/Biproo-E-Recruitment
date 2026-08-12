<?php use App\Libraries\LembarPenilaian; ?>
<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  button { padding: 9px 18px; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; font-weight: 600; font-size: 13px; }
  .btn-simpan { background: #20A277; color: #fff; }
  .btn-kembali { background: #f0f2f7; color: #555; }
  .kartu-n { background: #fff; border: 1px solid #eef0f5; border-radius: 12px; padding: 20px 22px; }
  .butir { border: 1px solid #eef0f5; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; }
  .butir .tanya { font-size: 14px; line-height: 1.6; margin: 6px 0 10px; }
  .tag { font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: 500; margin-right: 5px; }
  .tag-hard { background: #DCE9FF; color: #2b4d8a; }
  .tag-soft { background: #E8F7EE; color: #1d6b3d; }
  .tag-bobot { background: #f0f2f7; color: #555; }
  .rubrik { font-size: 12px; color: #666; line-height: 1.55; margin: 0 0 10px; }
  .rubrik p { margin: 2px 0; }
  .pilih { display: flex; gap: 8px; flex-wrap: wrap; }
  .pilih label { border: 1px solid #e2e6ee; border-radius: 8px; padding: 7px 16px; cursor: pointer; font-size: 13px; }
  .pilih input { margin-right: 6px; width: auto; }
  .pilih input:checked + span { font-weight: 700; }
  .catatan { width: 100%; box-sizing: border-box; margin-top: 8px; padding: 7px 11px;
             border: 1px solid #e2e6ee; border-radius: 8px; font-family: inherit; font-size: 13px; }
  /* matriks kompetensi x skala: satu baris per kompetensi, lima kolom radio */
  .lembar { border-collapse: collapse; }
  .lembar th, .lembar td { border: 1px solid #e2e6ee; padding: 7px 10px; text-align: center; font-size: 13px; }
  .lembar th { background: #FFF6E6; color: #8a6d1e; font-size: 12px; font-weight: 600; }
  .lembar th small { font-weight: 400; font-size: 10px; }
  .lembar tr:hover td { background: #fafbfd; }
</style>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter/tahap/interview_online?status=completed') ?>"><span class="l"><span>🏁</span><span>Completed</span></span></a>
<a href="<?= site_url('recruiter/cv/' . $app['id']) ?>" target="_blank" rel="noopener"><span class="l"><span>📄</span><span>CV Kandidat</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu-n">
  <h2 style="margin:0 0 2px"><?= esc($app['nama']) ?></h2>
  <p style="color:#888;font-size:13px;margin:0 0 16px"><?= esc($app['judul']) ?> &middot; <?= esc($app['email']) ?></p>

  <?php if ($sudah): ?>
    <div style="border:1px solid #cfd6e4;background:#f7f9fc;border-radius:8px;padding:12px 14px">
      <b>Kandidat ini sudah diputuskan.</b>
      <p style="font-size:13px;margin:6px 0 0;color:#555">
        Keputusan akhir hanya boleh sekali, dan riwayatnya bersifat tambah-saja.
        Rinciannya bisa dilihat di halaman review kandidat.
      </p>
    </div>
    <?php if ($penilaian !== []): ?>
      <p style="margin:16px 0 6px"><b>Penilaian yang tersimpan</b></p>
      <table>
        <tr><th>Butir</th><th>Nilai</th><th>Catatan</th></tr>
        <?php foreach ($penilaian as $p): ?>
          <?php
            // Satu tabel memuat dua jenis baris: kompetensi berangka dan kotak
            // narasi. Kolom Nilai menyesuaikan jenisnya.
            $kat  = $p['kategori'] ?? '';
            $t    = (string) ($p['tingkat'] ?? '');
            $nilai = match (true) {
                $kat === LembarPenilaian::KAT_HRD  => $t . ' - ' . (LembarPenilaian::SKALA[(int) $t] ?? '?'),
                $kat === LembarPenilaian::KAT_USER => $t . '/' . LembarPenilaian::MAKS_USER,
                default                            => '-',
            };
            $label = $kat === LembarPenilaian::KAT_NARASI
                ? (LembarPenilaian::NARASI[$p['kompetensi']] ?? $p['kompetensi'])
                : $p['kompetensi'];
          ?>
          <tr>
            <td><?= esc($label) ?></td>
            <td><?= esc($nilai) ?></td>
            <td><small><?= esc($p['catatan'] ?? '') ?></small></td>
          </tr>
        <?php endforeach ?>
        <?php if (($hasil = LembarPenilaian::hasil($gate2)) !== ''): ?>
          <tr>
            <td><b>Interview Result</b></td>
            <td colspan="2"><b><?= esc($hasil) ?></b></td>
          </tr>
        <?php endif ?>
      </table>
    <?php endif ?>

  <?php else: ?>
    <form method="post" action="<?= site_url('recruiter/interview/putus/' . $app['id']) ?>">
      <?= csrf_field() ?>

      <p style="color:#888;font-size:13px;margin:0 0 16px">
        Sembilan kompetensi ini sama untuk semua posisi, mengikuti lembar penilaian
        BIPROO. Pertanyaan yang diajukan tetap dari bank soal posisi ini
        <a href="<?= site_url('recruiter/pertanyaan/' . $app['job_id']) ?>?bingkai=1"
           onclick="return bukaJendela(this.href, 'Pertanyaan interview')">buka daftar pertanyaannya</a>.
      </p>

      <table class="lembar">
        <tr>
          <th style="text-align:left">Kompetensi</th>
          <?php foreach (LembarPenilaian::SKALA as $n => $label): ?>
            <th><?= $n ?><br><small><?= esc($label) ?></small></th>
          <?php endforeach ?>
        </tr>
        <?php foreach (LembarPenilaian::HRD as $i => $kompetensi): ?>
          <tr>
            <td style="text-align:left"><?= esc($kompetensi) ?></td>
            <?php foreach (array_keys(LembarPenilaian::SKALA) as $n): ?>
              <td><input type="radio" name="nilai[<?= $i ?>]" value="<?= $n ?>" required style="width:auto"></td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </table>

      <?php foreach (LembarPenilaian::NARASI as $kunci => $label): ?>
        <label style="margin-top:14px"><?= esc($label) ?></label>
        <textarea name="narasi[<?= $kunci ?>]" rows="2"
                  maxlength="<?= LembarPenilaian::MAKS_CATATAN ?>"
                  style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e2e6ee;border-radius:8px;font-family:inherit;font-size:13px"></textarea>
      <?php endforeach ?>

      <p style="color:#888;font-size:12px;margin:14px 0">
        Skor CV kandidat ini: <?= badge_skor($skorCv) ?>. Keputusan akhir menggabungkan
        keduanya, dan kandidat langsung dikabari lewat email - jadi tekan sekali saja.
      </p>
      <button type="submit" class="btn-simpan"
              onclick="return confirm('Simpan penilaian dan putuskan kandidat ini? Keputusan akhir tidak bisa diulang.')">
        Simpan &amp; Putuskan
      </button>
      <a href="<?= site_url('recruiter/tahap/interview_online?status=completed') ?>"><button type="button" class="btn-kembali">Batal</button></a>
    </form>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
