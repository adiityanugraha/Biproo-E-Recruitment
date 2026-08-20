<?php

use App\Libraries\AlurRekrutmen as A;

/**
 * Settings: alur rekrutmen per posisi (18 Agustus 2026).
 *
 * Tampilannya sengaja dibuat menyerupai web recruiter BIPROO supaya recruiter
 * yang sudah memakai sistem lama tidak perlu belajar dua tata letak: kepala
 * tabel biru, tombol Edit kuning, dan rangkaian tahap digambar sebagai anak
 * panah terpisah - biru untuk Assessment, hijau untuk Selection.
 *
 * Rangkaiannya digambar utuh, bukan diringkas jadi "6 tahap". Yang ditanyakan
 * orang saat membuka halaman ini bukan berapa tahapnya, melainkan apakah posisi
 * ini memakai Interview User - dan itu cuma terjawab kalau tahapnya terbaca.
 */
?>
<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  .kunci-warna { display: flex; align-items: center; gap: 18px; margin-bottom: 14px; }
  .kunci-warna span.k { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: #555; }
  .kunci-warna i { width: 15px; height: 15px; border-radius: 4px; display: inline-block; }
  .k-ass { background: #CFE2FB; } .k-sel { background: #CFEBDA; }

  .bingkai-tabel { border: 1px solid #e2e6ee; border-radius: 6px; overflow-x: auto; }
  table.posisi { border-collapse: collapse; width: 100%; min-width: 900px; background: #fff; }
  table.posisi th { background: #2E9BDA; color: #fff; font-weight: 600; font-size: 13px;
                    padding: 9px 12px; text-align: center; border: 1px solid #2E9BDA; }
  table.posisi td { border: 1px solid #eef0f5; padding: 9px 12px; font-size: 13px; vertical-align: middle; }
  table.posisi td.no { width: 44px; text-align: center; color: #777; }
  table.posisi td.aksi { width: 96px; text-align: center; }
  table.posisi td.nama { width: 250px; text-align: center; }

  /* Rangkaian tahap sebagai anak panah TERPISAH, sama dengan sistem lama:
     tiap tahap berdiri sendiri dengan sela di antaranya, bukan bersambung jadi
     satu pita. Selanya yang membuat batas antar tahap terbaca sekilas.

     clip-path, bukan gambar: ia ikut warna dan ikut lebar teksnya sendiri.
     Takik kiri dan ujung kanan sama-sama 11px, jadi celahnya tetap sejajar
     berapa pun panjang labelnya. */
  .alur { display: flex; align-items: center; gap: 4px; }
  .alur .tahap { flex: 0 0 auto; font-size: 12.5px; line-height: 1; white-space: nowrap;
                 padding: 10px 18px 10px 26px;
                 clip-path: polygon(0 0, calc(100% - 11px) 0, 100% 50%, calc(100% - 11px) 100%, 0 100%, 11px 50%); }
  /* Yang pertama rata kiri: tidak ada tahap sebelumnya untuk ditakik. */
  .alur .tahap:first-child { padding-left: 18px;
                             clip-path: polygon(0 0, calc(100% - 11px) 0, 100% 50%, calc(100% - 11px) 100%, 0 100%); }
  .alur .ass { background: #CFE2FB; color: #1f4f86; }
  .alur .sel { background: #CFEBDA; color: #1d6b3d; }
  /* Tahap yang ditandai manusia dibedakan tipis saja - bukan peringatan,
     cuma keterangan siapa yang mengisinya. */
  .alur .manual { font-style: italic; opacity: .92; }

  .btn-edit { padding: 5px 18px; border: 1px solid #F0C86A; border-radius: 6px; cursor: pointer;
              font-family: inherit; font-weight: 600; font-size: 12.5px;
              background: #FDF0D0; color: #8a6d1e; }
  .ket { font-size: 12.5px; color: #666; line-height: 1.65; margin: 0 0 14px; }

  .btn-tambah { padding: 7px 16px; border: none; border-radius: 6px; cursor: pointer;
                font-family: inherit; font-weight: 600; font-size: 12.5px;
                background: #1E8E5A; color: #fff; }
  /* Nama posisi merangkap tautan ke data lowongannya. Tidak diberi tombol
     sendiri: kolom Action sudah dipakai Edit alur, dan menambah tombol kedua di
     sana membuat tabelnya menjauh dari tampilan sistem lama tanpa perlu. */
  table.posisi td.nama a { color: #2E9BDA; font-weight: 600; }
  table.posisi td.nama a:hover { text-decoration: underline; }
</style>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter') ?>"><span class="l"><span>🏠</span><span>Dashboard</span></span></a>
<a href="<?= site_url('recruiter/kandidat') ?>"><span class="l"><span>👥</span><span>Kandidat</span></span></a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu">
  <div class="kunci-warna">
    <span class="k"><i class="k-ass"></i> Assessment</span>
    <span class="k"><i class="k-sel"></i> Selection</span>
    <span style="margin-left:auto">
      <a href="<?= site_url('recruiter/pengaturan/lowongan') ?>?bingkai=1"
         onclick="return bukaJendela(this.href, 'Lowongan Baru')">
        <button class="btn-tambah">+ Tambah Lowongan</button></a>
    </span>
  </div>

  <p class="ket">
    Tiap posisi punya rangkaian tahapnya sendiri. Tahap <i>miring</i> dikerjakan di luar sistem dan
    ditandai recruiter; sisanya digerakkan sistem dan tidak bisa dicabut - Keputusan Tahap 1,
    Interview HRD, dan Keputusan Akhir dipakai mesin penilai, jadi mencabutnya akan mematikan
    otomatisasinya tanpa ada yang kelihatan.
  </p>

  <p class="ket">
    Klik <b>nama posisi</b> untuk mengubah data lowongannya - judul, rumpun, dan kolom syarat
    yang dibaca mesin penilai. Tombol <b>Edit</b> mengatur rangkaian tahapnya.
  </p>

  <?php if ($daftar === []): ?>
    <p class="ket">Belum ada lowongan.</p>
  <?php else: ?>
    <div class="bingkai-tabel">
      <table class="posisi">
        <tr>
          <th style="width:44px">No</th>
          <th style="width:96px">Action</th>
          <th style="width:250px">Position Name</th>
          <th>Progress</th>
        </tr>
        <?php foreach ($daftar as $i => $j): ?>
          <tr>
            <td class="no"><?= $i + 1 ?></td>
            <td class="aksi">
              <?php // Dibuka di jendela pratinjau seperti Ruang Interview, jadi
                    // daftar posisinya tidak hilang saat menyunting satu baris. ?>
              <a href="<?= site_url('recruiter/pengaturan/alur/' . $j['id']) ?>?bingkai=1"
                 onclick="return bukaJendela(this.href, <?= esc(json_encode('Recruitment Progress - ' . $j['judul']), 'attr') ?>)">
                <button class="btn-edit">Edit</button></a>
            </td>
            <td class="nama">
              <a href="<?= site_url('recruiter/pengaturan/lowongan/' . $j['id']) ?>?bingkai=1"
                 onclick="return bukaJendela(this.href, <?= esc(json_encode('Ubah Lowongan - ' . $j['judul']), 'attr') ?>)"
                 title="Ubah data lowongan"><?= esc($j['judul']) ?></a>
            </td>
            <td>
              <div class="alur">
                <?php foreach ([A::ASSESSMENT => 'ass', A::SELECTION => 'sel'] as $grup => $kelas): ?>
                  <?php foreach ($j['alur'][$grup] as $t): ?>
                    <span class="tahap <?= $kelas ?><?= $t['wajib'] ? '' : ' manual' ?>"><?= esc($t['label']) ?></span>
                  <?php endforeach ?>
                <?php endforeach ?>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
      </table>
    </div>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
