<?= $this->extend('layout') ?>
<?= $this->section('isi') ?>

<style>
  .sched { display:flex; gap:14px; overflow-x:auto; padding-bottom:6px; }
  .sched .c { flex:0 0 230px; border:1px solid #eef0f5; border-radius:12px; padding:14px; background:#FAFAFC; font-size:12px; }
  .sched .c .d { font-weight:700; color:#F7941D; font-size:13px; margin-bottom:8px; }
  .sched .c div { margin-top:3px; color:#555; }

  .cal { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; }
  .cal .hd { text-align:center; font-weight:700; font-size:12px; padding:8px 0 6px; }
  .cal .day { min-height:70px; border-radius:10px; padding:6px; border:1px solid #eef0f5; background:#fff; }
  .cal .day.empty { background:#F7F8FB; border:none; }
  .cal .num { font-size:12px; font-weight:600; }
  .cal .day.today .num { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#F7941D; color:#fff; }
  .cal .ev { margin-top:4px; color:#fff; font-size:10px; font-weight:600; border-radius:6px; padding:2px 5px; }
  .cal .on { background:#1E73E8; } .cal .off { background:#2E9E5B; }

  .chat { background:#fff; border-radius:14px; box-shadow:0 4px 14px rgba(0,0,0,.05); overflow:hidden; display:flex; flex-direction:column; height:640px; }
  .chat .hd { padding:14px 18px; font-weight:700; font-size:15px; text-align:center; border-bottom:1px solid #eef0f5; }
  .chat .body { padding:16px; flex:1; overflow-y:auto; }
  .chat .warn { background:#FEF6E0; border:1px solid #F3D98A; border-radius:12px; padding:14px; }
  .chat .warn b { color:#a5771a; text-decoration:underline; display:block; margin-bottom:6px; }
  .chat .warn p { font-size:12px; line-height:1.6; color:#7a6320; margin:0; }
  .chat .msgs { margin-top:14px; background:#F2F4F8; border-radius:12px; min-height:180px; display:flex; align-items:center; justify-content:center; color:#b5b5b5; font-size:13px; }
  .chat .foot { padding:12px; border-top:1px solid #eef0f5; display:flex; gap:8px; }
  .chat .foot input { flex:1; margin:0; }
  .chat .foot button { margin:0; width:44px; padding:0; height:42px; }
</style>

<div class="dash">
  <div>
    <div class="banner">
      <span class="b-c" style="width:150px;height:150px;left:-40px;top:-30px"></span>
      <span class="b-c" style="width:120px;height:120px;left:30px;bottom:-60px"></span>
      <h2>Welcome to the E-Recruitment System!</h2>
      <p>E-Recruitment is a digital-based employee recruitment system.</p>
      <span class="tgl">📅 <?= date('d M, Y') ?></span>
    </div>

    <div class="kpis">
      <?php foreach ($kpi as $k): ?>
        <div class="kpi"><div class="v"><?= esc($k['v']) ?></div><div class="t"><?= esc($k['t']) ?></div></div>
      <?php endforeach ?>
    </div>

    <div class="kartu">
      <div class="qa">
        <?php
        $aksi = [
            ['FPK', '📄', site_url('recruiter/report')],
            ['SK Posting', '📝', null],
            ['Job Posting', '📢', null],
            ['Upload Candidat', '📤', null],
            ['Summary', '📊', site_url('recruiter/report')],
            ['Settings', '⚙️', null],
        ];
        foreach ($aksi as [$lbl, $ic, $url]): ?>
          <?php if ($url): ?>
            <a class="item" href="<?= $url ?>"><span class="circ"><?= $ic ?></span><span class="lbl"><?= esc($lbl) ?></span></a>
          <?php else: ?>
            <div class="item" onclick="segera('<?= esc($lbl, 'js') ?>')"><span class="circ"><?= $ic ?></span><span class="lbl"><?= esc($lbl) ?></span></div>
          <?php endif ?>
        <?php endforeach ?>
      </div>
    </div>

    <div class="kartu">
      <h2 style="margin-bottom:16px">Alur Proses Rekrutmen</h2>
      <?php
      $stepCard = static function (string $label, string $icon, ?string $url): string {
          $isi = '<span class="ic">' . $icon . '</span><span class="nm">' . esc($label) . '</span>';

          return $url !== null
              ? '<a class="card" href="' . $url . '">' . $isi . '</a>'
              : '<a class="card" href="#" onclick="segera(\'' . esc($label, 'js') . '\');return false">' . $isi . '</a>';
      };
      ?>
      <div class="stepper-cols">
        <div>
          <h3>I. Assessment Process</h3>
          <?php foreach ($assessmentSteps as [$label, $icon, $url]): ?>
            <div class="step done"><span class="dot"></span><?= $stepCard($label, $icon, $url) ?></div>
          <?php endforeach ?>
        </div>
        <div>
          <h3>II. Selection Process</h3>
          <?php foreach ($selectionSteps as [$label, $icon, $url]): ?>
            <div class="step done"><span class="dot"></span><?= $stepCard($label, $icon, $url) ?></div>
          <?php endforeach ?>
        </div>
      </div>
    </div>

    <div class="kartu">
      <h2>- Schedule &amp; Task</h2>
      <div class="sched">
        <?php foreach ($schedule as [$tgl, $program, $recruiter, $feedback]): ?>
          <div class="c">
            <div class="d"><?= esc($tgl) ?></div>
            <div><b style="color:#2B2B2B">Program:</b> <?= esc($program) ?></div>
            <div><b style="color:#2B2B2B">Recruiter:</b> <?= esc($recruiter) ?></div>
            <div><b style="color:#2B2B2B">Feedback:</b> <?= esc($feedback) ?></div>
          </div>
        <?php endforeach ?>
      </div>
    </div>

    <div class="kartu">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <button style="margin:0;padding:6px 14px;font-size:13px" onclick="segera('Bulan Sebelumnya')">‹ Prev</button>
        <b>Juli, 2026</b>
        <button style="margin:0;padding:6px 14px;font-size:13px" onclick="segera('Bulan Berikutnya')">Next ›</button>
      </div>
      <div class="cal">
        <?php foreach ($weekDays as [$nm, $warna, $border]): ?>
          <div class="hd" style="color:<?= $warna ?>;border-top:3px solid <?= $border ?>"><?= $nm ?></div>
        <?php endforeach ?>
        <?php foreach ($calendar as $c): ?>
          <?php if ($c['day'] === ''): ?>
            <div class="day empty"></div>
          <?php else: ?>
            <div class="day <?= $c['today'] ? 'today' : '' ?>">
              <span class="num"><?= $c['day'] ?></span>
              <?php if ($c['on']): ?><div class="ev on">Online: <?= $c['on'] ?></div><?php endif ?>
              <?php if ($c['off']): ?><div class="ev off">Offline: <?= $c['off'] ?></div><?php endif ?>
            </div>
          <?php endif ?>
        <?php endforeach ?>
      </div>
    </div>
  </div>

  <div>
    <div class="chat">
      <div class="hd">Chatting</div>
      <div class="body">
        <div class="warn">
          <b>Peringatan</b>
          <p>Ini adalah fitur <b style="display:inline;text-decoration:none">Obrolan Global</b>, semua user di divisi terkait yang kamu pilih bisa melihat obrolan ini, jadi fitur ini tidak untuk obrolan personal ataupun obrolan yang sensitif!</p>
        </div>
        <div style="margin-top:14px">
          <label style="font-size:12px">Divisi yang ingin dihubungi:</label>
          <select onchange="segera('Obrolan Divisi')"><option>-- Pilih --</option></select>
        </div>
        <div class="msgs">Belum ada pesan</div>
      </div>
      <div class="foot">
        <input placeholder="Ketik pesan..." onclick="segera('Chatting')" readonly>
        <button onclick="segera('Chatting')">➤</button>
      </div>
    </div>
  </div>
</div>

<?= $this->endSection() ?>
