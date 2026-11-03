<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    csrf_check();
    update_stock($pdo, $_POST['product_id'], max(0, (int)$_POST['new_stock']));
    $message = 'Inventory updated.';
}

$search = trim($_GET['q'] ?? '');
$cat    = trim($_GET['cat'] ?? '');
$sql = "SELECT p.*, i.Reorder_Level, s.Supplier_Name
        FROM products p
        LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
        LEFT JOIN suppliers s ON s.Supplier_ID = p.Supplier_ID
        WHERE p.Product_Name LIKE ?" . ($cat !== '' ? " AND p.Category = ?" : "") . "
        ORDER BY p.Product_Name";
$params = ['%' . $search . '%'];
if ($cat !== '') $params[] = $cat;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$categories = $pdo->query("SELECT DISTINCT Category FROM products ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);

$active = 'inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Inventory</h1></div>
<?php if ($message): ?><div class="alert-box alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<form method="get" class="toolbar">
  <input class="search-input" type="text" name="q" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
  <select class="search-input" name="cat" style="max-width:180px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?><option <?= $c === $cat ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-ghost" type="submit">Filter</button>
</form>

<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Reorder level</th><th>Status</th><th>Supplier</th><th>Adjust</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): $low = $p['Current_Stock_Quantity'] <= $p['Reorder_Level']; ?>
        <tr>
          <td><strong><?= htmlspecialchars($p['Product_Name']) ?></strong></td>
          <td><?= htmlspecialchars($p['Category']) ?></td>
          <td><?= peso($p['Unit_Price']) ?></td>
          <td><?= (int)$p['Current_Stock_Quantity'] ?></td>
          <td><?= (int)$p['Reorder_Level'] ?></td>
          <td><span class="badge <?= $low ? 'bad' : 'ok' ?>"><?= $low ? 'Low Stock' : 'In Stock' ?></span></td>
          <td><?= htmlspecialchars($p['Supplier_Name'] ?? '—') ?></td>
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
      <?php if (!$products): ?><tr><td colspan="8">No products found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
