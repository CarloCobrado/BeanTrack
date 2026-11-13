<?php
require 'config.php';
require_role(['Supervisor', 'Inventory Staff', 'Administrator']);

$isSupervisor = in_array(user_role(), ['Supervisor', 'Administrator'], true);
$msg = $_SESSION['inventory_message'] ?? '';
unset($_SESSION['inventory_message']);
$error = '';

// Handle CSV Export of Product Catalog & Price Matrix
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->query("
        SELECT p.qty_per_case, p.brand, p.name, p.category, p.srp, p.wholesale_price, p.bulk_price, p.dealer_price, p.reseller_price, p.quantity
        FROM products p
        WHERE p.active = 1
        ORDER BY p.brand ASC, p.name ASC
    ");
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product_catalog_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['QTY/CASE', 'BRAND', 'ITEM', 'TYPE', 'SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER', 'TOTAL_STOCK']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (int)$r['qty_per_case'],
            $r['brand'],
            $r['name'],
            $r['category'],
            (float)$r['srp'],
            (float)$r['wholesale_price'],
            (float)$r['bulk_price'],
            (float)$r['dealer_price'],
            (float)$r['reseller_price'],
            (int)$r['quantity']
        ]);
    }
    fclose($out);
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_product') {
            if (!$isSupervisor && !empty($_POST['product_id'])) {
                throw new Exception('Only Supervisors and Administrators can edit catalog products.');
            }

            $productId = (int)($_POST['product_id'] ?? 0);
            $brand = trim($_POST['brand'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'SUPPLY');
            $qtyPerCase = max(1, (int)($_POST['qty_per_case'] ?? 1));
            $unit = trim($_POST['unit'] ?? 'pc');
            $reorderLevel = max(0, (int)($_POST['reorder_level'] ?? 10));

            $srp = (float)($_POST['srp'] ?? 0);
            $wholesale = (float)($_POST['wholesale_price'] ?? 0);
            $bulk = (float)($_POST['bulk_price'] ?? 0);
            $dealer = (float)($_POST['dealer_price'] ?? 0);
            $reseller = (float)($_POST['reseller_price'] ?? 0);

            if ($brand === '' || $name === '') {
                throw new Exception('Brand and Product Name are required.');
            }

            // Find or create brand
            $stmtBrand = $pdo->prepare("SELECT id FROM brands WHERE name = ?");
            $stmtBrand->execute([$brand]);
            $bRow = $stmtBrand->fetch();
            $brandId = $bRow ? (int)$bRow['id'] : null;
            if (!$brandId) {
                $stmtNewB = $pdo->prepare("INSERT INTO brands (name) VALUES (?)");
                $stmtNewB->execute([$brand]);
                $brandId = (int)$pdo->lastInsertId();
            }

            // Find or create category
            if ($category !== '') {
                $stmtCat = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
                $stmtCat->execute([$category]);
            }

            $unitPrice = $srp > 0 ? $srp : ($wholesale > 0 ? $wholesale : 100.00);

            $pdo->beginTransaction();

            if ($productId > 0) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE products 
                    SET brand_id=?, brand=?, name=?, category=?, qty_per_case=?, unit=?, 
                        srp=?, wholesale_price=?, bulk_price=?, dealer_price=?, reseller_price=?, unit_price=?, reorder_level=?
                    WHERE id=? AND active=1
                ");
                $stmtUpdate->execute([
                    $brandId, $brand, $name, $category, $qtyPerCase, $unit,
                    $srp, $wholesale, $bulk, $dealer, $reseller, $unitPrice, $reorderLevel, $productId
                ]);

                audit('Edit Product', 'products', $productId, "Updated product [$brand] $name; SRP=₱$srp, Wholesale=₱$wholesale, Dealer=₱$dealer");
                $pdo->commit();
                $_SESSION['inventory_message'] = "Product '[$brand] $name' updated successfully.";
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO products (
                        brand_id, brand, name, category, qty_per_case, unit,
                        srp, wholesale_price, bulk_price, dealer_price, reseller_price, unit_price, quantity, reorder_level, active
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, 0, ?, 1
                    )
                ");
                $stmtInsert->execute([
                    $brandId, $brand, $name, $category, $qtyPerCase, $unit,
                    $srp, $wholesale, $bulk, $dealer, $reseller, $unitPrice, $reorderLevel
                ]);
                $newId = (int)$pdo->lastInsertId();

                // Initialize branch inventory rows
                $allBranches = $pdo->query("SELECT id FROM branches WHERE active=1")->fetchAll(PDO::FETCH_COLUMN);
                $stmtInitBranch = $pdo->prepare("INSERT INTO branch_inventory (branch_id, product_id, quantity) VALUES (?, ?, 0)");
                foreach ($allBranches as $bId) {
                    $stmtInitBranch->execute([$bId, $newId]);
                }

                audit('Create Product', 'products', $newId, "Added product [$brand] $name ($category); SRP=₱$srp, Case Qty=$qtyPerCase");
                $pdo->commit();
                $_SESSION['inventory_message'] = "Product '[$brand] $name' added to catalog.";
            }

            header('Location: inventory.php');
            exit;

        } elseif ($action === 'adjust_stock') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $branchId = (int)($_POST['branch_id'] ?? 1);
            $adjustQty = (float)($_POST['adjust_quantity'] ?? 0);
            $adjustType = $_POST['adjust_type'] ?? 'add'; // 'add' or 'subtract'
            $reason = trim($_POST['reason'] ?? 'Manual stock adjustment');

            if ($productId < 1 || $adjustQty <= 0) {
                throw new Exception('Select a product and enter a quantity greater than zero.');
            }

            $effectiveChange = ($adjustType === 'subtract') ? -$adjustQty : $adjustQty;

            $pdo->beginTransaction();

            $stmtP = $pdo->prepare("SELECT id, brand, name, qty_per_case FROM products WHERE id=? AND active=1 FOR UPDATE");
            $stmtP->execute([$productId]);
            $prod = $stmtP->fetch();
            if (!$prod) throw new Exception('Product not found.');

            $stmtB = $pdo->prepare("SELECT id, code, name FROM branches WHERE id=? AND active=1");
            $stmtB->execute([$branchId]);
            $branch = $stmtB->fetch();
            if (!$branch) throw new Exception('Branch not found.');

            // Update branch inventory
            $stmtInv = $pdo->prepare("
                INSERT INTO branch_inventory (branch_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity + VALUES(quantity))
            ");
            $stmtInv->execute([$branchId, $productId, $effectiveChange]);

            // Update global product quantity
            $stmtGlobal = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity + ?) WHERE id = ?");
            $stmtGlobal->execute([$effectiveChange, $productId]);

            audit(
                'Adjust Inventory Stock',
                'branch_inventory',
                $productId,
                "Adjusted [{$prod['brand']}] {$prod['name']} at {$branch['code']} by " . ($effectiveChange > 0 ? "+$adjustQty" : "-$adjustQty") . " units; Reason: $reason"
            );

            $pdo->commit();
            $_SESSION['inventory_message'] = "Stock updated for '{$prod['name']}' at {$branch['name']} (" . ($effectiveChange > 0 ? "+$adjustQty" : "-$adjustQty") . " units).";
            header('Location: inventory.php');
            exit;

        } elseif ($action === 'archive_product') {
            if (!$isSupervisor) {
                throw new Exception('Only Supervisors can archive products.');
            }
            $productId = (int)($_POST['product_id'] ?? 0);
            $pdo->beginTransaction();
            $stmtArch = $pdo->prepare("UPDATE products SET active=0 WHERE id=?");
            $stmtArch->execute([$productId]);
            audit('Archive Product', 'products', $productId, "Archived product ID #$productId");
            $pdo->commit();
            $_SESSION['inventory_message'] = "Product archived from catalog.";
            header('Location: inventory.php');
            exit;
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $t->getMessage();
    }
}

