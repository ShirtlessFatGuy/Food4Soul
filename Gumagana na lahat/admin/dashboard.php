<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

check_late_orders($pdo);   // raises Late Order alerts before the numbers below are read

$totals = $pdo->query("SELECT COUNT(*) AS skus, COUNT(DISTINCT Category) AS cats,
                              COALESCE(SUM(Current_Stock_Quantity),0) AS units,
                              COALESCE(SUM(Unit_Price * Current_Stock_Quantity),0) AS value
                         FROM products")->fetch();
$lowStock   = get_low_stock_products($pdo);
$outCount   = count(array_filter($lowStock, fn($p) => (int)$p['Current_Stock_Quantity'] <= 0));
$lowSorted  = $lowStock;
usort($lowSorted, fn($a, $b) => ($a['Current_Stock_Quantity'] / max(1, $a['Reorder_Level'])) <=> ($b['Current_Stock_Quantity'] / max(1, $b['Reorder_Level'])));
$supNames   = $pdo->query("SELECT Supplier_ID, Supplier_Name FROM suppliers")->fetchAll(PDO::FETCH_KEY_PAIR);
$incoming   = get_incoming_orders($pdo);
$incomingUnits = array_sum(array_column($incoming, 'units'));
$overdueN   = count(array_filter($incoming, fn($o) => $o['Expected_Delivery_Date'] && $o['Expected_Delivery_Date'] < date('Y-m-d')));
$flow7      = movement_totals($pdo, 7);
$moves      = get_stock_movements($pdo, 8);
$alerts     = $pdo->query("SELECT * FROM alerts WHERE Status = 'Unread' ORDER BY Date_Generated DESC, Alert_ID DESC LIMIT 5")->fetchAll();
$alertCount = unread_alert_count($pdo);

$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="topbar">
  <h1>Inventory Dashboard</h1>
  <div class="actions">
    <a class="btn btn-ghost" href="/admin/inventory.php">Adjust stock</a>
    <a class="btn btn-basil" href="/admin/orders.php">+ New restock order</a>
  </div>
</div>

<div class="kpi-row five" data-live="kpis">
  <div class="kpi"><div class="label">Products (SKUs)</div><div class="num" data-key="kpi-skus"><?= (int)$totals['skus'] ?></div><div class="delta up">in <?= (int)$totals['cats'] ?> categories</div></div>
  <div class="kpi"><div class="label">Units on hand</div><div class="num" data-key="kpi-units"><?= number_format((int)$totals['units']) ?></div><div class="delta up"><?= peso($totals['value']) ?> stock value</div></div>
  <div class="kpi <?= $lowStock ? 'alertish' : '' ?>"><div class="label">Low / out of stock</div><div class="num" data-key="kpi-low"><?= count($lowStock) ?></div><div class="delta <?= $lowStock ? 'warn' : 'up' ?>"><?= $lowStock ? ((int)$outCount . ' out of stock · reorder now') : 'all above reorder level' ?></div></div>
  <div class="kpi"><div class="label">Incoming stock</div><div class="num" data-key="kpi-incoming"><?= number_format((int)$incomingUnits) ?></div><div class="delta <?= $overdueN ? 'warn' : 'up' ?>"><?= count($incoming) ?> open order<?= count($incoming) === 1 ? '' : 's' ?><?= $overdueN ? ' · ' . $overdueN . ' overdue' : '' ?></div></div>
  <div class="kpi"><div class="label">Last 7 days</div><div class="num" data-key="kpi-net"><?= ($flow7['in'] - $flow7['out'] >= 0 ? '+' : '−') . number_format(abs($flow7['in'] - $flow7['out'])) ?></div><div class="delta up">+<?= number_format($flow7['in']) ?> received · −<?= number_format($flow7['out']) ?> issued</div></div>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Stock vs reorder level <small style="color:var(--slate);font-weight:500;font-size:11.5px;">most urgent first</small></h3>
    <div class="legend-note"><span><i style="background:#3E6B52"></i>Healthy</span><span><i style="background:#E2A33D"></i>Low</span><span><i style="background:#B23A2E"></i>Out</span><span><i style="background:#D9D2BD"></i>Reorder level</span></div>
    <div class="chart-wrap" id="stockWrap"><canvas id="stockChart"></canvas></div>
  </div>
  <div class="panel" data-live="reorder-list">
    <h3>Needs reordering <small style="color:var(--slate);font-weight:500;font-size:11.5px;"><?= count($lowStock) ?> item<?= count($lowStock) === 1 ? '' : 's' ?></small> <a href="/admin/inventory.php?status=low" style="margin-left:auto;font-size:12px;font-weight:600;">View all</a></h3>
    <?php if (!$lowSorted): ?>
      <p style="color:var(--slate);font-size:13.5px;">Every product is above its reorder level.</p>
    <?php else: foreach (array_slice($lowSorted, 0, 6) as $p): $st = stock_status($p['Current_Stock_Quantity'], $p['Reorder_Level']); ?>
      <div class="reorder-row">
        <div>
          <div class="nm"><?= htmlspecialchars($p['Product_Name']) ?></div>
          <div class="meta"><span data-key="low-<?= htmlspecialchars($p['Product_ID']) ?>"><?= (int)$p['Current_Stock_Quantity'] ?></span> on hand · reorder at <?= (int)$p['Reorder_Level'] ?> · <?= htmlspecialchars($supNames[$p['Supplier_ID']] ?? '—') ?></div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/orders.php?restock=<?= urlencode($p['Product_ID']) ?>">Reorder</a>
        <?= stock_bar($p['Current_Stock_Quantity'], $p['Reorder_Level']) ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="panel">
    <h3>Stock movement <small style="color:var(--slate);font-weight:500;font-size:11.5px;">units received vs issued, last 14 days</small></h3>
    <div class="chart-wrap"><canvas id="moveChart"></canvas></div>
  </div>
  <div class="panel" data-live="incoming">
    <h3>Incoming deliveries <a href="/admin/orders.php" style="margin-left:auto;font-size:12px;font-weight:600;">Restock orders</a></h3>
    <?php if (!$incoming): ?>
      <p style="color:var(--slate);font-size:13.5px;">No open restock orders. Reorder from the list when stock runs low.</p>
    <?php else: foreach (array_slice($incoming, 0, 5) as $o): $late = $o['Expected_Delivery_Date'] && $o['Expected_Delivery_Date'] < date('Y-m-d'); ?>
      <div class="incoming-row">
        <div class="top"><span><?= htmlspecialchars($o['Order_ID']) ?> · <?= htmlspecialchars($o['Supplier_Name'] ?? '—') ?></span>
          <span class="badge <?= badge_class($o['Status']) ?>" data-key="ostatus-<?= htmlspecialchars($o['Order_ID']) ?>"><?= htmlspecialchars($o['Status']) ?></span></div>
        <div class="items"><?= htmlspecialchars(implode(', ', array_map(fn($i) => $i['Product_Name'] . ' ×' . $i['Quantity'], $o['items']))) ?></div>
        <div class="items <?= $late ? 'overdue' : '' ?>"><?= $o['Expected_Delivery_Date'] ? ($late ? 'Overdue · was due ' : 'Expected ') . htmlspecialchars($o['Expected_Delivery_Date']) : 'No delivery date set' ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="panel" data-live="recent-moves">
    <h3>Recent stock movements <a href="/admin/movements.php" style="margin-left:auto;font-size:12px;font-weight:600;">View all</a></h3>
    <div class="table-scroll"><table>
      <thead><tr><th>Date</th><th>Product</th><th>Movement</th><th class="num-r">Qty</th></tr></thead>
      <tbody>
        <?php foreach ($moves as $m): $in = $m['Direction'] === 'IN'; ?>
          <tr>
            <td><?= htmlspecialchars($m['Move_Date']) ?></td>
            <td><?= htmlspecialchars($m['Product_Name']) ?><span class="sku"><?= htmlspecialchars($m['Ref']) ?> · <?= htmlspecialchars($m['Party'] ?? '—') ?></span></td>
            <td><span class="mv <?= $in ? 'in' : 'out' ?>"><?= $in ? '▲ Received' : '▼ Issued' ?></span></td>
            <td class="num-r <?= $in ? 'qty-in' : 'qty-out' ?>"><?= $in ? '+' : '−' ?><?= (int)$m['Qty'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$moves): ?><tr><td colspan="4">No stock movements yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
  <div>
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
    <div class="panel">
      <h3>Stock by category <small style="color:var(--slate);font-weight:500;font-size:11.5px;">units on hand</small></h3>
      <div class="chart-wrap" style="height:190px;"><canvas id="invChart"></canvas></div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// Real-time refresh: assets/js/live.js swaps the KPI/list panels and calls Live.on() below for the charts.
const OK = '#3E6B52', LOW = '#E2A33D', OUT = '#B23A2E', REORDER = '#D9D2BD', GRID = '#E2DCCB';
let stockChart, moveChart, invChart;

function loadStats(){
  fetch('/api/dashboard_stats.php').then(r => r.json()).then(d => {
    // --- stock vs reorder level (horizontal, one row per product) ---
    const colors = d.products.map(p => p.stock <= 0 ? OUT : (p.stock <= p.reorder ? LOW : OK));
    document.getElementById('stockWrap').style.height = Math.max(230, d.products.length * 38 + 30) + 'px';
    const stockData = { labels: d.products.map(p => p.name), datasets: [
      { label: 'On hand',       data: d.products.map(p => p.stock),   backgroundColor: colors,  borderRadius: 4, barPercentage: .8, categoryPercentage: .8 },
      { label: 'Reorder level', data: d.products.map(p => p.reorder), backgroundColor: REORDER, borderRadius: 4, barPercentage: .8, categoryPercentage: .8 } ] };
    if (!stockChart) {
      stockChart = new Chart(document.getElementById('stockChart'), { type: 'bar', data: stockData,
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } },
                   scales: { x: { beginAtZero: true, grid: { color: GRID }, ticks: { precision: 0 } }, y: { grid: { display: false } } } } });
    } else { stockChart.data = stockData; stockChart.resize(); stockChart.update(); }

    // --- units received vs issued ---
    const moveData = { labels: d.moves.labels, datasets: [
      { label: 'Received', data: d.moves.in,  backgroundColor: OK,  borderRadius: 4 },
      { label: 'Issued',   data: d.moves.out, backgroundColor: LOW, borderRadius: 4 } ] };
    if (!moveChart) {
      moveChart = new Chart(document.getElementById('moveChart'), { type: 'bar', data: moveData,
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } },
                   scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 7 } }, y: { beginAtZero: true, grid: { color: GRID }, ticks: { precision: 0 } } } } });
    } else { moveChart.data = moveData; moveChart.update(); }

    // --- stock by category ---
    if (!invChart) {
      invChart = new Chart(document.getElementById('invChart'), { type: 'bar',
        data: { labels: d.categories, datasets: [{ data: d.units, backgroundColor: OK, borderRadius: 6 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                   scales: { x: { grid: { display: false } }, y: { grid: { color: GRID }, ticks: { precision: 0 } } } } });
    } else { invChart.data.labels = d.categories; invChart.data.datasets[0].data = d.units; invChart.update(); }
  });
}

loadStats();
Live.on(loadStats);                 // instant: fired whenever stock/orders/sales change
setInterval(loadStats, 30000);      // fallback
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
