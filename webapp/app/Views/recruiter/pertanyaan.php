<?php
// Dibuka di dalam jendela pratinjau (dari tabel Interview HRD) atau sebagai
// halaman penuh. Di dalam bingkai, topbar dan sidebar dibuang: keduanya sudah
// ada di halaman induk. $q menjaga penanda itu ikut terbawa saat form dikirim,
// supaya setelah Simpan halamannya tidak berubah jadi versi utuh di dalam kotak.
$bingkai = $bingkai ?? false;
$q       = $bingkai ? '?bingkai=1' : '';
?>
<?= $this->extend($bingkai ? 'layout_bingkai' : 'layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  button { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; font-weight: 600; font-size: 13px; }
  .btn-buat { background: #2F6FED; color: #fff; }
  .btn-simpan { background: #20A277; color: #fff; }
  .btn-kembali { background: #f0f2f7; color: #555; }
  .btn-ulang { background: #f0f2f7; color: #555; padding: 8px 12px; font-size: 15px; line-height: 1; }
  .btn-ulang:hover { background: #DCE9FF; }
  .kartu-p { background: #fff; border: 1px solid #eef0f5; border-radius: 12px; padding: 20px 22px; }
  .baris-p { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
  .baris-p .no { width: 26px; height: 26px; flex-shrink: 0; border-radius: 50%; background: #FFF6E6;
                 color: #8a6d1e; display: flex; align-items: center; justify-content: center;
                 font-size: 12px; font-weight: 700; margin-top: 7px; }
  /* width:100%, BUKAN flex:1 - sejak rubrik ditambahkan, induk textarea adalah
     div biasa, jadi flex:1 tidak berlaku dan lebarnya jatuh ke bawaan browser
     (~20 kolom). Itu yang membuat kotaknya terlihat sempit dan penuh scrollbar. */
  .baris-p textarea { display: block; width: 100%; box-sizing: border-box;
                      padding: 10px 13px; border: 1px solid #e2e6ee; border-radius: 8px;
                      font-family: inherit; font-size: 14px; line-height: 1.6;
                      resize: vertical; min-height: 78px; }
  .baris-p textarea:focus { outline: none; border-color: #2F6FED; box-shadow: 0 0 0 3px rgba(47,111,237,.12); }
  .kosong { text-align: center; color: #999; padding: 34px 10px; }
  .tag { font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: 500; }
  .tag-hard { background: #DCE9FF; color: #2b4d8a; }
  .tag-soft { background: #E8F7EE; color: #1d6b3d; }
  .tag-komp { background: #FFF6E6; color: #8a6d1e; }
  .tag-bobot { background: #f0f2f7; color: #555; }
  .rubrik { font-size: 12px; color: #666; line-height: 1.55; margin-top: 5px; padding-left: 2px; }
  .rubrik p { margin: 2px 0; }
  .rubrik b { margin-right: 5px; }
</style>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter/tahap/interview_online') ?>"><span class="l"><span>👔</span><span>Interview HRD</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu-p">
  <h2 style="margin:0 0 4px"><?= esc($job['judul']) ?></h2>
  <p style="color:#888;font-size:13px;margin:0 0 18px">
    Dipakai untuk <b>semua kandidat</b> yang melamar posisi ini. Pertanyaan berikut
    rubriknya berasal dari bank soal tim DS; posisi di luar bank memakai pertanyaan
    yang dibuat AI dari uraian lowongan.
  </p>

  <?php if (! empty($pinjamDari)): ?>
    <div style="border:1px solid #F3B94A;background:#FFF6E6;border-radius:8px;padding:12px 14px;margin-bottom:16px">
      <b style="color:#8A5D00">Ini pinjaman, belum disetel untuk posisi ini</b>
      <p style="font-size:13px;margin:6px 0 0;color:#6b5320">
        Posisi ini belum punya bank pertanyaan sendiri, jadi yang ditampilkan diambil dari
        <b><?= esc($pinjamDari) ?></b> karena rumpun pekerjaannya ditebak sama. Periksa dan
        sesuaikan seperlunya, lalu tekan Simpan supaya menjadi milik posisi ini.
      </p>
    </div>
  <?php endif ?>

  <?php if ($pertanyaan === []): ?>
    <div class="kosong">
      <div style="font-size:34px">💬</div>
      <p style="margin:8px 0 0">Belum ada pertanyaan untuk posisi ini.</p>
      <p style="font-size:12px;margin:4px 0 0">Tekan <b>Buat dengan AI</b> di bawah untuk menyusunnya.</p>
    </div>
  <?php else: ?>
    <form method="post" action="<?= site_url('recruiter/pertanyaan/' . $job['id']) . $q ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <?php foreach ($pertanyaan as $i => $p): ?>
        <?php $r = is_array($p) ? $p : []; ?>
        <div class="baris-p">
          <span class="no"><?= $i + 1 ?></span>
          <div style="flex:1">
            <?php if ($r !== []): ?>
              <div style="display:flex;gap:6px;align-items:center;margin-bottom:5px;flex-wrap:wrap">
                <span class="tag tag-<?= stripos((string) ($r['kategori'] ?? ''), 'hard') !== false ? 'hard' : 'soft' ?>">
                  <?= esc($r['kategori'] ?? '') ?></span>
                <span class="tag tag-komp"><?= esc($r['kompetensi'] ?? '') ?></span>
                <?php if (! empty($r['bobot'])): ?>
                  <span class="tag tag-bobot">bobot <?= (int) $r['bobot'] ?></span>
                <?php endif ?>
              </div>
            <?php endif ?>
            <textarea name="pertanyaan[]" rows="3"
                      maxlength="<?= App\Controllers\Recruiter::MAKS_PANJANG_PERTANYAAN ?>"><?= esc(App\Controllers\Recruiter::teksPertanyaan($p)) ?></textarea>
            <?php if (! empty($r['indikator']) || ! empty($r['red_flag'])): ?>
              <div class="rubrik">
                <?php if (! empty($r['indikator'])): ?>
                  <p><b style="color:#1d6b3d">Jawaban baik</b> <?= esc($r['indikator']) ?></p>
                <?php endif ?>
                <?php if (! empty($r['red_flag'])): ?>
                  <p><b style="color:#a12734">Red flag</b> <?= esc($r['red_flag']) ?></p>
                <?php endif ?>
              </div>
            <?php endif ?>
          </div>
        </div>
      <?php endforeach ?>
      <p style="color:#999;font-size:12px;margin:2px 0 14px">
        Kosongkan sebuah kotak untuk menghapus pertanyaannya.
      </p>
      <button type="submit" class="btn-simpan">💾 Simpan Perubahan</button>
    </form>
  <?php endif ?>

  <hr style="border:none;border-top:1px solid #eef0f5;margin:20px 0 16px">

  <?php
    // Tombol AI HANYA muncul kalau posisi ini belum punya set dari bank tim DS.
    //
    // Pada posisi yang sudah punya, menekannya adalah penurunan mutu: pertanyaan
    // bank membawa kompetensi, indikator jawaban baik, red flag, dan bobot -
    // hasil kurasi manusia. Keluaran LLM cuma teks pertanyaan. Jadi "buat ulang"
    // di sana berarti menukar rubrik dengan teks polos, sekaligus memakai jatah
    // 20 panggilan per hari untuk hasil yang lebih miskin.
    //
    // Pinjaman dari posisi serumpun TIDAK dihitung sebagai milik sendiri:
    // recruiter di situ memang sedang memilih antara menerima pinjaman atau
    // menyusun yang khusus.
    $punyaBank = empty($pinjamDari) && array_filter($pertanyaan, 'is_array') !== [];
  ?>

  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?php if (! $punyaBank): ?>
      <form method="post" action="<?= site_url('recruiter/pertanyaan/' . $job['id']) . $q ?>" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="buat">
        <?php if ($pertanyaan === []): ?>
          <?php // Belum ada apa pun: ini aksi utama halaman, jadi tetap tombol penuh. ?>
          <button type="submit" class="btn-buat">✨ Buat dengan AI</button>
        <?php else: ?>
          <?php // Sudah ada isinya: membuat ulang jarang dipakai dan menimpa semuanya,
                // jadi diperkecil jadi ikon supaya tidak bersaing dengan tombol Simpan.
                // title + aria-label menjaga maksudnya tetap terbaca. ?>
          <button type="submit" class="btn-ulang"
                  title="Buat ulang seluruh pertanyaan dengan AI"
                  aria-label="Buat ulang seluruh pertanyaan dengan AI"
                  onclick="return confirm('Buat ulang akan MENGGANTI seluruh pertanyaan di halaman ini. Lanjutkan?')">🔄</button>
        <?php endif ?>
      </form>
    <?php endif ?>
    <?php if ($bingkai): ?>
      <?php // Di dalam bingkai, "kembali" berarti menutup jendelanya. Menavigasi
            // ke tabel di sini cuma menampilkan tabel kedua di dalam kotak kecil. ?>
      <button type="button" class="btn-kembali" onclick="parent.tutupJendela()">← Tutup</button>
    <?php else: ?>
      <a href="<?= site_url('recruiter/tahap/interview_online') ?>"><button type="button" class="btn-kembali">← Kembali ke Interview HRD</button></a>
    <?php endif ?>
  </div>

  <p style="color:#999;font-size:12px;margin:12px 0 0">
    <?php if ($punyaBank): ?>
      Pertanyaan ini berasal dari bank soal yang disusun tim rekrutmen, lengkap dengan
      indikator jawaban baik dan red flag. Silakan disunting bila perlu.
    <?php else: ?>
      Membuat pertanyaan memakai kuota harian layanan AI. Sekali dibuat, pertanyaannya
      tersimpan dan tidak perlu dibuat ulang tiap kandidat.
    <?php endif ?>
  </p>
</div>

<?= $this->endSection() ?>
