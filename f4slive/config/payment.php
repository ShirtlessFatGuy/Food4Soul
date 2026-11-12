<?php
/**
 * QR payment settings.
 *
 * mode = 'demo'  -> the pay page shows a "Simulate payment" button that stands in for the
 *                   bank / e-wallet confirming. Use this for the defense without a gateway.
 * mode = 'live'  -> no simulate button. A payment gateway (e.g. PayMongo, Xendit) must call
 *                   api/payment_webhook.php with a valid signature to mark a payment Paid.
 */
require_once __DIR__ . '/env.php';

return [
    'mode'           => 'demo',
    'expiry_minutes' => 15,
    'merchant_name'  => 'Food4Soul',
    // Shared secret used to sign webhook calls (HMAC-SHA256). Set PAYMENT_WEBHOOK_SECRET in the
    // environment or config/secrets.php. The webhook refuses to run while this is empty or weak.
    'webhook_secret' => secret('PAYMENT_WEBHOOK_SECRET'),
];
