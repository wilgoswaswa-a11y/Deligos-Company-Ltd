<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
$pageTitle = 'Inventory';

$role = $_SESSION['role'] ?? 'cashier';
$isAdmin = $role === 'admin';
$edit_id = 0;
$product_to_edit = null;

if (isset($_GET['edit'])) {
    if (!$isAdmin) {
        header('Location: inventory.php');
        exit;
    }

    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$edit_id]);
    $product_to_edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (isset($_POST['update_product']) && $isAdmin) {
        $product_id = validate_int($_POST['product_id'] ?? null, 1);
        $sku = trim($_POST['sku'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $price = validate_decimal($_POST['price'] ?? null, 0.01);
        $cost = validate_decimal($_POST['cost'] ?? 0, 0);
        $stock = validate_int($_POST['stock_qty'] ?? null, 0);
        $reorder = validate_int($_POST['reorder_level'] ?? 5, 0);

        if ($product_id && $sku !== '' && $name !== '' && $price !== null && $cost !== null && $stock !== null && $reorder !== null) {
            try {
                $stmt = $pdo->prepare("UPDATE products SET sku = ?, name = ?, price = ?, cost = ?, stock_qty = ?, reorder_level = ? WHERE id = ?");
                $stmt->execute([$sku, $name, $price, $cost, $stock, $reorder, $product_id]);
                header('Location: inventory.php');
                exit;
            } catch (Throwable $e) {
                $error = app_exception_message($e, 'We could not update the product right now. Please try again.');
            }
        } else {
            $error = 'Please complete all required product fields before saving.';
        }
    }

    if (isset($_POST['add_product']) && $isAdmin) {
        $sku = trim($_POST['sku'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $price = validate_decimal($_POST['price'] ?? null, 0.01);
        $cost = validate_decimal($_POST['cost'] ?? 0, 0);
        $stock = validate_int($_POST['stock_qty'] ?? null, 0);
        $reorder = validate_int($_POST['reorder_level'] ?? 5, 0);

        if ($sku !== '' && $name !== '' && $price !== null && $cost !== null && $stock !== null && $reorder !== null) {
            try {
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, price, cost, stock_qty, reorder_level)
                                   VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $price, $cost, $stock, $reorder]);
                header('Location: inventory.php');
                exit;
            } catch (Throwable $e) {
                $error = app_exception_message($e, 'We could not add the product right now. Please try again.');
            }
        } else {
            $error = 'Please complete all required product fields before adding a product.';
        }
    }

    if (isset($_POST['delete_product']) && $isAdmin) {
        $id = validate_int($_POST['product_id'] ?? null, 1);
        try {
            if ($id) {
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            }
            header('Location: inventory.php');
            exit;
        } catch (Throwable $e) {
            $error = app_exception_message($e, 'We could not delete the product right now. Please try again.');
        }
    }
}

include 'includes/header.php';
?>
<h2>Inventory</h2>
<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $product_to_edit ? 'Edit Product' : 'Add Product' ?></div>
            <div class="card-body">
                <?php if ($isAdmin): ?>
                <form method="POST" class="needs-validation" novalidate data-loading-text="Saving product…">
                    <?= csrf_field() ?>
                    <?php if ($product_to_edit): ?>
                        <input type="hidden" name="product_id" value="<?= (int)$product_to_edit['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <input name="sku" class="form-control" placeholder="SKU" value="<?= htmlspecialchars($product_to_edit['sku'] ?? '') ?>" required>
                        <div class="invalid-feedback">SKU is required.</div>
                    </div>
                    <div class="mb-2">
                        <input name="name" class="form-control" placeholder="Product Name" value="<?= htmlspecialchars($product_to_edit['name'] ?? '') ?>" required>
                        <div class="invalid-feedback">Product name is required.</div>
                    </div>
                    <div class="mb-2">
                        <input name="price" type="number" step="0.01" class="form-control" placeholder="Price" value="<?= htmlspecialchars($product_to_edit['price'] ?? '') ?>" required>
                        <div class="invalid-feedback">Price is required.</div>
                    </div>
                    <div class="mb-2">
                        <input name="cost" type="number" step="0.01" min="0" class="form-control" placeholder="Cost" value="<?= htmlspecialchars($product_to_edit['cost'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <input name="stock_qty" type="number" class="form-control" placeholder="Stock Qty" value="<?= htmlspecialchars($product_to_edit['stock_qty'] ?? '') ?>" required>
                        <div class="invalid-feedback">Stock quantity is required.</div>
                    </div>
                    <div class="mb-2">
                        <input name="reorder_level" type="number" class="form-control" placeholder="Reorder Level" value="<?= htmlspecialchars($product_to_edit['reorder_level'] ?? 5) ?>">
                    </div>
                    <button type="submit" name="<?= $product_to_edit ? 'update_product' : 'add_product' ?>" class="btn btn-primary"><?= $product_to_edit ? 'Save Changes' : 'Add Product' ?></button>
                    <?php if ($product_to_edit): ?>
                        <a href="inventory.php" class="btn btn-secondary ms-2">Cancel</a>
                    <?php endif; ?>
                </form>
                <?php else: ?>
                    <p class="text-muted mb-0">Only admins can add or edit products.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Product List</div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead><tr><th>SKU</th><th>Name</th><th>Price</th><th>Cost</th><th>Stock</th><th>Reorder</th><th></th></tr></thead>
                    <tbody>
                    <?php
                    $products = $pdo->query("SELECT * FROM products ORDER BY id DESC");
                    while ($p = $products->fetch()): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['sku']) ?></td>
                            <td><?= htmlspecialchars($p['name']) ?></td>
                            <td>KSh <?= number_format((float)$p['price'], 2) ?></td>
                            <td>KSh <?= number_format((float)($p['cost'] ?? 0), 2) ?></td>
                            <td><?= (int)$p['stock_qty'] ?></td>
                            <td><?= (int)$p['reorder_level'] ?></td>
                            <td>
                                <?php if ($isAdmin): ?>
                                    <a href="inventory.php?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-secondary me-1">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" name="delete_product" value="1" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>

