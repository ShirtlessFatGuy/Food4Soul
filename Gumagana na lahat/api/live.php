<?php
/**
 * Live-sync heartbeat. Every page polls this every ~2 s (assets/js/live.js).
 * It returns a short fingerprint of everything the pages display: stock levels, restock
 * orders and their statuses, alerts and sales. When the fingerprint changes, the page
 * re-fetches itself and swaps the [data-live] regions in place; no manual refresh.
 * Works for every role, and is read-only and cheap (a handful of tiny SELECTs).
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}
session_write_close();   // don't hold the session lock while we query

$snap = [
    'stock'   => $pdo->query("SELECT p.Product_ID, p.Current_Stock_Quantity, i.Reorder_Level
                                FROM products p LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
                               ORDER BY p.Product_ID")->fetchAll(PDO::FETCH_NUM),
    'orders'  => $pdo->query("SELECT Order_ID, Status, Supplier_ID FROM orders ORDER BY Order_ID")->fetchAll(PDO::FETCH_NUM),
    'alerts'  => $pdo->query("SELECT Alert_ID, Status FROM alerts ORDER BY Alert_ID")->fetchAll(PDO::FETCH_NUM),
    'sales'   => $pdo->query("SELECT COUNT(*), COALESCE(SUM(Total_Amount),0) FROM sales")->fetch(PDO::FETCH_NUM),
];

echo json_encode([
    'v'      => md5(json_encode($snap)),
    'alerts' => (int)count(array_filter($snap['alerts'], fn($a) => $a[1] === 'Unread')),
]);
