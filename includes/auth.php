<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../config/db.php';

start_secure_session();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Authorize using the current database role. Session role values are refreshed
// only after this lookup so a role change takes effect on the next request.
if (!$pdo) {
    http_response_code(503);
    exit('Unable to validate the current user.');
}

$roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
$roleStmt->execute([$user_id]);
$role = $roleStmt->fetchColumn();

if ($role === false) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$role = (string) $role;
$_SESSION['role'] = $role;

// Optional: restrict admin pages
if (isset($required_role) && $required_role === 'admin' && $role !== 'admin') {
    http_response_code(403);
    header('Location: ../dashboard.php');
    exit;
}

