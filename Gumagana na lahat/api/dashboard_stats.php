<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');
header('Content-Type: application/json');

// Stock units per category
$cats = $pdo->query("
    SELECT Category, SUM(Current_Stock_Quantity) AS units
    FROM products
    GROUP BY Category
    ORDER BY Category
")->fetchAll();

// Stock vs reorder level per product, most urgent first (lowest stock-to-reorder ratio)
$prods = $pdo->query("
    SELECT p.Product_Name AS name, p.Current_Stock_Quantity AS stock, COALESCE(i.Reorder_Level, 0) AS reorder
      FROM products p LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
     ORDER BY p.Current_Stock_Quantity / GREATEST(COALESCE(i.Reorder_Level, 0), 1), p.Product_Name
")->fetchAll();

// Units received (IN) and issued (OUT) per day, last 14 days
$st = $pdo->query("SELECT m.Move_Date, m.Direction, SUM(m.Qty) AS q FROM (" . movement_union_sql() . ") m
                    WHERE m.Move_Date >= CURDATE() - INTERVAL 13 DAY GROUP BY m.Move_Date, m.Direction");
$byDay = [];
foreach ($st->fetchAll() as $r) $byDay[$r['Move_Date']][$r['Direction']] = (int)$r['q'];
$labels = $in = $out = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $labels[] = date('M j', strtotime($d));
    $in[]  = $byDay[$d]['IN']  ?? 0;
    $out[] = $byDay[$d]['OUT'] ?? 0;
}

echo json_encode([
    'categories' => array_column($cats, 'Category'),
    'units'      => array_map('intval', array_column($cats, 'units')),
    'products'   => array_map(fn($p) => ['name' => $p['name'], 'stock' => (int)$p['stock'], 'reorder' => (int)$p['reorder']], $prods),
    'moves'      => ['labels' => $labels, 'in' => $in, 'out' => $out],
]);
