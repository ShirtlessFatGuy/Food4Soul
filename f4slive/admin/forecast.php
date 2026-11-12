<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forecast_data.php';
require_role('Admin');

// ---- request parameters (all whitelisted) ----------------------------------------------
$metric  = ($_GET['metric'] ?? 'units') === 'revenue' ? 'revenue' : 'units';
$select  = strtoupper($_GET['select'] ?? 'MAE') === 'MAPE' ? 'MAPE' : 'MAE';
$holdout = (int)($_GET['holdout'] ?? 3);
$holdout = in_array($holdout, [2, 3], true) ? $holdout : 3;
$horizon = (int)($_GET['horizon'] ?? 36);
$horizon = in_array($horizon, [12, 24, 36], true) ? $horizon : 36;
$item    = (string)($_GET['item'] ?? 'ALL');

$data = forecast_load_series($pdo, $metric);
if ($item !== 'ALL' && !isset($data['products'][$item])) $item = 'ALL';

// ---- run validation + selection for the company total and for every product -------------
$runs = ['ALL' => forecast_run_series(['start' => $data['total']['start'], 'series' => $data['total']['series']],
                                       $horizon, $holdout, $select)];
foreach ($data['products'] as $pid => $p) {
    $runs[$pid] = forecast_run_series($p, $horizon, $holdout, $select);
}

$fmt = static fn($v) => $v === null ? '—' : ($metric === 'revenue' ? peso($v) : number_format($v));
$unitWord = $metric === 'revenue' ? 'revenue (₱)' : 'units sold';

// ---- CSV export of every series' forecast ------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $safe = static fn($s) => preg_match('/^[=+\-@\t\r]/', (string)$s) ? "'" . $s : (string)$s;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="food4soul_forecast_' . $metric . '_' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Series', 'Product_ID', 'Metric', 'Month', 'Forecast', 'Lower_80', 'Upper_80', 'Selected_Model', 'Backtest_' . $select]);
    foreach ($runs as $key => $r) {
        if (empty($r['forecast'])) continue;
        $name = $key === 'ALL' ? 'All products (total)' : $data['products'][$key]['name'];
        $sc = $r['candidates'][$r['selected']][strtolower($r['metric_used'])] ?? null;
        foreach ($r['forecast'] as $i => $v) {
            fputcsv($o, [$safe($name), $key === 'ALL' ? '' : $key, $metric, $r['fc_months'][$i],
                         round($v, 2), isset($r['lower']) ? round($r['lower'][$i], 2) : '',
                         isset($r['upper']) ? round($r['upper'][$i], 2) : '', $r['selected'],
                         $sc === null ? '' : round($sc, 2)]);
        }
    }
    fclose($o);
    exit;
}

$cur  = $runs[$item];
$curName = $item === 'ALL' ? 'All products (total)' : $data['products'][$item]['name'];
$curSeries = $item === 'ALL' ? $data['total']['series'] : $data['products'][$item]['series'];
$ok = ($cur['status'] ?? '') === 'ok';
$years = $ok ? forecast_year_totals($cur['forecast']) : [];
$sel = $ok ? $cur['candidates'][$cur['selected']] : null;
$mk = strtolower($cur['metric_used'] ?? 'mae');

// chart payload
$chart = null;
if ($ok) {
    $nh = count($cur['hist_months']); $nf = count($cur['fc_months']);
    $pad = static fn(int $k) => array_fill(0, $k, null);
    $chart = [
        'labels'   => array_map('fc_label', array_merge($cur['hist_months'], $cur['fc_months'])),
        'history'  => array_merge($curSeries, $pad($nf)),
        'forecast' => array_merge($pad($nh - 1), [end($curSeries)], array_map(fn($v) => round($v, 2), $cur['forecast'])),
        'lower'    => isset($cur['lower']) ? array_merge($pad($nh), array_map(fn($v) => round($v, 2), $cur['lower'])) : null,
        'upper'    => isset($cur['upper']) ? array_merge($pad($nh), array_map(fn($v) => round($v, 2), $cur['upper'])) : null,
    ];
}

$qs = fn(array $o) => '?' . http_build_query(array_merge(
    ['metric' => $metric, 'select' => $select, 'holdout' => $holdout, 'horizon' => $horizon, 'item' => $item], $o));

