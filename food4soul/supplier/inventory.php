<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Supplier');

$supplierId = current_user()['Supplier_ID'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Ownership check: the product must belong to this supplier.
    $check = $pdo->prepare("SELECT 1 FROM products WHERE Product_ID = ? AND Supplier_ID = ?");
    $check->execute([$_POST['product_id'] ?? '', $supplierId]);
    if ($check->fetchColumn()) {
        update_stock($pdo, $_POST['product_id'], max(0, (int)$_POST['new_stock']));
        $message = 'Inventory updated.';
    }
}

$stmt = $pdo->prepare("SELECT p.*, i.Reorder_Level FROM products p
                        LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
                        WHERE p.Supplier_ID = ? ORDER BY p.Product_Name");
$stmt->execute([$supplierId]);
$products = $stmt->fetchAll();

$active = 'inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Update Inventory</h1></div>
<?php if ($message): ?><div class="alert-box alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Product</th><th>Category</th><th>Current stock</th><th>Reorder level</th><th>Status</th><th>Update</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): $low = $p['Current_Stock_Quantity'] <= $p['Reorder_Level']; ?>
        <tr>
          <td><strong><?= htmlspecialchars($p['Product_Name']) ?></strong></td>
          <td><?= htmlspecialchars($p['Category']) ?></td>
          <td><?= (int)$p['Current_Stock_Quantity'] ?></td>
          <td><?= (int)$p['Reorder_Level'] ?></td>
          <td><span class="badge <?= $low ? 'bad' : 'ok' ?>"><?= $low ? 'Low Stock' : 'In Stock' ?></span></td>
          <td>
            <form method="post" style="display:flex;gap:6px;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="product_id" value="<?= htmlspecialchars($p['Product_ID']) ?>">
              <input class="inline-input" type="number" name="new_stock" value="<?= (int)$p['Current_Stock_Quantity'] ?>" min="0">
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="6">No products linked to your account.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
