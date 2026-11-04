<?php
/**
 * Sales trajectory API.
 *   GET /api/sales_trend.php?period=daily|weekly|monthly|yearly
 *
 * Returns a gap-filled series so the chart always shows every bucket,
 * even buckets with no sales:
 *   daily   -> last 14 days
 *   weekly  -> last 12 weeks   (weeks start on Monday)
 *   monthly -> last 12 months
 *   yearly  -> last 5 years
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('Admin');
header('Content-Type: application/json');

$period = $_GET['period'] ?? 'daily';
if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid period']);
    exit;
}

$today = new DateTimeImmutable('today');

// Build the ordered list of bucket keys + display labels, and the SQL for each period.
$buckets = [];   // key => label
switch ($period) {
    case 'daily':
        $count = 14;
        for ($i = $count - 1; $i >= 0; $i--) {
            $d = $today->modify("-$i day");
            $buckets[$d->format('Y-m-d')] = $d->format('M j');
        }
        $keyExpr = "DATE_FORMAT(Sale_Date, '%Y-%m-%d')";
        $start   = $today->modify('-' . ($count - 1) . ' day');
        break;

    case 'weekly':
        $count = 12;
        $thisMonday = $today->modify('monday this week');
        for ($i = $count - 1; $i >= 0; $i--) {
            $d = $thisMonday->modify("-$i week");
            $buckets[$d->format('Y-m-d')] = 'Wk ' . $d->format('M j');
        }
        $keyExpr = "DATE_FORMAT(DATE_SUB(Sale_Date, INTERVAL WEEKDAY(Sale_Date) DAY), '%Y-%m-%d')";
        $start   = $thisMonday->modify('-' . ($count - 1) . ' week');
        break;

    case 'monthly':
        $count = 12;
        $thisMonth = $today->modify('first day of this month');
        for ($i = $count - 1; $i >= 0; $i--) {
            $d = $thisMonth->modify("-$i month");
            $buckets[$d->format('Y-m')] = $d->format('M Y');
        }
        $keyExpr = "DATE_FORMAT(Sale_Date, '%Y-%m')";
        $start   = $thisMonth->modify('-' . ($count - 1) . ' month');
        break;

    case 'yearly':
        $count = 5;
        $thisYear = (int)$today->format('Y');
        for ($y = $thisYear - $count + 1; $y <= $thisYear; $y++) {
            $buckets[(string)$y] = (string)$y;
        }
        $keyExpr = "DATE_FORMAT(Sale_Date, '%Y')";
        $start   = new DateTimeImmutable(($thisYear - $count + 1) . '-01-01');
        break;
}

$stmt = $pdo->prepare(
    "SELECT $keyExpr AS bucket, SUM(Total_Amount) AS revenue, COUNT(*) AS tx
       FROM sales
      WHERE Sale_Date >= ?
      GROUP BY bucket"
);
$stmt->execute([$start->format('Y-m-d')]);
$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[$r['bucket']] = $r;
}

$labels = $revenue = $tx = [];
foreach ($buckets as $key => $label) {
    $labels[]  = $label;
    $revenue[] = (float)($rows[$key]['revenue'] ?? 0);
    $tx[]      = (int)($rows[$key]['tx'] ?? 0);
}

// Change of the latest bucket vs. the one before it (null if previous bucket had no sales).
$n = count($revenue);
$prev = $n >= 2 ? $revenue[$n - 2] : 0;
$change = $prev > 0 ? round((($revenue[$n - 1] - $prev) / $prev) * 100, 1) : null;

echo json_encode([
    'period'     => $period,
    'labels'     => $labels,
    'revenue'    => $revenue,
    'tx'         => $tx,
    'total'      => array_sum($revenue),
    'change_pct' => $change,
    'in_progress' => true,   // latest bucket is still running, so the % change compares a partial period
]);