$active = 'forecast';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar">
  <h1>Sales Forecast</h1>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>">Export CSV</a>
    <button class="btn btn-ghost" type="button" onclick="window.print()">Print</button>
  </div>
</div>

<form method="get" class="toolbar filters">
  <label class="fl">Forecast
    <select class="search-input" name="item">
      <option value="ALL">All products (total)</option>
      <?php foreach ($data['products'] as $pid => $p): ?>
        <option value="<?= htmlspecialchars($pid) ?>" <?= $item === $pid ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="fl">Measure
    <select class="search-input" name="metric">
      <option value="units" <?= $metric === 'units' ? 'selected' : '' ?>>Units sold</option>
      <option value="revenue" <?= $metric === 'revenue' ? 'selected' : '' ?>>Revenue (₱)</option>
    </select>
  </label>
  <label class="fl">Horizon
    <select class="search-input" name="horizon">
      <?php foreach ([12 => '1 year', 24 => '2 years', 36 => '3 years'] as $h => $l): ?>
        <option value="<?= $h ?>" <?= $horizon === $h ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="fl">Pick model by
    <select class="search-input" name="select">
      <option value="MAE" <?= $select === 'MAE' ? 'selected' : '' ?>>Lowest MAE</option>
      <option value="MAPE" <?= $select === 'MAPE' ? 'selected' : '' ?>>Lowest MAPE</option>
    </select>
  </label>
  <label class="fl">Hidden months
    <select class="search-input" name="holdout">
      <option value="2" <?= $holdout === 2 ? 'selected' : '' ?>>2 (backtest)</option>
      <option value="3" <?= $holdout === 3 ? 'selected' : '' ?>>3 (backtest)</option>
    </select>
  </label>
  <div class="fl-actions"><button class="btn btn-primary" type="submit">Update</button></div>
</form>

<?php if ($data['first'] === null): ?>
  <div class="panel"><p style="color:var(--slate);font-size:13.5px;margin:0;">There are no completed months of sales yet, so there is nothing to forecast. Forecasts appear once sales have been recorded in at least one finished calendar month.</p></div>
<?php else: ?>

<?php if (!$ok): ?>
  <div class="panel">
    <h3><?= htmlspecialchars($curName) ?></h3>
    <p style="color:var(--slate);font-size:13.5px;margin:0;">
      <?php if (($cur['status'] ?? '') === 'nodata'): ?>No sales have been recorded for this product yet.
      <?php else: ?>Not enough history: Holt-Winters needs at least 24 months of sales.<?php endif; ?>
      <?= htmlspecialchars(implode(' ', $cur['warnings'] ?? [])) ?>
    </p>
  </div>
<?php else: ?>

<div class="panel">
  <h3><?= htmlspecialchars($curName) ?> <small style="color:var(--slate);font-weight:500;font-size:11.5px;">monthly <?= htmlspecialchars($unitWord) ?> · history and <?= (int)$horizon / 12 ?>-year forecast</small></h3>
  <div class="legend-note"><span><i style="background:#3E6B52"></i>Actual</span><span><i style="background:#E2A33D"></i>Forecast</span><span><i style="background:#F3E3BE"></i>80% range (approximate)</span></div>
  <div class="chart-wrap" style="height:320px;"><canvas id="fcChart"></canvas></div>
  <?php foreach ($cur['warnings'] as $w): ?><p class="hint" style="margin:8px 0 0;">⚠ <?= htmlspecialchars($w) ?></p><?php endforeach; ?>
</div>

<div class="panel ai-panel" id="aiPanel"
     data-endpoint="/api/forecast_summary.php"
     data-params="<?= htmlspecialchars(http_build_query(['item' => $item, 'metric' => $metric, 'horizon' => $horizon, 'select' => $select, 'holdout' => $holdout])) ?>"
     data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
  <h3>
    <span>In plain words <small>summary of <?= htmlspecialchars($curName) ?></small></span>
    <button class="btn btn-ghost btn-sm no-print" type="button" id="aiRegen" hidden>Regenerate</button>
  </h3>
  <div id="aiBody" aria-live="polite"><p class="hint" style="margin:0;">Writing the summary…</p></div>
  <p class="hint" id="aiFoot" style="margin:12px 0 0;" hidden></p>
