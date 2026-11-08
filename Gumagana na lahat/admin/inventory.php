<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    csrf_check();
    update_stock($pdo, $_POST['product_id'], max(0, (int)$_POST['new_stock']));
    $message = 'Stock count updated.';
}

$search = trim($_GET['q'] ?? '');
$cat    = trim($_GET['cat'] ?? '');
$status = in_array($_GET['status'] ?? '', ['in', 'low', 'out'], true) ? $_GET['status'] : '';

$sql = "SELECT p.*, COALESCE(i.Reorder_Level, 0) AS Reorder_Level, i.Last_Updated_Date, s.Supplier_Name
        FROM products p
        LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
        LEFT JOIN suppliers s ON s.Supplier_ID = p.Supplier_ID
        WHERE (p.Product_Name LIKE ? OR p.Product_ID LIKE ?)" . ($cat !== '' ? " AND p.Category = ?" : "");
$params = ['%' . $search . '%', '%' . $search . '%'];
if ($cat !== '') $params[] = $cat;
if ($status === 'out')      $sql .= " AND p.Current_Stock_Quantity <= 0";
elseif ($status === 'low')  $sql .= " AND p.Current_Stock_Quantity > 0 AND p.Current_Stock_Quantity <= COALESCE(i.Reorder_Level, 0)";
elseif ($status === 'in')   $sql .= " AND p.Current_Stock_Quantity > COALESCE(i.Reorder_Level, 0)";
$sql .= " ORDER BY (p.Current_Stock_Quantity / GREATEST(COALESCE(i.Reorder_Level, 0), 1)), p.Product_Name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$categories = $pdo->query("SELECT DISTINCT Category FROM products ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);

// Whole-warehouse counts for the summary strip (unaffected by the filters above)
$all = $pdo->query("SELECT p.Current_Stock_Quantity AS q, COALESCE(i.Reorder_Level,0) AS r, p.Unit_Price AS price
                      FROM products p LEFT JOIN inventory i ON i.Product_ID = p.Product_ID")->fetchAll();
$n = ['in' => 0, 'low' => 0, 'out' => 0]; $units = 0; $value = 0;
foreach ($all as $a) {
    $st = stock_status($a['q'], $a['r']);
    $n[$st === 'In Stock' ? 'in' : ($st === 'Low Stock' ? 'low' : 'out')]++;
    $units += (int)$a['q']; $value += $a['q'] * $a['price'];
}

$qs = function (array $over) use ($search, $cat, $status) {
    return '?' . http_build_query(array_filter(array_merge(['q' => $search, 'cat' => $cat, 'status' => $status], $over), fn($v) => $v !== ''));
};

$active = 'inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar">
  <h1>Inventory</h1>
  <div class="actions"><a class="btn btn-ghost" href="/admin/movements.php">Stock movements</a><a class="btn btn-basil" href="/admin/orders.php">+ New restock order</a></div>
</div>
<?php if ($message): ?><div class="alert-box alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="kpi-row" data-live="inv-kpis">
  <div class="kpi"><div class="label">Products</div><div class="num"><?= count($all) ?></div><div class="delta up"><?= number_format($units) ?> units on hand</div></div>
  <div class="kpi"><div class="label">In stock</div><div class="num"><?= $n['in'] ?></div><div class="delta up">above reorder level</div></div>
  <div class="kpi <?= $n['low'] ? 'alertish' : '' ?>"><div class="label">Low stock</div><div class="num"><?= $n['low'] ?></div><div class="delta <?= $n['low'] ? 'warn' : 'up' ?>">at/below reorder level</div></div>
  <div class="kpi <?= $n['out'] ? 'alertish' : '' ?>"><div class="label">Out of stock</div><div class="num"><?= $n['out'] ?></div><div class="delta <?= $n['out'] ? 'warn' : 'up' ?>">zero on hand</div></div>
  <div class="kpi"><div class="label">Stock value</div><div class="num"><?= peso($value) ?></div><div class="delta up">at unit price</div></div>
</div>

<div class="tabs">
  <a href="<?= $qs(['status' => '']) ?>" class="<?= $status === '' ? 'active' : '' ?>">All (<?= count($all) ?>)</a>
  <a href="<?= $qs(['status' => 'in']) ?>" class="<?= $status === 'in' ? 'active' : '' ?>">In stock (<?= $n['in'] ?>)</a>
  <a href="<?= $qs(['status' => 'low']) ?>" class="<?= $status === 'low' ? 'active' : '' ?>">Low (<?= $n['low'] ?>)</a>
  <a href="<?= $qs(['status' => 'out']) ?>" class="<?= $status === 'out' ? 'active' : '' ?>">Out (<?= $n['out'] ?>)</a>
</div>

<form method="get" class="toolbar">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
  <input class="search-input" type="text" name="q" placeholder="Search by product name or SKU…" value="<?= htmlspecialchars($search) ?>">
  <select class="search-input" name="cat" style="max-width:180px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?><option <?= $c === $cat ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-ghost" type="submit">Filter</button>
</form>

<div class="panel" data-live="inventory">
  <div class="legend-note"><span><i class="tick"></i>Reorder level</span><span>Sorted by urgency: lowest stock relative to its reorder level first</span></div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Product / SKU</th><th>Category</th><th>Supplier</th><th>On hand</th><th class="num-r">Reorder at</th><th>Status</th><th class="num-r">Stock value</th><th>Last updated</th><th>Update count</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): $st = stock_status($p['Current_Stock_Quantity'], $p['Reorder_Level']); $pid = htmlspecialchars($p['Product_ID']); ?>
        <tr>
          <td style="min-width:150px;"><strong><?= htmlspecialchars($p['Product_Name']) ?></strong><span class="sku"><?= $pid ?></span></td>
          <td><?= htmlspecialchars($p['Category']) ?></td>
          <td class="nowrap"><?= htmlspecialchars($p['Supplier_Name'] ?? '—') ?></td>
          <td><div class="qtycell"><span class="n" data-key="stock-<?= $pid ?>"><?= (int)$p['Current_Stock_Quantity'] ?></span><?= stock_bar($p['Current_Stock_Quantity'], $p['Reorder_Level']) ?></div></td>
          <td class="num-r"><?= (int)$p['Reorder_Level'] ?></td>
          <td style="white-space:nowrap;"><span class="badge <?= badge_class($st) ?>" data-key="st-<?= $pid ?>"><?= $st ?></span><?php if ($st !== 'In Stock'): ?> <a class="btn btn-ghost btn-sm" href="/admin/orders.php?restock=<?= urlencode($p['Product_ID']) ?>">Reorder</a><?php endif; ?></td>
          <td class="num-r" title="<?= peso($p['Unit_Price']) ?> per unit"><?= peso($p['Unit_Price'] * $p['Current_Stock_Quantity']) ?></td>
          <td class="nowrap"><?= htmlspecialchars($p['Last_Updated_Date'] ?? '—') ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="product_id" value="<?= $pid ?>">
              <input class="inline-input" type="number" name="new_stock" value="<?= (int)$p['Current_Stock_Quantity'] ?>" min="0" aria-label="New stock count for <?= htmlspecialchars($p['Product_Name']) ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="10">No products match these filters.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
