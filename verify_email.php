<?php
require_once 'includes/security.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/mail.php';

start_secure_session();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';
$email = trim($_GET['email'] ?? $_SESSION['pending_verify_email'] ?? '');
$code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $email = trim($_POST['email'] ?? $email);
    $code = trim($_POST['code'] ?? '');

    if (isset($_POST['resend_code'])) {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address to resend the verification code.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, full_name, email_verification_resend_count, email_verification_last_sent_at FROM users WHERE email = ? AND email_verified = 0 LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $error = 'No unverified account was found for that email address.';
                } else {
                    $now = new DateTime();
                    $lastSent = $user['email_verification_last_sent_at'] ? new DateTime($user['email_verification_last_sent_at']) : null;
                    $resendCount = (int)($user['email_verification_resend_count'] ?? 0);
                    $cooldownSeconds = 300;
                    $windowSeconds = 3600;
                    $maxResends = 5;

                    if ($lastSent !== null && $now->getTimestamp() - $lastSent->getTimestamp() < $cooldownSeconds) {
                        $remaining = $cooldownSeconds - ($now->getTimestamp() - $lastSent->getTimestamp());
                        $error = 'Please wait another ' . ceil($remaining / 60) . ' minute(s) before requesting a new code.';
                    } elseif ($lastSent !== null && $now->getTimestamp() - $lastSent->getTimestamp() < $windowSeconds && $resendCount >= $maxResends) {
                        $error = 'You have reached the maximum number of resend attempts. Please try again in an hour.';
                    } else {
                        $verificationCode = generate_email_verification_code();
                        $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
                        if ($lastSent === null || $now->getTimestamp() - $lastSent->getTimestamp() > $windowSeconds) {
                            $resendCount = 1;
                        } else {
                            $resendCount++;
                        }

                        $stmt = $pdo->prepare('UPDATE users SET email_verification_code = ?, email_verification_expires_at = ?, email_verification_resend_count = ?, email_verification_last_sent_at = ? WHERE id = ?');
                        $stmt->execute([$verificationCode, $expiresAt, $resendCount, $now->format('Y-m-d H:i:s'), $user['id']]);

                        if (send_email_verification_code($email, $user['full_name'], $verificationCode)) {
                            $_SESSION['pending_verify_email'] = $email;
                            $success = 'A new verification code has been sent to your email address.';
                        } else {
                            $error = 'Unable to resend the verification code right now. Please try again later.';
                        }
                    }
                }
            } catch (Throwable $e) {
                $error = app_exception_message($e, 'We could not resend the verification code right now. Please try again later.');
            }
        }
    } else {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($code === '' || !preg_match('/^[0-9]{6}$/', $code)) {
            $error = 'Please enter the 6-digit verification code.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND email_verification_code = ? AND email_verification_expires_at > NOW() AND email_verified = 0 LIMIT 1');
                $stmt->execute([$email, $code]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $stmt = $pdo->prepare('UPDATE users SET email_verified = 1, email_verification_code = NULL, email_verification_expires_at = NULL, email_verification_resend_count = 0, email_verification_last_sent_at = NULL WHERE id = ?');
                    $stmt->execute([$user['id']]);
                    unset($_SESSION['pending_verify_email']);
                    // Redirect to login page so the user can sign in immediately
                    header('Location: index.php?verified=1');
                    exit;
                } else {
                    $error = 'The verification code is invalid or has expired. Request a new code if needed.';
                }
            } catch (Throwable $e) {
                $error = app_exception_message($e, 'We could not verify your email right now. Please try again later.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid px-3" style="max-width: 460px; margin-top: min(70px, 8vh);">
    <div class="card shadow">
        <div class="card-header bg-primary text-white text-center">
            <h4>Email Verification</h4>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <p class="mb-3">Enter the 6-digit code sent to your email to complete registration.</p>
            <form method="POST" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
                    <div class="invalid-feedback">A valid email address is required.</div>
                </div>
                <div class="mb-3">
                    <label for="code" class="form-label">Verification Code</label>
                    <input type="text" id="code" name="code" class="form-control" value="<?= htmlspecialchars($code) ?>" minlength="6" maxlength="6" required>
                    <div class="invalid-feedback">Please enter the 6-digit verification code.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Verify Email</button>
            </form>
            <form method="POST" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <button type="submit" name="resend_code" value="1" class="btn btn-outline-secondary w-100">Resend Code</button>
            </form>
            <div class="mt-3 text-center">
                <a href="index.php" class="small">Back to login</a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>
</body>
</html>
