<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$validDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : '';

$dir   = in_array($_GET['dir'] ?? '', ['IN', 'OUT'], true) ? $_GET['dir'] : '';
$pid   = trim($_GET['product'] ?? '');
$from  = $validDate($_GET['from'] ?? '');
$to    = $validDate($_GET['to'] ?? '');
$party = trim($_GET['party'] ?? '');
if ($from && $to && $from > $to) [$from, $to] = [$to, $from];   // tolerate swapped dates

$products = $pdo->query("SELECT Product_ID, Product_Name FROM products ORDER BY Product_Name")->fetchAll(PDO::FETCH_KEY_PAIR);
if ($pid !== '' && !isset($products[$pid])) $pid = '';
$parties = movement_parties($pdo);
if ($party !== '' && !in_array($party, array_column($parties, 'Party'), true)) $party = '';

$filters = ['dir' => $dir, 'product' => $pid, 'from' => $from, 'to' => $to, 'party' => $party];
$moves   = get_stock_movements($pdo, 300, $filters);
$sum     = movement_summary($pdo, $filters);
$net     = $sum['in'] - $sum['out'];
$filtered = ($pid || $from || $to || $party);

$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge($filters, $o), fn($v) => $v !== ''));

$active = 'movements';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Stock Movements</h1></div>
<p class="page-sub">Every change to stock levels: deliveries from suppliers coming in, and reseller orders going out.</p>

<div class="kpi-row" data-live="move-kpis">
  <div class="kpi"><div class="label">Received</div><div class="num qty-in">+<?= number_format($sum['in']) ?></div><div class="delta up">units from suppliers<?= $filtered ? ' · filtered' : '' ?></div></div>
  <div class="kpi"><div class="label">Issued</div><div class="num qty-out">−<?= number_format($sum['out']) ?></div><div class="delta warn">units to resellers<?= $filtered ? ' · filtered' : '' ?></div></div>
  <div class="kpi"><div class="label">Net change</div><div class="num"><?= ($net >= 0 ? '+' : '−') . number_format(abs($net)) ?></div><div class="delta <?= $net >= 0 ? 'up' : 'warn' ?>"><?= $net >= 0 ? 'stock is growing' : 'stock is drawing down' ?></div></div>
</div>

<div class="tabs">
  <a href="<?= $qs(['dir' => '']) ?>" class="<?= $dir === '' ? 'active' : '' ?>">All</a>
  <a href="<?= $qs(['dir' => 'IN']) ?>" class="<?= $dir === 'IN' ? 'active' : '' ?>">Received</a>
  <a href="<?= $qs(['dir' => 'OUT']) ?>" class="<?= $dir === 'OUT' ? 'active' : '' ?>">Issued</a>
</div>

<form method="get" class="toolbar filters">
  <?php if ($dir): ?><input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>"><?php endif; ?>
  <label class="fl">From date<input class="search-input" type="date" name="from" value="<?= htmlspecialchars($from) ?>" max="<?= date('Y-m-d') ?>"></label>
  <label class="fl">To date<input class="search-input" type="date" name="to" value="<?= htmlspecialchars($to) ?>" max="<?= date('Y-m-d') ?>"></label>
  <label class="fl">Product
    <select class="search-input" name="product">
      <option value="">All products</option>
      <?php foreach ($products as $id => $name): ?><option value="<?= htmlspecialchars($id) ?>" <?= $id === $pid ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
    </select></label>
  <label class="fl">From / to
    <select class="search-input" name="party">
      <option value="">Everyone</option>
      <?php foreach (['IN' => 'Suppliers', 'OUT' => 'Resellers'] as $d => $label): $grp = array_filter($parties, fn($p) => $p['Direction'] === $d); if (!$grp) continue; ?>
        <optgroup label="<?= $label ?>">
          <?php foreach ($grp as $p): ?><option value="<?= htmlspecialchars($p['Party']) ?>" <?= $p['Party'] === $party ? 'selected' : '' ?>><?= htmlspecialchars($p['Party']) ?></option><?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select></label>
  <div class="fl-actions">
    <button class="btn btn-basil" type="submit">Apply</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= $qs(['product' => '', 'from' => '', 'to' => '', 'party' => '']) ?>">Clear filters</a><?php endif; ?>
  </div>
</form>

<div class="panel" data-live="movements"><div class="table-scroll">
  <table>
    <thead><tr><th>Date</th><th>Reference</th><th>Product</th><th>Movement</th><th>From / to</th><th class="num-r">Qty</th></tr></thead>
    <tbody>
      <?php foreach ($moves as $m): $in = $m['Direction'] === 'IN'; ?>
        <tr>
          <td><?= htmlspecialchars($m['Move_Date']) ?></td>
          <td><?= htmlspecialchars($m['Ref']) ?></td>
          <td><strong><?= htmlspecialchars($m['Product_Name']) ?></strong><span class="sku"><?= htmlspecialchars($m['Product_ID']) ?></span></td>
          <td><span class="mv <?= $in ? 'in' : 'out' ?>"><?= $in ? '▲ Received' : '▼ Issued' ?></span></td>
          <td><?= $in ? 'Supplier: ' : 'Reseller: ' ?><?= htmlspecialchars($m['Party'] ?? '—') ?></td>
          <td class="num-r <?= $in ? 'qty-in' : 'qty-out' ?>"><?= $in ? '+' : '−' ?><?= (int)$m['Qty'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$moves): ?><tr><td colspan="6">No stock movements match these filters.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="hint" style="margin:12px 0 0;"><?= count($moves) ?> entr<?= count($moves) === 1 ? 'y' : 'ies' ?> shown (latest 300 at most). Supplier deliveries are dated by their order date; manual count corrections made on the Inventory page are not listed here.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
