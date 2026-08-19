<p>Halo <?= esc($nama ?? 'Bapak/Ibu') ?>,</p>

<p>Anda ditunjuk sebagai pewawancara <b>Interview User</b> untuk posisi
<b><?= esc($posisi ?? '-') ?></b>. Berikut akun untuk masuk ke halaman wawancara.</p>

<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <td><b>Halaman masuk</b></td>
        <td><a href="<?= esc($url ?? '#', 'attr') ?>"><?= esc($url ?? '-') ?></a></td>
    </tr>
    <tr>
        <td><b>Email</b></td>
        <td><?= esc($email ?? '-') ?></td>
    </tr>
    <tr>
        <td><b>Kata sandi</b></td>
        <td><code style="font-size:15px;letter-spacing:1px"><?= esc($sandi ?? '-') ?></code></td>
    </tr>
</table>

<p>Yang bisa Anda lakukan dengan akun ini:</p>
<ul>
    <li>Melihat kandidat posisi <b><?= esc($posisi ?? '-') ?></b> yang sudah lolos tahap HRD.</li>
    <li>Membaca lembar profil, riwayat kerja, dan hasil wawancara HRD-nya.</li>
    <li>Mengisi penilaian wawancara Anda, lalu memutuskan kandidat diterima atau tidak.</li>
</ul>

<?php
// Batas kewenangannya disebut terang-terangan, bukan disembunyikan. Atasan yang
// mengira akunnya bisa melihat seluruh pelamar akan mengira sistemnya rusak saat
// daftarnya cuma berisi satu posisi - dan menghubungi HRD untuk hal yang memang
// disengaja.
?>
<p style="color:#666;font-size:13px">Akun ini hanya berlaku untuk posisi tersebut dan tidak
bisa melihat pelamar posisi lain. Keputusan Anda dikirimkan langsung ke kandidat lewat email,
jadi mohon dipastikan sebelum disimpan.</p>

<p style="color:#a53a1c;font-size:13px">Kata sandi di atas dikirim sekali dan tidak disimpan
dalam bentuk terbaca oleh siapa pun, termasuk tim HRD. Bila hilang, mintalah HRD menerbitkan
yang baru.</p>

<p>Terima kasih.<br>Tim Rekrutmen BIPROO</p>
