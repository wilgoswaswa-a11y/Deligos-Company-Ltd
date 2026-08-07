<?php

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user_role(): string
{
    return $_SESSION['role'] ?? 'cashier';
}

function require_role(string $role): void
{
    if (current_user_role() !== $role) {
        http_response_code(403);
        header('Location: dashboard.php');
        exit;
    }
}

const CSRF_TOKEN_MAX_AGE = 7200;

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_expires']) || (int)$_SESSION['csrf_token_expires'] < time()) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_expires'] = time() + CSRF_TOKEN_MAX_AGE;
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function request_csrf_token(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'x-csrf-token') {
            return (string)$value;
        }
    }

    return (string)($_POST['csrf_token'] ?? '');
}

function verify_csrf_request(): bool
{
    $token = request_csrf_token();
    return $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf_request()) {
        http_response_code(403);
        exit('Invalid request token.');
    }
}

function app_log_file(): string
{
    return __DIR__ . '/../logs/app.log';
}

function app_log(string $message): void
{
    $dir = dirname(app_log_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = app_log_file();

    // Keep the shared application log capped: rotate at 5 MB, keep at most
    // one rotated file. Prevents unbounded growth in long-running installs.
    if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
        $rotated = $file . '.1';
        @rename($file, $rotated);
        if (is_file($rotated) && filesize($rotated) > 10 * 1024 * 1024) {
            @unlink($rotated);
        }
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    @error_log($line);
}

function validate_decimal($value, float $min = 0.0, ?float $max = null): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $number = round((float)$value, 2);
    if ($number < $min || ($max !== null && $number > $max)) {
        return null;
    }
    return $number;
}

function validate_int($value, int $min = 0, ?int $max = null): ?int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }
    $number = (int)$value;
    if ($number < $min || ($max !== null && $number > $max)) {
        return null;
    }
    return $number;
}
