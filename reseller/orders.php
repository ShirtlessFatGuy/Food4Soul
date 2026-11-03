<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Reseller');

$stmt = $pdo->prepare("SELECT s.*, p.Product_Name FROM sales s JOIN products p ON p.Product_ID = s.Product_ID
                        WHERE s.User_ID = ? ORDER BY s.Sale_Date DESC, s.Sale_ID DESC");
$stmt->execute([current_user()['User_ID']]);
$mine = $stmt->fetchAll();

$active = 'orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>My Orders</h1></div>
<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Order</th><th>Product</th><th>Qty</th><th>Total</th><th>Payment</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($mine as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['Sale_ID']) ?></td>
          <td><?= htmlspecialchars($s['Product_Name']) ?></td>
          <td><?= (int)$s['Quantity_Sold'] ?></td>
          <td><?= peso($s['Total_Amount']) ?></td>
          <td><?= htmlspecialchars($s['Payment_Method']) ?></td>
          <td><?= htmlspecialchars($s['Sale_Date']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mine): ?><tr><td colspan="6">No orders yet. Place your first one from the catalog.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
