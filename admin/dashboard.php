<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$todaySales    = $pdo->query("SELECT COALESCE(SUM(Total_Amount),0) FROM sales WHERE Sale_Date = CURDATE()")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE Status != 'Delivered'")->fetchColumn();
$awaitingConf  = $pdo->query("SELECT COUNT(*) FROM orders WHERE Status = 'Pending'")->fetchColumn();
$lowStock      = get_low_stock_products($pdo);
$stockValue    = $pdo->query("SELECT COALESCE(SUM(Unit_Price * Current_Stock_Quantity),0) FROM products")->fetchColumn();
$skuCount      = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$alerts        = $pdo->query("SELECT * FROM alerts WHERE Status = 'Unread' ORDER BY Date_Generated DESC, Alert_ID DESC LIMIT 6")->fetchAll();
$alertCount    = $pdo->query("SELECT COUNT(*) FROM alerts WHERE Status = 'Unread'")->fetchColumn();
$recentOrders  = $pdo->query("SELECT o.*, s.Supplier_Name FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID ORDER BY Order_Date DESC LIMIT 5")->fetchAll();

$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="topbar"><h1>Overview</h1></div>

<div class="kpi-row">
  <div class="kpi"><div class="label">Today's sales</div><div class="num"><?= peso($todaySales) ?></div><div class="delta up">live across branches</div></div>
  <div class="kpi"><div class="label">Orders in progress</div><div class="num"><?= (int)$pendingOrders ?></div><div class="delta warn"><?= (int)$awaitingConf ?> awaiting confirmation</div></div>
  <div class="kpi"><div class="label">Low-stock items</div><div class="num"><?= count($lowStock) ?></div><div class="delta warn">below reorder level</div></div>
  <div class="kpi"><div class="label">Inventory value</div><div class="num"><?= peso($stockValue) ?></div><div class="delta up">across <?= (int)$skuCount ?> SKUs</div></div>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Sales trend <small style="color:var(--slate);font-weight:500;font-size:11.5px;">Last 14 days</small></h3>
    <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
  </div>
  <div class="panel">
    <h3>Alerts <small style="color:var(--slate);font-weight:500;font-size:11.5px;"><?= (int)$alertCount ?> active</small></h3>
    <?php if (!$alerts): ?>
      <p style="color:var(--slate);font-size:13.5px;">All clear. No active alerts right now.</p>
    <?php else: foreach ($alerts as $a): $isLow = $a['Alert_Type'] === 'Low Stock'; ?>
      <div class="alert-item <?= $isLow ? 'low' : 'info' ?>">
        <div class="ico"><?= $isLow ? '⚠' : 'ⓘ' ?></div>
        <div class="body"><b><?= htmlspecialchars($a['Alert_Type']) ?></b><span><?= htmlspecialchars($a['Alert_Message']) ?></span></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Stock by category</h3>
    <div class="chart-wrap"><canvas id="invChart"></canvas></div>
  </div>
  <div class="panel">
    <h3>Recent restock orders</h3>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Order</th><th>Supplier</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td><?= htmlspecialchars($o['Order_ID']) ?></td>
              <td><?= htmlspecialchars($o['Supplier_Name'] ?? '—') ?></td>
              <td><span class="badge <?= badge_class($o['Status']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// Real-time refresh: re-poll the API every 30 seconds without a page reload.
let salesChart, invChart;
function loadCharts(){
  fetch('/api/dashboard_stats.php').then(r => r.json()).then(data => {
    if (!salesChart) {
      salesChart = new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: { labels: data.labels, datasets: [{ data: data.totals, borderColor: '#B97F1F', backgroundColor: 'rgba(226,163,61,.15)', fill: true, tension: .35, pointRadius: 0, borderWidth: 2.5 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                   scales: { x: { grid: { display: false } }, y: { grid: { color: '#E2DCCB' }, ticks: { callback: v => '₱' + v } } } }
      });
      invChart = new Chart(document.getElementById('invChart'), {
        type: 'bar',
        data: { labels: data.categories, datasets: [{ data: data.units, backgroundColor: '#3E6B52', borderRadius: 6 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                   scales: { x: { grid: { display: false } }, y: { grid: { color: '#E2DCCB' } } } }
      });
    } else {
      salesChart.data.labels = data.labels;           salesChart.data.datasets[0].data = data.totals; salesChart.update();
      invChart.data.labels   = data.categories;       invChart.data.datasets[0].data   = data.units;  invChart.update();
    }
  });
}
loadCharts();
setInterval(loadCharts, 30000);
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
