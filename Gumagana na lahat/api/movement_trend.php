<?php
/**
 * Stock movement trend API (units received vs issued).
 *   GET /api/movement_trend.php?period=daily|weekly|monthly|yearly
 *
 * Returns a gap-filled series so the chart always shows every bucket,
 * even buckets with no movement:
 *   daily   -> last 14 days
 *   weekly  -> last 12 weeks   (weeks start on Monday)
 *   monthly -> last 12 months
 *   yearly  -> last 5 years
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
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
        $keyExpr = "DATE_FORMAT(Move_Date, '%Y-%m-%d')";
        $start   = $today->modify('-' . ($count - 1) . ' day');
        break;

    case 'weekly':
        $count = 12;
        $thisMonday = $today->modify('monday this week');
        for ($i = $count - 1; $i >= 0; $i--) {
            $d = $thisMonday->modify("-$i week");
            $buckets[$d->format('Y-m-d')] = 'Wk ' . $d->format('M j');
        }
        $keyExpr = "DATE_FORMAT(DATE_SUB(Move_Date, INTERVAL WEEKDAY(Move_Date) DAY), '%Y-%m-%d')";
        $start   = $thisMonday->modify('-' . ($count - 1) . ' week');
        break;

    case 'monthly':
        $count = 12;
        $thisMonth = $today->modify('first day of this month');
        for ($i = $count - 1; $i >= 0; $i--) {
            $d = $thisMonth->modify("-$i month");
            $buckets[$d->format('Y-m')] = $d->format('M Y');
        }
        $keyExpr = "DATE_FORMAT(Move_Date, '%Y-%m')";
        $start   = $thisMonth->modify('-' . ($count - 1) . ' month');
        break;

    case 'yearly':
        $count = 5;
        $thisYear = (int)$today->format('Y');
        for ($y = $thisYear - $count + 1; $y <= $thisYear; $y++) {
            $buckets[(string)$y] = (string)$y;
        }
        $keyExpr = "DATE_FORMAT(Move_Date, '%Y')";
        $start   = new DateTimeImmutable(($thisYear - $count + 1) . '-01-01');
        break;
}

$stmt = $pdo->prepare(
    "SELECT $keyExpr AS bucket, m.Direction, SUM(m.Qty) AS units
       FROM (" . movement_union_sql() . ") m
      WHERE Move_Date >= ?
      GROUP BY bucket, m.Direction"
);
$stmt->execute([$start->format('Y-m-d')]);
$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[$r['bucket']][$r['Direction']] = (int)$r['units'];
}

$labels = $in = $out = [];
foreach ($buckets as $key => $label) {
    $labels[] = $label;
    $in[]     = $rows[$key]['IN']  ?? 0;
    $out[]    = $rows[$key]['OUT'] ?? 0;
}

echo json_encode([
    'period'   => $period,
    'labels'   => $labels,
    'received' => $in,
    'issued'   => $out,
    'total_received' => array_sum($in),
    'total_issued'   => array_sum($out),
]);
