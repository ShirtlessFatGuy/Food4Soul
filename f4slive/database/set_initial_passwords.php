<?php
/**
 * Run ONCE after importing food4soul_schema.sql, from a terminal in the project folder:
 *   php database/set_initial_passwords.php
 *
 * The schema seeds three starter accounts with placeholder passwords that cannot log in.
 * This script gives each one a fresh RANDOM password, hashes it with bcrypt (REQ-007) and
 * prints the passwords ONCE. Copy them somewhere safe, then change them after first login.
 * Nothing is hard-coded, so there are no default credentials to guess.
 *
 * Add --emails=a@x.com,b@y.com to target other accounts.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Run this script from the command line only.');
}
require_once __DIR__ . '/../config/db.php';

$emails = ['admin@food4soul.com', 'supplier@food4soul.com', 'reseller@food4soul.com'];
foreach ($argv as $a) {
    if (strpos($a, '--emails=') === 0) {
        $emails = array_filter(array_map('trim', explode(',', substr($a, 9))));
    }
}

/** 16 chars from an unambiguous alphabet (no 0/O, 1/l/I). */
function random_password(int $len = 16): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$stmt = $pdo->prepare('UPDATE users SET Password = ? WHERE Email_Address = ?');
foreach ($emails as $email) {
    $plain = random_password();
    $stmt->execute([password_hash($plain, PASSWORD_BCRYPT), $email]);
    if ($stmt->rowCount() === 0) {
        echo "No account found for $email (skipped)\n";
        continue;
    }
    echo str_pad($email, 28) . $plain . "\n";
}
echo "\nDone. These passwords are shown only now. Store them safely and change them after first login.\n";
