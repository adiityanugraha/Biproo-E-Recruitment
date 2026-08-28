<?php

use App\Models\SlotInterviewModel;

/**
 * Pengaturan slot jadwal interview (28 Agustus 2026, permintaan atasan).
 *
 * Sampai hari itu slotnya terkunci di kode. Halaman ini yang membuka kuncinya:
 * recruiter menambah, menutup, menghapus jam, dan menentukan berapa kandidat
 * yang boleh mengambil tiap jam.
 *
 * Kolom "Terisi" penting: tanpanya recruiter menurunkan kuota atau menghapus
 * slot tanpa tahu ada orang yang sudah memegangnya.
 */
?>
<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  table { white-space: nowrap; border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #cfd8e3; padding: 7px 12px; font-size: 13px;
           text-align: center; vertical-align: middle; }
  th { background: #1E88E5; color: #fff; font-weight: 700; font-size: 12px;
       letter-spacing: .2px; border-color: #1877cc; }
  tbody tr:hover td { background: #f7fafd; }
  .scroll-x { overflow-x: auto; border: 1px solid #cfd8e3; border-radius: 8px; }

  .lewat td { color: #9aa5b1; background: #fbfcfd; }
  .penuh { color: #a12734; font-weight: 700; }
  .tutup { color: #8a6d1e; font-weight: 700; }

  .tambah { border: 1px solid #cfd8e3; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
  .tambah h3 { margin: 0 0 10px; font-size: 14px; }
  .baris { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
  .baris label { display: block; font-size: 12px; font-weight: 600; color: #555; margin-bottom: 4px; }
  .baris input[type=date], .baris input[type=time], .baris input[type=number] {
      width: auto; padding: 7px 10px; border: 1px solid #cfd8e3; border-radius: 7px;
      font-family: inherit; font-size: 13px; }
  .ulangi { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: #444; }
  .ulangi input { width: auto; margin: 0; }

  button { padding: 0 14px; height: 32px; border: 1px solid transparent; border-radius: 6px;
           cursor: pointer; font-family: inherit; font-weight: 600; font-size: 12px; }
  .b-simpan { background: #1E88E5; color: #fff; }
  .b-kecil { height: 28px; padding: 0 10px; font-size: 11.5px; }
  .b-hapus { background: #FDECEC; color: #a12734; border-color: #f3b7be; }
  .sel-kuota { display: flex; gap: 5px; justify-content: center; align-items: center; }
  .sel-kuota input { width: 62px; padding: 4px 6px; border: 1px solid #cfd8e3; border-radius: 6px;
                     font-family: inherit; font-size: 12.5px; text-align: center; }
  .ket { font-size: 12.5px; color: #666; line-height: 1.7; margin: 0 0 14px; }
</style>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<?= $this->include('recruiter/sisi_tahap') ?>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<div class="kartu">
  <h2><?= esc($judul) ?></h2>

  <p class="ket">
    Kandidat memilih jadwal wawancara dari daftar ini. <b>Kuota</b> menentukan berapa orang
    yang boleh mengambil satu jam - jam 10.00 berkuota 2 berarti dua kandidat bisa
    mendaftar di jam itu sebelum penuh. Tiap kandidat tetap mendapat ruang Zoom sendiri.<br>
    Kuota dihitung <b>terpisah</b> untuk Interview HRD dan Interview User, karena
    pewawancaranya orang yang berbeda.
  </p>

  <div class="tambah">
    <h3>Tambah slot</h3>
    <form method="post" action="<?= site_url('recruiter/pengaturan/jadwal') ?>" class="baris">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tambah">
      <div>
        <label for="tanggal">Tanggal</label>
        <input type="date" id="tanggal" name="tanggal" required value="<?= esc(date('Y-m-d')) ?>">
      </div>
      <div>
        <label for="jam">Jam</label>
        <input type="time" id="jam" name="jam" required step="3600" value="10:00">
      </div>
      <div>
        <label for="kuota">Kuota</label>
        <input type="number" id="kuota" name="kuota" required min="1"
               max="<?= SlotInterviewModel::MAKS_KUOTA ?>" value="1">
      </div>
      <label class="ulangi">
        <input type="checkbox" name="ulangi" value="1">
        Ulangi untuk 7 hari kerja ke depan
      </label>
      <button type="submit" class="b-simpan">Tambah</button>
    </form>
  </div>

  <?php if ($daftar === []): ?>
    <p class="ket">
      Belum ada slot sama sekali, dan itu berarti <b>kandidat tidak bisa memilih jadwal</b>.
      Tambahkan minimal satu jam di atas, centang "ulangi" supaya berlaku sepekan.
    </p>
  <?php else: ?>
    <div class="scroll-x">
      <table>
        <thead>
          <tr>
            <th style="width:170px">Tanggal</th>
            <th style="width:80px">Jam</th>
            <th style="width:150px">Kuota</th>
            <th style="width:170px">Terisi</th>
            <th style="width:110px">Status</th>
            <th style="width:90px">Hapus</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($daftar as $d): ?>
            <tr class="<?= $d['lewat'] ? 'lewat' : '' ?>">
              <td><?= esc(date('d M Y', strtotime($d['waktu']))) ?></td>
              <td><?= esc(substr($d['waktu'], 11, 5)) ?></td>
              <td>
                <form method="post" action="<?= site_url('recruiter/pengaturan/jadwal') ?>" class="sel-kuota">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="kuota">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <input type="number" name="kuota" min="0"
                         max="<?= SlotInterviewModel::MAKS_KUOTA ?>" value="<?= (int) $d['kuota'] ?>">
                  <button type="submit" class="b-simpan b-kecil">Simpan</button>
                </form>
              </td>
              <td>
                <?php // Dipisah per jenis: kuotanya memang dihitung terpisah,
                      // jadi menjumlahkannya di layar akan menyesatkan. ?>
                HRD <?= (int) $d['hrd'] ?> &middot; User <?= (int) $d['user'] ?>
              </td>
              <td>
                <?php if ((int) $d['kuota'] === 0): ?>
                  <span class="tutup">Ditutup</span>
                <?php elseif ($d['hrd'] >= $d['kuota'] && $d['user'] >= $d['kuota']): ?>
                  <span class="penuh">Penuh</span>
                <?php elseif ($d['lewat']): ?>
                  Sudah lewat
                <?php else: ?>
                  Terbuka
                <?php endif ?>
              </td>
              <td>
                <?php if ($d['terpakai'] > 0): ?>
                  <?php // Tidak ditawarkan sama sekali, bukan ditawarkan lalu
                        // ditolak: tombol yang selalu gagal cuma membuat orang
                        // menekannya berulang kali. ?>
                  <span style="color:#9aa5b1;font-size:11.5px">dipakai</span>
                <?php else: ?>
                  <form method="post" action="<?= site_url('recruiter/pengaturan/jadwal') ?>"
                        onsubmit="return confirm('Hapus slot ini?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button type="submit" class="b-hapus b-kecil">Hapus</button>
                  </form>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
