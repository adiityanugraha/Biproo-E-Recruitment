<?php
use App\Libraries\PenilaianRubrik;

// Sama seperti halaman pertanyaan: bisa dibuka utuh atau di dalam jendela
// pratinjau di atas tabel Interview HRD.
$bingkai = $bingkai ?? false;
?>
<?= $this->extend($bingkai ? 'layout_bingkai' : 'layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  .kartu-w { background: #fff; border: 1px solid #eef0f5; border-radius: 12px; padding: 20px 22px; }
  .giliran { border-left: 3px solid #e2e6ee; padding: 0 0 14px 14px; margin-left: 6px; position: relative; }
  .giliran:last-child { padding-bottom: 0; }
  .giliran .titik { position: absolute; left: -7px; top: 4px; width: 11px; height: 11px; border-radius: 50%; background: #c9ccd4; }
  .giliran.lanjutan { border-left-color: #8b74d4; } .giliran.lanjutan .titik { background: #8b74d4; }
  .giliran.ulangi   { border-left-color: #F3B94A; } .giliran.ulangi .titik { background: #F3B94A; }
  .giliran.penutup  { border-left-color: #2E9E5B; } .giliran.penutup .titik { background: #2E9E5B; }
  .giliran .cap { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #9aa0ab; }
  .giliran .tanya { font-size: 14px; font-weight: 600; line-height: 1.55; margin: 3px 0 0; }
  .giliran .jawab { font-size: 13px; line-height: 1.65; color: #444; background: #FAFAFC;
                    border: 1px solid #eef0f5; border-radius: 8px; padding: 9px 12px; margin: 8px 0 0; }
  .giliran .menunggu { font-size: 13px; color: #a5771a; margin: 8px 0 0; font-style: italic; }
  .giliran .meta { font-size: 11px; color: #9aa0ab; margin: 6px 0 0; }
  .t { font-size: 11px; padding: 2px 9px; border-radius: 20px; font-weight: 600; }
  .t-kurang { background: #FDECEC; color: #a12734; }
  .t-cukup  { background: #FFF6E6; color: #8a6d1e; }
  .t-baik   { background: #E8F7EE; color: #1d6b3d; }
  .hidup { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 600; color: #1d6b3d; }
  .hidup .dot { width: 8px; height: 8px; border-radius: 50%; background: #2E9E5B; animation: kedip 1.4s infinite; }
  @keyframes kedip { 0%,100% { opacity: 1 } 50% { opacity: .25 } }
</style>
<?= $this->endSection() ?>

<?php if (! $bingkai): ?>
<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter/tahap/interview_online') ?>"><span class="l"><span>🎥</span><span>Interview HRD</span></span></a>
<a href="<?= site_url('recruiter/nilai/' . $appId) ?>"><span class="l"><span>📝</span><span>Form Penilaian</span></span></a>
<?= $this->endSection() ?>
<?php endif ?>

<?= $this->section('isi') ?>

<div class="kartu-w">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
    <div>
      <h2 style="margin:0 0 2px"><?= esc($app['nama']) ?></h2>
      <p style="color:#888;font-size:13px;margin:0"><?= esc($app['judul']) ?></p>
    </div>
    <div style="text-align:right">
      <div id="w-hidup"></div>
      <div style="font-size:12px;color:#888;margin-top:4px">Skor sementara AI:
        <b id="w-skor" style="font-size:15px;color:#2B2B2B"><?= $skorAi === null ? '-' : (int) $skorAi . '/100' ?></b>
      </div>
    </div>
  </div>

  <p style="font-size:12px;color:#9aa0ab;line-height:1.6;margin:14px 0 0">
    Transkrip bertambah sendiri selama kandidat berbicara. Skor di atas <b>belum tersimpan</b> -
    ia baru tercatat setelah Anda menyimpannya di
    <a href="<?= site_url('recruiter/nilai/' . $appId) ?>" style="color:#2F6FED">form penilaian</a>,
    di mana pilihannya sudah tercentang menurut penilaian AI dan boleh Anda ubah.
  </p>
</div>

<div class="kartu-w" style="margin-top:14px">
  <div id="w-dialog"></div>
</div>

<script>
(function () {
  var url = '<?= site_url('recruiter/wawancara/' . $appId . '/feed') ?>';
  var LABEL = <?= json_encode(PenilaianRubrik::LABEL, JSON_UNESCAPED_UNICODE) ?>;
  var CAP = { bank: 'Pertanyaan', lanjutan: 'Pendalaman dari jawaban sebelumnya',
              ulangi: 'Diulang - jawaban kurang tertangkap', penutup: 'Penutup' };

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function gambar(data) {
    var kotak = document.getElementById('w-dialog');
    if (!data.dialog.length) {
      kotak.innerHTML = '<p style="color:#9aa0ab;font-size:13px;margin:0">'
        + 'Kandidat belum memulai wawancara suara. Halaman ini memperbarui diri sendiri.</p>';
    } else {
      kotak.innerHTML = data.dialog.map(function (g) {
        var h = '<div class="giliran ' + esc(g.sumber) + '"><span class="titik"></span>'
              + '<div class="cap">' + esc(CAP[g.sumber] || g.sumber)
              + (g.kompetensi ? ' &middot; ' + esc(g.kompetensi) : '') + '</div>'
              + '<p class="tanya">' + esc(g.pertanyaan) + '</p>';
        if (g.sumber !== 'penutup') {
          h += g.jawaban
            ? '<div class="jawab">' + esc(g.jawaban) + '</div>'
            : '<p class="menunggu">Menunggu jawaban kandidat...</p>';
        }
        var meta = [];
        if (g.tingkat) meta.push('<span class="t t-' + esc(g.tingkat) + '">' + esc(LABEL[g.tingkat] || g.tingkat) + '</span>');
        if (g.alasan) meta.push(esc(g.alasan));
        if (g.keyakinan !== null && g.keyakinan < 0.6) {
          meta.push('<span style="color:#a12734">tangkapan suara kurang jernih ('
            + Math.round(g.keyakinan * 100) + '%)</span>');
        }
        if (meta.length) h += '<div class="meta">' + meta.join(' &middot; ') + '</div>';
        return h + '</div>';
      }).join('');
    }

    document.getElementById('w-skor').textContent = data.skorAi === null ? '-' : data.skorAi + '/100';
    document.getElementById('w-hidup').innerHTML = data.selesai
      ? '<span style="font-size:12px;font-weight:600;color:#1d6b3d">Wawancara selesai</span>'
      : '<span class="hidup"><span class="dot"></span>Memantau</span>';
    return data.selesai;
  }

  function tarik() {
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        // Berhenti menarik begitu sesinya tutup: tidak ada lagi yang berubah,
        // dan tab yang ditinggal terbuka semalaman tidak perlu terus meminta.
        if (!d.error && !gambar(d)) setTimeout(tarik, 3000);
      })
      .catch(function () { setTimeout(tarik, 8000); });
  }

  tarik();
})();
</script>

<?= $this->endSection() ?>
