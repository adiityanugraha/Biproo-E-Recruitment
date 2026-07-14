<p>Halo <?= esc($nama ?? 'Kandidat') ?>,</p>

<p>Ini pengingat bahwa <b>besok</b> Anda dijadwalkan mengikuti interview
online untuk posisi <b><?= esc($posisi ?? '-') ?></b>.</p>

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

<p>Sampai jumpa besok - jangan lupa kamera menyala dan bergabung 5 menit
lebih awal.</p>

<p>Salam,<br>Tim Rekrutmen BIPROO</p>
