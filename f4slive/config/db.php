<?php
/**
 * Database connection (Data Layer)
 * PDO with prepared statements throughout the app (REQ-010 and the
 * security non-functional requirement against SQL injection).
 *
 * Credentials come from environment variables or config/secrets.php (see config/env.php).
 * Defaults below are for a local XAMPP install only.
 */
require_once __DIR__ . '/env.php';

date_default_timezone_set('Asia/Manila');   // keep PHP dates consistent with the MySQL server clock

$DB_HOST = secret('DB_HOST', 'localhost');
$DB_NAME = secret('DB_NAME', 'food4soul');
$DB_USER = secret('DB_USER', 'root');
$DB_PASS = secret('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed. Check the database settings in config/secrets.php.');
}
unset($DB_PASS);   // don't leave the password sitting in a global variable
