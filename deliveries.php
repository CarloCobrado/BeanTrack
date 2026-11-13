<?php
require 'config.php';
require_role(['Supervisor', 'Inventory Staff', 'Administrator']);

$msg = $_SESSION['delivery_message'] ?? '';
unset($_SESSION['delivery_message']);
$error = '';

// Handle CSV Export matching client's exact format
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->query("
        SELECT date_arrived, supplier, po_no, branch_name, brand_name, item_name, cases, qty, remarks
        FROM supplier_deliveries
        ORDER BY date_arrived DESC, id DESC
    ");
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deliveries_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    // Exact client header
    fputcsv($out, ['DATE ARRIVED', 'SUPPLIER', 'P.O. NO.', 'BRANCH', 'BRAND', 'ITEM', 'CASE', 'QTY', 'REMARKS']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['date_arrived'],
            $r['supplier'],
            $r['po_no'],
            $r['branch_name'],
            $r['brand_name'],
            $r['item_name'],
            (float)$r['cases'],
            (float)$r['qty'],
            $r['remarks'] ?? ''
        ]);
    }
    fclose($out);
    audit('Export Deliveries (CSV)', 'supplier_deliveries', null, 'Exported ' . count($rows) . ' delivery records to CSV');
    exit;
}

// Handle Receive Delivery Form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'receive_delivery') {
            $dateArrived = trim($_POST['date_arrived'] ?? date('Y-m-d'));
            $supplier = trim($_POST['supplier'] ?? '');
            $poNo = trim($_POST['po_no'] ?? '');
            $branchId = (int)($_POST['branch_id'] ?? 1);
            $productId = (int)($_POST['product_id'] ?? 0);
            $cases = (float)($_POST['cases'] ?? 0);
            $qty = (float)($_POST['qty'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');

            if ($supplier === '' || $poNo === '' || $productId < 1 || $qty <= 0) {
                throw new Exception('Fill in all required fields. Received unit quantity must be greater than zero.');
            }

            $pdo->beginTransaction();

            // Fetch and lock branch
            $stmtB = $pdo->prepare("SELECT id, code, name FROM branches WHERE id=? AND active=1");
            $stmtB->execute([$branchId]);
            $branch = $stmtB->fetch();
            if (!$branch) throw new Exception('Invalid branch selected.');

            // Fetch and lock product
            $stmtP = $pdo->prepare("SELECT id, brand, name, qty_per_case FROM products WHERE id=? AND active=1 FOR UPDATE");
            $stmtP->execute([$productId]);
            $product = $stmtP->fetch();
            if (!$product) throw new Exception('Product not found or inactive.');

            // Insert into supplier_deliveries
            $stmtDel = $pdo->prepare("
                INSERT INTO supplier_deliveries (
                    date_arrived, supplier, po_no, branch_id, branch_name, 
                    product_id, brand_name, item_name, cases, qty, remarks, received_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtDel->execute([
                $dateArrived,
                $supplier,
                $poNo,
                $branchId,
                $branch['code'],
                $productId,
                $product['brand'],
                $product['name'],
                $cases,
                $qty,
                $remarks ?: null,
                $_SESSION['user']['id'] ?? 1
            ]);
            $deliveryId = (int)$pdo->lastInsertId();

            // Update branch_inventory
            $stmtBranchInv = $pdo->prepare("
                INSERT INTO branch_inventory (branch_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmtBranchInv->execute([$branchId, $productId, $qty]);

            // Update global product quantity summary
            $stmtGlobal = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
            $stmtGlobal->execute([$qty, $productId]);

            // Tamper-evident Audit trail
            audit(
                'Receive Supplier Delivery',
                'supplier_deliveries',
                $deliveryId,
                "Received {$cases} case(s) / {$qty} unit(s) of [{$product['brand']}] {$product['name']} at {$branch['code']} from {$supplier}; P.O. #{$poNo}" . ($remarks ? " ($remarks)" : "")
            );

            $pdo->commit();
            $_SESSION['delivery_message'] = "Delivery recorded: Received {$qty} unit(s) of {$product['name']} at {$branch['name']}.";
            header('Location: deliveries.php');
            exit;
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $t->getMessage();
    }
}

// Fetch filter options
$branches = $pdo->query("SELECT id, code, name FROM branches WHERE active=1 ORDER BY id")->fetchAll();
$suppliers = $pdo->query("SELECT DISTINCT supplier FROM supplier_deliveries ORDER BY supplier")->fetchAll(PDO::FETCH_COLUMN);
$allProducts = $pdo->query("SELECT id, brand, name, qty_per_case, unit FROM products WHERE active=1 ORDER BY brand, name")->fetchAll();

// Query Filters
$search = trim($_GET['search'] ?? '');
$filterBranch = (int)($_GET['branch_id'] ?? 0);
$filterSupplier = trim($_GET['supplier'] ?? '');
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');

$sql = "
    SELECT d.*, u.name AS receiver_name
    FROM supplier_deliveries d
    LEFT JOIN users u ON u.id = d.received_by
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (d.po_no LIKE ? OR d.item_name LIKE ? OR d.brand_name LIKE ? OR d.supplier LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterBranch > 0) {
    $sql .= " AND d.branch_id = ?";
    $params[] = $filterBranch;
}
if ($filterSupplier !== '') {
    $sql .= " AND d.supplier = ?";
    $params[] = $filterSupplier;
}
if ($filterFrom !== '') {
    $sql .= " AND d.date_arrived >= ?";
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $sql .= " AND d.date_arrived <= ?";
    $params[] = $filterTo;
}

$sql .= " ORDER BY d.date_arrived DESC, d.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliveriesList = $stmt->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Inbound Deliveries (P.O. Receipts)</h2>
        <p class="muted" style="margin:0;">Record incoming supplier shipments and stock arrivals following the client P.O. receiving format.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn secondary" href="deliveries.php?export=csv">Download CSV (Client Format)</a>
        <button class="btn primary" type="button" onclick="openDeliveryModal()">+ Record New Delivery</button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert success" style="margin-bottom:16px;"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert danger" style="margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<!-- Filter Bar -->
<section class="card" style="margin-bottom:20px; padding:16px;">
    <form method="get" action="deliveries.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="flex:1; min-width:200px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="P.O. #, item, brand, supplier..." style="width:100%;">
        </label>
        <label style="width:180px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Supplier</span>
            <select name="supplier" style="width:100%;">
                <option value="">All Suppliers</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filterSupplier === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="width:140px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">From Date</span>
            <input type="date" name="from" value="<?= e($filterFrom) ?>" style="width:100%;">
        </label>
        <label style="width:140px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">To Date</span>
            <input type="date" name="to" value="<?= e($filterTo) ?>" style="width:100%;">
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterBranch > 0 || $filterSupplier !== '' || $filterFrom !== '' || $filterTo !== ''): ?>
                <a class="btn secondary" href="deliveries.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Deliveries Table (Client Format) -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Inbound Shipments Log (Showing <?= count($deliveriesList) ?>)</h3>
        <span class="muted" style="font-size:12px;">Format: DATE ARRIVED, SUPPLIER, P.O. NO., BRAND, ITEM, CASE, QTY, REMARKS</span>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Date Arrived</th>
                    <th>Supplier</th>
                    <th>P.O. / S.I. No.</th>
                    <th>Brand</th>
                    <th>Item Description</th>
                    <th style="text-align:right;">Cases</th>
                    <th style="text-align:right;">Units (Qty)</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($deliveriesList): ?>
                    <?php foreach ($deliveriesList as $d): ?>
                        <tr>
                            <td><?= e($d['date_arrived']) ?></td>
                            <td><strong><?= e($d['supplier']) ?></strong></td>
                            <td><span style="font-family:monospace; background:#eef2f1; padding:2px 6px; border-radius:4px; font-weight:600;"><?= e($d['po_no']) ?></span></td>
                            <td><span class="badge" style="background:#f1f3f4; color:#37474f; font-weight:600;"><?= e($d['brand_name']) ?></span></td>
                            <td><?= e($d['item_name']) ?></td>
                            <td style="text-align:right; font-weight:600;"><?= number_format((float)$d['cases'], 1) ?></td>
                            <td style="text-align:right; font-weight:bold; color:var(--primary);"><?= number_format((float)$d['qty']) ?></td>
                            <td>
                                <?php if (!empty($d['remarks'])): ?>
                                    <span style="font-size:12px; color:var(--muted);"><?= e($d['remarks']) ?></span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="muted" style="text-align:center; padding:30px;">No deliveries found matching your search.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal: Record Delivery -->
<div class="modal" id="delivery-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeDeliveryModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:580px; padding:24px; border-radius:10px; background:#fff; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">Record Inbound Delivery</h3>
            <button type="button" onclick="closeDeliveryModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="deliveries.php" class="form-grid">
            <input type="hidden" name="action" value="receive_delivery">
            <input type="hidden" name="branch_id" value="1">

            <label>
                <span style="font-size:12px; font-weight:600;">Date Arrived *</span>
                <input type="date" name="date_arrived" required value="<?= date('Y-m-d') ?>">
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Supplier *</span>
                    <input type="text" name="supplier" list="supplier-datalist" required placeholder="e.g. GLOBAL PACIFIC, SKZ...">
                    <datalist id="supplier-datalist">
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= e($s) ?>"></option>
                        <?php endforeach; ?>
                        <option value="GLOBAL PACIFIC"></option>
                        <option value="SKZ"></option>
                        <option value="SHOPPEE"></option>
                        <option value="JK PRODUCT MFG. CORP."></option>
                    </datalist>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">P.O. / S.I. Number *</span>
                    <input type="text" name="po_no" required placeholder="e.g. SI A20098, PO-2025-01...">
                </label>
            </div>

            <label>
                <span style="font-size:12px; font-weight:600;">Item / Product *</span>
                <select name="product_id" id="delivery-product" required onchange="onProductSelect()" style="width:100%;">
                    <option value="">Select Item from Catalog</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-case="<?= (int)$p['qty_per_case'] ?>" data-unit="<?= e($p['unit']) ?>">
                            [<?= e($p['brand']) ?>] <?= e($p['name']) ?> (<?= (int)$p['qty_per_case'] ?>/case)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Number of Cases</span>
                    <input type="number" name="cases" id="delivery-cases" step="0.1" min="0" value="1" oninput="calculateUnits()">
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Total Unit Quantity (Qty) *</span>
                    <input type="number" name="qty" id="delivery-qty" step="1" min="1" required placeholder="Units received">
                </label>
            </div>

            <label>
                <span style="font-size:12px; font-weight:600;">Remarks / Notes (optional)</span>
                <input type="text" name="remarks" placeholder="e.g. ON PROMO, FREE FOR PROMO, DAMAGE 1 PACK...">
            </label>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px;">
                <button class="btn secondary" type="button" onclick="closeDeliveryModal()">Cancel</button>
                <button class="btn primary" type="submit">Confirm &amp; Receive Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeliveryModal() {
    const m = document.getElementById('delivery-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function closeDeliveryModal() {
    const m = document.getElementById('delivery-modal');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}

function onProductSelect() {
    calculateUnits();
}

function calculateUnits() {
    const select = document.getElementById('delivery-product');
    const opt = select.options[select.selectedIndex];
    const cases = parseFloat(document.getElementById('delivery-cases').value) || 0;
    if (opt && opt.dataset.case) {
        const perCase = parseInt(opt.dataset.case, 10) || 1;
        document.getElementById('delivery-qty').value = Math.round(cases * perCase);
    }
}
</script>

<?php include 'partials/footer.php'; ?>
