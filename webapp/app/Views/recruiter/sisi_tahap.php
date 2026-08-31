<?php

/**
 * Sidebar tahap rekrutmen.
 *
 * Dipisah dari tahap.php pada 28 Agustus 2026, ketika halaman pengaturan slot
 * jadwal jadi anak dari Settings tahap wawancara: halaman itu punya sidebarnya
 * sendiri yang isinya berbeda, sehingga menekan Settings terasa seperti
 * terlempar ke aplikasi lain. Sekarang keduanya membaca berkas yang sama.
 *
 * $stage - slug tahap yang sedang dibuka
 * $aktif - kunci tab yang disorot; halaman slot mengirim 'settings'
 */
$aktif = $aktif ?? ($status ?? '');
$base  = site_url('recruiter/tahap/' . $stage);

if (($stage ?? '') === 'upload_cv') {
    // Tidak ada keputusan lolos/gagal di tahap unggah - satu tab saja
    $tabs = ['uploaded' => ['Uploaded', '📥', $base]];
} elseif (in_array($stage ?? '', ['interview_online', 'interview_user'], true)) {
    // Tahap wawancara punya alurnya sendiri: terjadwal -> (dilepas) -> selesai.
    // Tidak ada "Passed"/"Failed" di sini, karena interview yang terjadwal
    // belum lolos apa-apa dan jadwal yang dilepas bukan kandidat yang gugur.
    $tabs = [
        'progress'    => ['On Progress', '🔄', $base],
        'rescheduled' => ['Rescheduled', '🔁', $base . '?status=rescheduled'],
        'completed'   => ['Completed', '🏁', $base . '?status=completed'],
    ];
} else {
    $tabs = [
        'progress' => ['On Progress', '🔄', $base],
        'passed'   => ['Passed', '✅', $base . '?status=passed'],
        'failed'   => ['Failed', '❌', $base . '?status=failed'],
    ];
}

foreach ($tabs as $k => [$lbl, $ic, $url]): ?>
  <a href="<?= $url ?>" class="<?= $aktif === $k ? 'on' : '' ?>">
    <span class="l"><span><?= $ic ?></span><span><?= $lbl ?></span></span><span><?= $aktif === $k ? '»' : '' ?></span></a>
<?php endforeach ?>
<?php // Settings HANYA di tahap Interview HRD, karena hanya jam wawancara
      // HRD yang diatur recruiter. Jam Interview User diatur atasan tiap posisi
      // lewat akunnya sendiri (31 Agustus 2026), jadi menaruh tombolnya di
      // tahap itu cuma menjanjikan setelan yang bukan miliknya. ?>
<?php if ($stage === 'interview_online'): ?>
  <a href="<?= site_url('recruiter/pengaturan/jadwal') ?>"
     class="<?= $aktif === 'settings' ? 'on' : '' ?>">
    <span class="l"><span>⚙️</span><span>Settings</span></span><span><?= $aktif === 'settings' ? '»' : '' ?></span></a>
<?php else: ?>
  <a href="#" onclick="segera('Settings');return false"><span class="l"><span>⚙️</span><span>Settings</span></span></a>
<?php endif ?>
<a href="#" onclick="segera('Upload History');return false"><span class="l"><span>🕘</span><span>Upload History</span></span></a>
