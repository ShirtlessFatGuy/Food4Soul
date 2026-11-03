<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email and password.';
    } elseif (attempt_login($pdo, $email, $password)) {
        redirect_to_dashboard();
    } else {
        $error = 'Email or password is not recognized. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — Food4Soul Analytics Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-hero">
      <div>
        <div class="brand"><span class="dot"></span>Food4Soul Analytics Portal</div>
        <h1>Real-time decisions for a seven-branch food business.</h1>
        <p>Centralized inventory, sales, and supplier coordination — replacing logbooks and spreadsheets with one live dashboard.</p>
      </div>
      <div class="stats">
        <div><b>7</b><span>Branches, Luzon</span></div>
        <div><b>24/7</b><span>Live monitoring</span></div>
        <div><b>3</b><span>Role-based portals</span></div>
      </div>
    </div>

    <div class="login-form-wrap">
      <h2>Sign in</h2>
      <p class="sub">Your role is detected automatically from your account.</p>

      <?php if ($error): ?>
        <div class="alert-box alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="/login.php">
        <div class="field">
          <label>Email address</label>
          <input type="email" name="email" required placeholder="you@food4soul.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn btn-basil" style="width:100%;padding:12px;">Log in</button>
      </form>

      <div class="demo-note">
        Demo accounts (after running the seed script, see README):<br>
        Admin — <code>admin@food4soul.com</code> / <code>admin123</code><br>
        Supplier — <code>supplier@food4soul.com</code> / <code>supplier123</code><br>
        Reseller — <code>reseller@food4soul.com</code> / <code>reseller123</code>
      </div>
    </div>
  </div>
</div>
</body>
</html>
