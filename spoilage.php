<?php
require 'config.php';
require_role(['Supervisor', 'Inventory Staff', 'Administrator']);

$msg = $_SESSION['spoilage_message'] ?? '';
unset($_SESSION['spoilage_message']);
$error = '';

// Handle CSV Export matching client's exact format
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->query("
        SELECT date, branch_name, brand_name, item_name, qty, remarks
        FROM inventory_spoilage
        ORDER BY date DESC, id DESC
    ");
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="spoilage_wastage_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    // Exact client header
    fputcsv($out, ['DATE', 'BRANCH', 'BRAND', 'ITEM', 'QTY', 'REMARKS']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['date'],
            $r['branch_name'],
            $r['brand_name'],
            $r['item_name'],
            (float)$r['qty'],
            $r['remarks'] ?? ''
        ]);
    }
    fclose($out);
    audit('Export Spoilage (CSV)', 'inventory_spoilage', null, 'Exported ' . count($rows) . ' spoilage records to CSV');
    exit;
}

// Handle Log Spoilage Form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'log_spoilage') {
            $date = trim($_POST['date'] ?? date('Y-m-d'));
            $branchId = (int)($_POST['branch_id'] ?? 1);
            $productId = (int)($_POST['product_id'] ?? 0);
            $qty = (float)($_POST['qty'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');

            if ($productId < 1 || $qty <= 0 || $remarks === '') {
                throw new Exception('Fill in all required fields. Quantity must be greater than zero, and a reason/remark must be provided.');
            }

            $pdo->beginTransaction();

            // Fetch and lock branch
            $stmtB = $pdo->prepare("SELECT id, code, name FROM branches WHERE id=? AND active=1");
            $stmtB->execute([$branchId]);
            $branch = $stmtB->fetch();
            if (!$branch) throw new Exception('Invalid branch selected.');

            // Fetch and lock product
            $stmtP = $pdo->prepare("SELECT id, brand, name FROM products WHERE id=? AND active=1 FOR UPDATE");
            $stmtP->execute([$productId]);
            $product = $stmtP->fetch();
            if (!$product) throw new Exception('Product not found or inactive.');

            // Lock branch inventory
            $stmtInv = $pdo->prepare("SELECT id, quantity FROM branch_inventory WHERE branch_id=? AND product_id=? FOR UPDATE");
            $stmtInv->execute([$branchId, $productId]);
            $currInv = $stmtInv->fetch();
            $onHand = $currInv ? (float)$currInv['quantity'] : 0.0;

            if ($onHand < $qty) {
                // If current recorded inventory is lower than logged wastage, we still allow recording with warning or deduct
                // To maintain accurate historical tracking, allow deduction (or clamp to zero)
            }

            // Insert into inventory_spoilage
            $stmtSp = $pdo->prepare("
                INSERT INTO inventory_spoilage (
                    date, branch_id, branch_name, product_id, brand_name, item_name, qty, remarks, logged_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtSp->execute([
                $date,
                $branchId,
                $branch['code'],
                $productId,
                $product['brand'],
                $product['name'],
                $qty,
                $remarks,
                $_SESSION['user']['id'] ?? 1
            ]);
            $spoilageId = (int)$pdo->lastInsertId();

            // Deduct from branch_inventory
            $stmtBranchInv = $pdo->prepare("
                INSERT INTO branch_inventory (branch_id, product_id, quantity)
                VALUES (?, ?, -?)
                ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity - ?)
            ");
            $stmtBranchInv->execute([$branchId, $productId, $qty, $qty]);

            // Deduct from global products summary
            $stmtGlobal = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
            $stmtGlobal->execute([$qty, $productId]);

            // Tamper-evident Audit trail
            audit(
                'Log Spoilage / Wastage',
                'inventory_spoilage',
                $spoilageId,
                "Deducted {$qty} unit(s) of [{$product['brand']}] {$product['name']} at {$branch['code']}; Reason: {$remarks}"
            );

            $pdo->commit();
            $_SESSION['spoilage_message'] = "Wastage recorded: {$qty} unit(s) of {$product['name']} logged as '{$remarks}' at {$branch['name']}.";
            header('Location: spoilage.php');
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
$allProducts = $pdo->query("SELECT id, brand, name, unit FROM products WHERE active=1 ORDER BY brand, name")->fetchAll();

// Query Filters
$search = trim($_GET['search'] ?? '');
$filterBranch = (int)($_GET['branch_id'] ?? 0);
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');

$sql = "
    SELECT s.*, u.name AS logger_name
    FROM inventory_spoilage s
    LEFT JOIN users u ON u.id = s.logged_by
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (s.item_name LIKE ? OR s.brand_name LIKE ? OR s.remarks LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterBranch > 0) {
    $sql .= " AND s.branch_id = ?";
    $params[] = $filterBranch;
}
if ($filterFrom !== '') {
    $sql .= " AND s.date >= ?";
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $sql .= " AND s.date <= ?";
    $params[] = $filterTo;
}

$sql .= " ORDER BY s.date DESC, s.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$spoilageList = $stmt->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Spoilage, Wastage &amp; Damage Tracking</h2>
        <p class="muted" style="margin:0;">Record damaged, expired, rat-spoiled, or taste-test stock following the client wastage format.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn secondary" href="spoilage.php?export=csv">Download CSV (Client Format)</a>
        <button class="btn primary" type="button" onclick="openSpoilageModal()">+ Log Spoilage / Wastage</button>
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
    <form method="get" action="spoilage.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="flex:1; min-width:200px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Item, brand, reason..." style="width:100%;">
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
            <?php if ($search !== '' || $filterBranch > 0 || $filterFrom !== '' || $filterTo !== ''): ?>
                <a class="btn secondary" href="spoilage.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Spoilage Table (Client Format) -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Spoilage &amp; Damage Records (Showing <?= count($spoilageList) ?>)</h3>
        <span class="muted" style="font-size:12px;">Format: DATE, BRAND, ITEM, QTY, REMARKS</span>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Brand</th>
                    <th>Item Description</th>
                    <th style="text-align:right;">Quantity (Units)</th>
                    <th>Remarks / Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($spoilageList): ?>
                    <?php foreach ($spoilageList as $s): ?>
                        <tr>
                            <td><?= e($s['date']) ?></td>
                            <td><span class="badge" style="background:#f1f3f4; color:#37474f; font-weight:600;"><?= e($s['brand_name']) ?></span></td>
                            <td><strong><?= e($s['item_name']) ?></strong></td>
                            <td style="text-align:right; font-weight:bold; color:var(--danger);"><?= number_format((float)$s['qty']) ?></td>
                            <td>
                                <span class="badge" style="background:#ffebee; color:#c62828; font-size:12px; font-weight:600; padding:3px 8px; border-radius:6px;">
                                    <?= e($s['remarks']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="muted" style="text-align:center; padding:30px;">No wastage records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal: Log Spoilage -->
<div class="modal" id="spoilage-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeSpoilageModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:540px; padding:24px; border-radius:10px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">Log Spoilage / Wastage</h3>
            <button type="button" onclick="closeSpoilageModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="spoilage.php" class="form-grid">
            <input type="hidden" name="action" value="log_spoilage">
            <input type="hidden" name="branch_id" value="1">

            <label>
                <span style="font-size:12px; font-weight:600;">Date *</span>
                <input type="date" name="date" required value="<?= date('Y-m-d') ?>">
            </label>

            <label>
                <span style="font-size:12px; font-weight:600;">Product / Item *</span>
                <select name="product_id" required style="width:100%;">
                    <option value="">Select Item from Catalog</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">[<?= e($p['brand']) ?>] <?= e($p['name']) ?> (<?= e($p['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="font-size:12px; font-weight:600;">Quantity Wasted / Damaged (Units) *</span>
                <input type="number" name="qty" step="1" min="1" required placeholder="Number of units lost">
            </label>

            <label>
                <span style="font-size:12px; font-weight:600;">Reason / Remarks *</span>
                <input type="text" name="remarks" list="reason-datalist" required placeholder="Select reason or type custom details...">
                <datalist id="reason-datalist">
                    <option value="EXPIRED"></option>
                    <option value="SPOILED BY RAT"></option>
                    <option value="DAMAGE FROM THE BOX"></option>
                    <option value="Taste test area"></option>
                    <option value="DAMAGED FOR CM"></option>
                    <option value="FACTORY DEFECT"></option>
                    <option value="PULLED OUT BY SUPPLIER"></option>
                    <option value="NEAR EXPIRY"></option>
                </datalist>
            </label>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px;">
                <button class="btn secondary" type="button" onclick="closeSpoilageModal()">Cancel</button>
                <button class="btn danger" type="submit">Confirm &amp; Deduct Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSpoilageModal() {
    const m = document.getElementById('spoilage-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function closeSpoilageModal() {
    const m = document.getElementById('spoilage-modal');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}
</script>

<?php include 'partials/footer.php'; ?>
