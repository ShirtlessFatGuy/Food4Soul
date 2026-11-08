<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$period = $_GET['period'] ?? null;
$report = null;

// Stock snapshot rows, used by the report table
$stock = $pdo->query("SELECT p.Product_ID, p.Product_Name, p.Category, p.Unit_Price, p.Current_Stock_Quantity AS q,
                             COALESCE(i.Reorder_Level,0) AS r, s.Supplier_Name
                        FROM products p LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
                        LEFT JOIN suppliers s ON s.Supplier_ID = p.Supplier_ID
                       ORDER BY (p.Current_Stock_Quantity / GREATEST(COALESCE(i.Reorder_Level,0),1)), p.Product_Name")->fetchAll();

if (in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30, 'yearly' => 365][$period];
    $flow = movement_totals($pdo, $days);

    $top = $pdo->prepare("SELECT m.Product_Name, SUM(m.Qty) AS qty FROM (" . movement_union_sql() . ") m
                           WHERE m.Direction = 'OUT' AND m.Move_Date >= CURDATE() - INTERVAL ? DAY
                           GROUP BY m.Product_ID, m.Product_Name ORDER BY qty DESC LIMIT 1");
    $top->execute([$days - 1]);
    $topProduct = $top->fetch();

    $units = $value = $low = $out = 0;
    foreach ($stock as $s) {
        $units += $s['q']; $value += $s['q'] * $s['Unit_Price'];
        $st = stock_status($s['q'], $s['r']);
        if ($st === 'Low Stock') $low++; elseif ($st === 'Out of Stock') $out++;
    }
    $open = get_incoming_orders($pdo);

    $report = [
        'period' => $period, 'days' => $days,
        'skus' => count($stock), 'units' => $units, 'value' => $value, 'low' => $low, 'out' => $out,
        'in' => $flow['in'], 'outflow' => $flow['out'],
        'top' => $topProduct ? $topProduct['Product_Name'] . ' (' . (int)$topProduct['qty'] . ' units)' : '—',
        'open_orders' => count($open), 'incoming_units' => array_sum(array_column($open, 'units')),
        'suppliers' => $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn(),
    ];
}

$active = 'reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Inventory Reports</h1></div>

<div class="grid-4">
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Daily</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Today's stock activity</p><a class="btn btn-primary" href="?period=daily">Generate</a></div>
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Weekly</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Last 7 days</p><a class="btn btn-primary" href="?period=weekly">Generate</a></div>
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Monthly</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Last 30 days</p><a class="btn btn-primary" href="?period=monthly">Generate</a></div>
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Yearly</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Last 365 days</p><a class="btn btn-primary" href="?period=yearly">Generate</a></div>
</div>

<?php if ($report): ?>
<div class="report-preview">
  <h3 style="margin:0 0 2px;">Food4Soul <?= ucfirst($report['period']) ?> Inventory Report</h3>
  <div class="meta">Generated <?= date('Y-m-d') ?> · Movement period: last <?= (int)$report['days'] ?> day<?= $report['days'] > 1 ? 's' : '' ?> · Stock figures are current</div>
  <table>
    <tr><td>Products tracked (SKUs)</td><td style="text-align:right;font-weight:700;"><?= (int)$report['skus'] ?></td></tr>
    <tr><td>Units on hand</td><td style="text-align:right;font-weight:700;"><?= number_format($report['units']) ?></td></tr>
    <tr><td>Stock value (at unit price)</td><td style="text-align:right;font-weight:700;"><?= peso($report['value']) ?></td></tr>
    <tr><td>Products at/below reorder level</td><td style="text-align:right;font-weight:700;"><?= (int)$report['low'] + (int)$report['out'] ?> <span class="hint">(<?= (int)$report['out'] ?> out of stock)</span></td></tr>
    <tr><td>Units received in period</td><td style="text-align:right;font-weight:700;" class="qty-in">+<?= number_format($report['in']) ?></td></tr>
    <tr><td>Units issued in period</td><td style="text-align:right;font-weight:700;" class="qty-out">−<?= number_format($report['outflow']) ?></td></tr>
    <tr><td>Fastest-moving product</td><td style="text-align:right;font-weight:700;"><?= htmlspecialchars($report['top']) ?></td></tr>
    <tr><td>Open restock orders</td><td style="text-align:right;font-weight:700;"><?= (int)$report['open_orders'] ?> <span class="hint">(<?= number_format($report['incoming_units']) ?> units incoming)</span></td></tr>
    <tr><td>Active suppliers</td><td style="text-align:right;font-weight:700;"><?= (int)$report['suppliers'] ?></td></tr>
  </table>

  <h4 style="margin:18px 0 6px;">Stock movement</h4>
  <div class="chart-wrap"><canvas id="reportChart"></canvas></div>

  <h4 style="margin:22px 0 6px;">Stock status by product</h4>
  <div class="table-scroll"><table>
    <thead><tr><th>Product</th><th>Category</th><th>Supplier</th><th class="num-r">On hand</th><th class="num-r">Reorder at</th><th>Status</th><th class="num-r">Stock value</th></tr></thead>
    <tbody>
      <?php foreach ($stock as $s): $st = stock_status($s['q'], $s['r']); ?>
        <tr>
          <td><strong><?= htmlspecialchars($s['Product_Name']) ?></strong><span class="sku"><?= htmlspecialchars($s['Product_ID']) ?></span></td>
          <td><?= htmlspecialchars($s['Category']) ?></td>
          <td><?= htmlspecialchars($s['Supplier_Name'] ?? '—') ?></td>
          <td class="num-r"><?= (int)$s['q'] ?></td>
          <td class="num-r"><?= (int)$s['r'] ?></td>
          <td><span class="badge <?= badge_class($st) ?>"><?= $st ?></span></td>
          <td class="num-r"><?= peso($s['q'] * $s['Unit_Price']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <button class="btn btn-ghost btn-sm" style="margin-top:14px;" onclick="window.print()">Print</button>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
fetch('/api/movement_trend.php?period=<?= htmlspecialchars($report['period']) ?>').then(r => r.json()).then(d => {
  new Chart(document.getElementById('reportChart'), {
    type: 'bar',
    data: { labels: d.labels, datasets: [
      { label: 'Received', data: d.received, backgroundColor: '#3E6B52', borderRadius: 5 },
      { label: 'Issued',   data: d.issued,   backgroundColor: '#E2A33D', borderRadius: 5 } ] },
    options: { maintainAspectRatio: false, animation: false,
               plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } },
               scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
