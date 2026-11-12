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
        $message = 'Stock count updated.';
    }
}

$stmt = $pdo->prepare("SELECT p.*, COALESCE(i.Reorder_Level, 0) AS Reorder_Level, i.Last_Updated_Date FROM products p
                        LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
                        WHERE p.Supplier_ID = ? ORDER BY p.Product_Name");
$stmt->execute([$supplierId]);
$products = $stmt->fetchAll();

$active = 'inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Update Stock</h1></div>
<?php if ($message): ?><div class="alert-box alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="panel" data-live="inventory"><div class="table-scroll">
  <table>
    <thead><tr><th>Product / SKU</th><th>Category</th><th>On hand</th><th class="num-r">Reorder at</th><th>Status</th><th>Last updated</th><th>Update count</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): $st = stock_status($p['Current_Stock_Quantity'], $p['Reorder_Level']); ?>
        <tr>
          <td><strong><?= htmlspecialchars($p['Product_Name']) ?></strong><span class="sku"><?= htmlspecialchars($p['Product_ID']) ?></span></td>
          <td><?= htmlspecialchars($p['Category']) ?></td>
          <td><div class="qtycell"><span class="n" data-key="stock-<?= htmlspecialchars($p['Product_ID']) ?>"><?= (int)$p['Current_Stock_Quantity'] ?></span><?= stock_bar($p['Current_Stock_Quantity'], $p['Reorder_Level']) ?></div></td>
          <td class="num-r"><?= (int)$p['Reorder_Level'] ?></td>
          <td><span class="badge <?= badge_class($st) ?>" data-key="st-<?= htmlspecialchars($p['Product_ID']) ?>"><?= $st ?></span></td>
          <td><?= htmlspecialchars($p['Last_Updated_Date'] ?? '—') ?></td>
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
      <?php if (!$products): ?><tr><td colspan="7">No products linked to your account.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
