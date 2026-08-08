<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail.php';

require_permission('receipts.reprint', '/admin/index.php');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid receipt id.';
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT r.*, s.invoice_no FROM receipts r LEFT JOIN sales s ON r.sale_id = s.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        http_response_code(404);
        echo 'Receipt not found.';
        exit;
    }
} catch (Throwable $e) {
    if (function_exists('app_log')) {
        app_log('receipt_view error: ' . $e->getMessage());
    }
    http_response_code(500);
    echo 'An internal error occurred while loading the receipt. Please check the application logs.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $email = normalize_email($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Enter a valid customer email address.';
    } else {
        $data = json_decode($rec['snapshot'] ?? '', true) ?: [];
        $invoice = $data['invoice_no'] ?? $rec['invoice_no'] ?? ('Receipt #' . $id);
        $total = number_format((float)($data['grand_total'] ?? 0), 2);
        $html = '<h2>Receipt ' . htmlspecialchars($invoice, ENT_QUOTES, 'UTF-8') . '</h2><p>Thank you for your purchase.</p><p>Total: <strong>KSh ' . $total . '</strong></p>';
        $ok = send_email_message($email, '', 'Your receipt ' . $invoice, $html, 'Receipt ' . $invoice . ' - Total: KSh ' . $total);
        $stmt = $pdo->prepare("INSERT INTO receipt_deliveries (receipt_id, delivery_type, recipient_email, status, delivered_by) VALUES (?, 'email', ?, ?, ?)");
        $stmt->execute([$id, $email, $ok ? 'sent' : 'failed', $_SESSION['user_id'] ?? null]);
        audit_log('email', 'receipts', $id, null, ['recipient' => $email, 'status' => $ok ? 'sent' : 'failed'], 'Receipt email');
        $_SESSION[$ok ? 'message' : 'error'] = $ok ? 'Receipt emailed to the customer.' : 'Email could not be sent. Check mail configuration.';
    }
    header('Location: receipt_view.php?id=' . $id); exit;
}

include __DIR__ . '/../includes/header.php';
?>

<h2>Receipt #<?= $rec['id'] ?> (Sale: <?= htmlspecialchars($rec['invoice_no'] ?? $rec['sale_id']) ?>)</h2>

<?php foreach (['message'=>'success','error'=>'danger'] as $key=>$type): if (!empty($_SESSION[$key])): ?><div class="alert alert-<?= $type ?>"><?= htmlspecialchars($_SESSION[$key]); unset($_SESSION[$key]); ?></div><?php endif; endforeach; ?>

<?php
$requestId = bin2hex(random_bytes(8));
$decoded = null;
$raw = $rec['snapshot'] ?? '';
try {
    if (!empty($raw)) {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    if (function_exists('app_log')) {
        app_log('receipt_view snapshot decode failed (req ' . $requestId . '): ' . $e->getMessage());
    }
}

if (is_array($decoded) && !empty($decoded['items']) && is_array($decoded['items'])):
    $items = $decoded['items'];
    ?>
    <div class="card">
        <div class="card-body">
            <h5>Invoice: <?= htmlspecialchars($decoded['invoice_no'] ?? ($rec['invoice_no'] ?? '')) ?></h5>
            <p class="text-muted mb-2">Created: <?= htmlspecialchars($decoded['created_at'] ?? '') ?></p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Qty</th>
                            <th>SKU</th>
                            <th>Item</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars((int)($it['qty'] ?? 0)) ?></td>
                                <td><?= htmlspecialchars($it['sku'] ?? '') ?></td>
                                <td><?= htmlspecialchars($it['name'] ?? '') ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format((float)($it['unit_price'] ?? 0), 2)) ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format((float)($it['total'] ?? 0), 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end">
                <div style="min-width:220px;text-align:right;">
                    <div>Subtotal: <strong>KSh <?= htmlspecialchars(number_format((float)($decoded['total_amount'] ?? $decoded['grand_total'] ?? 0), 2)) ?></strong></div>
                    <div>Discount: <?= htmlspecialchars(number_format((float)($decoded['discount'] ?? 0), 2)) ?></div>
                    <?php if (!empty($decoded['mpesa_code'])): ?>
                        <div>MPESA Code: <strong><?= htmlspecialchars($decoded['mpesa_code']) ?></strong></div>
                    <?php endif; ?>
                    <div class="mt-2"><strong>Grand Total: KSh <?= htmlspecialchars(number_format((float)($decoded['grand_total'] ?? $decoded['total_amount'] ?? 0), 2)) ?></strong></div>
                </div>
            </div>
            <hr>
            <div class="small text-muted">Reference: <?= htmlspecialchars($rec['id']) ?> &nbsp;|&nbsp; Stored: <?= htmlspecialchars($rec['created_at'] ?? '') ?></div>
        </div>
    </div>
    <a href="receipts.php" class="btn btn-secondary mt-3">Back</a>
    <button class="btn btn-outline-primary mt-3 ms-2 d-print-none" onclick="window.print()">Reprint</button>
    <a href="download_receipt.php?id=<?= urlencode($rec['id']) ?>" class="btn btn-success mt-3 ms-2" target="_blank">Download PDF</a>
    <?php if (user_can('receipts.email')): ?><form class="row g-2 mt-3 d-print-none" method="post"><div class="col-sm-5"><input required type="email" name="email" class="form-control" placeholder="Customer email"></div><div class="col-auto"><?= csrf_field() ?><button class="btn btn-primary">Email receipt</button></div></form><?php endif; ?>
<?php else: ?>
    <div class="alert alert-warning">Snapshot could not be rendered as a receipt. Showing raw data. Request ID: <?= htmlspecialchars($requestId) ?></div>
    <pre><?= htmlspecialchars(substr($raw, 0, 2000), ENT_QUOTES, 'UTF-8') ?></pre>
    <a href="receipts.php" class="btn btn-secondary">Back</a>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