// Load lookup options
$branches = $pdo->query("SELECT id, code, name FROM branches WHERE active=1 ORDER BY id")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Query Filters
$search = trim($_GET['search'] ?? '');
$filterBrand = trim($_GET['brand'] ?? '');
$filterCat = trim($_GET['category'] ?? '');
$filterStock = trim($_GET['stock_status'] ?? '');

$sql = "
    SELECT p.*,
           COALESCE(bi_davao.quantity, 0) AS stock_davao,
           COALESCE(bi_pagadian.quantity, 0) AS stock_pagadian,
           COALESCE(bi_zambo.quantity, 0) AS stock_zambo,
           EXISTS(SELECT 1 FROM product_recipe_items pri WHERE pri.product_id = p.id) AS has_recipe
    FROM products p
    LEFT JOIN branch_inventory bi_davao ON bi_davao.product_id = p.id AND bi_davao.branch_id = 1
    LEFT JOIN branch_inventory bi_pagadian ON bi_pagadian.product_id = p.id AND bi_pagadian.branch_id = 2
    LEFT JOIN branch_inventory bi_zambo ON bi_zambo.product_id = p.id AND bi_zambo.branch_id = 3
    WHERE p.active = 1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterBrand !== '') {
    $sql .= " AND p.brand = ?";
    $params[] = $filterBrand;
}
if ($filterCat !== '') {
    $sql .= " AND p.category = ?";
    $params[] = $filterCat;
}
if ($filterStock === 'low') {
    $sql .= " AND p.quantity <= p.reorder_level";
} elseif ($filterStock === 'out') {
    $sql .= " AND p.quantity <= 0";
}

