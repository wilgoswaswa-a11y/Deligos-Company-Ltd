<?php
require_once 'includes/security.php';
require_once 'includes/functions.php';
require_once 'includes/mail.php';

start_secure_session();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'config/db.php';

$message = '';
$error = '';
$full_name = '';
$username = '';
$phone = '';
$id_number = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '' || $username === '' || $phone === '' || $id_number === '' || $email === '' || $password === '' || $confirm_password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'Password must include at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'Password must include at least one lowercase letter.';
    } elseif (!preg_match('/\d/', $password)) {
        $error = 'Password must include at least one number.';
    } elseif (!preg_match('/[^a-zA-Z\d]/', $password)) {
        $error = 'Password must include at least one special character.';
    } elseif ($password !== $confirm_password) {
        $error = 'Password and confirmation do not match.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT username, email, id_number FROM users WHERE username = ? OR email = ? OR id_number = ? LIMIT 1");
            $stmt->execute([$username, $email, $id_number]);
            $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_user && $existing_user['username'] === $username) {
                $error = 'That username is already registered.';
            } elseif ($existing_user && $existing_user['email'] === $email) {
                $error = 'That email is already registered.';
            } elseif ($existing_user && $existing_user['id_number'] === $id_number) {
                $error = 'That ID number is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $verificationCode = generate_email_verification_code();
                $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, phone, id_number, email, role, email_verified, email_verification_code, email_verification_expires_at) VALUES (?, ?, ?, ?, ?, ?, 'cashier', 0, ?, ?)");
                $stmt->execute([$username, $hash, $full_name, $phone, $id_number, $email, $verificationCode, $expiresAt]);
                $userId = $pdo->lastInsertId();

                $sent = send_email_verification_code($email, $full_name, $verificationCode);
                if ($sent) {
                    $stmt = $pdo->prepare('UPDATE users SET email_verification_resend_count = 1, email_verification_last_sent_at = NOW() WHERE id = ?');
                    $stmt->execute([$userId]);
                    $_SESSION['pending_verify_email'] = $email;
                    header('Location: check_email.php');
                    exit;
                }

                $message = 'Account created successfully, but we could not send the verification code. Please try again later.';
                $full_name = '';
                $username = '';
                $phone = '';
                $id_number = '';
                $email = '';
            }
        } catch (Throwable $e) {
            $error = app_exception_message($e, 'We could not complete registration right now. Please try again later.');
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Register</title>
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
        .required-mark { color: #dc3545; }
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
<div class="container-fluid px-3" style="max-width: 460px; margin-top: min(70px, 8vh);">
    <div class="card shadow">
        <div class="card-header bg-primary text-white text-center">
            <img src="assets/DELIGOS%20LOGO.png" class="login-logo bg-white p-2 mb-2" alt="Deligos Company">
            <h4>Create Account</h4>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label>Full Name <span class="required-mark">*</span></label>
                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($full_name) ?>" required autofocus>
                    <div class="invalid-feedback">Full name is required.</div>
                </div>
                <div class="mb-3">
                    <label>Username <span class="required-mark">*</span></label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($username) ?>" required>
                    <div class="invalid-feedback">Username is required.</div>
                </div>
                <div class="mb-3">
                    <label>Phone Number <span class="required-mark">*</span></label>
                    <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>" required>
                    <div class="invalid-feedback">Phone number is required.</div>
                </div>
                <div class="mb-3">
                    <label>ID Number <span class="required-mark">*</span></label>
                    <input type="text" name="id_number" class="form-control" value="<?= htmlspecialchars($id_number) ?>" required>
                    <div class="invalid-feedback">ID number is required.</div>
                </div>
                <div class="mb-3">
                    <label>Email <span class="required-mark">*</span></label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
                    <div class="invalid-feedback">A valid email is required.</div>
                </div>
                <div class="mb-3">
                    <label>Password <span class="required-mark">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password" id="registerPassword" class="form-control password-input" data-hint-id="registerPasswordHint" minlength="8" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="registerPassword" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback">Password is required.</div>
                    <div class="form-text text-muted mt-1 password-hint" id="registerPasswordHint" style="display:none;">Use at least 8 characters, with uppercase, lowercase, a number, and a special character.</div>
                </div>
                <div class="mb-3">
                    <label>Confirm Password <span class="required-mark">*</span></label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control password-input" data-hint-id="confirmPasswordHint" minlength="8" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirmPassword" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback">Please confirm your password.</div>
                    <div class="form-text text-muted mt-1 password-hint" id="confirmPasswordHint" style="display:none;">Re-enter the same password to confirm it.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Register</button>
            </form>
            <div class="mt-3 text-center">
                <span class="text-muted small">Already have an account?</span>
                <a href="index.php" class="small">Login</a>
            </div>
        </div>
    </div>
</div>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
