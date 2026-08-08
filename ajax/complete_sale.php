<?php
require_once '../includes/security.php';

start_secure_session();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
if (!verify_csrf_request()) { http_response_code(403); exit; }

require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/lipana.php';

$data = json_decode(file_get_contents('php://input'), true);
app_log('Sale complete request: invoice=' . ($data['invoice_no'] ?? 'none') . ' user=' . $_SESSION['user_id'] . ' items=' . (is_array($data['items'] ?? null) ? count($data['items']) : 0));
if (!$data || empty($data['items'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => app_error_message('No items', 'Please add at least one item before completing the sale.')]);
    exit;
}

$invoice_no = preg_match('/^INV-\d{8}-[A-Fa-f0-9]{6}$/', (string)($data['invoice_no'] ?? '')) ? (string)$data['invoice_no'] : generate_invoice_no();
$customer_id = !empty($data['customer_id']) ? validate_int($data['customer_id'], 1) : null;
if (!empty($data['customer_id']) && $customer_id === null) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => app_error_message('Invalid customer', 'The selected customer could not be validated.')]);
    exit;
}
$discount = validate_decimal($data['discount'] ?? 0, 0);
if ($discount === null) {
    $discount = 0;
}
$payment_method = in_array((string)($data['payment_method'] ?? 'Cash'), ['Cash', 'Lipana'], true)
    ? (string)($data['payment_method'] ?? 'Cash')
    : 'Cash';
$payload_token = isset($data['payload_token']) ? trim((string)$data['payload_token']) : null;
$user_id = $_SESSION['user_id'];

$total_amount = 0;
foreach ($data['items'] as $item) {
    $qty = validate_int($item['qty'] ?? null, 1);
    $productId = validate_int($item['product_id'] ?? null, 1);
    if ($qty === null || $productId === null) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => app_error_message('Invalid sale item', 'One or more cart items are invalid.')]);
        exit;
    }
}

