<?php
/**
 * Database connection (Data Layer)
 * PDO with prepared statements throughout the app (REQ-010 and the
 * security non-functional requirement against SQL injection).
 */

$DB_HOST = 'localhost';
$DB_NAME = 'food4soul';
$DB_USER = 'root';      // change for production
$DB_PASS = '';          // change for production

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
    die('Database connection failed. Check config/db.php credentials.');
}
