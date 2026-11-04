<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

/* Role picker: the key is used in the URL, 'db' must match users.Role in the database. */
$roles = [
    'admin' => [
        'label' => 'Admin',
        'db'    => 'Admin',
        'desc'  => 'Monitor inventory, orders, suppliers, and reports.',
        'demo'  => ['admin@food4soul.com', 'admin123'],
        'icon'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    ],
    'supplier' => [
        'label' => 'Supplier',
        'db'    => 'Supplier',
        'desc'  => 'View purchase orders and update deliveries.',
        'demo'  => ['supplier@food4soul.com', 'supplier123'],
        'icon'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="6" width="13" height="11" rx="1"/><path d="M14.5 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>',
    ],
    'reseller' => [
        'label' => 'Reseller',
        'db'    => 'Reseller',
        'desc'  => 'Browse products, place orders, and pay by cash or QR.',
        'demo'  => ['reseller@food4soul.com', 'reseller123'],
        'icon'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1.2 11.2a1.5 1.5 0 01-1.5 1.3H7.7a1.5 1.5 0 01-1.5-1.3z"/><path d="M9 8V6.5a3 3 0 016 0V8"/></svg>',
    ],
];

$roleKey = strtolower((string)($_GET['role'] ?? $_POST['role'] ?? ''));
$role    = $roles[$roleKey] ?? null;

$error = '';
if ($role && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (attempt_login($pdo, $email, $password)) {
        if (($_SESSION['user']['Role'] ?? '') === $role['db']) {
            redirect_to_dashboard();
        }
        // Correct password, but the account belongs to a different role: do not keep them signed in.
        logout();
        $error = 'That account is not a ' . $role['label'] . ' account. Go back and choose the role that matches it.';
    } else {
        $error = 'Email or password is not recognized. Please try again.';
    }
}

$logoSvg = '<svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<path d="M6 22h36c0 9.4-7.6 17-17 17h-2C13.6 39 6 31.4 6 22z" fill="#3E6B52"/>'
         . '<path d="M16 16c0-3 3-3 3-6M24 16c0-3 3-3 3-6M32 16c0-3 3-3 3-6" stroke="#E2A33D" stroke-width="2.6"/>'
         . '<path d="M17 43h14" stroke="#1E2420" stroke-width="3"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Food4Soul Analytics Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/login.css">
</head>
<body>
<div class="lg">

  <aside class="lg-hero">
    <div class="lg-logo"><?= $logoSvg ?></div>
    <h2 class="lg-brand-name">Food4Soul<br>Analytics Portal</h2>
    <p class="lg-tagline">Real-time stock, sales, and supplier updates in one place.</p>
    <div class="lg-foot">© <?= date('Y') ?> Food4Soul · Inventory and order analytics for administrators, suppliers, and resellers</div>
  </aside>

  <main class="lg-main">
    <div class="lg-box">
      <div class="lg-mobile-brand"><div class="lg-logo"><?= $logoSvg ?></div>Food4Soul</div>

      <?php if (!$role): ?>
        <h1>Sign in</h1>
        <p class="lg-sub">Choose your role to continue.</p>
        <nav class="role-list" aria-label="Choose your role">
          <?php foreach ($roles as $key => $r): ?>
            <a class="role-card" href="/login.php?role=<?= urlencode($key) ?>">
              <span class="role-icon"><?= $r['icon'] ?></span>
              <span class="role-text"><b><?= htmlspecialchars($r['label']) ?></b><span><?= htmlspecialchars($r['desc']) ?></span></span>
              <svg class="role-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </a>
          <?php endforeach; ?>
        </nav>

      <?php else: ?>
        <a class="lg-back" href="/login.php">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
          Choose a different role
        </a>
        <h1>Sign in</h1>
        <p class="lg-sub">Enter your account details to continue.</p>
        <div class="lg-role-chip"><span class="role-icon"><?= $role['icon'] ?></span><?= htmlspecialchars($role['label']) ?></div>

        <?php if ($error): ?>
          <div class="lg-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php?role=<?= urlencode($roleKey) ?>">
          <input type="hidden" name="role" value="<?= htmlspecialchars($roleKey) ?>">
          <div class="lg-field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" required autofocus autocomplete="username"
                   placeholder="you@food4soul.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <div class="lg-field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
          </div>
          <button type="submit" class="lg-submit">Sign in as <?= htmlspecialchars($role['label']) ?></button>
        </form>

        <div class="lg-demo">
          Demo account (after running the seed script):<br>
          <code><?= htmlspecialchars($role['demo'][0]) ?></code> / <code><?= htmlspecialchars($role['demo'][1]) ?></code>
        </div>
      <?php endif; ?>
    </div>
  </main>

</div>
</body>
</html>
