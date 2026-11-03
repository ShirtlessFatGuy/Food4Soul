<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) {
    redirect_to_dashboard();
}
header('Location: /login.php');
exit;
