<?php
/**
 * Authentication + Role-Based Access Control (RBAC)
 * Implements REQ-001, REQ-002, REQ-007.
 */
session_start();
require_once __DIR__ . '/../config/db.php';

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_logged_in() {
    return isset($_SESSION['user']);
}

/**
 * Call at the top of any protected page.
 *   require_role('Admin');
 *   require_role(['Admin','Supplier']);
 */
function require_role($roles) {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['user']['Role'], $roles, true)) {
        http_response_code(403);
        die('Access denied: this page is restricted to ' . htmlspecialchars(implode('/', $roles)) . '.');
    }
}

function attempt_login(PDO $pdo, string $email, string $password) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE Email_Address = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['Password'])) {
        unset($user['Password']);          // never keep the hash in the session
        session_regenerate_id(true);       // prevent session fixation
        $_SESSION['user'] = $user;
        return true;
    }
    return false;
}

function logout() {
    $_SESSION = [];
    session_destroy();
}

/** Sends a logged-in user to the dashboard for their role. */
function redirect_to_dashboard() {
    $role = $_SESSION['user']['Role'] ?? null;
    switch ($role) {
        case 'Admin':    header('Location: /admin/dashboard.php'); break;
        case 'Supplier': header('Location: /supplier/dashboard.php'); break;
        case 'Reseller': header('Location: /reseller/catalog.php'); break;
        default:         header('Location: /login.php');
    }
    exit;
}