</div>

<div class="kpi-row">
  <div class="kpi"><div class="label">Selected model</div><div class="num" style="font-size:17px;line-height:1.3;"><?= htmlspecialchars($cur['selected']) ?></div><div class="delta up">lowest <?= htmlspecialchars($cur['metric_used']) ?> on hidden months</div></div>
  <div class="kpi"><div class="label">Backtest MAE</div><div class="num"><?= $fmt($sel['mae']) ?></div><div class="delta up">average miss per month</div></div>
  <div class="kpi"><div class="label">Backtest MAPE</div><div class="num"><?= $sel['mape'] === null ? '—' : number_format($sel['mape'], 1) . '%' ?></div><div class="delta <?= ($sel['mape'] ?? 0) > 30 ? 'warn' : 'up' ?>"><?= $sel['mape'] === null ? 'undefined (zero-sales months)' : 'average % miss' ?></div></div>
  <?php foreach ($years as $i => $t): ?>
    <div class="kpi"><div class="label">Year <?= $i + 1 ?> forecast</div><div class="num"><?= $fmt($t) ?></div><div class="delta up"><?= htmlspecialchars(fc_label($cur['fc_months'][$i * 12])) ?> – <?= htmlspecialchars(fc_label($cur['fc_months'][min(count($cur['fc_months']), $i * 12 + 12) - 1])) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Backtest: model comparison <small style="color:var(--slate);font-weight:500;font-size:11.5px;">last <?= (int)$cur['holdout'] ?> months hidden, then predicted</small></h3>
    <div class="table-scroll"><table>
      <thead><tr><th>Model</th><th class="num-r">MAE</th><th class="num-r">RMSE</th><th class="num-r">MAPE</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cur['candidates'] as $name => $c): ?>
        <tr>
          <td><strong><?= htmlspecialchars($name) ?></strong></td>
          <?php if ($c['eligible']): ?>
            <td class="num-r"><?= $fmt($c['mae']) ?></td>
            <td class="num-r"><?= $fmt($c['rmse']) ?></td>
            <td class="num-r"><?= $c['mape'] === null ? '—' : number_format($c['mape'], 1) . '%' ?></td>
            <td><?= !empty($c['selected']) ? '<span class="badge ok">Selected</span>' : '' ?></td>
          <?php else: ?>
            <td colspan="3" class="hint"><?= htmlspecialchars($c['reason']) ?></td><td><span class="badge neutral">Not eligible</span></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <h4 style="margin:16px 0 6px;font-size:13px;">Hidden months: actual vs predicted by the selected model</h4>
    <div class="table-scroll"><table>
      <thead><tr><th>Month</th><th class="num-r">Actual</th><th class="num-r">Predicted</th></tr></thead>
      <tbody>
      <?php $nh = count($cur['hist_months']); foreach ($cur['actual_holdout'] as $i => $a): ?>
        <tr><td><?= htmlspecialchars(fc_label($cur['hist_months'][$nh - $cur['holdout'] + $i])) ?></td>
            <td class="num-r"><?= $fmt($a) ?></td><td class="num-r"><?= $fmt($sel['predicted_holdout'][$i]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="panel">
    <h3>Seasonality <small style="color:var(--slate);font-weight:500;font-size:11.5px;"><?= $cur['seasonal'] ? htmlspecialchars($cur['seasonal_type']) . ' seasonal factors' : 'not used by this model' ?></small></h3>
    <?php if ($cur['seasonal']): ?>
      <p class="hint" style="margin:0 0 8px;"><?= $cur['seasonal_type'] === 'multiplicative'
          ? '1.20× means that month typically runs 20% above the underlying level.'
          : 'A positive value is the typical amount that month runs above the underlying level.' ?></p>
      <div class="table-scroll"><table>
        <thead><tr><th>Month</th><th class="num-r">Factor</th></tr></thead>
        <tbody>
        <?php
          $rows = [];
          foreach ($cur['seasonal'] as $k => $f) $rows[(int)substr($cur['fc_months'][$k], 5, 2)] = $f;
          ksort($rows);
          foreach ($rows as $mo => $f): ?>
          <tr><td><?= date('F', mktime(0, 0, 0, $mo, 1)) ?></td>
              <td class="num-r"><?= $cur['seasonal_type'] === 'multiplicative' ? number_format($f, 2) . '×' : ($f >= 0 ? '+' : '−') . ($metric === 'revenue' ? peso(abs($f)) : number_format(abs($f))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <p style="color:var(--slate);font-size:13.5px;margin:0;">No seasonal pattern available for this item.</p>
    <?php endif; ?>
    <?php if ($cur['params'] && isset($cur['params']['alpha'])): ?>
      <p class="hint" style="margin:14px 0 0;">Fitted smoothing constants: α = <?= $cur['params']['alpha'] ?><?= isset($cur['params']['beta']) ? ', β = ' . $cur['params']['beta'] . ', γ = ' . $cur['params']['gamma'] : '' ?>.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Every product <small style="color:var(--slate);font-weight:500;font-size:11.5px;">model chosen separately for each product by backtest</small></h3>
  <div class="table-scroll"><table>
    <thead><tr><th>Product</th><th>Selected model</th><th class="num-r">MAE</th><th class="num-r">MAPE</th><th class="num-r">Next 12 months</th><th class="num-r"><?= $horizon === 36 ? '3-year total' : ($horizon / 12) . '-year total' ?></th></tr></thead>
    <tbody>
    <?php foreach ($data['products'] as $pid => $p): $r = $runs[$pid]; $rok = ($r['status'] ?? '') === 'ok';
          $sc = $rok ? $r['candidates'][$r['selected']] : null; ?>
      <tr>
        <td><a href="<?= htmlspecialchars($qs(['item' => $pid])) ?>"><strong><?= htmlspecialchars($p['name']) ?></strong></a><span class="sku"><?= htmlspecialchars($pid) ?></span></td>
        <?php if ($rok): ?>
          <td><?= htmlspecialchars($r['selected']) ?></td>
          <td class="num-r"><?= $fmt($sc['mae']) ?></td>
          <td class="num-r"><?= $sc['mape'] === null ? '—' : number_format($sc['mape'], 1) . '%' ?></td>
          <td class="num-r"><?= $fmt(array_sum(array_slice($r['forecast'], 0, 12))) ?></td>
          <td class="num-r"><?= $fmt(array_sum($r['forecast'])) ?></td>
        <?php else: ?>
          <td colspan="5" class="hint"><?= $r['status'] === 'nodata' ? 'No sales yet' : htmlspecialchars(implode(' ', $r['warnings'])) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<details class="panel">
  <summary style="cursor:pointer;font-weight:700;font-size:14px;">How the forecast is produced</summary>
  <div style="font-size:13px;color:var(--ink-soft);line-height:1.6;margin-top:10px;">
    <p><strong>Holt-Winters (triple exponential smoothing)</strong> tracks a <em>level</em>, a <em>trend</em> and a 12-month <em>seasonal pattern</em>, updating each as every new month arrives. Both additive and multiplicative seasonality are tried and the better one on the backtest is kept. It needs at least 24 months of history; the multiplicative form also needs every month to be above zero.</p>
    <p><strong>Validation and selection.</strong> For each product the last <?= (int)$holdout ?> months are hidden, each eligible version forecasts them from the earlier months, and the errors are scored with MAE (average absolute miss) and MAPE (average percentage miss). The version with the lowest <?= htmlspecialchars($select) ?> wins, is refitted on the full history and produces the forecast. Smoothing constants are found by minimising squared one-step errors with a plain grid search.</p>
    <p><strong>Read with care.</strong> The 80% range is an approximation that widens with the horizon. A 3-year forecast assumes recent trend and seasonality continue; it is a planning aid, not a promise.</p>
  </div>
</details>

<?php if ($ok): ?>
<script>
// Plain-language summary: all text is inserted with textContent (never innerHTML), so it cannot inject markup.
(function () {
  const panel = document.getElementById('aiPanel');
  if (!panel) return;
  const body = document.getElementById('aiBody'), foot = document.getElementById('aiFoot'), regen = document.getElementById('aiRegen');
  const el = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; };
  const section = (title, items) => {
    if (!items || !items.length) return null;
    const d = el('div', undefined, 'ai-sec'); d.appendChild(el('h4', title));
    const ul = el('ul'); items.forEach(t => ul.appendChild(el('li', t))); d.appendChild(ul); return d;
  };
  const note = (msg) => { body.replaceChildren(el('p', msg, 'hint')); foot.hidden = true; };

  function render(r) {
    if (!r.ok) {
      regen.hidden = false; regen.disabled = false; regen.textContent = 'Try again';
      if (r.error === 'no_key') { regen.hidden = true;
        return note('The plain-language summary is switched off. Add a Gemini API key in config/gemini.php (or the GEMINI_API_KEY setting) to turn it on.'); }
      if (r.error === 'nodata') { regen.hidden = true; return note('Not enough forecast data to summarise yet.'); }
      return note('Could not write the summary right now. ' + (r.error || '') + ' The numbers below are not affected.');
    }
    const s = r.summary, frag = document.createDocumentFragment();
    frag.appendChild(el('p', s.headline, 'ai-headline'));
    [['What has been happening', s.what_happened], ['What to expect', s.outlook],
     ['Things to keep an eye on', s.watch_out], ['Suggested next steps', s.suggested_actions]]
      .forEach(([t, i]) => { const x = section(t, i); if (x) frag.appendChild(x); });
    if (s.how_reliable) { const d = el('div', undefined, 'ai-sec'); d.appendChild(el('h4', 'How much to trust this')); d.appendChild(el('p', s.how_reliable)); frag.appendChild(d); }
    body.replaceChildren(frag);
    foot.textContent = 'AI-written explanation of the forecast below' + (r.generated_at ? ' · ' + r.generated_at : '') +
      '. It can make mistakes, so check the figures in the tables. It only uses numbers from this page.';
    foot.hidden = false; regen.hidden = false; regen.disabled = false; regen.textContent = 'Regenerate';
  }

  function load(force) {
    regen.disabled = true;
    body.replaceChildren(el('p', force ? 'Writing a fresh summary…' : 'Writing the summary…', 'hint'));
    const url = panel.dataset.endpoint, opts = force
      ? { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: panel.dataset.params + '&regenerate=1&csrf=' + encodeURIComponent(panel.dataset.csrf) }
      : { method: 'GET' };
    fetch(force ? url : url + '?' + panel.dataset.params, Object.assign({ credentials: 'same-origin' }, opts))
      .then(res => res.json()).then(render)
      .catch(() => render({ ok: false, error: 'The server did not answer.' }));
  }
  regen.addEventListener('click', () => load(true));
  load(false);
})();
</script>
<?php endif; ?>

<?php if ($chart): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
const D = <?= json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const REVENUE = <?= $metric === 'revenue' ? 'true' : 'false' ?>;
const datasets = [];
if (D.lower && D.upper) {
  datasets.push({ label: 'lower', data: D.lower, borderWidth: 0, pointRadius: 0, fill: false });
  datasets.push({ label: '80% range', data: D.upper, borderWidth: 0, pointRadius: 0, backgroundColor: 'rgba(226,163,61,.18)', fill: '-1' });
}
datasets.push({ label: 'Actual', data: D.history, borderColor: '#3E6B52', backgroundColor: '#3E6B52', borderWidth: 2, pointRadius: 2, tension: .25 });
datasets.push({ label: 'Forecast', data: D.forecast, borderColor: '#E2A33D', backgroundColor: '#E2A33D', borderWidth: 2, borderDash: [6, 4], pointRadius: 0, tension: .25 });
const money = v => '₱' + Math.round(v).toLocaleString();
new Chart(document.getElementById('fcChart'), {
  type: 'line', data: { labels: D.labels, datasets },
  options: { maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, filter: i => i.text !== 'lower' } },
               tooltip: { filter: i => i.dataset.label !== 'lower',
                          callbacks: { label: c => c.dataset.label + ': ' + (REVENUE ? money(c.parsed.y) : Math.round(c.parsed.y).toLocaleString()) } } },
    scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
              y: { beginAtZero: true, grid: { color: '#E2DCCB' }, ticks: { callback: v => REVENUE ? money(v) : v.toLocaleString() } } } }
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
