<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$period = $_GET['period'] ?? null;
$report = null;

if (in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$period];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(Total_Amount),0) AS revenue, COUNT(*) AS tx
                            FROM sales WHERE Sale_Date >= CURDATE() - INTERVAL ? DAY");
    $stmt->execute([$days - 1]);
    $totals = $stmt->fetch();

    $top = $pdo->prepare("SELECT p.Product_Name, SUM(s.Quantity_Sold) AS qty
                           FROM sales s JOIN products p ON p.Product_ID = s.Product_ID
                           WHERE s.Sale_Date >= CURDATE() - INTERVAL ? DAY
                           GROUP BY p.Product_ID, p.Product_Name ORDER BY qty DESC LIMIT 1");
    $top->execute([$days - 1]);
    $topProduct = $top->fetch();

    $report = [
        'period'    => $period,
        'days'      => $days,
        'revenue'   => $totals['revenue'],
        'tx'        => $totals['tx'],
        'top'       => $topProduct['Product_Name'] ?? '—',
        'low_stock' => count(get_low_stock_products($pdo)),
        'suppliers' => $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn(),
    ];
}

$active = 'reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Reports</h1></div>

<div class="grid-3">
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Daily</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Today's summary</p><a class="btn btn-primary" href="?period=daily">Generate</a></div>
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Weekly</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Last 7 days</p><a class="btn btn-primary" href="?period=weekly">Generate</a></div>
  <div class="panel" style="text-align:center;"><h3 style="justify-content:center;">Monthly</h3><p style="color:var(--slate);font-size:13px;margin:0 0 12px;">Last 30 days</p><a class="btn btn-primary" href="?period=monthly">Generate</a></div>
</div>

<?php if ($report): ?>
<div class="report-preview">
  <h3 style="margin:0 0 2px;">Food4Soul <?= ucfirst($report['period']) ?> Business Report</h3>
  <div class="meta">Generated <?= date('Y-m-d') ?> · Period: last <?= (int)$report['days'] ?> day<?= $report['days'] > 1 ? 's' : '' ?></div>
  <table>
    <tr><td>Total revenue</td><td style="text-align:right;font-weight:700;"><?= peso($report['revenue']) ?></td></tr>
    <tr><td>Transactions recorded</td><td style="text-align:right;font-weight:700;"><?= (int)$report['tx'] ?></td></tr>
    <tr><td>Top-selling product</td><td style="text-align:right;font-weight:700;"><?= htmlspecialchars($report['top']) ?></td></tr>
    <tr><td>Products below reorder level</td><td style="text-align:right;font-weight:700;"><?= (int)$report['low_stock'] ?></td></tr>
    <tr><td>Active suppliers</td><td style="text-align:right;font-weight:700;"><?= (int)$report['suppliers'] ?></td></tr>
  </table>
  <button class="btn btn-ghost btn-sm" style="margin-top:14px;" onclick="window.print()">Print</button>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
