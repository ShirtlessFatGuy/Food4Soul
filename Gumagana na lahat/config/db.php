<?php
/**
 * Database connection (Data Layer)
 * PDO with prepared statements throughout the app (REQ-010 and the
 * security non-functional requirement against SQL injection).
 */

date_default_timezone_set('Asia/Manila');   // keep PHP dates consistent with the MySQL server clock

$DB_HOST = 'localhost';
$DB_NAME = 'u965573248_food4soul';
$DB_USER = 'u965573248_admin';      // change for production
$DB_PASS = 'Adminroot12123';          // change for production

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
