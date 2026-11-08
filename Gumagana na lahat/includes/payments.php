<?php
/**
 * QR payment helpers. See config/payment.php for demo vs live mode.
 *
 * Lifecycle:  create_qr_payment()  -> Pending
 *             complete_payment()   -> Paid (sale recorded, stock deducted)
 *                                  -> Expired / Failed / Cancelled otherwise
 */

function payment_config(): array {
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/../config/payment.php';
}

/** Creates a Pending payment from a cart and returns its row. Stock is validated now and again on payment. */
function create_qr_payment(PDO $pdo, string $userId, array $cart): array {
    foreach ($cart as $pid => $qty) {
        $q = $pdo->prepare("SELECT Product_Name, Current_Stock_Quantity FROM products WHERE Product_ID = ?");
        $q->execute([$pid]);
        $p = $q->fetch();
        if ($p && $p['Current_Stock_Quantity'] < $qty) {
            throw new RuntimeException('Not enough stock for ' . $p['Product_Name'] . ' (only ' . $p['Current_Stock_Quantity'] . ' left).');
        }
    }
    $amount = cart_total($pdo, $cart);
    if ($amount <= 0) throw new RuntimeException('Your order is empty.');

    $cfg = payment_config();
    $ref = 'F4S-' . strtoupper(bin2hex(random_bytes(4)));
    $now = date('Y-m-d H:i:s');
    $exp = date('Y-m-d H:i:s', time() + $cfg['expiry_minutes'] * 60);
    $id  = next_id($pdo, 'payments', 'Payment_ID', 'PY');

    // Demo QR content: a link to the pay page. In live mode, replace with the gateway's QR string.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $payload = "$scheme://$host/reseller/pay.php?ref=$ref";

    $pdo->prepare("INSERT INTO payments (Payment_ID, Reference_No, User_ID, Amount, Method, Status, Cart_Snapshot, QR_Payload, Created_At, Expires_At)
                   VALUES (?,?,?,?,'QR','Pending',?,?,?,?)")
        ->execute([$id, $ref, $userId, $amount, json_encode($cart), $payload, $now, $exp]);

    return get_payment($pdo, $ref);
}

function get_payment(PDO $pdo, string $ref): ?array {
    $st = $pdo->prepare("SELECT * FROM payments WHERE Reference_No = ?");
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

/** Flags a Pending payment as Expired if its time ran out. Returns the (possibly updated) status. */
function refresh_payment_status(PDO $pdo, array $payment): string {
    if ($payment['Status'] === 'Pending' && strtotime($payment['Expires_At']) < time()) {
        $pdo->prepare("UPDATE payments SET Status = 'Expired' WHERE Payment_ID = ? AND Status = 'Pending'")
            ->execute([$payment['Payment_ID']]);
        return 'Expired';
    }
    return $payment['Status'];
}

/**
 * Marks a payment Paid and records the sale. Safe to call twice (idempotent): only the first
 * call on a Pending, unexpired payment does anything. Returns the final status.
 * Throws RuntimeException if stock ran out in the meantime (payment is then marked Failed).
 */
function complete_payment(PDO $pdo, string $ref, ?string $providerRef = null): string {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM payments WHERE Reference_No = ? FOR UPDATE");
        $st->execute([$ref]);
        $pay = $st->fetch();
        if (!$pay) { $pdo->rollBack(); throw new RuntimeException('Unknown payment reference.'); }

        if ($pay['Status'] !== 'Pending') { $pdo->commit(); return $pay['Status']; }   // already handled
        if (strtotime($pay['Expires_At']) < time()) {
            $pdo->prepare("UPDATE payments SET Status = 'Expired' WHERE Payment_ID = ?")->execute([$pay['Payment_ID']]);
            $pdo->commit();
            return 'Expired';
        }

        place_order($pdo, $pay['User_ID'], json_decode($pay['Cart_Snapshot'], true) ?: [], 'QR');
        $pdo->prepare("UPDATE payments SET Status = 'Paid', Paid_At = ?, Provider_Ref = ? WHERE Payment_ID = ?")
            ->execute([date('Y-m-d H:i:s'), $providerRef, $pay['Payment_ID']]);
        $pdo->commit();
        return 'Paid';
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Money was received but the goods are gone: flag for a manual refund.
        $pdo->prepare("UPDATE payments SET Status = 'Failed' WHERE Reference_No = ? AND Status = 'Pending'")->execute([$ref]);
        throw $e;
    }
}

function cancel_payment(PDO $pdo, string $ref, string $userId): void {
    $pdo->prepare("UPDATE payments SET Status = 'Cancelled' WHERE Reference_No = ? AND User_ID = ? AND Status = 'Pending'")
        ->execute([$ref, $userId]);
}
