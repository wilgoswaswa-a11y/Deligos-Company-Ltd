<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

require_permission('purchase_orders.manage');

function po_redirect(string $key, string $message): never
{
    $_SESSION[$key] = $message;
    header('Location: purchase_orders.php');
    exit;
}

function po_money($value): ?float
{
    if (!is_scalar($value) || !preg_match('/^\d+(?:\.\d{1,2})?$/', trim((string)$value))) {
        return null;
    }
    $amount = (float)$value;
    return $amount >= 0 && $amount <= 99999999.99 ? $amount : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($action === 'supplier') {
            $name = trim((string)($_POST['name'] ?? ''));
            $contact = trim((string)($_POST['contact_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = normalize_email((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));

            if ($name === '' || mb_strlen($name) > 150) {
                throw new RuntimeException('Enter a supplier name (up to 150 characters).');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid supplier email address.');
            }

            $stmt = $pdo->prepare('INSERT INTO suppliers (name, contact_name, phone, email, address) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null]);
            audit_log('create', 'suppliers', (int)$pdo->lastInsertId(), null, ['name' => $name], 'Supplier added');
            $message = 'Supplier added.';
        } elseif ($action === 'create') {
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$supplierId) {
                throw new RuntimeException('Choose a supplier before creating an order.');
            }
            $supplier = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? FOR UPDATE');
            $supplier->execute([$supplierId]);
            if (!$supplier->fetchColumn()) {
                throw new RuntimeException('The selected supplier no longer exists.');
            }

            $quantities = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
            $costs = is_array($_POST['cost'] ?? null) ? $_POST['cost'] : [];
            $items = [];
            foreach ($quantities as $productId => $quantity) {
                $productId = filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
                if (!$productId || !$quantity) {
                    continue;
                }
                $cost = po_money($costs[$productId] ?? null);
                if ($cost === null) {
                    throw new RuntimeException('Enter a valid unit cost for every ordered item.');
                }

                $product = $pdo->prepare('SELECT id, name FROM products WHERE id = ? FOR UPDATE');
                $product->execute([$productId]);
                $row = $product->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('One of the selected products is no longer available.');
                }
                $items[] = ['product_id' => (int)$row['id'], 'name' => $row['name'], 'qty' => (int)$quantity, 'cost' => $cost];
            }
            if (!$items) {
                throw new RuntimeException('Enter an order quantity for at least one product.');
            }

            do {
                $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $check = $pdo->prepare('SELECT id FROM purchase_orders WHERE po_no = ?');
                $check->execute([$poNumber]);
            } while ($check->fetch());

            $total = array_reduce($items, static fn(float $sum, array $item): float => $sum + $item['qty'] * $item['cost'], 0.0);
            $stmt = $pdo->prepare("INSERT INTO purchase_orders (po_no, supplier_id, created_by, status, total_amount) VALUES (?, ?, ?, 'draft', ?)");
            $stmt->execute([$poNumber, $supplierId, $_SESSION['user_id'], $total]);
            $poId = (int)$pdo->lastInsertId();
            $insertItem = $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, qty, unit_cost) VALUES (?, ?, ?, ?)');
            foreach ($items as $item) {
                $insertItem->execute([$poId, $item['product_id'], $item['qty'], $item['cost']]);
            }
            audit_log('create', 'purchase_orders', $poId, null, ['po_no' => $poNumber, 'total' => $total], 'Draft purchase order created');
            $message = "Draft purchase order {$poNumber} created.";
        } elseif (in_array($action, ['order', 'receive', 'cancel'], true)) {
            $poId = filter_var($_POST['po_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$poId) {
                throw new RuntimeException('Invalid purchase order.');
            }
            $orderStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
            $orderStmt->execute([$poId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new RuntimeException('Purchase order not found.');
            }

            if ($action === 'order') {
                if ($order['status'] !== 'draft') {
                    throw new RuntimeException('Only draft purchase orders can be marked as ordered.');
                }
                $pdo->prepare("UPDATE purchase_orders SET status = 'ordered' WHERE id = ?")->execute([$poId]);
                audit_log('update', 'purchase_orders', $poId, ['status' => 'draft'], ['status' => 'ordered'], 'Order sent to supplier');
                $message = "Purchase order {$order['po_no']} marked as ordered.";
            } elseif ($action === 'receive') {
                if ($order['status'] !== 'ordered') {
                    throw new RuntimeException('Mark the purchase order as ordered before receiving stock.');
                }
                $itemsStmt = $pdo->prepare('SELECT product_id, qty, unit_cost FROM purchase_order_items WHERE purchase_order_id = ?');
                $itemsStmt->execute([$poId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$items) {
                    throw new RuntimeException('This purchase order has no items to receive.');
                }
                $updateCost = $pdo->prepare('UPDATE products SET cost = ? WHERE id = ?');
                foreach ($items as $item) {
                    if (!updateStock($pdo, (int)$item['product_id'], (int)$item['qty'], (int)$_SESSION['user_id'], 'purchase_order', 'Received ' . $order['po_no'])) {
                        throw new RuntimeException('Could not update product stock.');
                    }
                    $updateCost->execute([(float)$item['unit_cost'], (int)$item['product_id']]);
                }
                $pdo->prepare("UPDATE purchase_orders SET status = 'received', received_at = NOW() WHERE id = ?")->execute([$poId]);
                audit_log('receive', 'purchase_orders', $poId, ['status' => 'ordered'], ['status' => 'received'], 'Stock received for ' . $order['po_no']);
                $message = "Stock received for {$order['po_no']}.";
            } else {
                if (!in_array($order['status'], ['draft', 'ordered'], true)) {
                    throw new RuntimeException('Only open purchase orders can be cancelled.');
                }
                $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ?")->execute([$poId]);
                audit_log('update', 'purchase_orders', $poId, ['status' => $order['status']], ['status' => 'cancelled'], 'Purchase order cancelled');
                $message = "Purchase order {$order['po_no']} cancelled.";
            }
        } else {
            throw new RuntimeException('Unknown purchase-order action.');
        }

        $pdo->commit();
        po_redirect('message', $message);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_log('Purchase-order action failed: ' . $e->getMessage());
        po_redirect('error', app_exception_message($e, 'Could not update the purchase order.'));
    }
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$lowStock = $pdo->query('SELECT id, name, sku, stock_qty, reorder_level, COALESCE(cost, 0) AS cost FROM products WHERE stock_qty <= reorder_level ORDER BY stock_qty, name')->fetchAll(PDO::FETCH_ASSOC);
$orders = $pdo->query(
    'SELECT po.*, s.name AS supplier, u.full_name AS created_by_name
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN users u ON u.id = po.created_by
     ORDER BY po.ordered_at DESC, po.id DESC LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);
$orderItems = [];
if ($orders) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT poi.purchase_order_id, poi.qty, poi.unit_cost, p.name, p.sku FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE poi.purchase_order_id IN ($placeholders) ORDER BY poi.id");
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $orderItems[$item['purchase_order_id']][] = $item;
    }
}

$pageTitle = 'Purchase Orders';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h2 class="mb-1">Purchase Orders</h2><p class="text-muted mb-0">Create supplier orders, confirm them, and receive stock into inventory.</p></div>
</div>

<?php foreach (['message' => 'success', 'error' => 'danger'] as $key => $type): ?>
    <?php if (!empty($_SESSION[$key])): ?><div class="alert alert-<?= $type ?> alert-dismissible fade show" role="alert"><?= htmlspecialchars($_SESSION[$key]); unset($_SESSION[$key]); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" class="card">
            <div class="card-header">Create draft order from low-stock items</div>
            <div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="action" value="create">
                <label class="form-label" for="supplierId">Supplier</label>
                <select required id="supplierId" name="supplier_id" class="form-select mb-3"><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option><?php endforeach; ?></select>
                <?php if ($lowStock): ?>
                    <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Product</th><th>Available</th><th>Reorder at</th><th>Order qty</th><th>Unit cost (KSh)</th></tr></thead><tbody>
                    <?php foreach ($lowStock as $product): $suggestedQty = max(1, (int)$product['reorder_level'] - (int)$product['stock_qty']); ?>
                        <tr><td><strong><?= htmlspecialchars($product['name']) ?></strong><small class="d-block text-muted"><?= htmlspecialchars($product['sku']) ?></small></td><td><?= (int)$product['stock_qty'] ?></td><td><?= (int)$product['reorder_level'] ?></td><td><input class="form-control" style="min-width:90px" name="qty[<?= (int)$product['id'] ?>]" min="0" max="1000000" type="number" value="<?= $suggestedQty ?>"></td><td><input class="form-control" style="min-width:110px" name="cost[<?= (int)$product['id'] ?>]" min="0" step="0.01" type="number" value="<?= htmlspecialchars(number_format((float)$product['cost'], 2, '.', '')) ?>"></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-plus"></i> Create draft PO</button>
                <?php else: ?><p class="text-success mb-0"><i class="bi bi-check-circle"></i> No products are currently at or below their reorder level.</p><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <form method="post" class="card"><div class="card-header">Add supplier</div><div class="card-body"><?= csrf_field() ?><input type="hidden" name="action" value="supplier"><label class="form-label">Supplier name</label><input required maxlength="150" class="form-control mb-2" name="name"><input class="form-control mb-2" name="contact_name" placeholder="Contact person"><input class="form-control mb-2" name="phone" placeholder="Phone"><input class="form-control mb-2" name="email" type="email" placeholder="Email"><textarea class="form-control mb-3" name="address" rows="2" placeholder="Address (optional)"></textarea><button class="btn btn-outline-primary" type="submit">Save supplier</button></div></form>
    </div>
</div>

<h4 class="mt-4">Purchase-order history</h4>
<div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>PO</th><th>Supplier</th><th>Status</th><th>Total</th><th>Created by</th><th>Items</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($orders as $order): $statusClass = ['draft' => 'secondary', 'ordered' => 'primary', 'received' => 'success', 'cancelled' => 'dark'][$order['status']] ?? 'secondary'; ?>
    <tr><td><strong><?= htmlspecialchars($order['po_no']) ?></strong><small class="d-block text-muted"><?= htmlspecialchars(date('d M Y H:i', strtotime($order['ordered_at']))) ?></small></td><td><?= htmlspecialchars($order['supplier'] ?? 'No supplier') ?></td><td><span class="badge text-bg-<?= $statusClass ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></td><td>KSh <?= number_format((float)$order['total_amount'], 2) ?></td><td><?= htmlspecialchars($order['created_by_name'] ?? '-') ?></td><td><details><summary><?= count($orderItems[$order['id']] ?? []) ?> item(s)</summary><ul class="small mb-0 ps-3"><?php foreach ($orderItems[$order['id']] ?? [] as $item): ?><li><?= htmlspecialchars($item['name']) ?> — <?= (int)$item['qty'] ?> × KSh <?= number_format((float)$item['unit_cost'], 2) ?></li><?php endforeach; ?></ul></details></td><td class="text-nowrap">
        <?php if ($order['status'] === 'draft'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="order"><input type="hidden" name="po_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Mark ordered</button></form><?php endif; ?>
        <?php if ($order['status'] === 'ordered'): ?><form method="post" class="d-inline" onsubmit="return confirm('Receive this stock into inventory? This cannot be undone.');"><?= csrf_field() ?><input type="hidden" name="action" value="receive"><input type="hidden" name="po_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-sm btn-success" type="submit">Receive stock</button></form><?php endif; ?>
        <?php if (in_array($order['status'], ['draft', 'ordered'], true)): ?><form method="post" class="d-inline" onsubmit="return confirm('Cancel this purchase order?');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="po_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button></form><?php endif; ?>
    </td></tr>
<?php endforeach; ?>
<?php if (!$orders): ?><tr><td colspan="7" class="text-center text-muted py-4">No purchase orders have been created yet.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php include 'includes/footer.php'; ?>
