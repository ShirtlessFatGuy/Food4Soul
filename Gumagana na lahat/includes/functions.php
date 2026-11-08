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
        'Pending' => 'warn', 'Low Stock' => 'bad', 'Out of Stock' => 'out',
        'Processing' => 'neutral', 'Cancelled' => 'bad',
        'Unread' => 'warn', 'Read' => 'neutral',
    ];
    return $map[$status] ?? 'neutral';
}

/** Stock status of a product: 'Out of Stock', 'Low Stock' (at/below reorder level) or 'In Stock'. */
function stock_status($qty, $reorder): string {
    $qty = (int)$qty; $reorder = (int)$reorder;
    if ($qty <= 0) return 'Out of Stock';
    return $qty <= $reorder ? 'Low Stock' : 'In Stock';
}

/** Small horizontal bar showing on-hand quantity with a tick at the reorder level. */
function stock_bar($qty, $reorder, bool $tick = true): string {
    $qty = max(0, (int)$qty); $reorder = max(0, (int)$reorder);
    $scale = max($reorder * 2, $qty, 1);
    $w = round($qty / $scale * 100, 1);
    $t = round($reorder / $scale * 100, 1);
    $cls = ['Out of Stock' => 'out', 'Low Stock' => 'low', 'In Stock' => 'ok'][stock_status($qty, $reorder)];
    return '<div class="stockbar" title="' . $qty . ' on hand' . ($tick ? ', reorder level ' . $reorder : '') . '"><i class="' . $cls . '" style="width:' . $w . '%"></i>'
         . ($tick && $reorder > 0 ? '<b style="left:' . $t . '%"></b>' : '') . '</div>';
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

/**
 * Admin -> supplier restock order (the "ordering" half of the order cycle).
 * $lines is [Product_ID => qty]. Every product must belong to $supplierId.
 * Returns the new Order_ID. Throws RuntimeException with a user-facing message.
 */
function create_restock_order(PDO $pdo, string $supplierId, array $lines, ?string $expected): string {
    $sup = $pdo->prepare('SELECT 1 FROM suppliers WHERE Supplier_ID = ?');
    $sup->execute([$supplierId]);
    if (!$sup->fetchColumn()) throw new RuntimeException('Please choose a supplier.');

    $clean = [];
    foreach ($lines as $pid => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) $clean[(string)$pid] = $qty;
    }
    if (!$clean) throw new RuntimeException('Enter a quantity for at least one product.');

    if ($expected !== null && $expected !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected)) {
        throw new RuntimeException('Invalid expected delivery date.');
    }
    if ($expected === '') $expected = null;

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare('SELECT 1 FROM products WHERE Product_ID = ? AND Supplier_ID = ?');
        foreach ($clean as $pid => $qty) {
            $chk->execute([$pid, $supplierId]);
            if (!$chk->fetchColumn()) throw new RuntimeException('One of the products is not sold by that supplier.');
        }
        $orderId = next_id($pdo, 'orders', 'Order_ID', 'O');
        $pdo->prepare("INSERT INTO orders (Order_ID, Order_Date, Expected_Delivery_Date, Status, Supplier_ID) VALUES (?, CURDATE(), ?, 'Pending', ?)")
            ->execute([$orderId, $expected, $supplierId]);
        foreach ($clean as $pid => $qty) {
            $pdo->prepare('INSERT INTO order_details (Order_Detail_ID, Order_ID, Product_ID, Quantity) VALUES (?,?,?,?)')
                ->execute([next_id($pdo, 'order_details', 'Order_Detail_ID', 'OD'), $orderId, $pid, $qty]);
        }
        if ($own) $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Changes a restock order's status. Delivered and Cancelled are final.
 * Moving to Delivered adds every line's quantity to that product's stock, exactly once,
 * in the same transaction (update_stock() then clears Low Stock alerts that are no longer true),
 * and leaves an info alert for the admin.
 * $supplierId limits the change to that supplier's own orders (null = admin).
 * Returns a user-facing message. Throws RuntimeException on invalid changes.
 */
