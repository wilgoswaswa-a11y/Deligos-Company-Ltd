<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lipana.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are supported.']);
    exit;
}

require_post_csrf();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? ($_POST['action'] ?? '');

if ($action === 'initiate_payment') {
    $phone = (string)($input['phone_number'] ?? ($_POST['phone_number'] ?? ''));
    $accountReference = (string)($input['account_reference'] ?? ($_POST['account_reference'] ?? 'POS'));
    $amount = (string)($input['amount'] ?? ($_POST['amount'] ?? 0));

    $result = lipana_stk_push([
        'amount' => $amount,
        'phone_number' => $phone,
        'account_reference' => $accountReference,
        'transaction_desc' => (string)($input['transaction_desc'] ?? ($_POST['transaction_desc'] ?? 'POS payment')),
        'callback_url' => (string)($input['callback_url'] ?? ($_POST['callback_url'] ?? '')),
    ]);

    // Persist the server-side payment request so the sale can only be
    // completed against a request that was actually initiated.
    if (!empty($result['success'])) {
        $payloadToken = record_lipana_payment_request(
            $pdo,
            (int)$_SESSION['user_id'],
            $accountReference,
            normalize_lipana_phone($phone),
            (float)$amount,
            trim((string)($result['transaction_id'] ?? '')),
            trim((string)($result['checkout_request_id'] ?? ''))
        );
        if ($payloadToken === false) {
            $result['success'] = false;
            $result['message'] = 'The payment was initiated but could not be recorded. Do not complete this sale; contact an administrator.';
        } else {
            $result['payload_token'] = $payloadToken;
        }
    }

    echo json_encode($result);
    exit;
}

if ($action === 'verify_payment') {
    $invoiceNo = trim((string)($input['invoice_no'] ?? ($_POST['invoice_no'] ?? '')));
    $payloadToken = trim((string)($input['payload_token'] ?? ($_POST['payload_token'] ?? '')));
    $request = get_lipana_payment_request($pdo, $invoiceNo, $payloadToken);

    if (!$request || (int)$request['user_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment request not found.']);
        exit;
    }

    $verified = $request['status'] === 'completed';
    echo json_encode([
        'success' => true,
        'verified' => $verified,
        'status' => $request['status'],
        'message' => $verified ? 'Payment verified successfully.' : 'Payment is still awaiting Lipana webhook confirmation. Complete the prompt on the customer phone, then verify again.',
        'mpesa_code' => $request['mpesa_code'] ?: null,
        'customer_phone' => $request['customer_phone'] ?: $request['phone_number'],
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown Lipana action.']);
