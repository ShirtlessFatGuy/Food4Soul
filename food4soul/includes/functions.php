<?php
/**
 * Shared application-layer helpers.
 */

function peso($amount) {
    return '₱' . number_format((float)$amount, 0);
}

function badge_class($status) {
    $map = [
        'Delivered' => 'ok', 'In Stock' => 'ok',
        'Pending' => 'warn', 'Low Stock' => 'bad',
        'Processing' => 'neutral', 'Cancelled' => 'bad',
        'Unread' => 'warn', 'Read' => 'neutral',
    ];
    return $map[$status] ?? 'neutral';
}

/** Emoji thumbnail per product category (matches the prototype catalog). */
function category_icon(?string $category): string {
    $map = ['Meat'=>'🥩','Produce'=>'🥬','Seafood'=>'🐟','Pantry'=>'🫙','Dairy'=>'🧀','Beverage'=>'🥤'];
    return $map[$category] ?? '🧺';
}

/** Next sequential ID for a table, e.g. next_id($pdo,'alerts','Alert_ID','A') -> A000004 */
function next_id(PDO $pdo, string $table, string $column, string $prefix): string {
    $stmt = $pdo->query("SELECT $column FROM $table ORDER BY $column DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $num = $last ? ((int)substr($last, strlen($prefix))) + 1 : 1;
    return $prefix . str_pad((string)$num, 6, '0', STR_PAD_LEFT);
}

/** Products at/below reorder level, optionally limited to one supplier. */
function get_low_stock_products(PDO $pdo, ?string $supplierId = null): array {
    $sql = "SELECT p.*, i.Reorder_Level
            FROM products p
            JOIN inventory i ON i.Product_ID = p.Product_ID
            WHERE p.Current_Stock_Quantity <= i.Reorder_Level";
    $params = [];
    if ($supplierId) {
        $sql .= " AND p.Supplier_ID = ?";
        $params[] = $supplierId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Updates stock, stamps the inventory row, and raises a Low Stock alert
 * when the new quantity is at/below the reorder level (REQ-004).
 */
function update_stock(PDO $pdo, string $productId, int $newQty): void {
    $pdo->prepare('UPDATE products SET Current_Stock_Quantity = ? WHERE Product_ID = ?')
        ->execute([$newQty, $productId]);
    $pdo->prepare('UPDATE inventory SET Last_Updated_Date = CURDATE() WHERE Product_ID = ?')
        ->execute([$productId]);

    $stmt = $pdo->prepare('SELECT p.Product_Name, i.Reorder_Level FROM products p
                            JOIN inventory i ON i.Product_ID = p.Product_ID
                            WHERE p.Product_ID = ?');
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if ($row && $newQty <= $row['Reorder_Level']) {
        $alertId = next_id($pdo, 'alerts', 'Alert_ID', 'A');
        $msg = "{$row['Product_Name']} is at {$newQty} units (reorder level: {$row['Reorder_Level']}).";
        $pdo->prepare('INSERT INTO alerts (Alert_ID, Product_ID, Alert_Type, Alert_Message, Date_Generated, Status)
                        VALUES (?,?,?,?,CURDATE(),"Unread")')
            ->execute([$alertId, $productId, 'Low Stock', $msg]);
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}
