<?php
/**
 * Supplier dashboard data. Every query is scoped to the logged-in supplier's own
 * Supplier_ID (taken from the session, never from the request), so a supplier can
 * only ever see their own products, orders and sales.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('Supplier');
header('Content-Type: application/json');

$sid   = current_user()['Supplier_ID'];
$today = new DateTimeImmutable('today');

// 1) Stock vs reorder level, per product
$st = $pdo->prepare("SELECT p.Product_Name, p.Current_Stock_Quantity AS stock, COALESCE(i.Reorder_Level, 0) AS reorder
                       FROM products p LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
                      WHERE p.Supplier_ID = ? ORDER BY p.Product_Name");
$st->execute([$sid]);
$stock = $st->fetchAll();

// 2) Orders by status
$st = $pdo->prepare("SELECT Status, COUNT(*) AS n FROM orders WHERE Supplier_ID = ? GROUP BY Status");
$st->execute([$sid]);
$byStatus = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$statusOrder = ['Pending', 'Processing', 'Delivered', 'Cancelled'];
$statusCounts = array_map(fn($s) => (int)($byStatus[$s] ?? 0), $statusOrder);

// 3) Units sold per product, last 30 days (demand for this supplier's products)
$since30 = $today->modify('-29 day')->format('Y-m-d');
$st = $pdo->prepare("SELECT p.Product_Name, SUM(s.Quantity_Sold) AS units
                       FROM sales s JOIN products p ON p.Product_ID = s.Product_ID
                      WHERE p.Supplier_ID = ? AND s.Sale_Date >= ?
                      GROUP BY p.Product_ID, p.Product_Name ORDER BY units DESC");
$st->execute([$sid, $since30]);
$sold = $st->fetchAll();

// 4) Units sold per day, last 14 days (gap-filled)
$since14 = $today->modify('-13 day')->format('Y-m-d');
$st = $pdo->prepare("SELECT s.Sale_Date AS d, SUM(s.Quantity_Sold) AS units
                       FROM sales s JOIN products p ON p.Product_ID = s.Product_ID
                      WHERE p.Supplier_ID = ? AND s.Sale_Date >= ?
                      GROUP BY s.Sale_Date");
$st->execute([$sid, $since14]);
$perDay = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$labels = $units = [];
for ($i = 13; $i >= 0; $i--) {
    $d = $today->modify("-$i day");
    $labels[] = $d->format('M j');
    $units[]  = (int)($perDay[$d->format('Y-m-d')] ?? 0);
}

// 5) Needs attention: overdue orders + low-stock products
$st = $pdo->prepare("SELECT Order_ID, Expected_Delivery_Date, Status FROM orders
                      WHERE Supplier_ID = ? AND Status IN ('Pending','Processing')
                        AND Expected_Delivery_Date IS NOT NULL AND Expected_Delivery_Date < ?
                      ORDER BY Expected_Delivery_Date");
$st->execute([$sid, $today->format('Y-m-d')]);
$overdue = $st->fetchAll();

$low = [];
foreach ($stock as $p) {
    if ($p['stock'] <= $p['reorder']) {
        $low[] = ['name' => $p['Product_Name'], 'stock' => (int)$p['stock'], 'reorder' => (int)$p['reorder']];
    }
}

echo json_encode([
    'stock'   => ['labels' => array_column($stock, 'Product_Name'),
                  'stock'  => array_map('intval', array_column($stock, 'stock')),
                  'reorder'=> array_map('intval', array_column($stock, 'reorder'))],
    'status'  => ['labels' => $statusOrder, 'counts' => $statusCounts],
    'sold'    => ['labels' => array_column($sold, 'Product_Name'), 'units' => array_map('intval', array_column($sold, 'units'))],
    'trend'   => ['labels' => $labels, 'units' => $units],
    'overdue' => $overdue,
    'low'     => $low,
]);
