<?php
require_once '../includes/security.php';

start_secure_session();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require_once '../config/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;
$invoice_no = isset($_GET['invoice_no']) ? trim((string)$_GET['invoice_no']) : '';

if ($sale_id <= 0 && $invoice_no === '') {
    echo json_encode(['success' => false, 'message' => 'Provide sale_id or invoice_no']);
    exit;
}

try {
    if ($sale_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT s.*, u.full_name AS cashier, c.first_name, c.last_name, c.phone
             FROM sales s
             JOIN users u ON s.user_id = u.id
             LEFT JOIN customers c ON s.customer_id = c.id
             WHERE s.id = ?"
        );
        $stmt->execute([$sale_id]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT s.*, u.full_name AS cashier, c.first_name, c.last_name, c.phone
             FROM sales s
             JOIN users u ON s.user_id = u.id
             LEFT JOIN customers c ON s.customer_id = c.id
             WHERE s.invoice_no = ?"
        );
        $stmt->execute([$invoice_no]);
    }

    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Sale not found']);
        exit;
    }

    $itemsStmt = $pdo->prepare(
        "SELECT si.*, p.name AS product_name, p.sku
         FROM sale_items si
         JOIN products p ON si.product_id = p.id
         WHERE si.sale_id = ?
         ORDER BY si.id ASC"
    );
    $itemsStmt->execute([(int)$sale['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'sale' => [
            'sale_id' => (int)$sale['id'],
            'invoice_no' => $sale['invoice_no'],
            'customer' => [
                'id' => $sale['customer_id'] !== null ? (int)$sale['customer_id'] : null,
                'name' => $sale['first_name'] ? trim($sale['first_name'] . ' ' . ($sale['last_name'] ?? '')) : null,
                'phone' => $sale['phone'] ?? null
            ],
            'cashier' => $sale['cashier'],
            'total_amount' => (float)$sale['total_amount'],
            'discount' => (float)$sale['discount'],
            'tax' => (float)$sale['tax'],
            'grand_total' => (float)$sale['grand_total'],
            'payment_method' => $sale['payment_method'],
            'sale_date' => $sale['sale_date']
        ],
        'items' => array_map(function($it) {
            return [
                'id' => (int)$it['id'],
                'product_id' => (int)$it['product_id'],
                'sku' => $it['sku'],
                'product_name' => $it['product_name'],
                'qty' => (int)$it['qty'],
                'unit_price' => (float)$it['unit_price'],
                'total' => (float)$it['total']
            ];
        }, $items)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    app_log('get_sale failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => app_exception_message($e, 'We could not load the sale right now. Please try again.')]);
}
