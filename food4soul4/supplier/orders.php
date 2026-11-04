<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Supplier');

$supplierId = current_user()['Supplier_ID'];
$message = '';
$msgOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        // Ownership is enforced inside set_order_status(); marking Delivered adds the stock.
        $message = set_order_status($pdo, $_POST['order_id'] ?? '', $supplierId, $_POST['status'] ?? '');
        $msgOk = true;
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $msgOk = false;
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
<?php if ($message): ?><div class="alert-box <?= $msgOk ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="panel" data-live="orders"><div class="table-scroll">
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
          <td><span class="badge <?= badge_class($o['Status']) ?>" data-key="ostatus-<?= htmlspecialchars($o['Order_ID']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
          <td>
            <?php if (in_array($o['Status'], ['Delivered', 'Cancelled'], true)): ?>
              <span class="hint"><?= $o['Status'] === 'Delivered' ? 'Delivered · stock added' : 'Closed' ?></span>
            <?php else: ?>
            <form method="post" style="display:flex;gap:6px;" onsubmit="var s=this.status.value; return s!=='Delivered' || confirm('Mark <?= htmlspecialchars($o['Order_ID']) ?> as delivered? This adds the ordered quantities to stock.');">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['Order_ID']) ?>">
              <select class="inline-input" name="status" style="width:auto;">
                <?php foreach (['Pending','Processing','Delivered','Cancelled'] as $s): ?>
                  <option <?= $s === $o['Status'] ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6">No orders assigned.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
