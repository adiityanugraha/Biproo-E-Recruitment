<p>Halo <?= esc($nama ?? 'Kandidat') ?>,</p>

<p>Selamat! Anda diundang mengikuti <b>interview online</b> untuk posisi
<b><?= esc($posisi ?? '-') ?></b>.</p>

<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <td><b>Jadwal</b></td>
        <td><?= esc($jadwal ?? '-') ?></td>
    </tr>
    <tr>
        <td><b>Link Zoom</b></td>
        <td><a href="<?= esc($join_url ?? '#', 'attr') ?>"><?= esc($join_url ?? '-') ?></a></td>
    </tr>
</table>

<p>Beberapa hal yang perlu disiapkan:</p>
<ul>
    <li>Bergabunglah 5 menit sebelum jadwal dimulai.</li>
    <li>Kamera wajib menyala selama sesi berlangsung.</li>
    <li>Siapkan koneksi internet yang stabil dan tempat yang tenang.</li>
</ul>

<p>Salam,<br>Tim Rekrutmen BIPROO</p>
