<?= $this->extend('layout_recruiter') ?>

<?= $this->section('gaya') ?>
<style>
  /* khas halaman Report: filter tanggal di topbar, lonceng, form berukuran normal */
  .rtop .dates { display: flex; align-items: center; gap: 8px; font-size: 13px; }
  .rtop .dates input { border: none; border-radius: 8px; padding: 7px 10px; font-size: 13px; font-family: inherit; }
  .rtop .dates button { margin: 0; padding: 7px 16px; border: none; border-radius: 8px; background: #fff; color: #2F6FED; font-weight: 700; font-size: 13px; cursor: pointer; }
  .rtop .bell { position: relative; font-size: 18px; cursor: pointer; }
  .rtop .bell::after { content: ''; position: absolute; top: -2px; right: -2px; width: 8px; height: 8px; background: #E23B4E; border-radius: 50%; }

  h2 { margin-top: 0; }
  input, select { padding: 10px 12px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 14px; font-family: inherit; }
  button { padding: 12px 20px; border: none; border-radius: 10px; cursor: pointer; background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; font-size: 15px; font-weight: 700; font-family: inherit; }
  table { font-size: 14px; }
  th { font-size: 14px; }

  @media (max-width: 820px) { .rtop .dates { display: none; } }
</style>
<?= $this->endSection() ?>

<?= $this->section('topbar') ?>
<div class="dates">
  <span>Start Date:</span><input type="date" value="2026-01-01">
  <span>End Date:</span><input type="date" value="2026-07-16">
  <button onclick="segera('Filter Tanggal')">View</button>
</div>
<?= $this->endSection() ?>

<?= $this->section('topbarKanan') ?>
<span class="bell" onclick="segera('Notifikasi')">🔔</span>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<a href="<?= site_url('recruiter/report') ?>" class="<?= ($tab ?? 'summary') === 'summary' ? 'on' : '' ?>">
  <span class="l"><span>📊</span><span>Summary</span></span><span><?= ($tab ?? 'summary') === 'summary' ? '»' : '' ?></span>
</a>
<a href="<?= site_url('recruiter/report') ?>?tab=fpk" class="<?= ($tab ?? '') === 'fpk' ? 'on' : '' ?>">
  <span class="l"><span>🗃️</span><span>Data FPK</span></span><span><?= ($tab ?? '') === 'fpk' ? '»' : '' ?></span>
</a>
<?= $this->endSection() ?>

<?= $this->section('isi') ?>

<style>
  .rep-filter { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px; font-size:13px; }
  .rep-filter input { width:auto; }
  .rep-title { font-size:26px; font-weight:700; color:#2F6FED; margin:0; }
  .rep-title b { color:#F7941D; }
  .rep-sub { color:#888; font-size:13px; margin:2px 0 16px; }
  .client-toggle { display:inline-flex; background:#FFF6E6; border-radius:10px; padding:4px; margin-bottom:18px; }
  .client-toggle span { padding:7px 18px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; color:#8a6d1e; }
  .client-toggle span.on { background:#F7941D; color:#fff; }
  .rep-h { font-weight:700; font-size:15px; margin:26px 0 12px; }
  .rep-h small { color:#aaa; font-weight:500; }

  .sumcards { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
  .sumcard { border:1px solid #eef0f5; border-radius:12px; padding:16px 18px; }
  .sumcard .cap { font-size:13px; color:#777; font-weight:600; margin-bottom:12px; }
  .sumcard .big { font-size:30px; font-weight:700; }
  .slarow { display:flex; align-items:center; gap:8px; font-size:12px; margin:5px 0; }
  .slarow .lbl { width:34px; color:#888; } .slarow .val { width:42px; text-align:right; font-weight:600; }
  .slarow .track { flex:1; height:9px; background:#e9ecf2; border-radius:6px; overflow:hidden; }
  .slarow .fill { height:100%; border-radius:6px; }

  .subtabs { display:flex; gap:2px; border-bottom:1px solid #eef0f5; margin:8px 0 16px; flex-wrap:wrap; }
  .subtabs span { padding:9px 18px; font-size:13px; font-weight:600; cursor:pointer; color:#999; border:1px solid transparent; border-bottom:none; }
  .subtabs span.on { color:#F7941D; background:#fff; border-color:#eef0f5; border-radius:8px 8px 0 0; }
  .scroll-x { overflow-x:auto; }

  .bar-row { display:flex; align-items:center; gap:10px; margin:8px 0; font-size:12px; }
  .bar-row .lbl { width:190px; text-align:right; color:#555; flex-shrink:0; }
  .bar-row .bar { flex:1; display:flex; height:22px; }
  .bar-row .seg { height:100%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px; font-weight:600; }
  .seg-need { background:#2F6FED; } .seg-fulfill { background:#20A277; } .seg-open { background:#F5A623; }
  .legend { display:flex; gap:16px; font-size:12px; justify-content:center; margin-bottom:8px; }
  .legend b { display:inline-block; width:12px; height:12px; border-radius:3px; vertical-align:middle; margin-right:5px; }
  .num { text-align:right; } .cell-blue { color:#2F6FED; } .cell-green { color:#20A277; } .cell-amber { background:#FFF8E8; }
  .pill { background:#FFF3D6; border-radius:8px; padding:2px 10px; font-size:12px; color:#8a6d1e; font-weight:600; }
  .fpk-badge { display:inline-flex; gap:6px; }
</style>

<div>
  <h2 class="rep-title">E-Recruitment <b>Summary</b></h2>
  <div class="rep-sub">Monitoring proses rekrutmen, FPK, Fulfillment &amp; status kandidat</div>
  <div class="client-toggle">
    <span class="on">Internal Client</span>
    <span onclick="segera('External Client')">External Client</span>
  </div>

  <?php if ($tab === 'fpk'): ?>
    <!-- ===================== DATA FPK ===================== -->
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div class="rep-h">- Data FPK</div>
      <button style="margin:0;background:#20A277;padding:9px 16px" onclick="segera('Download Excel')">⬇ Download Excel</button>
    </div>
    <div class="scroll-x">
      <table>
        <tr><th>No</th><th>Recruiter</th><th>RequestNo</th><th>DateApproved_Final</th><th>SLA</th><th>CompanyCode</th><th>JobTitleName</th></tr>
        <?php foreach ($dataFpk as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= esc($r[0]) ?></td>
            <td><?= esc($r[1]) ?></td>
            <td><?= esc($r[2]) ?></td>
            <td><?= $r[3] ?></td>
            <td><?= esc($r[4]) ?></td>
            <td><?= esc($r[5]) ?></td>
          </tr>
        <?php endforeach ?>
      </table>
    </div>

  <?php else: ?>
    <!-- ===================== SUMMARY ===================== -->
    <div class="rep-h">FPK Summary <small>| <?= number_format($fpk['total']) ?> FPK</small></div>
    <div class="sumcards">
      <?php foreach (['outstanding' => ['Outstanding', '#F5A623'], 'fulfilled' => ['Fulfilled', '#20A277']] as $key => [$cap, $warna]): $d = $fpk[$key]; ?>
        <div class="sumcard">
          <div class="cap"><?= $cap ?> | <b style="color:<?= $warna ?>"><?= $d['n'] ?></b> | <b style="color:<?= $warna ?>"><?= $d['pct'] ?>%</b></div>
          <?php foreach ($d['sla'] as $lbl => $pct): ?>
            <div class="slarow"><span class="lbl"><?= $lbl ?></span>
              <span class="track"><span class="fill" style="width:<?= min($pct * 2, 100) ?>%;background:<?= $lbl === '> 14' ? '#E23B4E' : $warna ?>"></span></span>
              <span class="val"><?= $pct ?>%</span></div>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
      <div class="sumcard"><div class="cap">Fulfillment Rate</div><div class="big" style="color:#20A277"><?= $fpk['rate'] ?>%</div></div>
      <div class="sumcard"><div class="cap">Fulfillment by SLA</div><div class="big" style="color:#2F6FED"><?= $fpk['bySla'] ?>%</div></div>
    </div>

    <div class="rep-h">- Program &amp; Activity</div>
    <div class="scroll-x">
      <table>
        <tr><th>No</th><th>Task Title</th><th class="num">Total Program</th><th class="num">Total Kandidat</th><th class="num">Join</th><th class="num">Not Join</th><th class="num">% Join</th></tr>
        <?php foreach ($program as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><small style="color:#aaa"><?= esc($p[0]) ?></small><br><b><?= esc($p[1]) ?></b></td>
            <td class="num"><span class="pill"><?= $p[2] ?></span></td>
            <td class="num"><?= $p[3] ?></td><td class="num"><?= $p[4] ?></td><td class="num"><?= $p[5] ?></td><td class="num"><?= $p[6] ?>%</td>
          </tr>
        <?php endforeach ?>
      </table>
    </div>

    <div class="rep-h">Recruitment View</div>
    <div class="subtabs">
      <span class="on">Summary</span>
      <?php foreach (['Recruiter', 'Reference', 'KPI/Performance', 'Projection'] as $t): ?>
        <span onclick="segera('<?= esc($t, 'js') ?>')"><?= $t ?></span>
      <?php endforeach ?>
    </div>
    <div class="scroll-x">
      <table>
        <tr>
          <th>No</th><th>Recruiter</th><th class="num">Inprogress</th><th class="num cell-blue">Need</th>
          <th class="num cell-green">Total Fulfill</th><th class="num cell-amber">Total Open</th>
          <th class="num">F ≤7</th><th class="num">F ≤14</th><th class="num">F >14</th>
          <th class="num">O ≤7</th><th class="num">O ≤14</th><th class="num">O >14</th>
        </tr>
        <?php foreach ($recruiters as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><small style="color:#aaa"><?= esc($r[0]) ?></small><br><b><?= esc($r[1]) ?></b></td>
            <td class="num"><?= $r[2] ? '<span class="pill">' . $r[2] . '</span>' : '0' ?></td>
            <td class="num cell-blue"><?= $r[3] ?></td><td class="num cell-green"><?= $r[4] ?></td><td class="num cell-amber"><?= $r[5] ?></td>
            <td class="num"><?= $r[6] ?></td><td class="num"><?= $r[7] ?></td><td class="num"><?= $r[8] ?></td>
            <td class="num"><?= $r[9] ?></td><td class="num"><?= $r[10] ?></td><td class="num"><?= $r[11] ?></td>
          </tr>
        <?php endforeach ?>
        <tr style="background:#F7F8FB;font-weight:700">
          <td colspan="2">TOTAL</td>
          <?php foreach ($total as $t): ?><td class="num"><?= $t ?></td><?php endforeach ?>
        </tr>
      </table>
    </div>

    <?php
    $legend = '<div class="legend"><span><b style="background:#2F6FED"></b>Need</span><span><b style="background:#20A277"></b>Fulfill</span><span><b style="background:#F5A623"></b>Open</span></div>';
    $chart  = static function (array $data) use ($legend): string {
        $maks = 1;
        foreach ($data as $v) {
            $maks = max($maks, array_sum($v));
        }
        $html = $legend;
        foreach ($data as $label => [$need, $fulfill, $open]) {
            $html .= '<div class="bar-row"><span class="lbl">' . esc($label) . '</span><span class="bar">';
            foreach ([['need', $need], ['fulfill', $fulfill], ['open', $open]] as [$cls, $val]) {
                $w = round($val / $maks * 100, 2);
                $html .= '<span class="seg seg-' . $cls . '" style="width:' . $w . '%">' . ($w > 4 ? $val : '') . '</span>';
            }
            $html .= '</span></div>';
        }

        return $html;
    };
    ?>

    <div class="rep-h">Vertical View</div>
    <div class="subtabs">
      <span class="on">Summary</span>
      <?php foreach (['Vertical', 'Directorat', 'Org Group Name', 'Organization Name'] as $t): ?>
        <span onclick="segera('<?= esc($t, 'js') ?>')"><?= $t ?></span>
      <?php endforeach ?>
    </div>
    <div class="scroll-x"><?= $chart($vertical) ?></div>

    <div class="rep-h">Region View</div>
    <div class="subtabs">
      <span class="on">Summary</span>
      <?php foreach (['Region', 'Province', 'City', 'Store'] as $t): ?>
        <span onclick="segera('<?= esc($t, 'js') ?>')"><?= $t ?></span>
      <?php endforeach ?>
    </div>
    <div class="scroll-x"><?= $chart($region) ?></div>

    <div class="rep-h">Monitoring</div>
    <div class="subtabs">
      <span class="on">HOC</span>
      <span onclick="segera('LOB')">LOB</span><span onclick="segera('TSH')">TSH</span>
    </div>
    <div class="scroll-x">
      <table>
        <tr><th>No</th><th>HOC</th><th class="num cell-blue">ERO</th><th class="num cell-blue">Trainee</th><th class="num cell-blue">Junior/Store Leader</th>
          <th class="num cell-amber">Existing Total</th><th class="num cell-green">Ideal</th><th class="num">Gap (±)</th><th class="num">End Contract (30 Days)</th><th class="num">Talent Pool</th></tr>
        <?php foreach ($monitoring as $i => $m): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><small style="color:#aaa">undefined</small><br><b><?= esc($m[0]) ?></b></td>
            <td class="num"><?= $m[1] ?></td><td class="num"><?= $m[2] ?></td><td class="num"><?= $m[3] ?></td>
            <td class="num cell-amber"><?= $m[4] ?></td><td class="num cell-green"><?= $m[5] ?></td>
            <td class="num" style="color:#E23B4E;font-weight:600"><?= $m[6] ?></td><td class="num"><?= $m[7] ?></td><td class="num"><?= $m[8] ?></td>
          </tr>
        <?php endforeach ?>
      </table>
    </div>
  <?php endif ?>
</div>

<?= $this->endSection() ?>
