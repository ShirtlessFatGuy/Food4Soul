<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Supplier');

$supplierId = current_user()['Supplier_ID'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $allowed = ['Pending', 'Processing', 'Delivered', 'Cancelled'];
    if (in_array($_POST['status'] ?? '', $allowed, true)) {
        // Ownership check: a supplier may only update their own orders.
        $stmt = $pdo->prepare("UPDATE orders SET Status = ? WHERE Order_ID = ? AND Supplier_ID = ?");
        $stmt->execute([$_POST['status'], $_POST['order_id'] ?? '', $supplierId]);
        if ($stmt->rowCount() > 0) {
            $message = 'Order ' . $_POST['order_id'] . ' marked as ' . $_POST['status'] . '.';
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE Supplier_ID = ? ORDER BY Order_Date DESC");
$stmt->execute([$supplierId]);
$orders = $stmt->fetchAll();
$itemsStmt = $pdo->prepare("SELECT od.Quantity, p.Product_Name FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID WHERE od.Order_ID = ?");

$active = 'orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Assigned Orders</h1></div>
<?php if ($message): ?><div class="alert-box alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Order ID</th><th>Items</th><th>Order date</th><th>Expected delivery</th><th>Status</th><th>Update</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o):
        $itemsStmt->execute([$o['Order_ID']]);
        $itemStr = implode(', ', array_map(fn($i) => $i['Product_Name'] . ' ×' . $i['Quantity'], $itemsStmt->fetchAll()));
      ?>
        <tr>
          <td><strong><?= htmlspecialchars($o['Order_ID']) ?></strong></td>
          <td><?= htmlspecialchars($itemStr) ?></td>
          <td><?= htmlspecialchars($o['Order_Date']) ?></td>
          <td><?= htmlspecialchars($o['Expected_Delivery_Date']) ?></td>
          <td><span class="badge <?= badge_class($o['Status']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
          <td>
            <form method="post" style="display:flex;gap:6px;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['Order_ID']) ?>">
              <select class="inline-input" name="status" style="width:auto;">
                <?php foreach (['Pending','Processing','Delivered','Cancelled'] as $s): ?>
                  <option <?= $s === $o['Status'] ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6">No orders assigned.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
