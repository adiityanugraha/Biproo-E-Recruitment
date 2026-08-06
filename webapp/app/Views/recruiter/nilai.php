<?php use App\Libraries\PenilaianRubrik; ?>
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
        <tr><th>Kompetensi</th><th>Bobot</th><th>Nilai</th><th>Catatan</th></tr>
        <?php foreach ($penilaian as $p): ?>
          <tr>
            <td><?= esc($p['kompetensi'] ?? '') ?></td>
            <td><?= (int) ($p['bobot'] ?? 0) ?></td>
            <td><?= esc(PenilaianRubrik::LABEL[$p['tingkat'] ?? ''] ?? '-') ?></td>
            <td><small><?= esc($p['catatan'] ?? '') ?></small></td>
          </tr>
        <?php endforeach ?>
      </table>
    <?php endif ?>

  <?php else: ?>
    <form method="post" action="<?= site_url('recruiter/interview/putus/' . $app['id']) ?>">
      <?= csrf_field() ?>

      <?php if (PenilaianRubrik::jumlahDinilai($rubrik) === 0): ?>
        <?php // Lowongan ini belum punya bank soal. Slider lama dipertahankan -
              // lebih baik jalur yang jujur daripada rubrik karangan. ?>
        <div style="border:1px solid #F3B94A;background:#FFF6E6;border-radius:8px;padding:12px 14px;margin-bottom:16px">
          <b style="color:#8A5D00">Posisi ini belum punya rubrik penilaian</b>
          <p style="font-size:13px;margin:6px 0 0;color:#6b5320">
            Penilaiannya masih memakai satu angka, tanpa rincian per kompetensi. Susun bank
            pertanyaannya lebih dulu bila ingin penilaian yang bisa dijelaskan.
          </p>
        </div>
        <p style="font-size:13px;margin:0 0 6px">Skor interview: <b id="sv">70</b>/100</p>
        <input type="range" name="skor" min="0" max="100" value="70" style="width:280px"
               oninput="document.getElementById('sv').textContent=this.value">
      <?php else: ?>
        <p style="color:#888;font-size:13px;margin:0 0 16px">
          Nilai tiap butir sambil mendengarkan. Skor akhir dihitung dari bobot tiap
          kompetensi, tidak diketik manusia. Butir pembuka seperti ekspektasi gaji
          sengaja tidak dinilai.
        </p>

        <?php $n = 0; ?>
        <?php foreach ($rubrik as $i => $soal): ?>
          <?php if (! PenilaianRubrik::dinilai($soal)) {
              continue;
          } ?>
          <?php $n++; ?>
          <div class="butir">
            <div>
              <span class="tag tag-<?= stripos((string) ($soal['kategori'] ?? ''), 'hard') !== false ? 'hard' : 'soft' ?>"><?= esc($soal['kategori'] ?? '') ?></span>
              <span class="tag tag-bobot">bobot <?= (int) $soal['bobot'] ?></span>
              <b style="font-size:13px"><?= esc($soal['kompetensi'] ?? '') ?></b>
            </div>
            <p class="tanya"><?= $n ?>. <?= esc($soal['pertanyaan'] ?? '') ?></p>

            <?php if (! empty($soal['indikator']) || ! empty($soal['red_flag'])): ?>
              <div class="rubrik">
                <?php if (! empty($soal['indikator'])): ?>
                  <p><b style="color:#1d6b3d">Jawaban baik</b> <?= esc($soal['indikator']) ?></p>
                <?php endif ?>
                <?php if (! empty($soal['red_flag'])): ?>
                  <p><b style="color:#a12734">Red flag</b> <?= esc($soal['red_flag']) ?></p>
                <?php endif ?>
              </div>
            <?php endif ?>

            <div class="pilih">
              <?php foreach (PenilaianRubrik::LABEL as $kunci => $label): ?>
                <label><input type="radio" name="nilai[<?= $i ?>]" value="<?= $kunci ?>" required><span><?= $label ?></span></label>
              <?php endforeach ?>
            </div>
            <input type="text" class="catatan" name="catatan[<?= $i ?>]"
                   maxlength="<?= PenilaianRubrik::MAKS_CATATAN ?>" placeholder="Catatan (opsional)">
          </div>
        <?php endforeach ?>
      <?php endif ?>

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
