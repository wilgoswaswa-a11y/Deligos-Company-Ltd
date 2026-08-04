<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
$pageTitle = 'Dashboard';

$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) AS total_sales, COALESCE(SUM(grand_total),0) AS revenue FROM sales WHERE DATE(sale_date) = ?");
$stmt->execute([$today]);
$today_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) AS total_products FROM products");
$total_products = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) AS low_stock FROM products WHERE stock_qty <= reorder_level");
$low_stock = $stmt->fetchColumn();

include 'includes/header.php';
?>
<style>
@media (max-width: 575.98px) {
    .dashboard-summary-card { margin-bottom: 0.6rem !important; }
    .dashboard-summary-card .card-body { padding: 0.75rem !important; }
    .dashboard-summary-card .card-title { font-size: 1rem !important; margin-bottom: 0.3rem !important; }
    .dashboard-summary-card .display-6 { font-size: 2rem !important; }
}
</style>
<h2>Dashboard</h2>
<?php
    $lastLoginText = 'Unknown';
    if (!empty($_SESSION['last_login'])) {
        $lastLoginRaw = $_SESSION['last_login'];
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $lastLoginRaw);
        if (!$dateTime) {
            $dateTime = date_create($lastLoginRaw);
        }
        $lastLoginText = $dateTime ? $dateTime->format('F j, Y H:i') : $lastLoginRaw;
    }
?>
<div class="mb-2"><small class="text-muted">Last login: <strong><?= htmlspecialchars($lastLoginText) ?></strong></small></div>
<div class="row mt-4">
    <div class="col-md-3">
        <div class="card dashboard-summary-card text-white bg-primary mb-3">
            <div class="card-body">
                <h5 class="card-title">Today's Sales</h5>
                <p class="card-text display-6"><?= htmlspecialchars((string)($today_stats['total_sales'] ?? 0)) ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card dashboard-summary-card text-white bg-success mb-3">
            <div class="card-body">
                <h5 class="card-title">Revenue (Today)</h5>
                <p class="card-text display-6">KSh <?= number_format($today_stats['revenue'] ?? 0, 2) ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card dashboard-summary-card text-white bg-warning mb-3">
            <div class="card-body">
                <h5 class="card-title">Total Products</h5>
                <p class="card-text display-6"><?= htmlspecialchars((string)$total_products) ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card dashboard-summary-card text-white bg-danger mb-3">
            <div class="card-body">
                <h5 class="card-title">Low Stock Items</h5>
                <p class="card-text display-6"><?= htmlspecialchars((string)$low_stock) ?></p>
            </div>
        </div>
    </div>
</div>
<?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
<div class="row mt-2">
    <div class="col-md-6">
        <div class="card border-success mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-1">Profit Manager</h5>
                    <p class="card-text text-muted mb-0">Review sales profit, cost of goods, expenses, and net profit.</p>
                </div>
                <a class="btn btn-success" href="profits.php"><i class="bi bi-graph-up"></i> Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-danger mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-1">Expenses Manager</h5>
                    <p class="card-text text-muted mb-0">Add, filter, and delete business expenses.</p>
                </div>
                <a class="btn btn-danger" href="expenses.php"><i class="bi bi-cash-coin"></i> Open</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Recent Sales</div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead><tr><th>Invoice</th><th>Total</th><th>Cashier</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT s.*, u.full_name FROM sales s JOIN users u ON s.user_id = u.id ORDER BY s.sale_date DESC LIMIT 5");
                    while ($row = $stmt->fetch()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                            <td>KSh <?= number_format($row['grand_total'], 2) ?></td>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= date('d/m H:i', strtotime($row['sale_date'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Low Stock Alerts</div>
            <div class="card-body">
                <ul class="list-group">
                <?php
                $stmt = $pdo->query("SELECT name, stock_qty, reorder_level FROM products WHERE stock_qty <= reorder_level LIMIT 5");
                if ($stmt->rowCount() == 0) echo '<li class="list-group-item">All items are well stocked.</li>';
                while ($row = $stmt->fetch()): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <?= htmlspecialchars($row['name']) ?>
                        <span class="badge bg-danger">Stock: <?= htmlspecialchars((string)$row['stock_qty']) ?></span>
                    </li>
                <?php endwhile; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>

