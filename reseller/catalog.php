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
        $exists = $pdo->prepare("SELECT 1 FROM products WHERE Product_ID = ?");
        $exists->execute([$pid]);
        if ($exists->fetchColumn()) {
            $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + 1;
            $message = 'Added to your order.';
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
$sql = "SELECT p.*, i.Reorder_Level, s.Supplier_Name
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
<div class="topbar"><h1>Product Catalog</h1></div>
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

<div class="product-grid">
  <?php foreach ($products as $p): $low = $p['Current_Stock_Quantity'] <= $p['Reorder_Level']; ?>
    <div class="product-card">
      <div class="thumb"><?= category_icon($p['Category']) ?></div>
      <div class="name"><?= htmlspecialchars($p['Product_Name']) ?></div>
      <div class="cat"><?= htmlspecialchars($p['Category']) ?> · <?= htmlspecialchars($p['Supplier_Name'] ?? '—') ?></div>
      <div class="price"><?= peso($p['Unit_Price']) ?></div>
      <div class="stock-note" style="color:<?= $low ? 'var(--paprika)' : 'var(--basil)' ?>;"><?= $low ? 'Low stock' : 'In stock' ?> · <?= (int)$p['Current_Stock_Quantity'] ?> left</div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="product_id" value="<?= htmlspecialchars($p['Product_ID']) ?>">
        <button class="btn btn-primary" style="width:100%;" type="submit">Add to order</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$products): ?><p style="color:var(--slate);">No products found. Try a different search or category.</p><?php endif; ?>
</div>

<div class="panel" style="margin-top:24px;">
  <h3>Your order (<?= (int)$cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?>)</h3>
  <?php if (!$cartLines): ?>
    <p style="color:var(--slate);font-size:13.5px;margin:0;">Your order is empty. Add products above.</p>
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
        <button class="btn btn-basil" type="submit">Place order</button>
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
