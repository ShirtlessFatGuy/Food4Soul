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
 * Updates stock, stamps the inventory row, and keeps Low Stock alerts in sync (REQ-004):
 *  - at/below the reorder level -> raise ONE Unread alert (no duplicates while one is open)
 *  - back above the reorder level -> auto-resolve (mark Read) any open Low Stock alert
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
    if (!$row) return;

    if ($newQty <= $row['Reorder_Level']) {
        $open = $pdo->prepare("SELECT 1 FROM alerts WHERE Product_ID = ? AND Alert_Type = 'Low Stock' AND Status = 'Unread' LIMIT 1");
        $open->execute([$productId]);
        if (!$open->fetchColumn()) {
            $alertId = next_id($pdo, 'alerts', 'Alert_ID', 'A');
            $msg = "{$row['Product_Name']} is at {$newQty} units (reorder level: {$row['Reorder_Level']}).";
            $pdo->prepare("INSERT INTO alerts (Alert_ID, Product_ID, Alert_Type, Alert_Message, Date_Generated, Status)
                            VALUES (?,?,?,?,CURDATE(),'Unread')")
                ->execute([$alertId, $productId, 'Low Stock', $msg]);
        }
    } else {
        $pdo->prepare("UPDATE alerts SET Status = 'Read' WHERE Product_ID = ? AND Alert_Type = 'Low Stock' AND Status = 'Unread'")
            ->execute([$productId]);
    }
}

/**
 * Raises a "Late Order" alert for every restock order that is past its expected
 * delivery date and still Pending/Processing. One alert per order, ever: once an
 * alert exists for an order (read or not) it is not raised again.
 * Called whenever an admin page that shows alerts is loaded.
 */
function check_late_orders(PDO $pdo): int {
    $late = $pdo->query("SELECT o.Order_ID, o.Expected_Delivery_Date, s.Supplier_Name
                           FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID
                          WHERE o.Status IN ('Pending','Processing')
                            AND o.Expected_Delivery_Date IS NOT NULL
                            AND o.Expected_Delivery_Date < CURDATE()")->fetchAll();
    $has = $pdo->prepare("SELECT 1 FROM alerts WHERE Alert_Type = 'Late Order' AND Alert_Message LIKE ? LIMIT 1");
    $raised = 0;
    foreach ($late as $o) {
        $has->execute(['Order ' . $o['Order_ID'] . ' %']);
        if ($has->fetchColumn()) continue;
        $msg = "Order {$o['Order_ID']} from " . ($o['Supplier_Name'] ?? 'supplier') .
               " was due {$o['Expected_Delivery_Date']} and is not delivered yet.";
        $pdo->prepare("INSERT INTO alerts (Alert_ID, Product_ID, Alert_Type, Alert_Message, Date_Generated, Status)
                        VALUES (?,NULL,?,?,CURDATE(),'Unread')")
            ->execute([next_id($pdo, 'alerts', 'Alert_ID', 'A'), 'Late Order', $msg]);
        $raised++;
    }
    return $raised;
}

function unread_alert_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE Status = 'Unread'")->fetchColumn();
}

/**
 * Records a reseller purchase: one sales row per cart line, stock deducted, low-stock
 * alerts raised. Rejects quantities above available stock.
 * Runs in its own DB transaction unless the caller already opened one.
 * $cart is [Product_ID => qty]. Throws RuntimeException with a user-facing message.
 */
function place_order(PDO $pdo, string $userId, array $cart, string $method): void {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach ($cart as $pid => $qty) {
            $q = $pdo->prepare("SELECT * FROM products WHERE Product_ID = ? FOR UPDATE");
            $q->execute([$pid]);
            $prod = $q->fetch();
            if (!$prod) continue;

            if ($prod['Current_Stock_Quantity'] < $qty) {
                throw new RuntimeException('Not enough stock for ' . $prod['Product_Name'] . ' (only ' . $prod['Current_Stock_Quantity'] . ' left).');
            }

            $saleId = next_id($pdo, 'sales', 'Sale_ID', 'S');
            $total  = $prod['Unit_Price'] * $qty;
            $pdo->prepare("INSERT INTO sales (Sale_ID, Sale_Date, User_ID, Product_ID, Quantity_Sold, Unit_Price, Total_Amount, Payment_Method)
                           VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)")
                ->execute([$saleId, $userId, $pid, $qty, $prod['Unit_Price'], $total, $method]);

            update_stock($pdo, $pid, $prod['Current_Stock_Quantity'] - $qty);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Total price of a [Product_ID => qty] cart, using current prices. */
function cart_total(PDO $pdo, array $cart): float {
    $ps = $pdo->prepare("SELECT Unit_Price FROM products WHERE Product_ID = ?");
    $total = 0.0;
    foreach ($cart as $pid => $qty) {
        $ps->execute([$pid]);
        if ($price = $ps->fetchColumn()) $total += $price * $qty;
    }
    return $total;
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
