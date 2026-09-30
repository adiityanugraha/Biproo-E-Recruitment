<?= $this->extend('layout') ?>

<?= $this->section('gaya') ?>
<style>
  .wp-status { display: inline-block; padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 600;
               background: #EEF1F6; color: #5a5a5a; }
  .wp-status.bicara    { background: #E8EFFE; color: #23509f; }
  .wp-status.dengar    { background: #E8F7EE; color: #1d6b3d; }
  .wp-status.tunggu    { background: #FFF9EC; color: #a5771a; }
  .wp-status.kalibrasi { background: #F3EEFB; color: #5b3f9e; }
  .wp-status.kirim     { background: #EEF1F6; color: #5a5a5a; }
  .wp-status.selesai   { background: #E8F7EE; color: #1d6b3d; }
  .wp-status.gagal     { background: #FDECEC; color: #a12734; }

  .wp-tanya { background: linear-gradient(120deg, #FFF6E6, #F3EEFB); border: 1px solid #F3B94A;
              border-radius: 16px; padding: 22px 24px; margin: 16px 0; }
  .wp-tanya .lbl { font-size: 11px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: #a5771a; }
  .wp-tanya .teks { font-size: 19px; font-weight: 600; line-height: 1.5; margin-top: 8px; color: #2B2B2B; }

  .wp-meter { height: 12px; background: #EEF1F6; border-radius: 999px; overflow: hidden; margin: 14px 0 6px; }
  .wp-bar { height: 100%; width: 0; background: #c9ccd4; border-radius: 999px; transition: width .08s linear; }
  .wp-bar.aktif { background: linear-gradient(90deg, #2E9E5B, #7ED09B); }

  .wp-jawaban { background: #FAFAFC; border: 1px solid #eef0f5; border-radius: 12px; padding: 14px 16px; font-size: 14px; line-height: 1.6; }
  .wp-jawaban .sementara { color: #9aa0ab; font-style: italic; }
  .wp-buangan { margin: 8px 0 0; padding-left: 18px; font-size: 12px; color: #9aa0ab; }
  .wp-kendali { display: flex; gap: 10px; flex-wrap: wrap; }
  .wp-kendali button { margin-top: 0; background: #fff; color: #5a5a5a; border: 1px solid #d8dce4; font-size: 13px; padding: 9px 16px; }
</style>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu">
  <h2>Wawancara Suara AI - <?= esc($app['judul']) ?></h2>
  <p style="color:#666;font-size:13px;margin-top:-6px">
    Sesi ini <b>mendampingi</b> interview Zoom Anda, bukan menggantikannya. Biarkan jendela Zoom tetap
    terbuka di sebelah - recruiter mengikuti dari sana.
  </p>

  <div style="background:#FFF9EC;border:1px solid #F3B94A;border-radius:10px;padding:12px 14px;font-size:13px;color:#7a5a12">
    <b>Sebelum mulai, tiga hal:</b>
    <ol style="margin:8px 0 0;padding-left:20px;line-height:1.7">
      <li>Pakai <b>Google Chrome</b> atau <b>Microsoft Edge</b>. Browser lain belum bisa mengenali suara.</li>
      <li>Gunakan <b>pengeras suara</b>, jangan headset - supaya recruiter ikut mendengar pertanyaan yang saya bacakan lewat Zoom.</li>
      <li>Saat diminta, izinkan akses <b>mikrofon</b>. Suara Anda diproses di komputer Anda sendiri; yang dikirim ke sistem hanya teksnya.</li>
    </ol>
  </div>
</div>

<?php if ($selesai): ?>
  <div class="kartu">
    <div class="wp-status selesai">Wawancara AI untuk lamaran ini sudah selesai</div>
    <p style="margin:14px 0 0;font-size:14px;color:#666">
      Transkrip dan penilaiannya sudah diteruskan ke recruiter. Silakan lanjutkan percakapan di Zoom.
    </p>
    <p class="tautan" style="margin-top:10px"><a href="<?= site_url('jadwal') ?>" style="color:#2F6FED">Kembali ke jadwal</a></p>
  </div>
<?php else: ?>

<div class="kartu">
  <div id="wp-status" class="wp-status">Siap memulai<?= $terjawab > 0 ? ' - melanjutkan sesi yang tertunda' : '' ?></div>

  <div class="wp-tanya">
    <div class="lbl" id="wp-label">Pertanyaan wawancara</div>
    <div class="teks" id="wp-pertanyaan">Tekan tombol di bawah untuk memulai. Saya akan menyapa lebih dulu, lalu bertanya.</div>
    <div style="font-size:12px;color:#8a6d1e;margin-top:10px" id="wp-kemajuan"></div>
  </div>

  <div class="wp-meter"><div class="wp-bar" id="wp-bar"></div></div>
  <div style="font-size:12px;color:#9aa0ab">Meteran suara masuk. Batang menghijau saat suara Anda melewati ambang derau ruangan.</div>

  <div id="wp-jawaban" class="wp-jawaban" style="display:none;margin-top:14px">
    <span id="wp-transkrip"></span> <span class="sementara" id="wp-sementara"></span>
  </div>

  <div id="wp-buangan-kotak" style="display:none;margin-top:10px">
    <div style="font-size:12px;font-weight:600;color:#9aa0ab">Suara yang diabaikan penyaring:</div>
    <ul class="wp-buangan" id="wp-buangan"></ul>
  </div>

  <button type="button" id="wp-mulai">Mulai Wawancara Suara</button>

  <div class="wp-kendali" id="wp-kendali" style="display:none;margin-top:16px">
    <button type="button" id="wp-ulang">Ulangi pertanyaan</button>
    <button type="button" id="wp-selesai">Saya sudah selesai menjawab</button>
  </div>

  <div id="wp-penutup" style="display:none;margin-top:16px">
    <p style="font-size:14px;color:#1d6b3d;margin:0"><b>Sesi selesai.</b> Silakan lanjutkan percakapan dengan recruiter di Zoom.</p>
    <p class="tautan" style="margin-top:8px"><a href="<?= site_url('jadwal') ?>" style="color:#2F6FED">Kembali ke jadwal</a></p>
  </div>
</div>

<script src="<?= base_url('js/wawancara.js') ?>"></script>
<script>
  RuangWawancara.pasang({
    urlMulai: '<?= site_url('wawancara/' . $appId . '/mulai') ?>',
    urlJawab: '<?= site_url('wawancara/' . $appId . '/jawab') ?>',
    csrfName: '<?= csrf_token() ?>',
    csrf: '<?= csrf_hash() ?>',
    sapaan: <?= json_encode($sapaan, JSON_UNESCAPED_UNICODE) ?>
  });
</script>

<?php endif ?>

<?= $this->endSection() ?>
