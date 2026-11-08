<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

// Flash message survives the redirect that follows a successful POST (stops re-submit on refresh).
$message = $_SESSION['flash']['msg'] ?? '';
$msgOk   = $_SESSION['flash']['ok'] ?? true;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $lines = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
            $id = create_restock_order($pdo, $_POST['supplier_id'] ?? '', $lines, $_POST['expected'] ?? null);
            $_SESSION['flash'] = ['ok' => true, 'msg' => "Restock order $id sent to the supplier. It will show up on their Assigned Orders page right away."];
        } elseif ($action === 'cancel') {
            $_SESSION['flash'] = ['ok' => true, 'msg' => set_order_status($pdo, $_POST['order_id'] ?? '', null, 'Cancelled')];
        }
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => $e->getMessage()];
    }
    header('Location: /admin/orders.php');
    exit;
}

$orders = $pdo->query("SELECT o.*, s.Supplier_Name FROM orders o LEFT JOIN suppliers s ON s.Supplier_ID = o.Supplier_ID ORDER BY o.Order_Date DESC, o.Order_ID DESC")->fetchAll();
$itemsStmt = $pdo->prepare("SELECT od.Quantity, p.Product_Name FROM order_details od JOIN products p ON p.Product_ID = od.Product_ID WHERE od.Order_ID = ?");

$suppliers = $pdo->query("SELECT Supplier_ID, Supplier_Name FROM suppliers ORDER BY Supplier_Name")->fetchAll();
$products  = $pdo->query("SELECT p.Product_ID, p.Product_Name, p.Supplier_ID, p.Current_Stock_Quantity, i.Reorder_Level
                            FROM products p LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
                           ORDER BY p.Product_Name")->fetchAll();

// "Reorder" links from Inventory / Alerts pre-select a product.
$preselect = $_GET['restock'] ?? '';
$preSupplier = '';
foreach ($products as $p) if ($p['Product_ID'] === $preselect) $preSupplier = $p['Supplier_ID'];

$active = 'orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Orders</h1></div>
<?php if ($message): ?><div class="alert-box <?= $msgOk ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:18px;">
  <h3>New restock order</h3>
  <form method="post" id="restockForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create">
    <div class="toolbar">
      <select class="search-input" name="supplier_id" id="rsSupplier" style="max-width:240px;" required>
        <option value="">Choose a supplier…</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= htmlspecialchars($s['Supplier_ID']) ?>" <?= $s['Supplier_ID'] === $preSupplier ? 'selected' : '' ?>><?= htmlspecialchars($s['Supplier_Name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="hint" for="rsDate">Expected delivery</label>
      <input class="search-input" type="date" id="rsDate" name="expected" style="max-width:170px;"
             value="<?= date('Y-m-d', strtotime('+3 days')) ?>" min="<?= date('Y-m-d') ?>">
      <button type="button" class="btn btn-ghost btn-sm" id="rsFillLow">Fill low-stock items</button>
    </div>
    <p class="hint" id="rsEmpty" style="margin:0 0 8px;">Pick a supplier to see the products they provide.</p>
    <div class="table-scroll"><table class="restock-grid" id="rsTable" style="display:none;">
      <thead><tr><th>Product</th><th>In stock</th><th>Reorder level</th><th style="width:130px;">Order qty</th></tr></thead>
      <tbody>
        <?php foreach ($products as $p): $low = $p['Current_Stock_Quantity'] <= $p['Reorder_Level'];
              $sugg = max(1, (int)$p['Reorder_Level'] * 2 - (int)$p['Current_Stock_Quantity']); ?>
          <tr data-supplier="<?= htmlspecialchars($p['Supplier_ID']) ?>" data-low="<?= $low ? 1 : 0 ?>" data-sugg="<?= $sugg ?>" class="<?= $low ? 'suggest' : '' ?>" hidden>
            <td><strong><?= htmlspecialchars($p['Product_Name']) ?></strong><?= $low ? ' <span class="badge bad">Low stock</span>' : '' ?></td>
            <td><?= (int)$p['Current_Stock_Quantity'] ?></td>
            <td><?= (int)$p['Reorder_Level'] ?></td>
            <td><input class="inline-input" type="number" min="0" max="100000" name="qty[<?= htmlspecialchars($p['Product_ID']) ?>]" data-pid="<?= htmlspecialchars($p['Product_ID']) ?>" placeholder="0" disabled></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="margin-top:12px;"><button class="btn btn-basil" type="submit">Place restock order</button></div>
  </form>
</div>

<div class="panel" data-live="orders"><div class="table-scroll">
  <table>
    <thead><tr><th>Order ID</th><th>Supplier</th><th>Items</th><th>Order date</th><th>Expected delivery</th><th>Status</th><th></th></tr></thead>
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
          <td><span class="badge <?= badge_class($o['Status']) ?>" data-key="ostatus-<?= htmlspecialchars($o['Order_ID']) ?>"><?= htmlspecialchars($o['Status']) ?></span></td>
          <td>
            <?php if (in_array($o['Status'], ['Pending', 'Processing'], true)): ?>
              <form method="post" onsubmit="return confirm('Cancel order <?= htmlspecialchars($o['Order_ID']) ?>?');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['Order_ID']) ?>">
                <button class="btn btn-ghost btn-sm btn-danger" type="submit">Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="7">No orders yet. Place one above.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<script>
(function () {
  var sel = document.getElementById('rsSupplier'), tbl = document.getElementById('rsTable'),
      empty = document.getElementById('rsEmpty'), rows = tbl.querySelectorAll('tbody tr');
  function sync() {
    var sid = sel.value, any = false;
    rows.forEach(function (r) {
      var show = r.dataset.supplier === sid;
      r.hidden = !show;
      var inp = r.querySelector('input');
      inp.disabled = !show;
      if (!show) inp.value = '';
      any = any || show;
    });
    tbl.style.display = any ? '' : 'none';
    empty.style.display = any ? 'none' : '';
    empty.textContent = sid ? 'This supplier has no products yet.' : 'Pick a supplier to see the products they provide.';
  }
  sel.addEventListener('change', sync);
  document.getElementById('rsFillLow').addEventListener('click', function () {
    if (!sel.value) { sel.focus(); return; }
    rows.forEach(function (r) {
      if (!r.hidden && r.dataset.low === '1') r.querySelector('input').value = r.dataset.sugg;
    });
  });
  sync();
  var pre = <?= json_encode($preselect) ?>;
  if (pre) {
    var inp = document.querySelector('input[data-pid="' + pre + '"]');
    if (inp && !inp.disabled) { inp.value = inp.closest('tr').dataset.sugg; inp.focus(); }
  }
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
