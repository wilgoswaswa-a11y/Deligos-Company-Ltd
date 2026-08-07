<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_permission('refunds.approve', '/admin/index.php');

$pageTitle = 'Refund Approvals';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = $_POST['action'] ?? '';
    $refund_id = (int)($_POST['refund_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($refund_id > 0 && in_array($action, ['approve','reject'])) {
        $ok = approve_refund($refund_id, $action === 'approve', $note);
        if ($ok) {
            $_SESSION['message'] = 'Refund updated.';
            header('Location: admin/refunds.php');
            exit;
        } else {
            $_SESSION['error'] = 'Could not update refund.';
        }
    }
}

// Searchable approval history, with pending requests first.
$search = trim($_GET['q'] ?? '');
$sql = "SELECT r.*, u.full_name AS requested_by_name, a.full_name AS approved_by_name, s.invoice_no FROM refunds r LEFT JOIN users u ON r.requested_by = u.id LEFT JOIN users a ON r.approved_by = a.id LEFT JOIN sales s ON r.sale_id = s.id";
$params = [];
if ($search !== '') { $sql .= " WHERE s.invoice_no LIKE ? OR r.id = ? OR u.full_name LIKE ?"; $params = ['%' . $search . '%', ctype_digit($search) ? (int)$search : -1, '%' . $search . '%']; }
$sql .= " ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $refunds = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h2>Refund approvals & history</h2>
<form class="row g-2 mb-3" method="get"><div class="col-sm-5"><input class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Invoice, refund ID, or requester"></div><div class="col-auto"><button class="btn btn-outline-primary">Search</button></div></form>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['message']) ?></div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (empty($refunds)): ?>
    <div class="alert alert-info">No refunds found.</div>
<?php else: ?>
    <table class="table table-sm">
        <thead>
            <tr>
                <th>ID</th>
                <th>Sale</th>
                <th>Requested By</th>
                <th>Amount</th>
                <th>Reason</th>
                <th>Requested At</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($refunds as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['invoice_no'] ?? $r['sale_id']) ?></td>
                    <td><?= htmlspecialchars($r['requested_by_name'] ?? 'Unknown') ?></td>
                    <td><?= number_format($r['amount'],2) ?></td>
                    <td><?= htmlspecialchars($r['reason'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                    <td><?= htmlspecialchars($r['status']) ?><?= $r['approved_by_name'] ? '<br><small>' . htmlspecialchars($r['approved_by_name']) . '</small>' : '' ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="refund_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button class="btn btn-sm btn-success">Approve</button>
                        </form>
                        <?php else: ?><span class="text-muted small">Processed <?= htmlspecialchars($r['approved_at'] ?? '') ?></span><?php endif; ?>
                        <form method="POST" class="d-inline ms-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="refund_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button class="btn btn-sm btn-danger">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
