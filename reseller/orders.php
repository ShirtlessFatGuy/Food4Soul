<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
require_role('Reseller');

$stmt = $pdo->prepare("SELECT s.*, p.Product_Name FROM sales s JOIN products p ON p.Product_ID = s.Product_ID
                        WHERE s.User_ID = ? ORDER BY s.Sale_Date DESC, s.Sale_ID DESC");
$stmt->execute([current_user()['User_ID']]);
$mine = $stmt->fetchAll();

$pp = $pdo->prepare("SELECT * FROM payments WHERE User_ID = ? ORDER BY Created_At DESC, Payment_ID DESC LIMIT 10");
$pp->execute([current_user()['User_ID']]);
$payments = array_filter(array_map(function ($p) use ($pdo) { $p['Status'] = refresh_payment_status($pdo, $p); return $p; }, $pp->fetchAll()),
                         fn($p) => $p['Status'] !== 'Paid');   // paid ones already appear below as orders

$active = 'orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>My Orders</h1></div>
<?php if ($payments): ?>
<div class="panel" style="margin-bottom:18px;"><h3>QR payments</h3><div class="table-scroll"><table>
  <thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= htmlspecialchars($p['Reference_No']) ?></td>
      <td><?= peso($p['Amount']) ?></td>
      <td><span class="badge <?= $p['Status'] === 'Pending' ? 'warn' : 'bad' ?>"><?= htmlspecialchars($p['Status']) ?></span></td>
      <td><?= htmlspecialchars($p['Created_At']) ?></td>
      <td><?php if ($p['Status'] === 'Pending'): ?><a class="btn btn-ghost btn-sm" href="/reseller/pay.php?ref=<?= urlencode($p['Reference_No']) ?>">Pay now</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
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
