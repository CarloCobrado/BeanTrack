<?php
require 'config.php';
require_role(['Supervisor', 'Cashier', 'Administrator']);

$msg = '';
$error = '';
$lastTx = $_SESSION['last_transaction'] ?? null;
unset($_SESSION['last_transaction']);

// Handle AJAX order details request
if (isset($_GET['action']) && $_GET['action'] === 'get_order_details') {
    $txId = (int)($_GET['tx_id'] ?? 0);
    $tx = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $tx->execute([$txId]);
    $txRow = $tx->fetch();
    if (!$txRow) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    $items = $pdo->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id ASC");
    $items->execute([$txId]);
    $itemRows = $items->fetchAll();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'transaction' => $txRow, 'items' => $itemRows]);
    exit;
}

// 1. Handle CSV Export matching exact 19-column client format
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = "
        SELECT 
            t.transaction_date,
            t.customer_name,
            t.bundle,
            t.branch_name,
            t.payment_method,
            ti.brand_name,
            ti.product_name,
            ti.remarks AS item_remarks,
            ti.price_type,
            ti.ordered_qty,
            ti.unit_qty,
            ti.unit_price,
            ti.subtotal,
            ti.price_seq,
            ti.bundle_seq
        FROM transactions t
        JOIN transaction_items ti ON ti.transaction_id = t.id
        ORDER BY t.transaction_date DESC, t.id DESC, ti.id ASC
    ";
    $rows = $pdo->query($exportSql)->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_transactions_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');

    // Exact client header:
    fputcsv($out, [
        'DATE', 'MONTH', 'DAY', 'YEAR', 'M+Y', 
        'CUSTOMER', 'BUNDLE', 'BRANCH', 'BRAND', 'ITEM', 
        'REMARKS', 'PRICE TYPE', 'MOP', 'QTY', 'U-QTY', 
        'U-PRICE', 'Amount', 'PRICE SEQ', 'BUNDLE SEQ'
    ]);

    foreach ($rows as $r) {
        $txDate = $r['transaction_date'] ?: date('Y-m-d');
        $ts = strtotime($txDate);
        $month = date('M', $ts);
        $day = date('D', $ts);
        $year = date('Y', $ts);
        $my = date('Y M', $ts);

        fputcsv($out, [
            $txDate,
            $month,
            $day,
            $year,
            $my,
            $r['customer_name'] ?: 'WALK-IN',
            $r['bundle'] ?: '',
            $r['branch_name'] ?: 'DAVAO',
            $r['brand_name'],
            $r['product_name'],
            $r['item_remarks'] ?: '',
            $r['price_type'] ?: 'SRP',
            $r['payment_method'] ?: 'Cash',
            (float)$r['ordered_qty'],
            (float)$r['unit_qty'],
            (float)$r['unit_price'],
            (float)$r['subtotal'],
            $r['price_seq'] ?: '',
            $r['bundle_seq'] ?: 'NOT A BUNDLE'
        ]);
    }
    fclose($out);
    exit;
}

