<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

/*
 * Single sign-in form (paper, Figure 5: Role-based Navigation Process).
 * The user enters email + password; the system fetches the role from the
 * database and redirect_to_dashboard() sends them to the matching page.
 */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (attempt_login($pdo, $email, $password)) {
        redirect_to_dashboard();
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
<title>Sign in — Food4Soul Inventory</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/login.css?v=2">
</head>
<body>
<div class="lg-bg" aria-hidden="true"><i></i><i></i><i></i></div>

<main class="lg-shell">
  <section class="lg-card">

    <div class="lg-form-side">
      <div class="lg-brand"><?= $logoSvg ?><span>Food4Soul</span></div>

      <h1>Sign in</h1>
      <p class="lg-sub">Welcome back. Enter your account details to continue.</p>

      <?php if ($error): ?>
        <div class="lg-error" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="/login.php" novalidate>
        <div class="lg-field">
          <label for="email">Email</label>
          <input id="email" type="email" name="email" required autofocus autocomplete="username"
                 placeholder="you@food4soul.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="lg-field">
          <label for="password">Password</label>
          <div class="lg-pw">
            <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Password">
            <button type="button" class="lg-eye" id="togglePw" aria-label="Show password" aria-pressed="false">
              <svg class="eye-on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.2A9.7 9.7 0 0112 6c6.4 0 10 6 10 6a17 17 0 01-3.2 3.9M6.6 7.6A16.6 16.6 0 002 12s3.6 7 10 7c1.7 0 3.2-.4 4.5-1"/><path d="M9.9 9.9a3 3 0 004.2 4.2"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="lg-submit">Sign in</button>
      </form>

      <p class="lg-note">Accounts are created by the Food4Soul administrator.</p>

    </div>

    <aside class="lg-art" aria-hidden="true">
      <svg class="lg-art-svg" viewBox="0 0 400 400" fill="none" stroke-linecap="round" stroke-linejoin="round">
        <!-- floating bits -->
        <ellipse class="bit b1" cx="82"  cy="96"  rx="16" ry="8"  fill="#E2A33D" transform="rotate(-28 82 96)"/>
        <ellipse class="bit b2" cx="330" cy="82"  rx="12" ry="6"  fill="#CFE3D4" transform="rotate(24 330 82)"/>
        <ellipse class="bit b3" cx="352" cy="228" rx="15" ry="7"  fill="#E2A33D" transform="rotate(-18 352 228)"/>
        <ellipse class="bit b4" cx="58"  cy="262" rx="11" ry="5.5" fill="#CFE3D4" transform="rotate(32 58 262)"/>
        <ellipse class="bit b5" cx="300" cy="340" rx="13" ry="6.5" fill="#E2A33D" transform="rotate(-36 300 340)"/>
        <ellipse class="bit b6" cx="116" cy="352" rx="10" ry="5"  fill="#CFE3D4" transform="rotate(14 116 352)"/>
        <!-- steam -->
        <g class="steam" stroke="#E2A33D" stroke-width="9">
          <path d="M150 140c0-26 26-26 26-52"/>
          <path d="M200 128c0-26 26-26 26-52"/>
          <path d="M250 140c0-26 26-26 26-52"/>
        </g>
        <!-- bowl -->
        <path d="M70 196h260c0 76-58 136-130 136S70 272 70 196z" fill="#F4EFE4"/>
        <path d="M70 196h260c0 18-3 35-9 50H79c-6-15-9-32-9-50z" fill="#E2A33D" opacity=".9"/>
        <ellipse cx="200" cy="196" rx="130" ry="14" fill="#2F5340"/>
        <path d="M150 352h100" stroke="#1E2420" stroke-width="10" opacity=".55"/>
      </svg>
      <h2>Live stock, one place.</h2>
      <p>Inventory, restocking and supplier deliveries for administrators, suppliers and resellers.</p>
    </aside>

  </section>
  <footer class="lg-foot">© <?= date('Y') ?> Food4Soul Inventory System</footer>
</main>

<script>
(function () {
  var btn = document.getElementById('togglePw');
  var input = document.getElementById('password');
  if (!btn || !input) return;
  btn.addEventListener('click', function () {
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.classList.toggle('is-shown', show);
  });
})();
</script>
</body>
</html>
