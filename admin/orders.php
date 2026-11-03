<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$orders = $pdo->query("SELECT o.*, s.Supplier_Name FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID ORDER BY o.Order_Date DESC")->fetchAll();
$itemsStmt = $pdo->prepare("SELECT od.Quantity, p.Product_Name FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID WHERE od.Order_ID = ?");

$active = 'orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Orders</h1></div>
<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Order ID</th><th>Supplier</th><th>Items</th><th>Order date</th><th>Expected delivery</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o):
        $itemsStmt->execute([$o['Order_ID']]);
        $itemStr = implode(', ', array_map(fn($i) => $i['Product_Name'] . ' ×' . $i['Quantity'], $itemsStmt->fetchAll()));
      ?>
        <tr>
          <td><strong><?= htmlspecialchars($o['Order_ID']) ?></strong></td>
          <td><?= htmlspecialchars($o['Supplier_Name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($itemStr) ?></td>
          <td><?= htmlspecialchars($o['Order_Date']) ?></td>
          <td><?= htmlspecialchars($o['Expected_Delivery_Date']) ?></td>
          <td><span class="badge <?= badge_class($o['Status']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
