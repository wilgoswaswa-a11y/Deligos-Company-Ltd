<?php
$required_role = 'admin';
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

require_post_csrf();

$pageTitle = 'Roles & Permissions';

// Handle role update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_post_csrf();
    
    if ($_POST['action'] === 'update_permissions') {
        $role_id = (int)($_POST['role_id'] ?? 0);
        $permissions = $_POST['permissions'] ?? [];
        
        if ($role_id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Delete existing permissions for this role
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
                
                // Insert new permissions
                $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissions as $permission_id) {
                    $permission_id = (int)$permission_id;
                    if ($permission_id > 0) {
                        $stmt->execute([$role_id, $permission_id]);
                    }
                }
                
                $pdo->commit();
                
                // Audit log
                audit_log('update', 'roles', $role_id, null, ['permissions' => $permissions], 'Updated role permissions');
                
                $_SESSION['message'] = 'Permissions updated successfully';
                header('Location: admin/permissions.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['error'] = app_exception_message($e, 'Could not update permissions');
            }
        }
    }
}

// Get all roles
$roles = $pdo->query("SELECT id, name, description FROM roles ORDER BY name")->fetchAll();

// Get all permissions grouped by category
$permissions_by_category = [];
$all_permissions = $pdo->query("SELECT id, name, description FROM permissions ORDER BY name")->fetchAll();

foreach ($all_permissions as $perm) {
    $category = explode('.', $perm['name'])[0];
    if (!isset($permissions_by_category[$category])) {
        $permissions_by_category[$category] = [];
    }
    $permissions_by_category[$category][] = $perm;
}

include 'includes/header.php';
?>

<h2>Roles & Permissions Management</h2>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['message']) ?></div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<ul class="nav nav-tabs" role="tablist">
    <?php foreach ($roles as $index => $role): ?>
        <li class="nav-item">
            <a class="nav-link <?= $index === 0 ? 'active' : '' ?>" data-bs-toggle="tab" href="#role_<?= $role['id'] ?>" role="tab">
                <?= htmlspecialchars($role['name']) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content mt-3">
    <?php foreach ($roles as $index => $role): ?>
        <div class="tab-pane <?= $index === 0 ? 'active' : '' ?>" id="role_<?= $role['id'] ?>" role="tabpanel">
            <div class="card">
                <div class="card-header">
                    <h5><?= htmlspecialchars($role['name']) ?></h5>
                    <small class="text-muted"><?= htmlspecialchars($role['description'] ?? '') ?></small>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_permissions">
                        <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                        
                        <?php foreach ($permissions_by_category as $category => $perms): ?>
                            <div class="mb-4">
                                <h6 class="mb-3">
                                    <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($category)) ?></span>
                                </h6>
                                <div class="row">
                                    <?php foreach ($perms as $perm): ?>
                                        <?php
                                        $stmt = $pdo->prepare(
                                            "SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?"
                                        );
                                        $stmt->execute([$role['id'], $perm['id']]);
                                        $has_permission = $stmt->fetchColumn();
                                        ?>
                                        <div class="col-md-6 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="permissions[]" 
                                                       value="<?= $perm['id'] ?>" 
                                                       id="perm_<?= $perm['id'] ?>"
                                                       <?= $has_permission ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="perm_<?= $perm['id'] ?>">
                                                    <strong><?= htmlspecialchars($perm['name']) ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?= htmlspecialchars($perm['description'] ?? '') ?></small>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">Save Permissions</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<hr>

<h3 class="mt-4">Current Role Assignments</h3>
<table class="table table-sm table-hover">
    <thead>
        <tr>
            <th>User</th>
            <th>Role</th>
            <th>Email</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $stmt = $pdo->query(
            "SELECT u.id, u.full_name, r.name AS role_name, u.email, u.is_active
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             ORDER BY r.name, u.full_name"
        );
        foreach ($stmt->fetchAll() as $user):
        ?>
            <tr>
                <td><?= htmlspecialchars($user['full_name']) ?></td>
                <td>
                    <span class="badge bg-info">
                        <?= htmlspecialchars($user['role_name'] ?? 'Unassigned') ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($user['email'] ?? 'N/A') ?></td>
                <td>
                    <?= $user['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>' ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>
