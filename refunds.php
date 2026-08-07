<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
require_permission('refunds.create');

$saleId = (int)($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $selected = [];
    foreach (($_POST['qty'] ?? []) as $saleItemId => $qty) {
        if ((int)$qty > 0) $selected[] = ['sale_item_id' => (int)$saleItemId, 'qty' => (int)$qty];
    }
    $id = create_refund_request($saleId, 0.01, trim($_POST['reason'] ?? ''), $selected);
    $_SESSION[$id ? 'message' : 'error'] = $id ? 'Refund request submitted for approval.' : 'Could not create the return. Confirm the sale and item quantities.';
    header('Location: refunds.php'); exit;
}

$sale = null; $items = [];
if ($saleId) {
    $stmt = $pdo->prepare('SELECT s.*, c.first_name, c.last_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?');
    $stmt->execute([$saleId]); $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sale) {
        $stmt = $pdo->prepare('SELECT si.*, p.name, p.sku, COALESCE((SELECT SUM(ri.qty) FROM refund_items ri JOIN refunds r ON r.id=ri.refund_id WHERE ri.sale_item_id=si.id AND r.status IN (\'pending\',\'approved\')),0) returned_qty FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?');
        $stmt->execute([$saleId]); $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$history = $pdo->prepare('SELECT r.*, s.invoice_no FROM refunds r JOIN sales s ON s.id=r.sale_id WHERE r.requested_by=? ORDER BY r.created_at DESC LIMIT 20');
$history->execute([$_SESSION['user_id']]); $history = $history->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Returns & Refunds'; include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center"><h2>Returns & Refunds</h2><a class="btn btn-outline-primary" href="sales.php">Find sale in Sales</a></div>
<?php foreach (['message'=>'success','error'=>'danger'] as $key=>$type): if (!empty($_SESSION[$key])): ?><div class="alert alert-<?= $type ?> mt-3"><?= htmlspecialchars($_SESSION[$key]); unset($_SESSION[$key]); ?></div><?php endif; endforeach; ?>
<form class="row g-2 mt-2" method="get"><div class="col-sm-5"><input class="form-control" type="number" name="sale_id" min="1" value="<?= $saleId ?: '' ?>" placeholder="Sale ID"></div><div class="col-auto"><button class="btn btn-primary">Load sale</button></div></form>
<?php if ($sale): ?><form method="post" class="card mt-3"><div class="card-header">Return from <?= htmlspecialchars($sale['invoice_no']) ?></div><div class="card-body"><input type="hidden" name="sale_id" value="<?= $saleId ?>"><?= csrf_field() ?><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Sold</th><th>Previously requested</th><th>Return qty</th><th>Refund value</th></tr></thead><tbody><?php foreach ($items as $item): $available=max(0,(int)$item['qty']-(int)$item['returned_qty']); ?><tr><td><?= htmlspecialchars($item['name']) ?> <small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small></td><td><?= $item['qty'] ?></td><td><?= $item['returned_qty'] ?></td><td><input class="form-control" style="max-width:100px" type="number" name="qty[<?= $item['id'] ?>]" min="0" max="<?= $available ?>" value="0" <?= $available ? '' : 'disabled' ?>></td><td>KSh <?= number_format($item['unit_price'],2) ?></td></tr><?php endforeach; ?></tbody></table></div><label class="form-label">Reason</label><textarea required name="reason" class="form-control" maxlength="255"></textarea><button class="btn btn-warning mt-3">Submit for approval</button></div></form><?php elseif ($saleId): ?><div class="alert alert-warning mt-3">Sale not found.</div><?php endif; ?>
<h4 class="mt-4">My refund history</h4><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Invoice</th><th>Amount</th><th>Status</th><th>Requested</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><td><?= htmlspecialchars($row['invoice_no']) ?></td><td>KSh <?= number_format($row['amount'],2) ?></td><td><?= htmlspecialchars($row['status']) ?></td><td><?= htmlspecialchars($row['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php include 'includes/footer.php'; ?>
