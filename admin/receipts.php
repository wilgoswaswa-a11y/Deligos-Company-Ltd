<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_permission('receipts.reprint', '/admin/index.php');

$pageTitle = 'Stored Receipts';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT r.*, s.invoice_no, u.full_name, c.email AS customer_email FROM receipts r LEFT JOIN sales s ON r.sale_id = s.id LEFT JOIN users u ON r.created_by = u.id LEFT JOIN customers c ON c.id=s.customer_id";
$params = [];
if ($search !== '') { $sql .= ' WHERE s.invoice_no LIKE ? OR c.email LIKE ? OR r.id = ?'; $params = ['%' . $search . '%', '%' . $search . '%', ctype_digit($search) ? (int)$search : -1]; }
$sql .= ' ORDER BY r.created_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$receipts = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h2>Stored Receipts</h2>
<form class="row g-2 mb-3" method="get"><div class="col-sm-5"><input name="q" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Invoice, receipt ID, or customer email"></div><div class="col-auto"><button class="btn btn-outline-primary">Search</button></div></form>

<?php if (empty($receipts)): ?>
    <div class="alert alert-info">No stored receipts found.</div>
<?php else: ?>
    <table class="table table-sm">
        <thead>
            <tr>
                <th>ID</th>
                <th>Sale</th>
                <th>Stored By</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($receipts as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['invoice_no'] ?? $r['sale_id']) ?></td>
                    <td><?= htmlspecialchars($r['full_name'] ?? 'System') ?></td>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="receipt_view.php?id=<?= $r['id'] ?>">View / reprint</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