// 2. Handle POST New Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_transaction';
    if ($action === 'create_transaction') {
        $txDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $branchId = (int)($_POST['branch_id'] ?? 1);
        $customerName = trim($_POST['customer_name'] ?? 'WALK-IN');
        $orderPriceType = trim($_POST['default_price_type'] ?? 'SRP');
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $orderBundle = trim($_POST['bundle'] ?? '');
        $orderRemarks = trim($_POST['remarks'] ?? '');

        $rawItems = $_POST['items'] ?? [];

        try {
            if (empty($rawItems) || !is_array($rawItems)) {
                throw new Exception('Please add at least one line item to the order.');
            }

            // Verify branch
            $stmtB = $pdo->prepare("SELECT id, code, name FROM branches WHERE id=? AND active=1");
            $stmtB->execute([$branchId]);
            $branch = $stmtB->fetch();
            if (!$branch) throw new Exception('Invalid branch selected.');

            // Find or insert customer
            $stmtC = $pdo->prepare("SELECT id, name, default_price_type FROM customers WHERE name=?");
            $stmtC->execute([$customerName]);
            $cust = $stmtC->fetch();
            $customerId = $cust ? (int)$cust['id'] : null;
            if (!$cust && $customerName !== '' && $customerName !== 'WALK-IN') {
                $stmtNewC = $pdo->prepare("INSERT INTO customers (name, default_price_type) VALUES (?, ?)");
                $stmtNewC->execute([$customerName, $orderPriceType]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Consolidate and validate product IDs
            $cleanedItems = [];
            $requiredUnitsByProduct = [];

            foreach ($rawItems as $idx => $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                $uQty = (float)($it['unit_qty'] ?? 0);
                $unitPrice = (float)($it['unit_price'] ?? 0);
                $pType = trim($it['price_type'] ?? $orderPriceType);
                $itemRemarks = trim($it['remarks'] ?? '');
                $bundleSeq = trim($it['bundle_seq'] ?? 'NOT A BUNDLE');
                $priceSeq = trim($it['price_seq'] ?? '');

                if ($pid < 1 || $qty <= 0) {
                    continue; // Skip blank or invalid rows
                }

                $subtotal = (float)($it['subtotal'] ?? ($qty * $unitPrice));

                $cleanedItems[] = [
                    'product_id' => $pid,
                    'price_type' => $pType,
                    'ordered_qty' => $qty,
                    'unit_qty' => $uQty > 0 ? $uQty : $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'remarks' => $itemRemarks,
                    'bundle_seq' => $bundleSeq ?: 'NOT A BUNDLE',
                    'price_seq' => $priceSeq ?: null
                ];

                $requiredUnitsByProduct[$pid] = ($requiredUnitsByProduct[$pid] ?? 0) + ($uQty > 0 ? $uQty : $qty);
            }

            if (empty($cleanedItems)) {
                throw new Exception('No valid line items found. Enter valid product and quantity.');
            }

            $productIds = array_keys($requiredUnitsByProduct);
            sort($productIds, SORT_NUMERIC);

            $pdo->beginTransaction();

            // Lock products in deterministic order
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmtLockProd = $pdo->prepare("
                SELECT id, brand, name, category, qty_per_case, unit, srp, wholesale_price, bulk_price, dealer_price
                FROM products
                WHERE id IN ($placeholders) AND active=1
                ORDER BY id FOR UPDATE
            ");
            $stmtLockProd->execute($productIds);
            $lockedProducts = [];
            foreach ($stmtLockProd->fetchAll() as $row) {
                $lockedProducts[(int)$row['id']] = $row;
            }

            // Lock and fetch branch inventory
            $stmtLockInv = $pdo->prepare("
                SELECT product_id, quantity
                FROM branch_inventory
                WHERE branch_id=? AND product_id IN ($placeholders)
                ORDER BY product_id FOR UPDATE
            ");
            $params = array_merge([$branchId], $productIds);
            $stmtLockInv->execute($params);
            $branchStock = [];
            foreach ($stmtLockInv->fetchAll() as $row) {
                $branchStock[(int)$row['product_id']] = (float)$row['quantity'];
            }

            // Fetch recipe items for products in order
            $stmtRecipes = $pdo->prepare("
                SELECT pri.product_id, pri.ingredient_id, pri.quantity_required, 
                       i.name AS ingredient_name, i.unit AS ingredient_unit, i.quantity AS ingredient_stock
                FROM product_recipe_items pri
                JOIN ingredients i ON i.id = pri.ingredient_id
                WHERE pri.product_id IN ($placeholders) AND i.active = 1
                ORDER BY pri.ingredient_id
            ");
            $stmtRecipes->execute($productIds);
            $recipeRows = $stmtRecipes->fetchAll();

            $productRecipes = [];
            $totalIngredientsNeeded = [];
            foreach ($recipeRows as $r) {
                $pid = (int)$r['product_id'];
                $ingId = (int)$r['ingredient_id'];
                $productRecipes[$pid][] = $r;

                $unitsSold = (float)($requiredUnitsByProduct[$pid] ?? 0);
                $qtyNeeded = $unitsSold * (float)$r['quantity_required'];
                if (!isset($totalIngredientsNeeded[$ingId])) {
                    $totalIngredientsNeeded[$ingId] = [
                        'name' => $r['ingredient_name'],
                        'unit' => $r['ingredient_unit'],
                        'needed' => 0.0,
                        'stock' => (float)$r['ingredient_stock']
                    ];
                }
                $totalIngredientsNeeded[$ingId]['needed'] += $qtyNeeded;
            }

            // Lock and verify ingredients stock
            if (!empty($totalIngredientsNeeded)) {
                $ingIds = array_keys($totalIngredientsNeeded);
                sort($ingIds, SORT_NUMERIC);
                $ingPlaceholders = implode(',', array_fill(0, count($ingIds), '?'));
                $stmtLockIng = $pdo->prepare("
                    SELECT id, name, unit, quantity 
                    FROM ingredients 
                    WHERE id IN ($ingPlaceholders) AND active = 1 
                    ORDER BY id FOR UPDATE
                ");
                $stmtLockIng->execute($ingIds);
                foreach ($stmtLockIng->fetchAll() as $row) {
                    $ingId = (int)$row['id'];
                    $currStock = (float)$row['quantity'];
                    $needed = $totalIngredientsNeeded[$ingId]['needed'];
                    if ($currStock < $needed) {
                        throw new Exception("Insufficient ingredient stock for '{$row['name']}'. Available: " . number_format($currStock, 2) . " {$row['unit']}, Required: " . number_format($needed, 2) . " {$row['unit']}.");
                    }
                }
            }

            // Verify stock
            foreach ($requiredUnitsByProduct as $pid => $reqQty) {
                if (!isset($lockedProducts[$pid])) {
                    throw new Exception("Product ID #$pid is inactive or not found.");
                }
                $hasRecipe = !empty($productRecipes[$pid]);
                $avail = $branchStock[$pid] ?? 0.0;
                // If it has no recipe, it is packaged finished goods stock and must have branch stock
                if (!$hasRecipe && $avail < $reqQty) {
                    $pName = $lockedProducts[$pid]['name'];
                    throw new Exception("Insufficient stock for '$pName' at {$branch['name']}. Available: " . number_format($avail) . ", Requested: " . number_format($reqQty) . ".");
                }
            }

            // Calculate overall total amount
            $orderTotal = 0.0;
            foreach ($cleanedItems as $it) {
                $orderTotal += (float)$it['subtotal'];
            }

            // Insert transaction header
            $stmtTx = $pdo->prepare("
                INSERT INTO transactions (
                    cashier_id, customer_id, customer_name, branch_id, branch_name,
                    transaction_date, payment_method, payment_status, bundle, total_amount, remarks
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, 'Paid', ?, ?, ?
                )
            ");
            $stmtTx->execute([
                $_SESSION['user']['id'] ?? 1,
                $customerId,
                $customerName ?: 'WALK-IN',
                $branchId,
                $branch['code'],
                $txDate,
                $paymentMethod,
                $orderBundle ?: null,
                $orderTotal,
                $orderRemarks ?: null
            ]);
            $transactionId = (int)$pdo->lastInsertId();

            // Insert transaction line items & deduct stock
            $stmtItemInsert = $pdo->prepare("
                INSERT INTO transaction_items (
                    transaction_id, product_id, branch_name, brand_name, product_name,
                    price_type, ordered_qty, unit_qty, unit_price, subtotal, price_seq, bundle_seq, remarks
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmtDeductBranch = $pdo->prepare("
                UPDATE branch_inventory 
                SET quantity = quantity - ? 
                WHERE branch_id = ? AND product_id = ? AND quantity >= ?
            ");

            $stmtDeductGlobal = $pdo->prepare("
                UPDATE products 
                SET quantity = GREATEST(0, quantity - ?) 
                WHERE id = ?
            ");

            $stmtDeductIngredient = $pdo->prepare("
                UPDATE ingredients 
                SET quantity = GREATEST(0, quantity - ?) 
                WHERE id = ?
            ");

            $deductedIngredientsSummary = [];
            foreach ($cleanedItems as $it) {
                $pid = (int)$it['product_id'];
                $p = $lockedProducts[$pid];
                $stmtItemInsert->execute([
                    $transactionId,
                    $pid,
                    $branch['code'],
                    $p['brand'],
                    $p['name'],
                    $it['price_type'],
                    $it['ordered_qty'],
                    $it['unit_qty'],
                    $it['unit_price'],
                    $it['subtotal'],
                    $it['price_seq'],
                    $it['bundle_seq'],
                    $it['remarks'] ?: null
                ]);

                // Deduct from branch inventory (if in stock)
                $stmtDeductBranch->execute([$it['unit_qty'], $branchId, $pid, $it['unit_qty']]);
                // Deduct from global summary
                $stmtDeductGlobal->execute([$it['unit_qty'], $pid]);

                // Deduct recipe ingredients if product has a recipe
                if (!empty($productRecipes[$pid])) {
                    foreach ($productRecipes[$pid] as $recipe) {
                        $ingId = (int)$recipe['ingredient_id'];
                        $qtyDeduct = (float)$it['unit_qty'] * (float)$recipe['quantity_required'];
                        if ($qtyDeduct > 0) {
                            $stmtDeductIngredient->execute([$qtyDeduct, $ingId]);
                            $deductedIngredientsSummary[$ingId] = [
                                'name' => $recipe['ingredient_name'],
                                'unit' => $recipe['ingredient_unit'],
                                'qty' => ($deductedIngredientsSummary[$ingId]['qty'] ?? 0.0) + $qtyDeduct
                            ];
                        }
                    }
                }
            }

            // Audit Trail with SHA-256 chain
            $auditNote = "Sales Order #$transactionId for $customerName at {$branch['code']}; Total=₱" . number_format($orderTotal, 2) . " ($paymentMethod); Items count=" . count($cleanedItems);
            if (!empty($deductedIngredientsSummary)) {
                $ingNotes = [];
                foreach ($deductedIngredientsSummary as $di) {
                    $ingNotes[] = number_format($di['qty'], 2) . ' ' . $di['unit'] . ' ' . $di['name'];
                }
                $auditNote .= "; Ingredients used: [" . implode(', ', $ingNotes) . "]";
            }
            audit(
                'Create Sale Transaction',
                'transactions',
                $transactionId,
                $auditNote
            );

            $pdo->commit();

            $_SESSION['last_transaction'] = [
                'id' => $transactionId,
                'date' => $txDate,
                'customer' => $customerName,
                'branch' => $branch['name'],
                'total' => $orderTotal,
                'mop' => $paymentMethod,
                'items_count' => count($cleanedItems)
            ];

            header('Location: transactions.php?success=1');
            exit;

        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $t->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $msg = 'Sale transaction completed and stock deducted successfully!';
}

// 3. Load catalog & lookup data
$branches = $pdo->query("SELECT id, code, name FROM branches WHERE active=1 ORDER BY id")->fetchAll();
$customers = $pdo->query("SELECT id, name, default_price_type FROM customers WHERE active=1 ORDER BY name")->fetchAll();

// Fetch products with real-time stock per branch
$productsQuery = "
    SELECT p.id, p.brand, p.name, p.category, p.qty_per_case, p.unit,
           COALESCE(NULLIF(p.srp, 0), p.unit_price, 0) AS srp,
           p.wholesale_price, p.bulk_price, p.dealer_price, p.reseller_price,
           p.unit_price,
           p.quantity AS total_stock,
           EXISTS(SELECT 1 FROM product_recipe_items pri WHERE pri.product_id = p.id) AS has_recipe
    FROM products p
    WHERE p.active = 1
    ORDER BY p.brand ASC, p.name ASC
";
$catalogProducts = $pdo->query($productsQuery)->fetchAll();
foreach ($catalogProducts as &$cp) {
    $cp['id'] = (int)$cp['id'];
    $cp['qty_per_case'] = max(1, (int)$cp['qty_per_case']);
    $cp['srp'] = (float)($cp['srp'] > 0 ? $cp['srp'] : ($cp['unit_price'] ?? 0));
    $cp['unit_price'] = (float)$cp['unit_price'];
    $cp['wholesale_price'] = (float)$cp['wholesale_price'];
    $cp['bulk_price'] = (float)$cp['bulk_price'];
    $cp['dealer_price'] = (float)$cp['dealer_price'];
    $cp['reseller_price'] = (float)$cp['reseller_price'];
    $cp['total_stock'] = (float)$cp['total_stock'];
    $cp['has_recipe'] = (bool)$cp['has_recipe'];
}
unset($cp);

// Fetch branch inventory map [branch_id][product_id] => qty
$branchStockRows = $pdo->query("SELECT branch_id, product_id, quantity FROM branch_inventory")->fetchAll();
$stockMap = [];
foreach ($branchStockRows as $r) {
    $stockMap[(int)$r['branch_id']][(int)$r['product_id']] = (float)$r['quantity'];
}

// 4. Filters & Transaction History Query with Pagination (25 per page)
$search = trim($_GET['search'] ?? '');
$filterCustomer = trim($_GET['customer'] ?? '');
$filterBranch = (int)($_GET['branch_id'] ?? 0);
$filterDateFrom = trim($_GET['from'] ?? '');
$filterDateTo = trim($_GET['to'] ?? '');

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = " WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (t.customer_name LIKE ? OR t.bundle LIKE ? OR t.remarks LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterCustomer !== '') {
    $where .= " AND t.customer_name = ?";
    $params[] = $filterCustomer;
}
if ($filterBranch > 0) {
    $where .= " AND t.branch_id = ?";
    $params[] = $filterBranch;
}
if ($filterDateFrom !== '') {
    $where .= " AND t.transaction_date >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where .= " AND t.transaction_date <= ?";
    $params[] = $filterDateTo;
}

// Total distinct matching orders count
$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM transactions t" . $where);
$countStmt->execute($params);
$totalOrders = (int)$countStmt->fetchColumn();

// Total matching line items count
$itemsCountStmt = $pdo->prepare("SELECT COUNT(ti.id) FROM transactions t LEFT JOIN transaction_items ti ON ti.transaction_id = t.id" . $where);
$itemsCountStmt->execute($params);
$totalLineItems = (int)$itemsCountStmt->fetchColumn();

$totalPages = $totalOrders > 0 ? (int)ceil($totalOrders / $perPage) : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$histSql = "
    SELECT t.*, u.name AS cashier_name,
           COUNT(ti.id) AS item_count,
           GROUP_CONCAT(DISTINCT ti.price_type SEPARATOR ', ') AS price_types_used
    FROM transactions t
    LEFT JOIN users u ON u.id = t.cashier_id
    LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
    $where
    GROUP BY t.id
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT $perPage OFFSET $offset
";
$histStmt = $pdo->prepare($histSql);
$histStmt->execute($params);
$transactionsHistory = $histStmt->fetchAll();

$startEntry = $totalOrders > 0 ? $offset + 1 : 0;
$endEntry = min($offset + count($transactionsHistory), $totalOrders);
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);

function tx_page_url(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return 'transactions.php?' . http_build_query($q);
}

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Sales &amp; POS Checkout</h2>
        <p class="muted" style="margin:0;">Multi-item wholesale and retail sales order entry conforming to client distribution formats.</p>
    </div>
    <div>
        <button class="btn primary" type="button" onclick="focusPosOrder()">+ New Sales Order</button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert success" style="margin-bottom:16px;">
        <?= e($msg) ?>
        <?php if ($lastTx): ?>
            <strong>Order #<?= (int)$lastTx['id'] ?>:</strong> ₱<?= number_format((float)$lastTx['total'], 2) ?> for <?= e($lastTx['customer']) ?> (<?= e($lastTx['branch']) ?>).
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert danger" style="margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<!-- POS Order Entry Card -->
<section class="card" id="pos-card" style="margin-bottom:24px; padding:20px;">
    <h3 style="margin-top:0; margin-bottom:16px; display:flex; align-items:center; gap:8px;">
        <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:var(--primary);"></span>
        Order Entry &amp; Cashier Checkout
    </h3>

    <form method="post" action="transactions.php" id="pos-form">
        <input type="hidden" name="action" value="create_transaction">

        <!-- Header Fields: Date, Customer, Price Tier, MOP -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px; padding-bottom:16px; border-bottom:1px solid var(--line);">
            <label style="margin:0;">
                <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Date *</span>
                <input type="date" name="transaction_date" id="order-date" required value="<?= date('Y-m-d') ?>" style="width:100%;">
            </label>

            <input type="hidden" name="branch_id" id="order-branch" value="1">

            <label style="margin:0;">
                <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Customer Account *</span>
                <input type="text" name="customer_name" id="order-customer" list="cust-datalist" required placeholder="Type or select client..." oninput="onCustomerInput()" style="width:100%;">
                <datalist id="cust-datalist">
                    <option value="WALK-IN" data-tier="SRP">WALK-IN (Retail)</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= e($c['name']) ?>" data-tier="<?= e($c['default_price_type']) ?>">
                            <?= e($c['name']) ?> (<?= e($c['default_price_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </datalist>
            </label>

            <label style="margin:0;">
                <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Default Price Tier *</span>
                <select name="default_price_type" id="order-tier" required onchange="onDefaultTierChange()" style="width:100%;">
                    <option value="SRP">SRP (Retail)</option>
                    <option value="WHOLESALE">WHOLESALE</option>
                    <option value="BULK">BULK</option>
                    <option value="DEALER">DEALER</option>
                    <option value="RESELLER">RESELLER</option>
                </select>
            </label>

            <label style="margin:0;">
                <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Payment Method (MOP) *</span>
                <select name="payment_method" required style="width:100%;">
                    <option value="Cash">Cash</option>
                    <option value="GCash">GCash / Online</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Check">Check</option>
                    <option value="Terms">Terms / Credit Account</option>
                </select>
            </label>
        </div>

        <!-- Line Items Table -->
        <div style="margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:13px; font-weight:700; text-transform:uppercase; color:var(--text);">Order Line Items</span>
                <button class="btn secondary btn-sm" type="button" onclick="addLineItem()">+ Add Another Product</button>
            </div>

            <div class="table-wrap" style="overflow-x:auto;">
                <table style="width:100%; min-width:850px; font-size:13px;" id="items-table">
                    <thead>
                        <tr style="background:#f7faf8;">
                            <th style="width:30%;">Product (Brand &amp; Item)</th>
                            <th style="width:13%;">Price Tier</th>
                            <th style="width:10%;">Unit Mode</th>
                            <th style="width:8%; text-align:right;">Qty</th>
                            <th style="width:9%; text-align:right;">U-Qty (Units)</th>
                            <th style="width:10%; text-align:right;">Price (₱)</th>
                            <th style="width:10%; text-align:right;">Amount (₱)</th>
                            <th style="width:10%;">Remarks</th>
                            <th style="width:4%; text-align:center;"></th>
                        </tr>
                    </thead>
                    <tbody id="items-tbody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bottom Controls & Total -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; background:#f9fbf9; padding:16px; border-radius:8px;">
            <div style="flex:1; min-width:280px; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label style="margin:0;">
                    <span style="font-size:12px; font-weight:600; color:var(--muted);">Bundle Name / Code (optional)</span>
                    <input type="text" name="bundle" placeholder="e.g. SUMMER PROMO">
                </label>
                <label style="margin:0;">
                    <span style="font-size:12px; font-weight:600; color:var(--muted);">Order Remarks (optional)</span>
                    <input type="text" name="remarks" placeholder="e.g. Free delivery, Promo bundle...">
                </label>
            </div>

            <div style="text-align:right; min-width:220px;">
                <div style="font-size:12px; color:var(--muted); text-transform:uppercase; font-weight:600;">Total Payable</div>
                <div style="font-size:28px; font-weight:800; color:var(--primary); margin:4px 0 12px 0;" id="total-display">₱0.00</div>
                <button class="btn primary" type="submit" style="padding:10px 24px; font-size:15px; font-weight:700;">Complete Transaction</button>
            </div>
        </div>
    </form>
</section>

<!-- Filter Orders Bar -->
<section class="card" style="margin-bottom:20px; padding:16px;">
    <form method="get" action="transactions.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <input type="hidden" name="page" value="1">
        <label style="flex:1; min-width:200px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search Orders</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Customer, bundle, remarks..." style="width:100%;">
        </label>
        <label style="width:180px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Customer</span>
            <input type="text" name="customer" value="<?= e($filterCustomer) ?>" list="cust-datalist" placeholder="All Customers" style="width:100%;">
        </label>
        <label style="width:140px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">From Date</span>
            <input type="date" name="from" value="<?= e($filterDateFrom) ?>" style="width:100%;">
        </label>
        <label style="width:140px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">To Date</span>
            <input type="date" name="to" value="<?= e($filterDateTo) ?>" style="width:100%;">
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterCustomer !== '' || $filterBranch > 0 || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
                <a class="btn secondary" href="transactions.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Sales History Table -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:12px;">
        <h3 style="margin:0;">
            Transactions Record 
            <span style="font-size:13px; font-weight:normal; color:var(--muted);">
                (Showing <?= $startEntry ?>&ndash;<?= $endEntry ?> of <?= $totalOrders ?> Orders &middot; <?= $totalLineItems ?> Line Items)
            </span>
        </h3>
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <span class="muted" style="font-size:12px;">Page <?= $page ?> of <?= $totalPages ?></span>
            <a class="btn secondary btn-sm" href="transactions.php?export=csv" title="Export full transaction history in 19-column client CSV format">
                Download CSV (Client 19-Col Format)
            </a>
        </div>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Payment Method</th>
                    <th>Pricing Tier(s)</th>
                    <th style="text-align:right;">Lines</th>
                    <th style="text-align:right;">Total Amount</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactionsHistory): ?>
                    <?php foreach ($transactionsHistory as $tx): ?>
                        <tr>
                            <td><strong>#<?= (int)$tx['id'] ?></strong></td>
                            <td><?= e($tx['transaction_date']) ?></td>
                            <td><strong><?= e($tx['customer_name']) ?></strong></td>
                            <td><?= e($tx['payment_method']) ?></td>
                            <td><span class="badge" style="background:#f1f3f4; color:#37474f; font-size:11px;"><?= e($tx['price_types_used']) ?></span></td>
                            <td style="text-align:right;"><?= (int)$tx['item_count'] ?></td>
                            <td style="text-align:right; font-weight:700; color:var(--primary);">₱<?= number_format((float)$tx['total_amount'], 2) ?></td>
                            <td style="text-align:center;">
                                <button class="btn secondary btn-sm" type="button" onclick="viewOrderDetails(<?= (int)$tx['id'] ?>)">View Items</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="muted" style="text-align:center; padding:30px;">No sales transactions found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="section-head" style="margin-top:1.2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <a class="btn secondary btn-sm" href="<?= tx_page_url($prevPage) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none; opacity:0.5;"' : '' ?>>&laquo; Previous</a>
            
            <div style="display:flex; gap:4px; align-items:center;">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="btn primary btn-sm" style="min-width:32px; padding:4px 8px; text-align:center;"><?= $p ?></span>
                    <?php elseif ($p == 1 || $p == $totalPages || abs($p - $page) <= 2): ?>
                        <a class="btn secondary btn-sm" href="<?= tx_page_url($p) ?>" style="min-width:32px; padding:4px 8px; text-align:center;"><?= $p ?></a>
                    <?php elseif (abs($p - $page) == 3): ?>
                        <span style="padding:0 4px; color:var(--muted);">&hellip;</span>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>

            <a class="btn secondary btn-sm" href="<?= tx_page_url($nextPage) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none; opacity:0.5;"' : '' ?>>Next &raquo;</a>
        </div>
    <?php endif; ?>
</section>

<!-- Order Details Modal -->
<div class="modal" id="order-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeOrderModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:850px; padding:24px; border-radius:10px; background:#fff; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 id="order-modal-title" style="margin:0;">Order Breakdown</h3>
            <button type="button" onclick="closeOrderModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <div id="order-modal-content">
            <p class="muted">Loading item lines...</p>
        </div>
    </div>
</div>

<!-- Raw Catalog Data for JavaScript POS -->
<script>
const CATALOG = <?= json_encode($catalogProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const BRANCH_STOCK = <?= json_encode($stockMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

let rowCount = 0;

function focusPosOrder() {
    document.getElementById('pos-card').scrollIntoView({ behavior: 'smooth' });
}

function onBranchChange() {
    // Re-evaluate stock displays for all rows
    document.querySelectorAll('.item-row').forEach(row => {
        updateRowStockDisplay(row);
    });
}

function onCustomerInput() {
    const val = document.getElementById('order-customer').value.trim().toUpperCase();
    const opts = document.querySelectorAll('#cust-datalist option');
    for (let opt of opts) {
        if (opt.value.toUpperCase() === val) {
            const tier = opt.getAttribute('data-tier');
            if (tier) {
                document.getElementById('order-tier').value = tier;
                onDefaultTierChange();
            }
            break;
        }
    }
}

function onDefaultTierChange() {
    const defaultTier = document.getElementById('order-tier').value;
    document.querySelectorAll('.item-price-type').forEach(sel => {
        sel.value = defaultTier;
        const row = sel.closest('.item-row');
        updateRowPrice(row);
    });
    recalculateTotal();
}

function addLineItem(defaultPid = 0, defaultQty = 1) {
    const tbody = document.getElementById('items-tbody');
    const rowId = rowCount++;
    const defaultTier = document.getElementById('order-tier').value;

    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.id = 'row-' + rowId;

    let optionsHtml = '<option value="">-- Choose Product --</option>';
    let currentBrand = '';
    CATALOG.forEach(p => {
        if (p.brand !== currentBrand) {
            if (currentBrand !== '') optionsHtml += '</optgroup>';
            currentBrand = p.brand;
            optionsHtml += `<optgroup label="${escapeHtml(currentBrand)}">`;
        }
        optionsHtml += `<option value="${p.id}" ${p.id == defaultPid ? 'selected' : ''}>[${escapeHtml(p.brand)}] ${escapeHtml(p.name)} (${p.qty_per_case}/case)</option>`;
    });
    if (currentBrand !== '') optionsHtml += '</optgroup>';

    tr.innerHTML = `
        <td>
            <select name="items[${rowId}][product_id]" class="item-product" required onchange="onRowProductChange(this.closest('.item-row'))" style="width:100%;">
                ${optionsHtml}
            </select>
            <div class="row-stock-hint" style="font-size:11px; color:var(--muted); margin-top:2px;"></div>
        </td>
        <td>
            <select name="items[${rowId}][price_type]" class="item-price-type" onchange="updateRowPrice(this.closest('.item-row'))" style="width:100%;">
                <option value="SRP" ${defaultTier === 'SRP' ? 'selected' : ''}>SRP</option>
                <option value="WHOLESALE" ${defaultTier === 'WHOLESALE' ? 'selected' : ''}>WHOLESALE</option>
                <option value="BULK" ${defaultTier === 'BULK' ? 'selected' : ''}>BULK</option>
                <option value="DEALER" ${defaultTier === 'DEALER' ? 'selected' : ''}>DEALER</option>
                <option value="RESELLER" ${defaultTier === 'RESELLER' ? 'selected' : ''}>RESELLER</option>
            </select>
        </td>
        <td>
            <select class="item-unit-mode" onchange="onRowUnitModeChange(this.closest('.item-row'))" style="width:100%;">
                <option value="units">Units (pcs)</option>
                <option value="cases">Cases</option>
            </select>
        </td>
        <td>
            <input type="number" name="items[${rowId}][qty]" class="item-qty" step="any" min="1" value="${defaultQty}" required oninput="calculateRowAmount(this.closest('.item-row'))" onchange="calculateRowAmount(this.closest('.item-row'))" style="width:100%; text-align:right;">
        </td>
        <td>
            <input type="number" name="items[${rowId}][unit_qty]" class="item-u-qty" readonly style="width:100%; text-align:right; background:#f0f4f2; font-weight:600;">
        </td>
        <td>
            <input type="number" name="items[${rowId}][unit_price]" class="item-price" step="0.01" min="0" required oninput="onRowCustomPriceInput(this.closest('.item-row'))" onchange="onRowCustomPriceInput(this.closest('.item-row'))" style="width:100%; text-align:right;">
        </td>
        <td>
            <input type="number" name="items[${rowId}][subtotal]" class="item-subtotal" readonly style="width:100%; text-align:right; font-weight:700; color:var(--primary); background:#f0f4f2;">
        </td>
        <td>
            <input type="text" name="items[${rowId}][remarks]" placeholder="e.g. FREEBIES..." style="width:100%;">
            <input type="hidden" name="items[${rowId}][bundle_seq]" value="NOT A BUNDLE">
            <input type="hidden" name="items[${rowId}][price_seq]" value="${document.getElementById('order-date').value}">
        </td>
        <td style="text-align:center;">
            <button type="button" onclick="removeLineItem(this.closest('.item-row'))" style="background:none; border:none; color:var(--danger); font-size:18px; cursor:pointer;" title="Remove row">&times;</button>
        </td>
    `;

    tbody.appendChild(tr);
    onRowProductChange(tr);
}

function removeLineItem(row) {
    const tbody = document.getElementById('items-tbody');
    if (tbody.children.length > 1) {
        row.remove();
        recalculateTotal();
    } else {
        alert('An order requires at least one product line.');
    }
}

function getProductData(pid) {
    return CATALOG.find(p => p.id == pid) || null;
}

function onRowProductChange(row) {
    updateRowStockDisplay(row);
    updateRowPrice(row);
}

function updateRowStockDisplay(row) {
    const pid = row.querySelector('.item-product').value;
    const branchId = document.getElementById('order-branch').value;
    const hint = row.querySelector('.row-stock-hint');
    if (!pid) {
        hint.textContent = '';
        return;
    }
    const p = getProductData(pid);
    const stock = (BRANCH_STOCK[branchId] && BRANCH_STOCK[branchId][pid]) || 0;
    const perCase = p ? p.qty_per_case : 1;
    const cases = (stock / perCase).toFixed(1);
    if (p && p.has_recipe) {
        if (stock > 0) {
            hint.innerHTML = `<span style="color:#00796b; font-weight:600;">🌿 Made to Order</span> &bull; Prepped stock: <strong>${stock} units</strong> (Raw ingredients auto-deducted)`;
        } else {
            hint.innerHTML = `<span style="color:#00796b; font-weight:600;">🌿 Made to Order</span> &bull; Freshly prepared (Deducts raw ingredients on sale)`;
        }
    } else {
        hint.innerHTML = `Stock: <strong>${stock} units</strong> (~${cases} cases)`;
    }
}

function onRowUnitModeChange(row) {
    updateRowPrice(row);
}

function onRowQtyInput(row) {
    calculateRowAmount(row);
}

function onRowCustomPriceInput(row) {
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    row.querySelector('.item-subtotal').value = (qty * price).toFixed(2);
    recalculateTotal();
}

function updateRowPrice(row) {
    const pid = row.querySelector('.item-product').value;
    if (!pid) return;

    const p = getProductData(pid);
    if (!p) return;

    const tier = row.querySelector('.item-price-type').value;
    const mode = row.querySelector('.item-unit-mode').value;
    const perCase = parseFloat(p.qty_per_case) || 1;

    // Use srp or unit_price fallback
    const srpUnit = parseFloat(p.srp) > 0 ? parseFloat(p.srp) : (parseFloat(p.unit_price) || 0);
    const wsCase = parseFloat(p.wholesale_price) > 0 ? parseFloat(p.wholesale_price) : (srpUnit * perCase);
    const bulkCase = parseFloat(p.bulk_price) > 0 ? parseFloat(p.bulk_price) : wsCase;
    const dealerCase = parseFloat(p.dealer_price) > 0 ? parseFloat(p.dealer_price) : wsCase;
    const resellerCase = parseFloat(p.reseller_price) > 0 ? parseFloat(p.reseller_price) : (srpUnit * perCase);

    let effectivePrice = srpUnit;

    if (mode === 'cases') {
        if (tier === 'WHOLESALE') effectivePrice = wsCase;
        else if (tier === 'BULK') effectivePrice = bulkCase;
        else if (tier === 'DEALER') effectivePrice = dealerCase;
        else if (tier === 'RESELLER') effectivePrice = resellerCase;
        else effectivePrice = srpUnit * perCase;
    } else {
        if (tier === 'WHOLESALE') effectivePrice = wsCase / perCase;
        else if (tier === 'BULK') effectivePrice = bulkCase / perCase;
        else if (tier === 'DEALER') effectivePrice = dealerCase / perCase;
        else if (tier === 'RESELLER') effectivePrice = resellerCase / perCase;
        else effectivePrice = srpUnit;
    }

    row.querySelector('.item-price').value = effectivePrice.toFixed(2);
    calculateRowAmount(row);
}

function calculateRowAmount(row) {
    const pid = row.querySelector('.item-product').value;
    const p = getProductData(pid);
    const perCase = (p && parseFloat(p.qty_per_case)) ? parseFloat(p.qty_per_case) : 1;

    const qtyInput = row.querySelector('.item-qty');
    const qty = parseFloat(qtyInput.value) || 0;
    const mode = row.querySelector('.item-unit-mode').value;

    let totalUnits = qty;
    if (mode === 'cases') {
        totalUnits = Math.round(qty * perCase);
    }
    row.querySelector('.item-u-qty').value = totalUnits;

    const priceInput = row.querySelector('.item-price');
    let price = parseFloat(priceInput.value);
    if ((isNaN(price) || price <= 0) && p) {
        updateRowPrice(row);
        price = parseFloat(priceInput.value) || 0;
    }

    const subtotal = (qty * (isNaN(price) ? 0 : price)).toFixed(2);
    row.querySelector('.item-subtotal').value = subtotal;

    recalculateTotal();
}

function recalculateTotal() {
    let sum = 0;
    document.querySelectorAll('.item-subtotal').forEach(inp => {
        sum += parseFloat(inp.value) || 0;
    });
    document.getElementById('total-display').textContent = '₱' + sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}

function viewOrderDetails(txId) {
    const modal = document.getElementById('order-modal');
    const content = document.getElementById('order-modal-content');
    modal.style.display = 'flex';
    content.innerHTML = '<p class="muted">Loading order items...</p>';

    fetch('transactions.php?action=get_order_details&tx_id=' + txId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = '<div class="alert danger">' + escapeHtml(data.error) + '</div>';
                return;
            }
            const tx = data.transaction;
            const items = data.items;

            let itemsHtml = `
                <div style="background:#f9fbf9; padding:12px; border-radius:8px; margin-bottom:14px; font-size:13px;">
                    <div><strong>Customer:</strong> ${escapeHtml(tx.customer_name)} &nbsp;|&nbsp; <strong>Branch:</strong> ${escapeHtml(tx.branch_name)}</div>
                    <div><strong>Date:</strong> ${escapeHtml(tx.transaction_date)} &nbsp;|&nbsp; <strong>Payment Method:</strong> ${escapeHtml(tx.payment_method)}</div>
                </div>
                <table style="width:100%; font-size:13px;">
                    <thead>
                        <tr style="background:#f1f5f3;">
                            <th>Brand</th>
                            <th>Item Description</th>
                            <th>Tier</th>
                            <th style="text-align:right;">Order Qty</th>
                            <th style="text-align:right;">U-Qty (Units)</th>
                            <th style="text-align:right;">Unit Price</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            items.forEach(it => {
                itemsHtml += `
                    <tr>
                        <td><span class="badge" style="background:#f1f3f4; font-size:11px;">${escapeHtml(it.brand_name)}</span></td>
                        <td><strong>${escapeHtml(it.product_name)}</strong></td>
                        <td><span class="badge" style="background:#e8f5e9; font-size:11px;">${escapeHtml(it.price_type)}</span></td>
                        <td style="text-align:right;">${parseFloat(it.ordered_qty)}</td>
                        <td style="text-align:right; font-weight:600;">${parseFloat(it.unit_qty)}</td>
                        <td style="text-align:right;">₱${parseFloat(it.unit_price).toFixed(2)}</td>
                        <td style="text-align:right; font-weight:700; color:var(--primary);">₱${parseFloat(it.subtotal).toFixed(2)}</td>
                        <td>${escapeHtml(it.remarks || '—')}</td>
                    </tr>
                `;
            });
            itemsHtml += `
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--line);">
                            <td colspan="6" style="text-align:right; font-weight:700; font-size:15px;">TOTAL:</td>
                            <td style="text-align:right; font-weight:800; font-size:16px; color:var(--primary);">₱${parseFloat(tx.total_amount).toFixed(2)}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            `;
            content.innerHTML = itemsHtml;
            document.getElementById('order-modal-title').textContent = 'Order #' + tx.id + ' Breakdown (' + escapeHtml(tx.customer_name) + ')';
        })
        .catch(err => {
            content.innerHTML = '<div class="alert danger">Failed to fetch order details.</div>';
        });
}

function closeOrderModal() {
    document.getElementById('order-modal').style.display = 'none';
}

// Initialize with 2 rows
window.addEventListener('DOMContentLoaded', () => {
    addLineItem(1, 1);
});
</script>

<?php include 'partials/footer.php'; ?>
