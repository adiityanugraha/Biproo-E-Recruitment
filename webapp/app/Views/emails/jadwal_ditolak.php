<p>Halo <?= esc($nama ?? 'Kandidat') ?>,</p>

<p>Terima kasih telah mengajukan jadwal interview untuk posisi
<b><?= esc($posisi ?? '-') ?></b>.</p>

<p>Mohon maaf, jadwal yang Anda ajukan (<b><?= esc($jadwal ?? '-') ?></b>) belum
dapat kami setujui. Silakan masuk ke portal kandidat dan ajukan jadwal lain yang
memungkinkan, agar tim kami dapat mengatur interview Anda.</p>

<p>Salam,<br>Tim Rekrutmen BIPROO</p>
