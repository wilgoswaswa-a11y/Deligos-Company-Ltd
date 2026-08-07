<?php
require_once __DIR__ . '/env.php';

function normalize_text(string $input): string
{
    return trim($input);
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function validate_password_strength(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^a-zA-Z\d]/', $password)) {
        return 'Password must include at least one special character.';
    }
    return null;
}

function app_error_message(string $message, string $fallback = 'Something went wrong. Please try again later.'): string
{
    $message = trim($message);
    if ($message === '') {
        return $fallback;
    }

    if (stripos($message, 'duplicate') !== false || stripos($message, 'already exists') !== false || stripos($message, 'already taken') !== false || stripos($message, 'already in use') !== false) {
        return 'A matching record already exists. Please review the information and try again.';
    }

    if (stripos($message, 'database') !== false || stripos($message, 'sqlstate') !== false || stripos($message, 'pdoexception') !== false) {
        return 'We could not complete the request because of a database issue. Please try again later.';
    }

    if (stripos($message, 'upload') !== false) {
        return 'The uploaded file could not be processed. Please try another file.';
    }

    return $message;
}

function app_exception_message(Throwable $e, string $fallback = 'Something went wrong. Please try again later.'): string
{
    return app_error_message($e->getMessage(), $fallback);
}

function cleanup_stale_unverified_accounts(PDO $pdo, int $days = 7): int
{
    $stmt = $pdo->prepare(
        'DELETE FROM users WHERE email_verified = 0 AND email_verification_expires_at IS NOT NULL AND email_verification_expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

function generateInvoiceNo() {
    // The sales.invoice_no column is varchar(20). This format is 19 characters:
    // INV-YYYYMMDD-XXXXXX.
    return 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Server-authoritative invoice number. Same shape as generateInvoiceNo();
 * kept as a separate, descriptive name for endpoints that mint numbers.
 */
function generate_invoice_no(): string
{
    return 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Maximum discount allowed as a percentage of the sale subtotal.
 * Reads MAX_DISCOUNT_PERCENT from the environment; 0-100.
 * A value of 0 means discounts are disabled entirely.
 */
function max_discount_percent(): float
{
    $value = (float)(env('MAX_DISCOUNT_PERCENT') ?: 0);
    return ($value >= 0 && $value <= 100) ? $value : 0.0;
}

/**
 * Verify that a Lipana payment request exists server-side for this invoice
 * and matches the amount being completed.
 *
 * @param PDO $pdo
 * @param string $invoice_no
 * @param float $amount
 * @param string|null $payload_token Session-bound token returned when the STK push was initiated.
 * @return bool
 */
function is_lipana_payment_verified(PDO $pdo, string $invoice_no, float $amount, ?string $payload_token = null): bool
{
    $request = get_lipana_payment_request($pdo, $invoice_no, $payload_token);
    if (!$request) {
        return false;
    }
    if (abs((float)$request['amount'] - $amount) > 0.01) {
        return false;
    }

    if (in_array($request['status'], ['completed'], true)) {
        return true;
    }

    // Lipana's STK response provides a public TXN reference, while its
    // /transactions/{id} endpoint currently expects an internal ObjectId.
    // Do not accept a client-side reference or a failed lookup as proof of
    // payment. The signed webhook is the authoritative confirmation path.
    return false;
}

/**
 * Record a successfully initiated Lipana STK push and return a payment token
 * that must be presented when the sale is completed.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $invoice_no
 * @param string $phone_number
 * @param float $amount
 * @return string|false Payload token, or false on failure.
 */
function record_lipana_payment_request(PDO $pdo, int $user_id, string $invoice_no, string $phone_number, float $amount, ?string $transaction_id = null, ?string $checkout_request_id = null)
{
    try {
        $pdo->prepare("DELETE FROM lipana_payment_requests WHERE invoice_no = ?")->execute([$invoice_no]);
        $token = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare(
            "INSERT INTO lipana_payment_requests (user_id, invoice_no, phone_number, amount, status, payload_token, transaction_id, checkout_request_id)
             VALUES (?, ?, ?, ?, 'initiated', ?, ?, ?)"
        );
        $stmt->execute([$user_id, $invoice_no, $phone_number, $amount, $token, $transaction_id, $checkout_request_id]);
        return $token;
    } catch (Throwable $e) {
        app_log('record_lipana_payment_request failed: ' . $e->getMessage());
        return false;
    }
}

function get_lipana_payment_request(PDO $pdo, string $invoice_no, ?string $payload_token = null): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM lipana_payment_requests
         WHERE invoice_no = ? AND (? IS NULL OR payload_token = ?)
         LIMIT 1"
    );
    $stmt->execute([$invoice_no, $payload_token, $payload_token]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    return $request ?: null;
}

function get_lipana_payment_request_by_identifiers(PDO $pdo, ?string $transaction_id = null, ?string $checkout_request_id = null): ?array
{
    if (empty($transaction_id) && empty($checkout_request_id)) {
        return null;
    }

    $query = "SELECT * FROM lipana_payment_requests WHERE ";
    $params = [];
    $clauses = [];

    if (!empty($transaction_id)) {
        $clauses[] = 'transaction_id = ?';
        $params[] = $transaction_id;
    }
    if (!empty($checkout_request_id)) {
        $clauses[] = 'checkout_request_id = ?';
        $params[] = $checkout_request_id;
    }

    $query .= implode(' OR ', $clauses) . ' LIMIT 1';
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    return $request ?: null;
}

function update_lipana_payment_request_identifiers(PDO $pdo, string $invoice_no, string $payload_token, ?string $transaction_id = null, ?string $checkout_request_id = null): bool
{
    try {
        $fields = [];
        $params = [];

        if (!empty($transaction_id)) {
            $fields[] = 'transaction_id = ?';
            $params[] = $transaction_id;
        }
        if (!empty($checkout_request_id)) {
            $fields[] = 'checkout_request_id = ?';
            $params[] = $checkout_request_id;
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $invoice_no;
        $params[] = $payload_token;

        $stmt = $pdo->prepare(
            "UPDATE lipana_payment_requests SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE invoice_no = ? AND payload_token = ? LIMIT 1"
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        app_log('update_lipana_payment_request_identifiers failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Confirm a Lipana payment request for the given invoice as paid.
 *
 * @param PDO $pdo
 * @param string $invoice_no
 * @param string $payload_token
 * @return bool
 */
function confirm_lipana_payment(PDO $pdo, string $invoice_no, string $payload_token): bool
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE lipana_payment_requests SET status = 'completed', completed_at = NOW(), updated_at = NOW()
             WHERE invoice_no = ? AND payload_token = ? AND status IN ('initiated', 'completed')"
        );
        $stmt->execute([$invoice_no, $payload_token]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        app_log('confirm_lipana_payment failed: ' . $e->getMessage());
        return false;
    }
}

/** Persist the provider-confirmed payer and M-Pesa receipt details. */
function update_lipana_payment_request_verification(PDO $pdo, string $invoice_no, string $payload_token, array $transaction): bool
{
    try {
        $customer = lipana_transaction_customer($transaction);
        $stmt = $pdo->prepare(
            "UPDATE lipana_payment_requests
             SET status = 'completed', completed_at = NOW(),
                 transaction_id = COALESCE(NULLIF(?, ''), transaction_id),
                 checkout_request_id = COALESCE(NULLIF(?, ''), checkout_request_id),
                 mpesa_code = COALESCE(NULLIF(?, ''), mpesa_code),
                 customer_name = COALESCE(NULLIF(?, ''), customer_name),
                 customer_phone = COALESCE(NULLIF(?, ''), customer_phone),
                 updated_at = NOW()
             WHERE invoice_no = ? AND payload_token = ? AND status IN ('initiated', 'completed')"
        );
        $stmt->execute([
            trim((string)($transaction['transactionId'] ?? $transaction['transaction_id'] ?? '')),
            trim((string)($transaction['checkoutRequestID'] ?? $transaction['checkout_request_id'] ?? '')),
            lipana_transaction_mpesa_code($transaction) ?? '',
            $customer['name'] ?? '',
            $customer['phone'] ?? '',
            $invoice_no,
            $payload_token,
        ]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        app_log('update_lipana_payment_request_verification failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Mark a Lipana payment request as failed so it cannot be used to complete a sale.
 */
function fail_lipana_payment(PDO $pdo, string $invoice_no): void
{
    try {
        $pdo->prepare("UPDATE lipana_payment_requests SET status = 'failed', updated_at = NOW() WHERE invoice_no = ? AND status = 'initiated'")
            ->execute([$invoice_no]);
    } catch (Throwable $e) {
        app_log('fail_lipana_payment failed: ' . $e->getMessage());
    }
}

function getProductById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateStock($pdo, $product_id, $qty_change, $user_id, $type, $note = '') {
    // Avoid opening nested transactions. If an outer transaction exists, rely on it.
    $manageTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $manageTx = true;
    }
    try {
        // Update product stock and make sure the row exists.
        $stmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
        $stmt->execute([$qty_change, $product_id]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('Failed to update stock for product id ' . $product_id);
        }

        // Log the change
        $stmt = $pdo->prepare("INSERT INTO inventory_logs (product_id, user_id, qty_change, type, note) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, $user_id, $qty_change, $type, $note]);

        if ($manageTx) {
            $pdo->commit();
        }
        return true;
    } catch (Exception $e) {
        if ($manageTx) {
            $pdo->rollBack();
            return false;
        }
        throw $e;
    }
}

function generate_email_verification_code(): string
{
    return (string)random_int(100000, 999999);
}

function ensure_password_resets_table_exists(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS password_resets (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  token varchar(128) NOT NULL,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY token (token),
  KEY user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    $pdo->exec($sql);
}

/**
 * Log an audit trail entry for compliance and accountability
 * 
 * @param string $action - Action type: 'create', 'update', 'delete', 'approve', etc.
 * @param string $entity_type - Entity being modified: 'sales', 'products', 'users', 'refunds', etc.
 * @param int|null $entity_id - ID of the entity
 * @param mixed $old_value - Previous value (for updates)
 * @param mixed $new_value - New value (for updates/creates)
 * @param string|null $reason - Reason for the change (optional)
 * @return bool
 */
function audit_log(string $action, string $entity_type, ?int $entity_id, $old_value = null, $new_value = null, ?string $reason = null): bool
{
    try {
        global $pdo;
        if (!$pdo) return false;
        
        $user_id = $_SESSION['user_id'] ?? null;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_value, new_value, change_reason, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        return $stmt->execute([
            $user_id,
            $action,
            $entity_type,
            $entity_id,
            is_array($old_value) || is_object($old_value) ? json_encode($old_value) : $old_value,
            is_array($new_value) || is_object($new_value) ? json_encode($new_value) : $new_value,
            $reason,
            $ip_address,
            $user_agent
        ]);
    } catch (Throwable $e) {
        app_log('Audit log failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get user permissions from their role
 * 
 * @param int $user_id
 * @return array Permission names
 */
function get_user_permissions(int $user_id): array
{
    try {
        global $pdo;
        if (!$pdo) return [];
        
        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.name FROM permissions p
             JOIN role_permissions rp ON p.id = rp.permission_id
             JOIN roles r ON rp.role_id = r.id
             JOIN users u ON u.role_id = r.id
             WHERE u.id = ? AND u.is_active = 1"
        );
        
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        app_log('Get permissions failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Check if current user has a specific permission
 * 
 * @param string $permission - Permission name to check
 * @return bool
 */
function user_can(string $permission): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    // Admin always has all permissions
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }
    
    $permissions = get_user_permissions($_SESSION['user_id']);
    return in_array($permission, $permissions, true);
}

/**
 * Get user's primary role
 * 
 * @param int $user_id
 * @return string|null
 */
function get_user_role(int $user_id): ?string
{
    try {
        global $pdo;
        if (!$pdo) return null;
        
        $stmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        app_log('Get user role failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Require a specific permission or redirect
 * Use at the start of admin pages
 * 
 * @param string $permission
 * @param string $fallback_url
 * @return void
 */
function require_permission(string $permission, string $fallback_url = 'dashboard.php'): void
{
    if (!user_can($permission)) {
        http_response_code(403);
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header("Location: $fallback_url");
        exit;
    }
}

/**
 * Create a refund request for a sale
 * @param int $sale_id
 * @param float $amount
 * @param string|null $reason
 * @return int|false Refund ID or false on failure
 */
function create_refund_request(int $sale_id, float $amount, ?string $reason = null, array $items = [])
{
    try {
        global $pdo;
        if (!$pdo) return false;
        $user_id = $_SESSION['user_id'] ?? null;
        if ($sale_id < 1 || $amount <= 0) return false;
        $pdo->beginTransaction();
        $sale = $pdo->prepare('SELECT id, grand_total FROM sales WHERE id = ? FOR UPDATE');
        $sale->execute([$sale_id]);
        if (!$sale->fetch()) throw new RuntimeException('Sale not found.');
        $stmt = $pdo->prepare("INSERT INTO refunds (sale_id, requested_by, amount, reason) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sale_id, $user_id, $amount, $reason]);
        $refund_id = (int)$pdo->lastInsertId();
        if ($items) {
            $itemStmt = $pdo->prepare('SELECT si.id, si.product_id, si.qty, si.unit_price FROM sale_items si WHERE si.id = ? AND si.sale_id = ?');
            $insert = $pdo->prepare('INSERT INTO refund_items (refund_id, sale_item_id, product_id, qty, refund_amount) VALUES (?, ?, ?, ?, ?)');
            $itemTotal = 0.0;
            foreach ($items as $item) {
                $itemStmt->execute([(int)($item['sale_item_id'] ?? 0), $sale_id]);
                $saleItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
                $qty = (int)($item['qty'] ?? 0);
                if (!$saleItem || $qty < 1 || $qty > (int)$saleItem['qty']) throw new RuntimeException('Invalid returned item.');
                $lineRefund = $qty * (float)$saleItem['unit_price'];
                $itemTotal += $lineRefund;
                $insert->execute([$refund_id, $saleItem['id'], $saleItem['product_id'], $qty, $lineRefund]);
            }
            $pdo->prepare('UPDATE refunds SET amount = ? WHERE id = ?')->execute([$itemTotal, $refund_id]);
            $amount = $itemTotal;
        }
        $pdo->commit();

        audit_log('create', 'refunds', $refund_id, null, ['sale_id'=>$sale_id,'amount'=>$amount,'reason'=>$reason,'items'=>$items], 'Refund requested');
        return $refund_id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        app_log('create_refund_request failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Approve or reject a refund
 * @param int $refund_id
 * @param bool $approve
 * @param string|null $note
 * @return bool
 */
function approve_refund(int $refund_id, bool $approve = true, ?string $note = null): bool
{
    try {
        global $pdo;
        if (!$pdo) return false;
        $approver = $_SESSION['user_id'] ?? null;
        $status = $approve ? 'approved' : 'rejected';
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        $refundStmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ? FOR UPDATE');
        $refundStmt->execute([$refund_id]);
        $refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund || $refund['status'] !== 'pending') throw new RuntimeException('This refund has already been processed.');
        $items = $pdo->prepare('SELECT ri.*, p.name FROM refund_items ri JOIN products p ON p.id = ri.product_id WHERE ri.refund_id = ?');
        $items->execute([$refund_id]);
        $returnedItems = $items->fetchAll(PDO::FETCH_ASSOC);
        if ($approve && !$returnedItems) throw new RuntimeException('A refund must include at least one returned item before it can be approved.');
        if ($approve) {
            foreach ($returnedItems as $item) {
                if (!updateStock($pdo, (int)$item['product_id'], (int)$item['qty'], (int)$approver, 'refund', 'Refund #' . $refund_id)) {
                    throw new RuntimeException('Could not restore returned stock.');
                }
            }
        }
        $stmt = $pdo->prepare("UPDATE refunds SET status = ?, approved_by = ?, approved_at = ?, reason = CASE WHEN ? <> '' THEN CONCAT(COALESCE(reason, ''), ' | Decision: ', ?) ELSE reason END WHERE id = ?");
        $stmt->execute([$status, $approver, $now, $note ?? '', $note ?? '', $refund_id]);
        $pdo->commit();

        audit_log($approve ? 'approve' : 'reject', 'refunds', $refund_id, null, ['status'=>$status,'note'=>$note,'stock_restored'=>$approve ? $returnedItems : []], 'Refund decision');
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        app_log('approve_refund failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Store a receipt snapshot for a sale
 * @param int $sale_id
 * @param array $snapshot
 * @return int|false
 */
function store_receipt_snapshot(int $sale_id, array $snapshot)
{
    try {
        global $pdo;
        if (!$pdo) return false;

        $tableExists = $pdo->query("SHOW TABLES LIKE 'receipts'")->fetch();
        if (!$tableExists) {
            app_log('store_receipt_snapshot skipped: receipts table does not exist');
            return false;
        }

        $user_id = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO receipts (sale_id, snapshot, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$sale_id, json_encode($snapshot), $user_id]);
        $receipt_id = (int)$pdo->lastInsertId();

        audit_log('create', 'receipts', $receipt_id, null, $snapshot, 'Receipt snapshot stored');
        return $receipt_id;
    } catch (Throwable $e) {
        app_log('store_receipt_snapshot failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create a cashier closing report
 * @param int $cashier_id
 * @param string $shift_start
 * @param string $shift_end
 * @param float $expected_total
 * @param float $counted_cash
 * @param string|null $notes
 * @return int|false
 */
function create_closing_report(int $cashier_id, string $shift_start, string $shift_end, float $expected_total, float $counted_cash, ?string $notes = null)
{
    try {
        global $pdo;
        if (!$pdo) return false;
        $discrepancy = $counted_cash - $expected_total;
        $stmt = $pdo->prepare("INSERT INTO cashier_closing_reports (cashier_id, shift_start, shift_end, expected_total, counted_cash, discrepancy, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cashier_id, $shift_start, $shift_end, $expected_total, $counted_cash, $discrepancy, $notes]);
        $report_id = (int)$pdo->lastInsertId();

        audit_log('create', 'cashier_closing', $report_id, null, ['expected'=>$expected_total,'counted'=>$counted_cash,'discrepancy'=>$discrepancy], 'Closing report submitted');
        return $report_id;
    } catch (Throwable $e) {
        app_log('create_closing_report failed: ' . $e->getMessage());
        return false;
    }
}
