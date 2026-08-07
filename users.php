<?php
$required_role = 'admin';
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
$pageTitle = 'Users';

$message = '';
$error = '';
$form_username = '';
$form_full_name = '';
$form_role = 'cashier';
$edit_user = null;

if (isset($_GET['edit'])) {
    $edit_id = validate_int($_GET['edit'], 1) ?? 0;
    if ($edit_id > 0) {
        $stmt = $pdo->prepare('SELECT id, username, full_name, email, role, email_verified FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$edit_id]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$edit_user) {
            $error = 'The requested user account was not found.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    require_post_csrf();
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'cashier';
    $form_username = $username;
    $form_full_name = $full_name;
    $form_role = in_array($role, ['admin', 'cashier'], true) ? $role : 'cashier';

    if (!$username || !$full_name || !$password) {
        $error = 'All fields are required.';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z\d]/', $password)) {
        $error = 'Password must be 8+ characters and include uppercase, lowercase, a number, and a special symbol.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                throw new RuntimeException('DUPLICATE_USERNAME');
            }

            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $full_name, $form_role]);
            $pdo->commit();
            $message = 'User added successfully!';
            $form_username = '';
            $form_full_name = '';
            $form_role = 'cashier';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getMessage() === 'DUPLICATE_USERNAME' || ($e instanceof PDOException && $e->getCode() === '23000')) {
                $error = 'Username "' . $username . '" is already taken. Please choose another username.';
            } else {
                $error = app_exception_message($e, 'We could not add this user right now. Please try again.');
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    require_post_csrf();
    $id = validate_int($_POST['user_id'] ?? null, 1) ?? 0;
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'cashier';
    $password = $_POST['password'] ?? '';

    if ($id === 0 || $username === '' || $full_name === '') {
        $error = 'Username and full name are required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!in_array($role, ['admin', 'cashier'], true)) {
        $error = 'Select a valid role.';
    } elseif ($password !== '' && (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z\d]/', $password))) {
        $error = 'A new password must be 8+ characters and include uppercase, lowercase, a number, and a special symbol.';
    } else {
        try {
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR (? <> \'\' AND email = ?)) AND id <> ? LIMIT 1');
            $duplicate->execute([$username, $email, $email, $id]);
            if ($duplicate->fetch()) {
                throw new RuntimeException('DUPLICATE_USER');
            }

            $passwordHash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
            $emailValue = $email !== '' ? $email : null;
            $stmt = $pdo->prepare(
                'UPDATE users SET username = ?, full_name = ?,
                 email_verified = IF(COALESCE(email, \'\') <> COALESCE(?, \'\'), 0, email_verified),
                 email_verification_code = IF(COALESCE(email, \'\') <> COALESCE(?, \'\'), NULL, email_verification_code),
                 email_verification_expires_at = IF(COALESCE(email, \'\') <> COALESCE(?, \'\'), NULL, email_verification_expires_at),
                 email_verification_resend_count = IF(COALESCE(email, \'\') <> COALESCE(?, \'\'), 0, email_verification_resend_count),
                 email_verification_last_sent_at = IF(COALESCE(email, \'\') <> COALESCE(?, \'\'), NULL, email_verification_last_sent_at),
                 email = ?, role = ?, password = COALESCE(?, password) WHERE id = ?'
            );
            $stmt->execute([$username, $full_name, $emailValue, $emailValue, $emailValue, $emailValue, $emailValue, $emailValue, $role, $passwordHash, $id]);
            $message = 'User account updated successfully.';
            $edit_user = null;
        } catch (Throwable $e) {
            $error = $e->getMessage() === 'DUPLICATE_USER'
                ? 'That username or email address is already in use.'
                : app_exception_message($e, 'We could not update this user right now. Please try again.');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_user'])) {
    require_post_csrf();
    $id = validate_int($_POST['user_id'] ?? null, 1) ?? 0;
    $stmt = $pdo->prepare('UPDATE users SET email_verified = 1, email_verification_code = NULL, email_verification_expires_at = NULL, email_verification_resend_count = 0, email_verification_last_sent_at = NULL WHERE id = ? AND email IS NOT NULL AND email <> \'\'');
    $stmt->execute([$id]);
    $message = $stmt->rowCount() > 0 ? 'User account verified.' : 'This user needs an email address before the account can be verified.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    require_post_csrf();
    $id = validate_int($_POST['user_id'] ?? null, 1) ?? 0;
    if ($id !== (int)($_SESSION['user_id'] ?? 0)) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    header('Location: users.php');
    exit;
}

include 'includes/header.php';
?>
<h2>User Management</h2>
<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $edit_user ? 'Edit User' : 'Add User' ?></div>
            <div class="card-body">
                <form method="POST" class="needs-validation" novalidate>
                    <?= csrf_field() ?>
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="user_id" value="<?= (int)$edit_user['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <input name="username" class="form-control" placeholder="Username" value="<?= htmlspecialchars($edit_user['username'] ?? $form_username) ?>" required>
                        <div class="invalid-feedback">Username is required.</div>
                    </div>
                    <div class="mb-2">
                        <input name="full_name" class="form-control" placeholder="Full Name" value="<?= htmlspecialchars($edit_user['full_name'] ?? $form_full_name) ?>" required>
                        <div class="invalid-feedback">Full name is required.</div>
                    </div>
                    <?php if ($edit_user): ?>
                    <div class="mb-2">
                        <input name="email" type="email" class="form-control" placeholder="Email address" value="<?= htmlspecialchars($edit_user['email'] ?? '') ?>">
                    </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <div class="input-group">
                            <input name="password" id="newUserPassword" type="password" class="form-control" placeholder="<?= $edit_user ? 'New password (optional)' : 'Password' ?>" <?= $edit_user ? '' : 'required' ?>>
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="newUserPassword" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback">Password is required.</div>
                    </div>
                    <div class="mb-2">
                        <select name="role" class="form-select">
                            <option value="cashier" <?= ($edit_user['role'] ?? $form_role) === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                            <option value="admin" <?= ($edit_user['role'] ?? $form_role) === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                    <button type="submit" name="<?= $edit_user ? 'update_user' : 'add_user' ?>" value="1" class="btn btn-primary"><?= $edit_user ? 'Save Changes' : 'Add User' ?></button>
                    <?php if ($edit_user): ?><a href="users.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Existing Users</div>
            <div class="card-body">
                <table class="table">
                    <thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Verification</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pdo->query("SELECT * FROM users ORDER BY id") as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><?= htmlspecialchars($u['role']) ?></td>
                            <td>
                                <?php if ((int)$u['email_verified'] === 1): ?>
                                    <span class="badge text-bg-success">Verified</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">Unverified</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="users.php?edit=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <?php if ((int)$u['email_verified'] !== 1): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" name="verify_user" value="1" class="btn btn-sm btn-outline-success" <?= empty($u['email']) ? 'disabled title="Add an email address before verifying this account"' : '' ?>>Verify</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($u['role'] === 'cashier'): ?>
                                    <form method="POST" action="download_recommendation.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark-pdf"></i> Recommendation PDF
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" name="delete_user" value="1" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
