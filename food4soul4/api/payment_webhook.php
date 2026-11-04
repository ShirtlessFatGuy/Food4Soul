<?php
/**
 * Payment confirmation webhook, for a real gateway (PayMongo, Xendit, ...) to call.
 *
 *   POST /api/payment_webhook.php
 *   Header:  X-Signature: hex(HMAC_SHA256(raw request body, webhook_secret))
 *   Body:    {"reference":"F4S-1A2B3C4D","status":"paid","provider_ref":"gw_123"}
 *
 * No login session: the HMAC signature is the authentication. Adapt the body/header
 * parsing to your gateway's actual format (each gateway signs differently).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $raw, payment_config()['webhook_secret']);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    echo json_encode(['error' => 'Bad signature']);
    exit;
}

$body = json_decode($raw, true);
if (!is_array($body) || empty($body['reference'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid body']);
    exit;
}

if (($body['status'] ?? '') !== 'paid') {
    echo json_encode(['ok' => true, 'ignored' => true]);   // acknowledge other events
    exit;
}

try {
    $status = complete_payment($pdo, (string)$body['reference'], $body['provider_ref'] ?? null);
    echo json_encode(['ok' => true, 'status' => $status]);
} catch (RuntimeException $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
