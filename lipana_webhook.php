<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/lipana.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}

$rawPayload = file_get_contents('php://input');
app_log('Lipana webhook payload: ' . substr($rawPayload, 0, 2000));

$signature = lipana_extract_webhook_signature();
if (!lipana_verify_webhook_signature($rawPayload, $signature)) {
    app_log('Lipana webhook signature verification failed. Signature: ' . $signature);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid webhook signature.']);
    exit;
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    app_log('Lipana webhook payload is not valid JSON.');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

$data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
$transactionId = trim((string)($data['transactionId'] ?? $data['transaction_id'] ?? ''));
$checkoutRequestId = trim((string)($data['checkoutRequestID'] ?? $data['checkout_request_id'] ?? ''));
$status = strtolower(trim((string)($data['status'] ?? $data['paymentStatus'] ?? '')));
$amount = isset($data['amount']) ? (float)$data['amount'] : null;

if ($transactionId === '' && $checkoutRequestId === '') {
    app_log('Lipana webhook missing identifiers: ' . json_encode([$transactionId, $checkoutRequestId]));
    echo json_encode(['success' => false, 'message' => 'Missing transaction identifiers.']);
    exit;
}

$request = get_lipana_payment_request_by_identifiers($pdo, $transactionId, $checkoutRequestId);
if (!$request) {
    app_log('Lipana webhook did not find a matching payment request for transaction_id=' . $transactionId . ' checkout_request_id=' . $checkoutRequestId);
    echo json_encode(['success' => true, 'message' => 'No matching payment request found.']);
    exit;
}

if (!empty($transactionId) || !empty($checkoutRequestId)) {
    update_lipana_payment_request_identifiers(
        $pdo,
        $request['invoice_no'],
        $request['payload_token'],
        $transactionId ?: null,
        $checkoutRequestId ?: null
    );
}

$successfulStatuses = ['success', 'completed', 'paid'];
$failedStatuses = ['failed', 'declined', 'cancelled', 'cancelled', 'error', 'rejected', 'timeout'];

if (in_array($status, $successfulStatuses, true)) {
    if ($amount !== null && abs($amount - (float)$request['amount']) > 0.01) {
        app_log('Lipana webhook amount mismatch for invoice ' . $request['invoice_no'] . ': expected ' . $request['amount'] . ', got ' . $amount);
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Payment amount does not match the request.']);
        exit;
    }
    update_lipana_payment_request_verification($pdo, $request['invoice_no'], $request['payload_token'], $data);
    echo json_encode(['success' => true, 'message' => 'Payment request marked completed.']);
    exit;
}

if (in_array($status, $failedStatuses, true)) {
    fail_lipana_payment($pdo, $request['invoice_no']);
    echo json_encode(['success' => true, 'message' => 'Payment request marked failed.']);
    exit;
}

app_log('Lipana webhook received unknown status for invoice ' . $request['invoice_no'] . ': ' . $status);
echo json_encode(['success' => true, 'message' => 'Webhook received. Status not actionable.']);
