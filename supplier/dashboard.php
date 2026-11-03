<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Supplier');

$supplierId = current_user()['Supplier_ID'];

$stmt = $pdo->prepare("SELECT * FROM orders WHERE Supplier_ID = ? ORDER BY Order_Date DESC");
$stmt->execute([$supplierId]);
$orders = $stmt->fetchAll();

$pending  = count(array_filter($orders, fn($o) => in_array($o['Status'], ['Pending', 'Processing'], true)));
$overdueCount = count(array_filter($orders, fn($o) => in_array($o['Status'], ['Pending', 'Processing'], true)
                                                  && $o['Expected_Delivery_Date'] && $o['Expected_Delivery_Date'] < date('Y-m-d')));
$lowStock = get_low_stock_products($pdo, $supplierId);

$pc = $pdo->prepare("SELECT COUNT(*) FROM products WHERE Supplier_ID = ?");
$pc->execute([$supplierId]);
$productCount = $pc->fetchColumn();

$itemsStmt = $pdo->prepare("SELECT od.Quantity, p.Product_Name FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID WHERE od.Order_ID = ?");

$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Overview</h1></div>
<div class="kpi-row">
  <div class="kpi"><div class="label">Assigned orders</div><div class="num"><?= count($orders) ?></div><div class="delta warn"><?= $pending ?> in progress</div></div>
  <div class="kpi"><div class="label">Products supplied</div><div class="num"><?= (int)$productCount ?></div><div class="delta up">to Food4Soul</div></div>
  <div class="kpi"><div class="label">Low-stock products</div><div class="num"><?= count($lowStock) ?></div><div class="delta warn">need restocking</div></div>
  <div class="kpi"><div class="label">Overdue orders</div><div class="num"><?= $overdueCount ?></div><div class="delta <?= $overdueCount ? 'warn' : 'up' ?>"><?= $overdueCount ? 'past expected delivery' : 'all on schedule' ?></div></div>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Stock vs reorder level <small style="color:var(--slate);font-weight:500;font-size:11.5px;">your products</small></h3>
    <div class="chart-wrap"><canvas id="stockChart"></canvas></div>
  </div>
  <div class="panel">
    <h3>Needs attention</h3>
    <div id="attention"><p style="color:var(--slate);font-size:13.5px;">Loading…</p></div>
  </div>
</div>

<div class="grid-3">
  <div class="panel"><h3>Orders by status</h3><div class="chart-wrap"><canvas id="statusChart"></canvas></div></div>
  <div class="panel"><h3>Units sold per day <small style="color:var(--slate);font-weight:500;font-size:11.5px;">last 14 days</small></h3><div class="chart-wrap"><canvas id="trendChart"></canvas></div></div>
  <div class="panel"><h3>Top sellers <small style="color:var(--slate);font-weight:500;font-size:11.5px;">last 30 days</small></h3><div class="chart-wrap"><canvas id="soldChart"></canvas></div></div>
</div>
<div class="panel">
  <h3>Your recent orders</h3>
  <div class="table-scroll"><table>
    <thead><tr><th>Order</th><th>Items</th><th>Expected delivery</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o):
        $itemsStmt->execute([$o['Order_ID']]);
        $itemStr = implode(', ', array_map(fn($i) => $i['Product_Name'] . ' ×' . $i['Quantity'], $itemsStmt->fetchAll()));
      ?>
        <tr>
          <td><?= htmlspecialchars($o['Order_ID']) ?></td>
          <td><?= htmlspecialchars($itemStr) ?></td>
          <td><?= htmlspecialchars($o['Expected_Delivery_Date']) ?></td>
          <td><span class="badge <?= badge_class($o['Status']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="4">No orders assigned yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// Live view: re-poll every 30 seconds without a page reload.
const charts = {};
const esc = t => String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const grid = { color: '#E2DCCB' };

function draw(id, config) {
  if (charts[id]) {
    charts[id].data = config.data;
    charts[id].update();
  } else {
    charts[id] = new Chart(document.getElementById(id), config);
  }
}

function loadSupplierStats() {
  fetch('/api/supplier_stats.php').then(r => r.json()).then(d => {
    draw('stockChart', { type: 'bar',
      data: { labels: d.stock.labels, datasets: [
        { label: 'In stock', data: d.stock.stock, backgroundColor: '#3E6B52', borderRadius: 5 },
        { label: 'Reorder level', data: d.stock.reorder, backgroundColor: '#E2A33D', borderRadius: 5 } ] },
      options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } },
                 scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid } } } });

    draw('statusChart', { type: 'doughnut',
      data: { labels: d.status.labels, datasets: [{ data: d.status.counts, backgroundColor: ['#E2A33D', '#6E7368', '#3E6B52', '#B23A2E'] }] },
      options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } } } });

    draw('trendChart', { type: 'line',
      data: { labels: d.trend.labels, datasets: [{ data: d.trend.units, borderColor: '#B97F1F', backgroundColor: 'rgba(226,163,61,.15)', fill: true, tension: .35, pointRadius: 2, borderWidth: 2.5 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                 scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 5 } }, y: { beginAtZero: true, grid, ticks: { precision: 0 } } } } });

    draw('soldChart', { type: 'bar',
      data: { labels: d.sold.labels, datasets: [{ data: d.sold.units, backgroundColor: '#3E6B52', borderRadius: 5 }] },
      options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } },
                 scales: { x: { beginAtZero: true, grid, ticks: { precision: 0 } }, y: { grid: { display: false } } } } });

    let html = '';
    d.overdue.forEach(o => html += `<div class="alert-item low"><div class="ico">⚠</div><div class="body"><b>Overdue order</b><span>${esc(o.Order_ID)} was due ${esc(o.Expected_Delivery_Date)} (${esc(o.Status)}).</span></div></div>`);
    d.low.forEach(p => html += `<div class="alert-item low"><div class="ico">⚠</div><div class="body"><b>Low stock</b><span>${esc(p.name)}: ${p.stock} left (reorder level ${p.reorder}).</span></div></div>`);
    document.getElementById('attention').innerHTML = html || '<p style="color:var(--slate);font-size:13.5px;">All clear. Nothing needs your attention.</p>';
  });
}
loadSupplierStats();
setInterval(loadSupplierStats, 30000);
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
