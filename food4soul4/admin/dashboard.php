<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

check_late_orders($pdo);   // raises Late Order alerts before the numbers below are read

$todaySales    = $pdo->query("SELECT COALESCE(SUM(Total_Amount),0) FROM sales WHERE Sale_Date = CURDATE()")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE Status IN ('Pending','Processing')")->fetchColumn();
$awaitingConf  = $pdo->query("SELECT COUNT(*) FROM orders WHERE Status = 'Pending'")->fetchColumn();
$lowStock      = get_low_stock_products($pdo);
$stockValue    = $pdo->query("SELECT COALESCE(SUM(Unit_Price * Current_Stock_Quantity),0) FROM products")->fetchColumn();
$skuCount      = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$alerts        = $pdo->query("SELECT * FROM alerts WHERE Status = 'Unread' ORDER BY Date_Generated DESC, Alert_ID DESC LIMIT 6")->fetchAll();
$alertCount    = $pdo->query("SELECT COUNT(*) FROM alerts WHERE Status = 'Unread'")->fetchColumn();
$recentOrders  = $pdo->query("SELECT o.*, s.Supplier_Name FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID ORDER BY Order_Date DESC, Order_ID DESC LIMIT 5")->fetchAll();

$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="topbar"><h1>Overview</h1></div>

<div class="kpi-row" data-live="kpis">
  <div class="kpi"><div class="label">Today's sales</div><div class="num" data-key="kpi-sales"><?= peso($todaySales) ?></div><div class="delta up">live across branches</div></div>
  <div class="kpi"><div class="label">Orders in progress</div><div class="num" data-key="kpi-orders"><?= (int)$pendingOrders ?></div><div class="delta warn"><?= (int)$awaitingConf ?> awaiting confirmation</div></div>
  <div class="kpi"><div class="label">Low-stock items</div><div class="num" data-key="kpi-low"><?= count($lowStock) ?></div><div class="delta warn">below reorder level</div></div>
  <div class="kpi"><div class="label">Inventory value</div><div class="num" data-key="kpi-value"><?= peso($stockValue) ?></div><div class="delta up">across <?= (int)$skuCount ?> SKUs</div></div>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Sales trend <small id="trendNote" style="color:var(--slate);font-weight:500;font-size:11.5px;">Last 14 days</small></h3>
    <div class="seg" id="periodSwitch" role="tablist" aria-label="Sales period">
      <button type="button" data-period="daily" class="active">Daily</button>
      <button type="button" data-period="weekly">Weekly</button>
      <button type="button" data-period="monthly">Monthly</button>
      <button type="button" data-period="yearly">Yearly</button>
    </div>
    <div class="trend-summary"><b id="trendTotal">₱0</b><span id="trendChange"></span></div>
    <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
  </div>
  <div class="panel" data-live="alerts">
    <h3>Alerts <small style="color:var(--slate);font-weight:500;font-size:11.5px;"><?= (int)$alertCount ?> unread</small> <a href="/admin/alerts.php" style="margin-left:auto;font-size:12px;font-weight:600;">View all</a></h3>
    <?php if (!$alerts): ?>
      <p style="color:var(--slate);font-size:13.5px;">All clear. No active alerts right now.</p>
    <?php else: foreach ($alerts as $a): $isLow = in_array($a['Alert_Type'], ['Low Stock', 'Late Order'], true); ?>
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
  <div class="panel" data-live="recent-orders">
    <h3>Recent restock orders</h3>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Order</th><th>Supplier</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td><?= htmlspecialchars($o['Order_ID']) ?></td>
              <td><?= htmlspecialchars($o['Supplier_Name'] ?? '—') ?></td>
              <td><span class="badge <?= badge_class($o['Status']) ?>" data-key="ostatus-<?= htmlspecialchars($o['Order_ID']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// Real-time refresh: assets/js/live.js swaps the KPI/alert/order panels and calls Live.on() below for the charts.
const PERIOD_NOTE = { daily: 'Last 14 days', weekly: 'Last 12 weeks', monthly: 'Last 12 months', yearly: 'Last 5 years' };
const PREV_UNIT = { daily: 'day', weekly: 'week', monthly: 'month', yearly: 'year' };
let period = 'daily', salesChart, invChart;
const peso = v => '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 });

function loadSales(){
  fetch('/api/sales_trend.php?period=' + period).then(r => r.json()).then(d => {
    document.getElementById('trendNote').textContent  = PERIOD_NOTE[period];
    document.getElementById('trendTotal').textContent = peso(d.total);
    const ch = document.getElementById('trendChange');
    if (d.change_pct === null) { ch.textContent = ''; ch.className = ''; }
    else {
      ch.textContent = (d.change_pct >= 0 ? '▲ ' : '▼ ') + Math.abs(d.change_pct) + '% vs previous ' + period.replace('ly','').replace('dai','day').replace('Dai','Day');
      ch.className = d.change_pct >= 0 ? 'up' : 'down';
    }
    if (!salesChart) {
      salesChart = new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: { labels: d.labels, datasets: [{ label: 'Revenue', data: d.revenue, borderColor: '#B97F1F', backgroundColor: 'rgba(226,163,61,.15)', fill: true, tension: .35, pointRadius: 2, borderWidth: 2.5 }] },
        options: { maintainAspectRatio: false,
                   plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => peso(c.parsed.y) + ' · ' + d.tx[c.dataIndex] + ' sale(s)' } } },
                   scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#E2DCCB' }, ticks: { callback: v => peso(v) } } } }
      });
    } else {
      salesChart.data.labels = d.labels;
      salesChart.data.datasets[0].data = d.revenue;
      salesChart.options.plugins.tooltip.callbacks.label = c => peso(c.parsed.y) + ' · ' + d.tx[c.dataIndex] + ' sale(s)';
      salesChart.update();
    }
  });
}

function loadInventory(){
  fetch('/api/dashboard_stats.php').then(r => r.json()).then(data => {
    if (!invChart) {
      invChart = new Chart(document.getElementById('invChart'), {
        type: 'bar',
        data: { labels: data.categories, datasets: [{ data: data.units, backgroundColor: '#3E6B52', borderRadius: 6 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                   scales: { x: { grid: { display: false } }, y: { grid: { color: '#E2DCCB' } } } }
      });
    } else {
      invChart.data.labels = data.categories; invChart.data.datasets[0].data = data.units; invChart.update();
    }
  });
}

document.querySelectorAll('#periodSwitch button').forEach(btn => btn.addEventListener('click', () => {
  period = btn.dataset.period;
  document.querySelectorAll('#periodSwitch button').forEach(b => b.classList.toggle('active', b === btn));
  loadSales();
}));

loadSales(); loadInventory();
Live.on(() => { loadSales(); loadInventory(); });          // instant: fired whenever stock/orders/sales change
setInterval(() => { loadSales(); loadInventory(); }, 30000); // fallback
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
