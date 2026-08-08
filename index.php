<?php
require_once 'includes/security.php';

start_secure_session();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'config/db.php';

$error = '';
$login = '';
$login_type = 'username';
$verification_message = '';
$verification_resend_email = '';
$verified_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_post_csrf();
    $login = trim($_POST['username'] ?? '');
    $login_type = in_array($_POST['login_type'] ?? 'username', ['username', 'email'], true) ? $_POST['login_type'] : 'username';
    $password = $_POST['password'] ?? '';
    require_once 'includes/functions.php';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $attemptKey = sha1(strtolower($login) . '|' . $ipAddress);

    // Database-backed rate limiting: survives cookie clearing and works
    // across multiple browser profiles/terminals behind the same IP.
    try {
        $upsert = $pdo->prepare(
            "INSERT INTO login_attempts (attempt_key, ip_address, attempt_count, locked_until)
             VALUES (?, ?, 1, NULL)
             ON DUPLICATE KEY UPDATE
               attempt_count = IF(locked_until IS NULL OR locked_until < NOW(), 1, attempt_count + 1),
               last_attempt_at = NOW()"
        );
        $upsert->execute([$attemptKey, $ipAddress]);
        $attemptRow = $pdo->prepare("SELECT attempt_count, locked_until FROM login_attempts WHERE attempt_key = ?");
        $attemptRow->execute([$attemptKey]);
        $attempt = $attemptRow->fetch(PDO::FETCH_ASSOC) ?: ['attempt_count' => 0, 'locked_until' => null];
    } catch (Throwable $e) {
        // Fall back to session tracking if the rate-limit table is unavailable.
        $attempt = ['attempt_count' => 0, 'locked_until' => null];
    }

    if ($attempt['locked_until'] !== null && strtotime($attempt['locked_until']) > time()) {
        $error = 'Too many login attempts. Please try again in a few minutes.';
    } else {
        if ($login_type === 'email' && $login !== '' && !filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $query = $login_type === 'email'
                ? 'SELECT * FROM users WHERE email = ? LIMIT 1'
                : 'SELECT * FROM users WHERE username = ? LIMIT 1';
            $stmt = $pdo->prepare($query);
            $stmt->execute([$login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (empty($user['email_verified'])) {
                    $verification_resend_email = $user['email'] ?? '';
                    $verification_message = $verification_resend_email !== ''
                        ? 'Your email address is not verified yet. Check your inbox for the 6-digit verification code.'
                        : 'Your email address is not verified yet. Please contact an administrator to update your account email address.';
                } else {
                    $previousLogin = !empty($user['last_login']) ? $user['last_login'] : null;
                    try {
                        $updateStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
                        $updateStmt->execute([$user['id']]);
                    } catch (Throwable $e) {
                        // ignore update failures
                    }

                    session_regenerate_id(true);
                    try {
                        $pdo->prepare("DELETE FROM login_attempts WHERE attempt_key = ?")->execute([$attemptKey]);
                    } catch (Throwable $e) {
                        // Rate-limit cleanup is best-effort.
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_photo'] = $user['profile_photo'] ?? null;
                    $_SESSION['last_login'] = $previousLogin ? date('Y-m-d H:i:s', strtotime($previousLogin)) : 'First login';
                    csrf_token();
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                // The upsert above already incremented the counter for this
                // attempt. Lock the key once the threshold is reached.
                $attemptCount = (int)($attempt['attempt_count'] ?? 0);
                if ($attemptCount >= 5) {
                    try {
                        $pdo->prepare("UPDATE login_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE attempt_key = ?")->execute([$attemptKey]);
                    } catch (Throwable $e) {
                        // Best-effort lock.
                    }
                }
                $error = 'Invalid username or password.';
            }
        }
    }
}
if (!empty($_GET['verified'])) {
    $verified_message = 'Your email address has been verified. Please log in.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Login</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
    <style>
        .login-logo {
            width: 96px;
            height: 96px;
            max-width: 45%;
            object-fit: contain;
            border-radius: 50% !important;
            overflow: hidden;
        }
        .app-toast-container { z-index: 1080; }
        .app-toast { min-width: 280px; box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,.18); }
        @media (max-width: 575.98px) {
            body { min-height: 100vh; }
            .card-header h4 { font-size: 1.2rem; }
            .app-toast-container { left: 0; right: 0; padding: 0.75rem !important; }
            .app-toast { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body class="bg-light">
<div class="toast-container position-fixed top-0 end-0 p-3 app-toast-container" id="appToastContainer"></div>
<div class="container-fluid px-3" style="max-width: 420px; margin-top: min(100px, 12vh);">
    <div class="card shadow">
        <div class="card-header bg-primary text-white text-center">
            <img src="assets/DELIGOS%20LOGO.png" class="login-logo bg-white p-2 mb-2" alt="Deligos Company">
            <h4>POS System Login</h4>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($verified_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($verified_message) ?></div>
            <?php endif; ?>
            <?php if ($verification_message): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($verification_message) ?>
                    <?php if ($verification_resend_email): ?>
                        <a href="verify_email.php?email=<?= urlencode($verification_resend_email) ?>" class="alert-link">Verify or resend the code</a>.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Login with</label>
                    <div class="btn-group w-100" role="group" aria-label="Login method">
                        <input type="radio" class="btn-check" name="login_type" id="loginTypeUsername" value="username" <?= $login_type === 'username' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="loginTypeUsername">Username</label>
                        <input type="radio" class="btn-check" name="login_type" id="loginTypeEmail" value="email" <?= $login_type === 'email' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="loginTypeEmail">Email</label>
                    </div>
                </div>
                <div class="mb-3">
                    <label id="loginFieldLabel">Username</label>
                    <input type="text" name="username" id="loginField" class="form-control" placeholder="Username" value="<?= htmlspecialchars($login) ?>" required autofocus>
                    <div class="invalid-feedback">Username is required.</div>
                </div>
                <div class="mb-3">
                    <label>Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="loginPassword" class="form-control password-input" data-hint-id="loginPasswordHint" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="loginPassword" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback">Password is required.</div>
                    <div class="form-text text-muted mt-1 password-hint" id="loginPasswordHint" style="display:none;">Password must be 8+ characters, include uppercase, lowercase, a number, and a special symbol.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
            <div class="mt-3 text-center">
                <a href="forgot_password.php" class="small">Forgot password?</a>
            </div>
            <div class="mt-3 text-center">
                <span class="text-muted small">Don't have an account?</span>
                <a href="register.php" class="small">Register</a>
            </div>
        </div>
    </div>
</div>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
<script>
const loginTypeRadios = document.querySelectorAll('input[name="login_type"]');
const loginField = document.getElementById('loginField');
const loginFieldLabel = document.getElementById('loginFieldLabel');
const loginFieldFeedback = loginField ? loginField.parentElement.querySelector('.invalid-feedback') : null;

function updateLoginFieldMode() {
    const selected = document.querySelector('input[name="login_type"]:checked')?.value || 'username';
    if (!loginField) {
        return;
    }

    if (selected === 'email') {
        loginField.type = 'email';
        loginField.placeholder = 'Email';
        loginFieldLabel.textContent = 'Email';
        if (loginFieldFeedback) {
            loginFieldFeedback.textContent = 'A valid email is required.';
        }
    } else {
        loginField.type = 'text';
        loginField.placeholder = 'Username';
        loginFieldLabel.textContent = 'Username';
        if (loginFieldFeedback) {
            loginFieldFeedback.textContent = 'Username is required.';
        }
    }
}

loginTypeRadios.forEach(radio => radio.addEventListener('change', updateLoginFieldMode));
updateLoginFieldMode();
</script>
</body>
</html>
