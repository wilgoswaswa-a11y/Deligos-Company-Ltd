<?php
$required_role = 'admin';
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$pageTitle = 'Commission Calculator';
$error = '';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$rate = validate_decimal($_GET['rate'] ?? 5, 0, 100);
$rate = $rate === null ? 5.0 : $rate;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end) {
    $error = 'Choose a valid date range.';
    $start = date('Y-m-01');
    $end = date('Y-m-d');
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            u.id,
            u.full_name,
            u.username,
            u.role,
            COUNT(s.id) AS sale_count,
            COALESCE(SUM(s.grand_total), 0) AS sales_total
         FROM sales s
         JOIN users u ON u.id = s.user_id
         WHERE DATE(s.sale_date) BETWEEN ? AND ?
         GROUP BY u.id, u.full_name, u.username, u.role
         ORDER BY sales_total DESC, u.full_name ASC"
    );
    $stmt->execute([$start, $end]);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = app_exception_message($e, 'We could not calculate commissions right now. Please try again later.');
    $commissions = [];
}

$totalSales = 0.0;
$totalCommission = 0.0;
foreach ($commissions as &$commission) {
    $commission['sales_total'] = (float)$commission['sales_total'];
    $commission['commission_amount'] = round($commission['sales_total'] * ($rate / 100), 2);
    $totalSales += $commission['sales_total'];
    $totalCommission += $commission['commission_amount'];
}
unset($commission);

include 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="mb-1">Commission Calculator</h2>
        <p class="text-muted mb-0">Commissions are calculated from each staff member's sales total after discounts.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="start" class="form-label">From</label>
                <input type="date" id="start" name="start" class="form-control" value="<?= htmlspecialchars($start) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="end" class="form-label">To</label>
                <input type="date" id="end" name="end" class="form-control" value="<?= htmlspecialchars($end) ?>" required>
            </div>
            <div class="col-md-2">
                <label for="rate" class="form-label">Commission rate (%)</label>
                <input type="number" id="rate" name="rate" class="form-control" value="<?= htmlspecialchars((string)$rate) ?>" min="0" max="100" step="0.01" required>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-calculator"></i> Calculate</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-primary h-100"><div class="card-body"><div class="text-muted small">Commission rate</div><div class="fs-4 fw-semibold"><?= number_format($rate, 2) ?>%</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-success h-100"><div class="card-body"><div class="text-muted small">Eligible sales</div><div class="fs-4 fw-semibold">KSh <?= number_format($totalSales, 2) ?></div></div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-warning h-100"><div class="card-body"><div class="text-muted small">Total commission</div><div class="fs-4 fw-semibold">KSh <?= number_format($totalCommission, 2) ?></div></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">Commission by staff member</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Staff member</th><th>Role</th><th class="text-end">Sales</th><th class="text-end">Sales total</th><th class="text-end">Rate</th><th class="text-end">Commission</th><th></th></tr></thead>
                <tbody>
                <?php if (!$commissions): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No sales were recorded for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($commissions as $commission): ?>
                        <tr>
                            <td><?= htmlspecialchars($commission['full_name']) ?><div class="small text-muted">@<?= htmlspecialchars($commission['username']) ?></div></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars(ucfirst($commission['role'])) ?></span></td>
                            <td class="text-end"><?= (int)$commission['sale_count'] ?></td>
                            <td class="text-end">KSh <?= number_format($commission['sales_total'], 2) ?></td>
                            <td class="text-end"><?= number_format($rate, 2) ?>%</td>
                            <td class="text-end fw-semibold">KSh <?= number_format($commission['commission_amount'], 2) ?></td>
                            <td class="text-end">
                                <form method="POST" action="download_commission_payslip.php" target="_blank">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$commission['id'] ?>">
                                    <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
                                    <input type="hidden" name="end" value="<?= htmlspecialchars($end) ?>">
                                    <input type="hidden" name="rate" value="<?= htmlspecialchars((string)$rate) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-text"></i> View payslip</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if ($commissions): ?>
                    <tfoot class="table-light"><tr><th colspan="3">Total</th><th class="text-end">KSh <?= number_format($totalSales, 2) ?></th><th></th><th class="text-end">KSh <?= number_format($totalCommission, 2) ?></th><th></th></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
