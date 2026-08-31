<?php

use App\Models\SlotInterviewModel;

/**
 * Tabel pengaturan slot jadwal: tambah, ubah kuota, hapus.
 *
 * DIPAKAI DUA HALAMAN (31 Agustus 2026): recruiter mengatur jam Interview HRD,
 * atasan mengatur jam Interview User untuk posisinya. Isinya sama persis - yang
 * berbeda cuma cangkang halamannya - jadi menggandakannya berarti dua tempat
 * yang harus diubah tiap kali satu kolom berubah.
 *
 * $daftar - baris dari PengaturanSlot::daftar()
 * $aksi   - alamat tujuan formulirnya
 */
?>
<style>
  .slot-tabel { white-space: nowrap; border-collapse: collapse; width: 100%; }
  .slot-tabel th, .slot-tabel td { border: 1px solid #cfd8e3; padding: 7px 12px; font-size: 13px;
                                   text-align: center; vertical-align: middle; }
  .slot-tabel th { background: #1E88E5; color: #fff; font-weight: 700; font-size: 12px;
                   letter-spacing: .2px; border-color: #1877cc; }
  .slot-tabel tbody tr:hover td { background: #f7fafd; }
  .slot-gulir { overflow-x: auto; border: 1px solid #cfd8e3; border-radius: 8px; }

  .slot-lewat td { color: #9aa5b1; background: #fbfcfd; }
  .slot-penuh { color: #a12734; font-weight: 700; }
  .slot-tutup { color: #8a6d1e; font-weight: 700; }

  .slot-tambah { border: 1px solid #cfd8e3; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
  .slot-tambah h3 { margin: 0 0 10px; font-size: 14px; }
  .slot-baris { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
  .slot-baris label { display: block; font-size: 12px; font-weight: 600; color: #555; margin-bottom: 4px; }
  .slot-baris input[type=date], .slot-baris input[type=time], .slot-baris input[type=number] {
      width: auto; padding: 7px 10px; border: 1px solid #cfd8e3; border-radius: 7px;
      font-family: inherit; font-size: 13px; }
  .slot-ulangi { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: #444; }
  .slot-ulangi input { width: auto; margin: 0; }

  .slot-tombol { padding: 0 14px; height: 32px; border: 1px solid transparent; border-radius: 6px;
                 cursor: pointer; font-family: inherit; font-weight: 600; font-size: 12px; }
  .slot-simpan { background: #1E88E5; color: #fff; }
  .slot-kecil { height: 28px; padding: 0 10px; font-size: 11.5px; }
  .slot-hapus { background: #FDECEC; color: #a12734; border-color: #f3b7be; }
  .slot-selkuota { display: flex; gap: 5px; justify-content: center; align-items: center; }
  .slot-selkuota input { width: 62px; padding: 4px 6px; border: 1px solid #cfd8e3; border-radius: 6px;
                         font-family: inherit; font-size: 12.5px; text-align: center; }
  .slot-ket { font-size: 12.5px; color: #666; line-height: 1.7; margin: 0 0 14px; }
</style>

<div class="slot-tambah">
  <h3>Tambah slot</h3>
  <form method="post" action="<?= $aksi ?>" class="slot-baris">
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
    <label class="slot-ulangi">
      <input type="checkbox" name="ulangi" value="1">
      Ulangi untuk 7 hari kerja ke depan
    </label>
    <button type="submit" class="slot-tombol slot-simpan">Tambah</button>
  </form>
</div>

<?php if ($daftar === []): ?>
  <p class="slot-ket">
    Belum ada slot sama sekali, dan itu berarti <b>kandidat tidak bisa memilih jadwal</b>.
    Tambahkan minimal satu jam di atas, centang "ulangi" supaya berlaku sepekan.
  </p>
<?php else: ?>
  <div class="slot-gulir">
    <table class="slot-tabel">
      <thead>
        <tr>
          <th style="width:170px">Tanggal</th>
          <th style="width:80px">Jam</th>
          <th style="width:150px">Kuota</th>
          <th style="width:90px">Terisi</th>
          <th style="width:110px">Status</th>
          <th style="width:90px">Hapus</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($daftar as $d): ?>
          <tr class="<?= $d['lewat'] ? 'slot-lewat' : '' ?>">
            <td><?= esc(date('d M Y', strtotime($d['waktu']))) ?></td>
            <td><?= esc(substr($d['waktu'], 11, 5)) ?></td>
            <td>
              <form method="post" action="<?= $aksi ?>" class="slot-selkuota">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="kuota">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <input type="number" name="kuota" min="0"
                       max="<?= SlotInterviewModel::MAKS_KUOTA ?>" value="<?= (int) $d['kuota'] ?>">
                <button type="submit" class="slot-tombol slot-simpan slot-kecil">Simpan</button>
              </form>
            </td>
            <td><?= (int) $d['terpakai'] ?></td>
            <td>
              <?php if ((int) $d['kuota'] === 0): ?>
                <span class="slot-tutup">Ditutup</span>
              <?php elseif ($d['terpakai'] >= $d['kuota']): ?>
                <span class="slot-penuh">Penuh</span>
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
                <form method="post" action="<?= $aksi ?>"
                      onsubmit="return confirm('Hapus slot ini?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button type="submit" class="slot-tombol slot-hapus slot-kecil">Hapus</button>
                </form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php endif ?>
