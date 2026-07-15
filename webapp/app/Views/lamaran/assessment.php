<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Assessment - <?= esc($app['judul']) ?></h2>
  <p style="color:#666;font-size:13px">Jawab pertanyaan berikut untuk melanjutkan proses seleksi.
  (Placeholder - assessment sesungguhnya di luar cakupan sistem ini.)</p>

  <form method="post" action="<?= site_url('assessment/' . $app['id']) ?>">
    <?= csrf_field() ?>
    <label>Apakah Anda seorang kandidat?</label>
    <div style="display:flex;gap:24px;margin:10px 0 4px">
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin:0">
        <input type="radio" name="jawaban" value="ya" required style="width:auto"> Ya
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin:0">
        <input type="radio" name="jawaban" value="tidak" style="width:auto"> Tidak
      </label>
    </div>
    <button type="submit">Kirim Jawaban</button>
  </form>
</div>

<?= $this->endSection() ?>