$sql .= " ORDER BY p.brand ASC, p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$catalogList = $stmt->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Product Inventory Catalog</h2>
        <p class="muted" style="margin:0;">Master catalog with multi-tier pricing (SRP, Wholesale, Bulk, Dealer) and real-time stock levels.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn secondary" href="inventory.php?export=csv">Download Catalog CSV</a>
        <button class="btn secondary" type="button" onclick="openStockAdjustModal()">Quick Adjust Stock</button>
        <?php if ($isSupervisor): ?>
            <a class="btn secondary" href="recipes.php">Menu Recipes</a>
            <button class="btn primary" type="button" onclick="openProductModal()">+ Add New Product</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert success" style="margin-bottom:16px;"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert danger" style="margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<!-- Filter & Search Bar -->
<section class="card" style="margin-bottom:20px; padding:16px;">
    <form method="get" action="inventory.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="flex:1; min-width:220px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search Product / Brand</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="e.g. Milk Essence, Easy, Y-Cup, Arla..." style="width:100%;">
        </label>
        <label style="width:170px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Brand</span>
            <select name="brand" style="width:100%;">
                <option value="">All Brands</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= e($b['name']) ?>" <?= $filterBrand === $b['name'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="width:170px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Category / Type</span>
            <select name="category" style="width:100%;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c['name']) ?>" <?= $filterCat === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="width:150px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Stock Status</span>
            <select name="stock_status" style="width:100%;">
                <option value="">All Levels</option>
                <option value="low" <?= $filterStock === 'low' ? 'selected' : '' ?>>Low Stock</option>
                <option value="out" <?= $filterStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterBrand !== '' || $filterCat !== '' || $filterStock !== ''): ?>
                <a class="btn secondary" href="inventory.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Catalog Table -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Product Catalog (Showing <?= count($catalogList) ?>)</h3>
        <span class="muted" style="font-size:12px;">Inventory Catalog &amp; Multi-Tier Pricing</span>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Item Description</th>
                    <th>Type</th>
                    <th style="text-align:center;">Qty/Case</th>
                    <th style="text-align:right;">In Stock</th>
                    <th style="text-align:right;">SRP</th>
                    <th style="text-align:right;">Wholesale</th>
                    <th style="text-align:right;">Dealer</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($catalogList): ?>
                    <?php foreach ($catalogList as $p): ?>
                        <?php 
                        $totalStock = (float)$p['quantity'];
                        $isLow = $totalStock <= (float)$p['reorder_level'];
                        $isOut = $totalStock <= 0;
                        $perCase = max(1, (int)$p['qty_per_case']);
                        $hasRecipe = !empty($p['has_recipe']);
                        ?>
                        <tr>
                            <td><span class="badge" style="background:#f1f3f4; color:#37474f; font-weight:700;"><?= e($p['brand']) ?></span></td>
                            <td>
                                <strong><?= e($p['name']) ?></strong>
                                <?php if ($hasRecipe): ?>
                                    <span class="badge" style="background:#eef7f2; color:#176b5b; font-size:10px; margin-left:4px;">Recipe Linked</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:#eef7f2; color:#176b5b; font-size:11px;"><?= e($p['category']) ?></span></td>
                            <td style="text-align:center; color:var(--muted);"><?= $perCase ?> <?= e($p['unit']) ?>/cs</td>
                            <td style="text-align:right; font-weight:700; color:<?= ($hasRecipe && $isOut) ? '#176b5b' : ($isOut ? 'var(--danger)' : ($isLow ? '#d32f2f' : 'var(--text)')) ?>;">
                                <?php if ($hasRecipe && $totalStock <= 0): ?>
                                    <span style="font-size:12px; font-weight:600; color:#176b5b;">Made to Order</span>
                                    <small class="muted" style="display:block; font-size:11px;">(Raw Ingredients)</small>
                                <?php else: ?>
                                    <?= number_format($totalStock) ?> <?= e($p['unit']) ?>
                                    <?php if ($perCase > 1): ?>
                                        <small class="muted">(~<?= number_format($totalStock / $perCase, 1) ?> cs)</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; font-weight:600;">₱<?= number_format((float)$p['srp'], 2) ?></td>
                            <td style="text-align:right; color:#2e7d32;">₱<?= number_format((float)$p['wholesale_price'], 2) ?></td>
                            <td style="text-align:right; color:#0277bd;">₱<?= number_format((float)$p['dealer_price'], 2) ?></td>
                            <td style="text-align:center;">
                                <?php if ($hasRecipe && $totalStock <= 0): ?>
                                    <span class="badge" style="background:#eef7f2; color:#176b5b; font-weight:700;">MADE TO ORDER</span>
                                <?php elseif ($isOut): ?>
                                    <span class="badge danger-badge" style="font-weight:700;">OUT OF STOCK</span>
                                <?php elseif ($isLow): ?>
                                    <span class="badge" style="background:#fff3e0; color:#e65100; font-weight:700;">LOW (≤<?= $p['reorder_level'] ?>)</span>
                                <?php else: ?>
                                    <span class="badge ok-badge">IN STOCK</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; white-space:nowrap;">
                                <button class="btn secondary btn-sm" type="button" 
                                        onclick="editProductModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)">
                                    Edit
                                </button>
                                <button class="btn secondary btn-sm" type="button" 
                                        onclick="quickAdjust(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>')">
                                    Adjust
                                </button>
                                <?php if ($isSupervisor): ?>
                                    <a class="btn secondary btn-sm" href="recipes.php?product_id=<?= (int)$p['id'] ?>" title="Manage Recipe">Recipe</a>
                                    <form method="post" action="inventory.php" style="display:inline;" onsubmit="return confirm('Archive <?= e(addslashes($p['name'])) ?> from catalog?');">
                                        <input type="hidden" name="action" value="archive_product">
                                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                        <button class="btn secondary btn-sm" type="submit" style="color:var(--danger);">&times;</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="13" class="muted" style="text-align:center; padding:30px;">No catalog products found matching your filter criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal: Add / Edit Product -->
