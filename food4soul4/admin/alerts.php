<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

check_late_orders($pdo);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'read' && !empty($_POST['alert_id'])) {
        $pdo->prepare("UPDATE alerts SET Status = 'Read' WHERE Alert_ID = ?")->execute([$_POST['alert_id']]);
        $message = 'Alert marked as read.';
    } elseif ($action === 'read_all') {
        $n = $pdo->exec("UPDATE alerts SET Status = 'Read' WHERE Status = 'Unread'");
        $message = $n . ' alert' . ($n === 1 ? '' : 's') . ' marked as read.';
    }
}

$filter = ($_GET['show'] ?? 'unread') === 'all' ? 'all' : 'unread';
$sql = "SELECT a.*, p.Product_Name FROM alerts a LEFT JOIN products p ON p.Product_ID = a.Product_ID"
     . ($filter === 'unread' ? " WHERE a.Status = 'Unread'" : "")
     . " ORDER BY a.Status = 'Unread' DESC, a.Date_Generated DESC, a.Alert_ID DESC";
$alerts = $pdo->query($sql)->fetchAll();
$unread = unread_alert_count($pdo);

$active = 'alerts';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Alerts</h1></div>
<?php if ($message): ?><div class="alert-box alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div data-live="alerts-page">
<div class="tabs">
  <a href="?show=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread (<?= $unread ?>)</a>
  <a href="?show=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
  <?php if ($unread > 0): ?>
  <form method="post" style="margin-left:auto;">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="read_all">
    <button class="btn btn-ghost btn-sm" type="submit">Mark all as read</button>
  </form>
  <?php endif; ?>
</div>

<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Type</th><th>Message</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($alerts as $a): ?>
        <tr class="<?= $a['Status'] === 'Unread' ? 'unread' : '' ?>">
          <td><span class="badge <?= in_array($a['Alert_Type'], ['Low Stock','Late Order'], true) ? 'bad' : ($a['Alert_Type'] === 'Delivery' ? 'ok' : 'neutral') ?>"><?= htmlspecialchars($a['Alert_Type']) ?></span></td>
          <td><?= htmlspecialchars($a['Alert_Message']) ?></td>
          <td><?= htmlspecialchars($a['Date_Generated']) ?></td>
          <td><span class="badge <?= badge_class($a['Status']) ?>"><?= htmlspecialchars($a['Status']) ?></span></td>
          <td style="display:flex;gap:6px;">
            <?php if ($a['Status'] === 'Unread'): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="read">
              <input type="hidden" name="alert_id" value="<?= htmlspecialchars($a['Alert_ID']) ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
            </form>
            <?php endif; ?>
            <?php if ($a['Alert_Type'] === 'Low Stock' && $a['Status'] === 'Unread' && $a['Product_ID']): ?>
              <a class="btn btn-ghost btn-sm" href="/admin/orders.php?restock=<?= urlencode($a['Product_ID']) ?>">Reorder</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$alerts): ?><tr><td colspan="5">No <?= $filter === 'unread' ? 'unread ' : '' ?>alerts. All clear.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
