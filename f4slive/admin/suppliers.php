<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

$suppliers = $pdo->query("SELECT s.*, COUNT(p.Product_ID) AS product_count
                           FROM suppliers s LEFT JOIN products p ON p.Supplier_ID = s.Supplier_ID
                           GROUP BY s.Supplier_ID, s.Supplier_Name, s.Contact_Person, s.Contact_Address, s.Phone_Number, s.Email_Address
                           ORDER BY s.Supplier_Name")->fetchAll();

$active = 'suppliers';
require __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><h1>Suppliers</h1></div>
<div class="panel"><div class="table-scroll">
  <table>
    <thead><tr><th>Supplier</th><th>Contact person</th><th>Phone</th><th>Email</th><th>Products supplied</th></tr></thead>
    <tbody>
      <?php foreach ($suppliers as $s): ?>
        <tr>
          <td><strong><?= htmlspecialchars($s['Supplier_Name']) ?></strong></td>
          <td><?= htmlspecialchars($s['Contact_Person']) ?></td>
          <td><?= htmlspecialchars($s['Phone_Number']) ?></td>
          <td><?= htmlspecialchars($s['Email_Address']) ?></td>
          <td><?= (int)$s['product_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