<div class="modal" id="product-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeProductModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:680px; padding:24px; border-radius:10px; background:#fff; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 id="product-modal-title" style="margin:0;">Add New Catalog Item</h3>
            <button type="button" onclick="closeProductModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="inventory.php" class="form-grid">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="product_id" id="prod-id" value="0">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Brand *</span>
                    <input type="text" name="brand" id="prod-brand" list="brand-datalist" required placeholder="e.g. EASY, NBR, ARLA...">
                    <datalist id="brand-datalist">
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= e($b['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Category / Type *</span>
                    <input type="text" name="category" id="prod-category" list="cat-datalist" required placeholder="e.g. MILKTEA, POWDERS, SAUCE...">
                    <datalist id="cat-datalist">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
            </div>

            <label>
                <span style="font-size:12px; font-weight:600;">Item / Product Name *</span>
                <input type="text" name="name" id="prod-name" required placeholder="e.g. MILK ESSENCE 1KG, FLAT LID THICK 100's...">
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Qty Per Case *</span>
                    <input type="number" name="qty_per_case" id="prod-qty-case" min="1" step="1" value="10" required>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Stock Unit</span>
                    <input type="text" name="unit" id="prod-unit" value="pack" placeholder="pack, bottle, can, pc...">
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Reorder Alert Level</span>
                    <input type="number" name="reorder_level" id="prod-reorder" min="0" step="1" value="10">
                </label>
            </div>

            <div style="background:#f7faf8; padding:14px; border-radius:8px; margin:8px 0 14px 0;">
                <h4 style="margin:0 0 10px 0; font-size:13px; text-transform:uppercase; color:var(--primary);">Pricing Matrix (Price Tiers)</h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <label>
                        <span style="font-size:12px; font-weight:600;">SRP (Retail Unit Price) *</span>
                        <input type="number" name="srp" id="prod-srp" min="0" step="0.01" value="0.00" required>
                    </label>
                    <label>
                        <span style="font-size:12px; font-weight:600;">Wholesale Price (Case/Tier)</span>
                        <input type="number" name="wholesale_price" id="prod-wholesale" min="0" step="0.01" value="0.00">
                    </label>
                    <label>
                        <span style="font-size:12px; font-weight:600;">Bulk Volume Price</span>
                        <input type="number" name="bulk_price" id="prod-bulk" min="0" step="0.01" value="0.00">
                    </label>
                    <label>
                        <span style="font-size:12px; font-weight:600;">Dealer Price</span>
                        <input type="number" name="dealer_price" id="prod-dealer" min="0" step="0.01" value="0.00">
                    </label>
                    <label style="grid-column: span 2;">
                        <span style="font-size:12px; font-weight:600;">Reseller Price</span>
                        <input type="number" name="reseller_price" id="prod-reseller" min="0" step="0.01" value="0.00">
                    </label>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button class="btn secondary" type="button" onclick="closeProductModal()">Cancel</button>
                <button class="btn primary" type="submit" id="prod-submit-btn">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Quick Adjust Stock -->
<div class="modal" id="adjust-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeStockAdjustModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:480px; padding:24px; border-radius:10px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">Quick Stock Adjustment</h3>
            <button type="button" onclick="closeStockAdjustModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="inventory.php" class="form-grid">
            <input type="hidden" name="action" value="adjust_stock">

            <label>
                <span style="font-size:12px; font-weight:600;">Select Product *</span>
                <select name="product_id" id="adjust-prod-id" required style="width:100%;">
                    <?php foreach ($catalogList as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">[<?= e($p['brand']) ?>] <?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <input type="hidden" name="branch_id" value="1">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Adjustment Type</span>
                    <select name="adjust_type" required>
                        <option value="add">+ Add Stock (Inbound/Count)</option>
                        <option value="subtract">- Deduct Stock (Correction)</option>
                    </select>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Units Quantity *</span>
                    <input type="number" name="adjust_quantity" step="1" min="1" required placeholder="Quantity">
                </label>
            </div>

            <label>
                <span style="font-size:12px; font-weight:600;">Reason / Reference *</span>
                <input type="text" name="reason" required placeholder="e.g. Physical inventory count, Sample received...">
            </label>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px;">
                <button class="btn secondary" type="button" onclick="closeStockAdjustModal()">Cancel</button>
                <button class="btn primary" type="submit">Update Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProductModal() {
    document.getElementById('prod-id').value = 0;
    document.getElementById('prod-brand').value = 'EASY';
    document.getElementById('prod-category').value = 'BASE';
    document.getElementById('prod-name').value = '';
    document.getElementById('prod-qty-case').value = 10;
    document.getElementById('prod-unit').value = 'pack';
    document.getElementById('prod-reorder').value = 10;
    document.getElementById('prod-srp').value = '0.00';
    document.getElementById('prod-wholesale').value = '0.00';
    document.getElementById('prod-bulk').value = '0.00';
    document.getElementById('prod-dealer').value = '0.00';
    document.getElementById('prod-reseller').value = '0.00';
    document.getElementById('product-modal-title').textContent = 'Add New Catalog Item';
    document.getElementById('prod-submit-btn').textContent = 'Add Product';
    const m = document.getElementById('product-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function editProductModal(p) {
    document.getElementById('prod-id').value = p.id;
    document.getElementById('prod-brand').value = p.brand;
    document.getElementById('prod-category').value = p.category;
    document.getElementById('prod-name').value = p.name;
    document.getElementById('prod-qty-case').value = p.qty_per_case || 1;
    document.getElementById('prod-unit').value = p.unit || 'pc';
    document.getElementById('prod-reorder').value = p.reorder_level || 10;
    document.getElementById('prod-srp').value = parseFloat(p.srp || 0).toFixed(2);
    document.getElementById('prod-wholesale').value = parseFloat(p.wholesale_price || 0).toFixed(2);
    document.getElementById('prod-bulk').value = parseFloat(p.bulk_price || 0).toFixed(2);
    document.getElementById('prod-dealer').value = parseFloat(p.dealer_price || 0).toFixed(2);
    document.getElementById('prod-reseller').value = parseFloat(p.reseller_price || 0).toFixed(2);
    document.getElementById('product-modal-title').textContent = 'Edit Product: ' + p.name;
    document.getElementById('prod-submit-btn').textContent = 'Update Product';
    const m = document.getElementById('product-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function closeProductModal() {
    document.getElementById('product-modal').style.display = 'none';
    document.getElementById('product-modal').setAttribute('aria-hidden', 'true');
}

function openStockAdjustModal() {
    const m = document.getElementById('adjust-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function quickAdjust(productId, productName) {
    document.getElementById('adjust-prod-id').value = productId;
    openStockAdjustModal();
}

function closeStockAdjustModal() {
    document.getElementById('adjust-modal').style.display = 'none';
    document.getElementById('adjust-modal').setAttribute('aria-hidden', 'true');
}
</script>

<?php include 'partials/footer.php'; ?>
