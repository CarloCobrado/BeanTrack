<?php
require 'config.php';
require_role(['Supervisor', 'Inventory Staff', 'Administrator']);

$msg = $_SESSION['transfer_message'] ?? '';
unset($_SESSION['transfer_message']);
$error = '';

// Handle CSV Export matching client's exact format
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->query("
        SELECT date, from_branch_name, to_branch_name, brand_name, item_name, qty, remarks
        FROM inventory_transfers
        ORDER BY date DESC, id DESC
    ");
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="branch_transfers_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    // Exact client header
    fputcsv($out, ['DATE', 'FROM', 'TO', 'BRAND', 'ITEM', 'QTY', 'REMARKS']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['date'],
            $r['from_branch_name'],
            $r['to_branch_name'],
            $r['brand_name'],
            $r['item_name'],
            (float)$r['qty'],
            $r['remarks'] ?? ''
        ]);
    }
    fclose($out);
    audit('Export Transfers (CSV)', 'inventory_transfers', null, 'Exported ' . count($rows) . ' stock transfer records to CSV');
    exit;
}

// Handle Transfer Stock Form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'transfer_stock') {
            $date = trim($_POST['date'] ?? date('Y-m-d'));
            $fromId = (int)($_POST['from_branch_id'] ?? 0);
            $toId = (int)($_POST['to_branch_id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? 0);
            $qty = (float)($_POST['qty'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');

            if ($fromId === $toId) {
                throw new Exception('Source and destination branches cannot be the same.');
            }
            if ($fromId < 1 || $toId < 1 || $productId < 1 || $qty <= 0) {
                throw new Exception('Please select valid branches, product, and a quantity greater than zero.');
            }

            $pdo->beginTransaction();

            // Fetch and validate branches
            $stmtFrom = $pdo->prepare("SELECT id, code, name FROM branches WHERE id=? AND active=1");
            $stmtFrom->execute([$fromId]);
            $fromBranch = $stmtFrom->fetch();

            $stmtTo = $pdo->prepare("SELECT id, code, name FROM branches WHERE id=? AND active=1");
            $stmtTo->execute([$toId]);
            $toBranch = $stmtTo->fetch();

            if (!$fromBranch || !$toBranch) {
                throw new Exception('One or more selected branches are invalid.');
            }

            // Fetch and lock product
            $stmtP = $pdo->prepare("SELECT id, brand, name FROM products WHERE id=? AND active=1 FOR UPDATE");
            $stmtP->execute([$productId]);
            $product = $stmtP->fetch();
            if (!$product) throw new Exception('Product not found or inactive.');

            // Lock source branch inventory
            $stmtInvFrom = $pdo->prepare("SELECT id, quantity FROM branch_inventory WHERE branch_id=? AND product_id=? FOR UPDATE");
            $stmtInvFrom->execute([$fromId, $productId]);
            $fromInv = $stmtInvFrom->fetch();
            $fromStock = $fromInv ? (float)$fromInv['quantity'] : 0.0;

            if ($fromStock < $qty) {
                throw new Exception("Insufficient stock at {$fromBranch['name']}. Available: " . number_format($fromStock) . " units, requested: " . number_format($qty) . ".");
            }

            // Deduct from source branch
            $stmtDeduct = $pdo->prepare("UPDATE branch_inventory SET quantity = quantity - ? WHERE branch_id=? AND product_id=?");
            $stmtDeduct->execute([$qty, $fromId, $productId]);

            // Add to destination branch
            $stmtAdd = $pdo->prepare("
                INSERT INTO branch_inventory (branch_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmtAdd->execute([$toId, $productId, $qty]);

            // Record in inventory_transfers
            $stmtTr = $pdo->prepare("
                INSERT INTO inventory_transfers (
                    date, from_branch_id, from_branch_name, to_branch_id, to_branch_name,
                    product_id, brand_name, item_name, qty, remarks, transferred_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtTr->execute([
                $date,
                $fromId,
                $fromBranch['code'],
                $toId,
                $toBranch['code'],
                $productId,
                $product['brand'],
                $product['name'],
                $qty,
                $remarks ?: null,
                $_SESSION['user']['id'] ?? 1
            ]);
            $transferId = (int)$pdo->lastInsertId();

            // Audit
            audit(
                'Transfer Stock',
                'inventory_transfers',
                $transferId,
                "Transferred {$qty} unit(s) of [{$product['brand']}] {$product['name']} from {$fromBranch['code']} to {$toBranch['code']}" . ($remarks ? " ($remarks)" : "")
            );

            $pdo->commit();
            $_SESSION['transfer_message'] = "Transferred {$qty} unit(s) of {$product['name']} from {$fromBranch['code']} to {$toBranch['code']}.";
            header('Location: transfers.php');
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
$filterFrom = (int)($_GET['from_branch'] ?? 0);
$filterTo = (int)($_GET['to_branch'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');

$sql = "
    SELECT t.*, u.name AS user_name
    FROM inventory_transfers t
    LEFT JOIN users u ON u.id = t.transferred_by
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (t.item_name LIKE ? OR t.brand_name LIKE ? OR t.remarks LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterFrom > 0) {
    $sql .= " AND t.from_branch_id = ?";
    $params[] = $filterFrom;
}
if ($filterTo > 0) {
    $sql .= " AND t.to_branch_id = ?";
    $params[] = $filterTo;
}
if ($filterDateFrom !== '') {
    $sql .= " AND t.date >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $sql .= " AND t.date <= ?";
    $params[] = $filterDateTo;
}

$sql .= " ORDER BY t.date DESC, t.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transfersList = $stmt->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Inter-Branch Transfers</h2>
        <p class="muted" style="margin:0;">Transfer inventory between Davao, Pagadian, and Zamboanga branches following the client transfer format.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn secondary" href="transfers.php?export=csv">Download CSV (Client Format)</a>
        <button class="btn primary" type="button" onclick="openTransferModal()">+ New Stock Transfer</button>
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
    <form method="get" action="transfers.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="flex:1; min-width:200px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Item, brand, remarks..." style="width:100%;">
        </label>
        <label style="width:150px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">From Branch</span>
            <select name="from_branch" style="width:100%;">
                <option value="0">All</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $filterFrom === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="width:150px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">To Branch</span>
            <select name="to_branch" style="width:100%;">
                <option value="0">All</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $filterTo === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="width:140px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">From Date</span>
            <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>" style="width:100%;">
        </label>
        <label style="width:140px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">To Date</span>
            <input type="date" name="date_to" value="<?= e($filterDateTo) ?>" style="width:100%;">
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterFrom > 0 || $filterTo > 0 || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
                <a class="btn secondary" href="transfers.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Transfers Table (Client Format) -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Inter-Branch Transfer Log (Showing <?= count($transfersList) ?>)</h3>
        <span class="muted" style="font-size:12px;">Format: DATE, FROM, TO, BRAND, ITEM, QTY, REMARKS</span>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>From Branch</th>
                    <th>To Branch</th>
                    <th>Brand</th>
                    <th>Item Description</th>
                    <th style="text-align:right;">Quantity (Units)</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transfersList): ?>
                    <?php foreach ($transfersList as $t): ?>
                        <tr>
                            <td><?= e($t['date']) ?></td>
                            <td><span class="badge" style="background:#fff3e0; color:#e65100; font-weight:600;"><?= e($t['from_branch_name']) ?></span></td>
                            <td><span class="badge" style="background:#e8f5e9; color:#2e7d32; font-weight:600;"><?= e($t['to_branch_name']) ?></span></td>
                            <td><span class="badge" style="background:#f1f3f4; color:#37474f; font-weight:600;"><?= e($t['brand_name']) ?></span></td>
                            <td><strong><?= e($t['item_name']) ?></strong></td>
                            <td style="text-align:right; font-weight:bold; color:var(--primary);"><?= number_format((float)$t['qty']) ?></td>
                            <td><?= e($t['remarks'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center; padding:30px;">No branch transfers found matching your filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal: New Transfer -->
<div class="modal" id="transfer-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeTransferModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:540px; padding:24px; border-radius:10px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">New Stock Transfer</h3>
            <button type="button" onclick="closeTransferModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="transfers.php" class="form-grid">
            <input type="hidden" name="action" value="transfer_stock">

            <label>
                <span style="font-size:12px; font-weight:600;">Transfer Date *</span>
                <input type="date" name="date" required value="<?= date('Y-m-d') ?>">
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">From Branch (Source) *</span>
                    <select name="from_branch_id" required>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['code']) ?> - <?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">To Branch (Destination) *</span>
                    <select name="to_branch_id" required>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $b['code'] === 'PAGADIAN' ? 'selected' : '' ?>><?= e($b['code']) ?> - <?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label>
                <span style="font-size:12px; font-weight:600;">Item to Transfer *</span>
                <select name="product_id" required style="width:100%;">
                    <option value="">Select Item from Catalog</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">[<?= e($p['brand']) ?>] <?= e($p['name']) ?> (<?= e($p['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="font-size:12px; font-weight:600;">Quantity (Units) *</span>
                <input type="number" name="qty" step="1" min="1" required placeholder="Units to transfer">
            </label>

            <label>
                <span style="font-size:12px; font-weight:600;">Remarks (optional)</span>
                <input type="text" name="remarks" placeholder="e.g. Stock replenishment, Near expiry swap...">
            </label>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px;">
                <button class="btn secondary" type="button" onclick="closeTransferModal()">Cancel</button>
                <button class="btn primary" type="submit">Execute Transfer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTransferModal() {
    const m = document.getElementById('transfer-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function closeTransferModal() {
    const m = document.getElementById('transfer-modal');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}
</script>

<?php include 'partials/footer.php'; ?>
