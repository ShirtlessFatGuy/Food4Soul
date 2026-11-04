<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
require_role('Reseller');
header('Content-Type: application/json');

$payment = get_payment($pdo, $_GET['ref'] ?? '');
// Only the owner can poll a payment; anyone else gets the same answer as a missing one.
if (!$payment || $payment['User_ID'] !== current_user()['User_ID']) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}
echo json_encode(['status' => refresh_payment_status($pdo, $payment)]);
