<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['Admin', 'Supplier']);
header('Content-Type: application/json');

// Sales per day, last 14 days
$stmt = $pdo->query("
    SELECT Sale_Date, SUM(Total_Amount) AS total
    FROM sales
    WHERE Sale_Date >= CURDATE() - INTERVAL 13 DAY
    GROUP BY Sale_Date
");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Sale_Date => total

$labels = []; $totals = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i day"));
    $labels[] = date('M j', strtotime($date));
    $totals[] = (float)($rows[$date] ?? 0);
}

// Stock units per category
$cats = $pdo->query("
    SELECT Category, SUM(Current_Stock_Quantity) AS units
    FROM products
    GROUP BY Category
    ORDER BY Category
")->fetchAll();

echo json_encode([
    'labels'     => $labels,
    'totals'     => $totals,
    'categories' => array_column($cats, 'Category'),
    'units'      => array_map('intval', array_column($cats, 'units')),
]);
