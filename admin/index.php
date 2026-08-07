<?php
$required_role = 'admin';
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$pageTitle = 'Admin Dashboard';

// Get statistics
$stats = [];

$stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stats['total_products'] = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$stats['total_sales'] = $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$stats['audit_entries'] = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

// Get recent audit logs
$recent_logs = $pdo->query(
    "SELECT a.*, u.full_name FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     ORDER BY a.created_at DESC LIMIT 10"
)->fetchAll();

include 'includes/header.php';
?>

<h2>Admin Dashboard</h2>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Total Users</h6>
                <h3><?= $stats['total_users'] ?></h3>
                <a href="users.php" class="btn btn-sm btn-outline-primary">Manage Users</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Total Products</h6>
                <h3><?= $stats['total_products'] ?></h3>
                <a href="products.php" class="btn btn-sm btn-outline-primary">Manage Products</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Total Sales</h6>
                <h3><?= $stats['total_sales'] ?></h3>
                <a href="sales.php" class="btn btn-sm btn-outline-primary">View Sales</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Audit Entries</h6>
                <h3><?= $stats['audit_entries'] ?></h3>
                <a href="admin/audit_log.php" class="btn btn-sm btn-outline-primary">View Audit Log</a>
            </div>
        </div>
    </div>
</div>

<hr>

<h3 class="mb-3">Admin Functions</h3>

<div class="row">
    <div class="col-md-6">
        <div class="list-group">
            <a href="admin/permissions.php" class="list-group-item list-group-item-action">
                <h6 class="mb-1">🔐 Roles & Permissions</h6>
                <small>Manage user roles and permissions</small>
            </a>
            <a href="admin/audit_log.php" class="list-group-item list-group-item-action">
                <h6 class="mb-1">📋 Audit Log</h6>
                <small>View system audit trail</small>
            </a>
        </div>
    </div>
</div>

<hr>

<h3 class="mb-3">Recent Activity</h3>

<div class="table-responsive">
    <table class="table table-sm table-hover">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_logs as $log): ?>
                <tr>
                    <td><small><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></small></td>
                    <td><?= htmlspecialchars($log['full_name'] ?? 'System') ?></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($log['action']) ?></span></td>
                    <td><small><?= htmlspecialchars($log['entity_type']) ?> #<?= $log['entity_id'] ?></small></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
