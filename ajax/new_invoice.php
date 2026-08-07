<?php
require_once '../includes/security.php';

start_secure_session();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

require_once '../config/db.php';
require_once '../includes/functions.php';

$invoiceNo = generate_invoice_no();
$attempts = 0;
while ($attempts < 4) {
    $stmt = $pdo->prepare('SELECT id FROM sales WHERE invoice_no = ?');
    $stmt->execute([$invoiceNo]);
    if (!$stmt->fetch()) {
        break;
    }
    $invoiceNo = generate_invoice_no();
    $attempts++;
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'invoice_no' => $invoiceNo,
]);
