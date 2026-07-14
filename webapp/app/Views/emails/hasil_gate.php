<p>Halo <?= esc($nama ?? 'Kandidat') ?>,</p>

<?php if (($status ?? '') === 'passed'): ?>
<p>Selamat! Anda <b>lolos</b> tahap seleksi untuk posisi
<b><?= esc($posisi ?? '-') ?></b>. Tim kami akan menghubungi Anda untuk
tahap berikutnya - pantau terus portal kandidat dan email Anda.</p>
<?php else: ?>
<p>Terima kasih atas partisipasi Anda dalam proses seleksi posisi
<b><?= esc($posisi ?? '-') ?></b>. Setelah evaluasi menyeluruh, kami belum
dapat melanjutkan lamaran Anda ke tahap berikutnya.</p>

<p>Jangan berkecil hati - profil Anda tetap tersimpan dan Anda dapat
melamar kembali untuk posisi lain yang sesuai.</p>
<?php endif ?>

<p>Salam,<br>Tim Rekrutmen BIPROO</p>
