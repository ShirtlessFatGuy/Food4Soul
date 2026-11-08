<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
require_role('Reseller');

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];   // Product_ID => qty
$user = current_user();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $pid = $_POST['product_id'] ?? '';
        $avail = $pdo->prepare("SELECT Current_Stock_Quantity FROM products WHERE Product_ID = ?");
        $avail->execute([$pid]);
        $have = $avail->fetchColumn();
        if ($have !== false) {
            $want = max(1, (int)($_POST['qty'] ?? 1));
            $inCart = (int)($_SESSION['cart'][$pid] ?? 0);
            $room = (int)$have - $inCart;
            if ($room <= 0) {
                $message = 'No more stock available for that product.';
            } else {
                $_SESSION['cart'][$pid] = $inCart + min($want, $room);
                $message = 'Added to your order.' . ($want > $room ? " Only $room more were available." : '');
            }
        }
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $message = 'Order cleared.';
    }

    if ($action === 'checkout' && $_SESSION['cart']) {
        $method = ($_POST['payment'] ?? 'Cash') === 'QR' ? 'QR' : 'Cash';
        try {
            if ($method === 'QR') {
                // Sale is NOT recorded yet: create a Pending payment, show the QR, record the sale when paid.
                $payment = create_qr_payment($pdo, $user['User_ID'], $_SESSION['cart']);
                $_SESSION['cart'] = [];
                header('Location: /reseller/pay.php?ref=' . urlencode($payment['Reference_No']));
                exit;
            }
            place_order($pdo, $user['User_ID'], $_SESSION['cart'], 'Cash');
            $_SESSION['cart'] = [];
            $message = 'Order placed! Check "My Orders" for details.';
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        } catch (Exception $e) {
            $message = 'Something went wrong placing your order. Please try again.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$cat    = trim($_GET['cat'] ?? '');
$sql = "SELECT p.*, COALESCE(i.Reorder_Level, 0) AS Reorder_Level, s.Supplier_Name
        FROM products p
        LEFT JOIN inventory i ON i.Product_ID = p.Product_ID
        LEFT JOIN suppliers s ON s.Supplier_ID = p.Supplier_ID
        WHERE p.Product_Name LIKE ?" . ($cat !== '' ? " AND p.Category = ?" : "") . " ORDER BY p.Product_Name";
$params = ['%' . $search . '%'];
if ($cat !== '') $params[] = $cat;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$categories = $pdo->query("SELECT DISTINCT Category FROM products ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);

// Cart summary
$cartLines = []; $cartTotal = 0; $cartCount = 0;
$ps = $pdo->prepare("SELECT * FROM products WHERE Product_ID = ?");
foreach ($_SESSION['cart'] as $pid => $qty) {
    $ps->execute([$pid]);
    if ($pr = $ps->fetch()) {
        $cartLines[] = ['p' => $pr, 'qty' => $qty];
        $cartTotal += $pr['Unit_Price'] * $qty;
        $cartCount += $qty;
    }
}

$active = 'catalog';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Stock Catalog</h1></div>
<p class="page-sub">What is available right now. Quantities update live as stock is received or ordered.</p>
<?php if ($message): ?>
  <div class="alert-box <?= str_starts_with($message, 'Order placed') || str_starts_with($message, 'Added') || str_starts_with($message, 'Order cleared') ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="get" class="toolbar">
  <input class="search-input" type="text" name="q" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
  <select class="search-input" name="cat" style="max-width:180px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?><option <?= $c === $cat ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-ghost" type="submit">Filter</button>
</form>

<div class="panel" data-live="catalog"><div class="table-scroll">
  <table>
    <thead><tr><th>Product</th><th>Category</th><th>Available</th><th>Availability</th><th class="num-r">Unit price</th><th class="num-r">Order qty</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p):
        $st = stock_status($p['Current_Stock_Quantity'], $p['Reorder_Level']); $pid = htmlspecialchars($p['Product_ID']);
        $room = (int)$p['Current_Stock_Quantity'] - (int)($_SESSION['cart'][$p['Product_ID']] ?? 0); ?>
        <tr>
          <td><div class="prod-cell"><span class="ic"><?= category_icon($p['Category']) ?></span><div><strong><?= htmlspecialchars($p['Product_Name']) ?></strong><span class="sku"><?= $pid ?> · <?= htmlspecialchars($p['Supplier_Name'] ?? '—') ?></span></div></div></td>
          <td><?= htmlspecialchars($p['Category']) ?></td>
          <td><div class="qtycell"><span class="n" data-key="stock-<?= $pid ?>"><?= (int)$p['Current_Stock_Quantity'] ?></span><?= stock_bar($p['Current_Stock_Quantity'], $p['Reorder_Level'], false) ?></div></td>
          <td><span class="badge <?= badge_class($st === 'Low Stock' ? 'Pending' : $st) ?>" data-key="st-<?= $pid ?>"><?= $st === 'Low Stock' ? 'Limited stock' : $st ?></span></td>
          <td class="num-r"><?= peso($p['Unit_Price']) ?></td>
          <td>
            <?php if ($room > 0): ?>
            <form method="post" class="add-form">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?= $pid ?>">
              <input class="inline-input" type="number" name="qty" value="1" min="1" max="<?= $room ?>" aria-label="Quantity of <?= htmlspecialchars($p['Product_Name']) ?>">
              <button class="btn btn-primary btn-sm" type="submit">Add</button>
            </form>
            <?php else: ?>
              <div class="add-form"><span class="hint"><?= (int)$p['Current_Stock_Quantity'] > 0 ? 'All in your order' : 'Unavailable' ?></span></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="6">No products found. Try a different search or category.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<div class="panel" style="margin-top:24px;">
  <h3>Order list (<?= (int)$cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?>)</h3>
  <?php if (!$cartLines): ?>
    <p style="color:var(--slate);font-size:13.5px;margin:0;">Nothing in your order list yet. Add products above.</p>
  <?php else: ?>
    <table>
      <?php foreach ($cartLines as $l): ?>
        <tr><td><?= htmlspecialchars($l['p']['Product_Name']) ?></td><td>×<?= (int)$l['qty'] ?></td><td style="text-align:right;"><?= peso($l['p']['Unit_Price'] * $l['qty']) ?></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="2"><strong>Total</strong></td><td style="text-align:right;"><strong><?= peso($cartTotal) ?></strong></td></tr>
    </table>
    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <form method="post" style="display:flex;gap:10px;align-items:center;">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="checkout">
        <select class="search-input" name="payment" style="flex:none;">
          <option value="Cash">Cash on delivery</option>
          <option value="QR">QR payment (scan with e-wallet / bank app)</option>
        </select>
        <button class="btn btn-basil" type="submit">Confirm order</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="clear">
        <button class="btn btn-ghost" type="submit">Clear</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
