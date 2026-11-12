<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
require_role('Reseller');

$user = current_user();
$ref  = $_GET['ref'] ?? '';
$payment = get_payment($pdo, $ref);

// A reseller can only open their own payments.
if (!$payment || $payment['User_ID'] !== $user['User_ID']) {
    http_response_code(404);
    die('Payment not found.');
}

$cfg = payment_config();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        cancel_payment($pdo, $ref, $user['User_ID']);
    } elseif ($action === 'simulate' && $cfg['mode'] === 'demo') {
        try { complete_payment($pdo, $ref, 'DEMO-' . strtoupper(bin2hex(random_bytes(3)))); }
        catch (RuntimeException $e) { $error = $e->getMessage(); }
    }
    header('Location: /reseller/pay.php?ref=' . urlencode($ref) . ($error ? '&err=' . urlencode($error) : ''));
    exit;
}
if (isset($_GET['err'])) $error = $_GET['err'];

$payment = get_payment($pdo, $ref);
$status  = refresh_payment_status($pdo, $payment);
$cart    = json_decode($payment['Cart_Snapshot'], true) ?: [];
$ps = $pdo->prepare("SELECT Product_Name, Unit_Price FROM products WHERE Product_ID = ?");
$lines = [];
foreach ($cart as $pid => $qty) {
    $ps->execute([$pid]);
    if ($p = $ps->fetch()) $lines[] = ['name' => $p['Product_Name'], 'qty' => (int)$qty, 'price' => $p['Unit_Price']];
}

$active = 'orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>QR Payment</h1></div>
<?php if ($error): ?><div class="alert-box alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="panel pay-box">
    <?php if ($status === 'Pending'): ?>
      <p class="pay-hint">Scan with your e-wallet or bank app to pay</p>
      <div id="qr" class="qr-frame"></div>
      <div class="pay-amount"><?= peso($payment['Amount']) ?></div>
      <div class="pay-ref">Reference <b><?= htmlspecialchars($payment['Reference_No']) ?></b></div>
      <div class="pay-timer">Expires in <b id="timer">--:--</b></div>
      <div id="state" class="badge warn" style="margin-top:10px;">Waiting for payment…</div>

      <div style="margin-top:16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <?php if ($cfg['mode'] === 'demo'): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="simulate">
          <button class="btn btn-basil" type="submit">Simulate payment (demo)</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Cancel this payment?');">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="cancel">
          <button class="btn btn-ghost" type="submit">Cancel</button>
        </form>
      </div>


    <?php elseif ($status === 'Paid'): ?>
      <div class="pay-result ok">✔</div>
      <h2>Payment received</h2>
      <p>Your order is recorded. Reference <b><?= htmlspecialchars($payment['Reference_No']) ?></b>.</p>
      <a class="btn btn-primary" href="/reseller/orders.php">View my orders</a>
    <?php elseif ($status === 'Failed'): ?>
      <div class="pay-result bad">!</div>
      <h2>Payment could not be completed</h2>
      <p>Stock ran out before the payment was confirmed. Please contact Food4Soul with reference <b><?= htmlspecialchars($payment['Reference_No']) ?></b> for a refund.</p>
      <a class="btn btn-ghost" href="/reseller/catalog.php">Back to catalog</a>
    <?php else: ?>
      <div class="pay-result bad">✕</div>
      <h2>Payment <?= strtolower(htmlspecialchars($status)) ?></h2>
      <p>No payment was taken and your order was not placed.</p>
      <a class="btn btn-primary" href="/reseller/catalog.php">Back to catalog</a>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Order summary</h3>
    <table>
      <?php foreach ($lines as $l): ?>
        <tr><td><?= htmlspecialchars($l['name']) ?></td><td>×<?= $l['qty'] ?></td><td style="text-align:right;"><?= peso($l['price'] * $l['qty']) ?></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="2"><strong>Total</strong></td><td style="text-align:right;"><strong><?= peso($payment['Amount']) ?></strong></td></tr>
    </table>
  </div>
</div>

<?php if ($status === 'Pending'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr'), {
  text: <?= json_encode($payment['QR_Payload'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  width: 220, height: 220, colorDark: '#1E2420', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M
});

// Countdown to expiry (server time is the source of truth, this is just the display)
let left = <?= max(0, strtotime($payment['Expires_At']) - time()) ?>;
const timerEl = document.getElementById('timer');
function tick() {
  const m = String(Math.floor(left / 60)).padStart(2, '0'), s = String(left % 60).padStart(2, '0');
  timerEl.textContent = m + ':' + s;
  if (left <= 0) { location.reload(); return; }
  left--;
}
tick(); setInterval(tick, 1000);

// Poll for confirmation (a bank/gateway webhook or the demo button flips the status)
setInterval(() => {
  fetch('/api/payment_status.php?ref=<?= urlencode($payment['Reference_No']) ?>').then(r => r.json()).then(d => {
    if (d.status && d.status !== 'Pending') location.reload();
  });
}, 3000);
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
