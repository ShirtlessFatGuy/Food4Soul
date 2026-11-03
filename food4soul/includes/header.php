<?php
/**
 * Expects $active (string) to be set by the including page to highlight
 * the current nav item, e.g. $active = 'inventory';
 */
$user = current_user();
$role = $user['Role'] ?? '';

$navByRole = [
    'Admin' => [
        ['dashboard', '/admin/dashboard.php', 'Overview', 'grid'],
        ['inventory', '/admin/inventory.php', 'Inventory', 'box'],
        ['orders',    '/admin/orders.php',    'Orders', 'clipboard'],
        ['reports',   '/admin/reports.php',   'Reports', 'file'],
        ['suppliers', '/admin/suppliers.php', 'Suppliers', 'truck'],
    ],
    'Supplier' => [
        ['dashboard', '/supplier/dashboard.php', 'Overview', 'grid'],
        ['orders',    '/supplier/orders.php',    'Assigned Orders', 'clipboard'],
        ['inventory', '/supplier/inventory.php', 'Update Inventory', 'box'],
    ],
    'Reseller' => [
        ['catalog', '/reseller/catalog.php', 'Product Catalog', 'grid'],
        ['orders',  '/reseller/orders.php',  'My Orders', 'clipboard'],
    ],
];

$ICONS = [
  'grid'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
  'box'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
  'clipboard' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 011-1h4a1 1 0 011 1v1"/><path d="M9 11h6M9 15h6"/></svg>',
  'file'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>',
  'truck'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="6" width="14" height="11"/><path d="M15 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Food4Soul Analytics Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="dot"></span>Food4Soul</div>
    <div class="role-badge"><?= htmlspecialchars($role) ?> Portal</div>
    <nav>
      <?php foreach ($navByRole[$role] ?? [] as [$key, $url, $label, $icon]): ?>
        <a class="nav-item <?= $active === $key ? 'active' : '' ?>" href="<?= $url ?>"><?= $ICONS[$icon] ?><span><?= htmlspecialchars($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="av"><?= htmlspecialchars(mb_substr($user['Full_Name'] ?? '?', 0, 1)) ?></div>
        <div class="meta"><b><?= htmlspecialchars($user['Full_Name'] ?? '') ?></b><span><?= htmlspecialchars($user['Email_Address'] ?? '') ?></span></div>
      </div>
      <a class="logout-btn" href="/logout.php">Log out</a>
    </div>
  </aside>
  <main class="main">