try {
    // validate customer_id if provided
    if ($customer_id !== null) {
        $cstmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
        $cstmt->execute([$customer_id]);
        if (!$cstmt->fetch()) {
            throw new RuntimeException('Invalid customer id: ' . $customer_id);
        }
    }

    $pdo->beginTransaction();
    app_log('Transaction started for invoice: ' . $invoice_no . ', customer: ' . $customer_id . ', user: ' . $user_id);

    // ensure invoice_no uniqueness (fail early with explicit message)
    $istmt = $pdo->prepare("SELECT id FROM sales WHERE invoice_no = ?");
    $istmt->execute([$invoice_no]);
    if ($istmt->fetch()) {
        throw new RuntimeException('Duplicate invoice_no: ' . $invoice_no);
    }

    $saleItems = [];
    foreach ($data['items'] as $item) {
        $product_id = (int)$item['product_id'];
        $qty = (int)$item['qty'];

        // Fetch authoritative product price and current stock with row lock
        $pstmt = $pdo->prepare("SELECT price, stock_qty FROM products WHERE id = ? FOR UPDATE");
        $pstmt->execute([$product_id]);
        $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            throw new RuntimeException('Product not found: ' . $product_id);
        }
        if ((int)$prod['stock_qty'] < $qty) {
            throw new RuntimeException('Insufficient stock for product id ' . $product_id);
        }

        $unit_price = (float)$prod['price'];
        $line_total = $unit_price * $qty;
        $total_amount += $line_total;
        $saleItems[] = [
            'product_id' => $product_id,
            'qty' => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
        ];
    }

    if ($discount > $total_amount) {
        throw new RuntimeException('Discount cannot be greater than subtotal.');
    }

    // Enforce the discount policy: admin-configurable cap as a percentage of
    // the subtotal (0 disables discounts entirely).
    $maxPercent = max_discount_percent();
    if ($discount > 0 && ($maxPercent <= 0 || $discount / $total_amount > $maxPercent / 100)) {
        throw new RuntimeException('The applied discount exceeds the allowed limit for this sale.');
    }

    $grand_total = $total_amount - $discount;

    // Lipana sales must be backed by a server-side payment request with a
    // matching payload token. The client cannot self-verify.
    $mpesa_code = null;
    if ($payment_method === 'Lipana') {
        if ($payload_token === null || !is_lipana_payment_verified($pdo, $invoice_no, $grand_total, $payload_token)) {
            throw new RuntimeException('This Lipana payment has not been verified. Send the payment prompt for this exact sale before completing it.');
        }

        $paymentRequest = get_lipana_payment_request($pdo, $invoice_no, $payload_token);
        if (!$paymentRequest) {
            throw new RuntimeException('Unable to locate the Lipana payment request for this sale.');
        }

        $mpesa_code = trim((string)($paymentRequest['mpesa_code'] ?? '')) ?: null;
        if ($paymentRequest['status'] === 'initiated') {
            $transactionId = trim((string)($paymentRequest['transaction_id'] ?? ''));
            if ($transactionId === '') {
                throw new RuntimeException('Cannot verify Lipana payment because the transaction reference is missing.');
            }

            $verification = lipana_fetch_transaction_by_id($transactionId);
            if (!$verification['success'] || !is_array($verification['response']['data'] ?? null)) {
                throw new RuntimeException('Unable to confirm Lipana payment. Please try again later.');
            }

            $transaction = $verification['response']['data'];
            if (!lipana_transaction_is_successful($transaction, $grand_total)) {
                throw new RuntimeException('The Lipana payment is not completed or does not match the sale amount.');
            }

            if (!update_lipana_payment_request_verification($pdo, $invoice_no, $payload_token, $transaction)) {
                throw new RuntimeException('Lipana payment verification succeeded, but the system could not mark the request as completed.');
            }

            $mpesa_code = lipana_transaction_mpesa_code($transaction) ?: $mpesa_code;
            $paymentRequest = get_lipana_payment_request($pdo, $invoice_no, $payload_token) ?: $paymentRequest;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO sales (invoice_no, user_id, customer_id, total_amount, discount, grand_total, payment_method)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$invoice_no, $user_id, $customer_id, $total_amount, $discount, $grand_total, $payment_method]);
    $sale_id = $pdo->lastInsertId();

    foreach ($saleItems as $item) {
        $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, qty, unit_price, total)
                                VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$sale_id, $item['product_id'], $item['qty'], $item['unit_price'], $item['line_total']]);

        // stock deduction + log (updateStock will not start a nested transaction)
        if (!updateStock($pdo, $item['product_id'], -$item['qty'], $user_id, 'sale', 'Sale #' . $invoice_no)) {
            throw new RuntimeException('Failed to update stock for product ' . $item['product_id']);
        }
    }

    // Mark the Lipana request as paid if this was a Lipana sale.
    if ($payment_method === 'Lipana' && $payload_token !== null) {
        confirm_lipana_payment($pdo, $invoice_no, $payload_token);
    }

    $pdo->commit();
    app_log('SUCCESS: Sale committed. Sale ID: ' . $sale_id . ', Invoice: ' . $invoice_no);

    // Audit any discount applied so discount abuse is traceable.
    if ($discount > 0) {
        $auditStmt = $pdo->prepare("SELECT total_amount, discount, grand_total FROM sales WHERE id = ?");
        $auditStmt->execute([$sale_id]);
        $auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);
        audit_log('update', 'sales', $sale_id, null, $auditRow ?: [], 'Customer discount applied');
    }

    // Build a receipt snapshot and store it (non-blocking for client)
    try {
        $itemsStmt = $pdo->prepare(
            "SELECT si.qty, si.unit_price, si.total, p.name AS product_name, p.sku
             FROM sale_items si
             JOIN products p ON si.product_id = p.id
             WHERE si.sale_id = ? ORDER BY si.id ASC"
        );
        $itemsStmt->execute([(int)$sale_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $snapshot = [
            'sale_id' => (int)$sale_id,
            'invoice_no' => $invoice_no,
            'user_id' => $user_id,
            'customer_id' => $customer_id,
            'payment_method' => $payment_method,
            'mpesa_code' => $mpesa_code,
            'mpesa_customer_name' => null,
            'mpesa_customer_phone' => $paymentRequest['customer_phone'] ?? null,
            'items' => array_map(function($it){
                return [
                    'name' => $it['product_name'],
                    'sku' => $it['sku'],
                    'qty' => (int)$it['qty'],
                    'unit_price' => (float)$it['unit_price'],
                    'total' => (float)$it['total']
                ];
            }, $items),
            'total_amount' => (float)$total_amount,
            'discount' => (float)$discount,
            'grand_total' => (float)$grand_total,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // store_receipt_snapshot returns receipt id or false
        $receipt_id = store_receipt_snapshot((int)$sale_id, $snapshot);
    } catch (Throwable $e) {
        app_log('WARNING: Failed to store receipt snapshot: ' . $e->getMessage());
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'invoice_no' => $invoice_no, 'sale_id' => (int)$sale_id, 'receipt_id' => isset($receipt_id) ? $receipt_id : null, 'mpesa_code' => $mpesa_code, 'mpesa_customer_name' => null, 'mpesa_customer_phone' => $paymentRequest['customer_phone'] ?? null]);
    exit;
} catch (Throwable $e) {
    app_log('ERROR: Sale completion failed: ' . $e->getMessage());
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => app_exception_message($e, 'We could not complete the sale right now. Please try again.')]);
    exit;
}
