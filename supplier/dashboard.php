<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Supplier');

$supplierId = current_user()['Supplier_ID'];

$stmt = $pdo->prepare("SELECT * FROM orders WHERE Supplier_ID = ? ORDER BY Order_Date DESC");
$stmt->execute([$supplierId]);
$orders = $stmt->fetchAll();

$pending  = count(array_filter($orders, fn($o) => $o['Status'] !== 'Delivered'));
$lowStock = get_low_stock_products($pdo, $supplierId);

$pc = $pdo->prepare("SELECT COUNT(*) FROM products WHERE Supplier_ID = ?");
$pc->execute([$supplierId]);
$productCount = $pc->fetchColumn();

$itemsStmt = $pdo->prepare("SELECT od.Quantity, p.Product_Name FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID WHERE od.Order_ID = ?");

$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Overview</h1></div>
<div class="kpi-row">
  <div class="kpi"><div class="label">Assigned orders</div><div class="num"><?= count($orders) ?></div><div class="delta warn"><?= $pending ?> in progress</div></div>
  <div class="kpi"><div class="label">Products supplied</div><div class="num"><?= (int)$productCount ?></div><div class="delta up">to Food4Soul</div></div>
  <div class="kpi"><div class="label">Low-stock products</div><div class="num"><?= count($lowStock) ?></div><div class="delta warn">need restocking</div></div>
</div>
<div class="panel">
  <h3>Your recent orders</h3>
  <div class="table-scroll"><table>
    <thead><tr><th>Order</th><th>Items</th><th>Expected delivery</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o):
        $itemsStmt->execute([$o['Order_ID']]);
        $itemStr = implode(', ', array_map(fn($i) => $i['Product_Name'] . ' ×' . $i['Quantity'], $itemsStmt->fetchAll()));
      ?>
        <tr>
          <td><?= htmlspecialchars($o['Order_ID']) ?></td>
          <td><?= htmlspecialchars($itemStr) ?></td>
          <td><?= htmlspecialchars($o['Expected_Delivery_Date']) ?></td>
          <td><span class="badge <?= badge_class($o['Status']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="4">No orders assigned yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