function set_order_status(PDO $pdo, string $orderId, ?string $supplierId, string $newStatus): string {
    if (!in_array($newStatus, ['Pending', 'Processing', 'Delivered', 'Cancelled'], true)) {
        throw new RuntimeException('Unknown status.');
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $sql = 'SELECT o.*, s.Supplier_Name FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID WHERE o.Order_ID = ?';
        $args = [$orderId];
        if ($supplierId !== null) { $sql .= ' AND o.Supplier_ID = ?'; $args[] = $supplierId; }
        $st = $pdo->prepare($sql . ' FOR UPDATE');
        $st->execute($args);
        $order = $st->fetch();
        if (!$order) throw new RuntimeException('Order not found.');

        if (in_array($order['Status'], ['Delivered', 'Cancelled'], true)) {
            throw new RuntimeException("Order $orderId is already {$order['Status']} and can't be changed.");
        }
        if ($order['Status'] === $newStatus) {
            if ($own) $pdo->commit();
            return "Order $orderId is already $newStatus.";
        }
        if ($supplierId === null && $newStatus !== 'Cancelled') {
            throw new RuntimeException('Admins can only cancel an order; the supplier confirms and delivers it.');
        }

        $pdo->prepare('UPDATE orders SET Status = ? WHERE Order_ID = ?')->execute([$newStatus, $orderId]);

        $msg = "Order $orderId marked as $newStatus.";
        if ($newStatus === 'Delivered') {
            $d = $pdo->prepare('SELECT od.Product_ID, od.Quantity, p.Product_Name, p.Current_Stock_Quantity
                                  FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID
                                 WHERE od.Order_ID = ?');
            $d->execute([$orderId]);
            $parts = [];
            foreach ($d->fetchAll() as $line) {
                // re-read under lock so a sale made a moment ago isn't overwritten
                $cur = $pdo->prepare('SELECT Current_Stock_Quantity FROM products WHERE Product_ID = ? FOR UPDATE');
                $cur->execute([$line['Product_ID']]);
                $stock = (int)$cur->fetchColumn();
                update_stock($pdo, $line['Product_ID'], $stock + (int)$line['Quantity']);
                $parts[] = $line['Product_Name'] . ' +' . $line['Quantity'];
            }
            // any "Late Order" alert for this order is no longer true
            $pdo->prepare("UPDATE alerts SET Status = 'Read' WHERE Alert_Type = 'Late Order' AND Alert_Message LIKE ?")
                ->execute(['Order ' . $orderId . ' %']);
            $alert = "Order $orderId from " . ($order['Supplier_Name'] ?? 'supplier') . ' was delivered. Stock updated: ' . implode(', ', $parts) . '.';
            $pdo->prepare("INSERT INTO alerts (Alert_ID, Product_ID, Alert_Type, Alert_Message, Date_Generated, Status) VALUES (?,NULL,?,?,CURDATE(),'Unread')")
                ->execute([next_id($pdo, 'alerts', 'Alert_ID', 'A'), 'Delivery', mb_substr($alert, 0, 255)]);
            $msg = "Order $orderId delivered. Stock was added to inventory.";
        }
        if ($own) $pdo->commit();
        return $msg;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Stock movement ledger, derived from existing data (no extra table):
 *   IN  = lines of restock orders that are Delivered (dated by the order date)
 *   OUT = reseller purchases (sales rows)
 * Returns a derived-table SQL string with columns:
 *   Direction, Move_Date, Ref, Product_ID, Product_Name, Qty, Party
 */
function movement_union_sql(): string {
    return "SELECT 'OUT' AS Direction, s.Sale_Date AS Move_Date, s.Sale_ID AS Ref, p.Product_ID, p.Product_Name,
                   s.Quantity_Sold AS Qty, u.Full_Name AS Party
              FROM sales s JOIN products p ON p.Product_ID = s.Product_ID LEFT JOIN users u ON u.User_ID = s.User_ID
            UNION ALL
            SELECT 'IN', o.Order_Date, o.Order_ID, p.Product_ID, p.Product_Name, od.Quantity, su.Supplier_Name
              FROM orders o JOIN order_details od ON od.Order_ID = o.Order_ID
                   JOIN products p ON p.Product_ID = od.Product_ID
                   LEFT JOIN suppliers su ON su.Supplier_ID = o.Supplier_ID
             WHERE o.Status = 'Delivered'";
}

/** Builds the WHERE clause shared by the movement queries. $f keys: dir, product, from, to, party. */
function movement_where(array $f): array {
    $where = []; $args = [];
    if (($f['dir'] ?? '') === 'IN' || ($f['dir'] ?? '') === 'OUT') { $where[] = 'm.Direction = ?'; $args[] = $f['dir']; }
    if (!empty($f['product'])) { $where[] = 'm.Product_ID = ?'; $args[] = $f['product']; }
    if (!empty($f['from']))    { $where[] = 'm.Move_Date >= ?'; $args[] = $f['from']; }
    if (!empty($f['to']))      { $where[] = 'm.Move_Date <= ?'; $args[] = $f['to']; }
    if (!empty($f['party']))   { $where[] = 'm.Party = ?';     $args[] = $f['party']; }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $args];
}

/** Latest stock movements. Filters: dir ('IN'/'OUT'), product, from/to (Y-m-d), party (supplier or reseller name). */
function get_stock_movements(PDO $pdo, int $limit = 50, array $f = []): array {
    [$w, $args] = movement_where($f);
    $st = $pdo->prepare('SELECT m.* FROM (' . movement_union_sql() . ') m' . $w
                      . ' ORDER BY m.Move_Date DESC, m.Ref DESC LIMIT ' . max(1, $limit));
    $st->execute($args);
    return $st->fetchAll();
}

/** Units received (in) / issued (out) across everything matching the same filters. */
function movement_summary(PDO $pdo, array $f = []): array {
    unset($f['dir']);   // the summary always shows both directions
    [$w, $args] = movement_where($f);
    $st = $pdo->prepare('SELECT m.Direction, COALESCE(SUM(m.Qty),0) FROM (' . movement_union_sql() . ') m' . $w . ' GROUP BY m.Direction');
    $st->execute($args);
    $r = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['in' => (int)($r['IN'] ?? 0), 'out' => (int)($r['OUT'] ?? 0)];
}

/** Every supplier and reseller that appears in the ledger: [['Party'=>..., 'Direction'=>'IN'|'OUT'], ...] */
function movement_parties(PDO $pdo): array {
    return $pdo->query('SELECT DISTINCT m.Direction, m.Party FROM (' . movement_union_sql() . ') m
                         WHERE m.Party IS NOT NULL ORDER BY m.Direction DESC, m.Party')->fetchAll();
}

/** Total units received (IN) and issued (OUT) over the last $days days, including today. */
function movement_totals(PDO $pdo, int $days): array {
    return movement_summary($pdo, ['from' => date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' day'))]);
}

/** Open restock orders (Pending/Processing) with their items, soonest delivery first. */
function get_incoming_orders(PDO $pdo): array {
    $orders = $pdo->query("SELECT o.*, s.Supplier_Name FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID
                            WHERE o.Status IN ('Pending','Processing')
                            ORDER BY o.Expected_Delivery_Date IS NULL, o.Expected_Delivery_Date, o.Order_ID")->fetchAll();
    $items = $pdo->prepare("SELECT od.Quantity, p.Product_Name FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID WHERE od.Order_ID = ?");
    foreach ($orders as &$o) {
        $items->execute([$o['Order_ID']]);
        $o['items'] = $items->fetchAll();
        $o['units'] = array_sum(array_column($o['items'], 'Quantity'));
    }
    return $orders;
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
