<?php
/**
 * Run ONCE after importing food4soul_schema.sql, from a terminal in the project folder:
 *   php database/generate_demo_users.php
 *
 * The schema seeds three demo users with placeholder passwords. This script
 * hashes real passwords with password_hash() (bcrypt) and updates the users
 * table with a parameterised query (REQ-007).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Run this script from the command line only.');
}
require_once __DIR__ . '/../config/db.php';

$accounts = [
    'admin@food4soul.com'    => 'admin123',
    'supplier@food4soul.com' => 'supplier123',
    'reseller@food4soul.com' => 'reseller123',
];

$stmt = $pdo->prepare('UPDATE users SET Password = ? WHERE Email_Address = ?');
foreach ($accounts as $email => $plaintext) {
    $stmt->execute([password_hash($plaintext, PASSWORD_BCRYPT), $email]);
    echo "Updated $email\n";
}
echo "Done. You can now log in with the passwords listed in README.md.\n";
