<p>Halo <?= esc($nama ?? 'Kandidat') ?>,</p>

<p>Jadwal interview Anda untuk posisi <b><?= esc($posisi ?? '-') ?></b> perlu
<b>dijadwalkan ulang</b>.</p>

<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <td><b>Jadwal sebelumnya</b></td>
        <td><?= esc($jadwal ?? '-') ?></td>
    </tr>
    <tr>
        <td><b>Alasan</b></td>
        <td><?= esc($alasan ?? 'tidak disebutkan') ?></td>
    </tr>
</table>

<p>Proses lamaran Anda <b>tetap berjalan</b> - yang berubah hanya waktunya.
Silakan masuk ke portal kandidat dan pilih slot lain yang tersedia. Slot yang
Anda tinggalkan sudah dilepas dan bisa dipakai kandidat lain, jadi sebaiknya
segera pilih ulang.</p>

<p>Mohon maaf atas ketidaknyamanannya.</p>

<p>Salam,<br>Tim Rekrutmen BIPROO</p>
