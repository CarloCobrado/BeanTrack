<?php
require 'config.php';
require_role(['Supervisor', 'Inventory Staff', 'Administrator']);

$role = user_role();
$isSupervisor = in_array($role, ['Supervisor', 'Administrator'], true);

$msg = $_SESSION['ingredient_message'] ?? '';
unset($_SESSION['ingredient_message']);
$error = '';

// 1. Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query("
        SELECT i.name, i.unit, i.quantity, i.reorder_level,
               COUNT(pri.product_id) AS recipe_count
        FROM ingredients i
        LEFT JOIN product_recipe_items pri ON pri.ingredient_id = i.id
        WHERE i.active = 1
        GROUP BY i.id
        ORDER BY i.name ASC
    ")->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ingredients_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ingredient Name', 'Stock Unit', 'On Hand Stock', 'Reorder Alert Level', 'Recipes Linked', 'Stock Status']);
    foreach ($rows as $r) {
        $q = (float)$r['quantity'];
        $rl = (float)$r['reorder_level'];
        $status = $q <= 0 ? 'OUT OF STOCK' : ($q <= $rl ? 'LOW STOCK' : 'IN STOCK');
        fputcsv($out, [
            $r['name'],
            $r['unit'],
            number_format($q, 3, '.', ''),
            number_format($rl, 3, '.', ''),
            (int)$r['recipe_count'],
            $status
        ]);
    }
    fclose($out);
    audit('Export Ingredients (CSV)', 'ingredients', null, 'Exported ' . count($rows) . ' ingredient records to CSV');
    exit;
}

// 2. Handle POST CRUD Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_ingredient') {
            $id = (int)($_POST['ingredient_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $reorderLevel = (float)($_POST['reorder_level'] ?? 0);

            if ($name === '' || $unit === '' || $reorderLevel < 0) {
                throw new Exception('Please provide a valid ingredient name, unit, and non-negative reorder alert level.');
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                // Update existing ingredient
                if (!$isSupervisor) {
                    throw new Exception('Only Supervisors and Administrators can edit ingredient specifications.');
                }

                $stmtCheck = $pdo->prepare("SELECT id, name, unit, reorder_level FROM ingredients WHERE id=? AND active=1 FOR UPDATE");
                $stmtCheck->execute([$id]);
                $orig = $stmtCheck->fetch();
                if (!$orig) throw new Exception('Ingredient not found.');

                // Check duplicate name
                $stmtDup = $pdo->prepare("SELECT id FROM ingredients WHERE name=? AND id != ? AND active=1");
                $stmtDup->execute([$name, $id]);
                if ($stmtDup->fetch()) {
                    throw new Exception("An active ingredient named '$name' already exists.");
                }

                $stmtUpd = $pdo->prepare("UPDATE ingredients SET name=?, unit=?, reorder_level=? WHERE id=?");
                $stmtUpd->execute([$name, $unit, $reorderLevel, $id]);

                audit('Update Ingredient', 'ingredients', $id, "Updated ingredient #$id: Name='{$orig['name']}' -> '$name', Unit='{$orig['unit']}' -> '$unit', Reorder Level={$orig['reorder_level']} -> $reorderLevel");
                $pdo->commit();
                $_SESSION['ingredient_message'] = "Ingredient '$name' updated successfully.";
            } else {
                // Create new ingredient
                $initQty = (float)($_POST['initial_quantity'] ?? 0);
                if ($initQty < 0) throw new Exception('Initial quantity cannot be negative.');

                $stmtDup = $pdo->prepare("SELECT id, active FROM ingredients WHERE name=?");
                $stmtDup->execute([$name]);
                $existing = $stmtDup->fetch();
                if ($existing) {
                    if ((int)$existing['active'] === 1) {
                        throw new Exception("An ingredient named '$name' already exists.");
                    } else {
                        // Reactivate previously archived ingredient
                        $stmtReactivate = $pdo->prepare("UPDATE ingredients SET active=1, unit=?, quantity=?, reorder_level=? WHERE id=?");
                        $stmtReactivate->execute([$unit, $initQty, $reorderLevel, $existing['id']]);
                        $id = (int)$existing['id'];
                        audit('Reactivate Ingredient', 'ingredients', $id, "Reactivated ingredient '$name' with $initQty $unit");
                        $pdo->commit();
                        $_SESSION['ingredient_message'] = "Ingredient '$name' restored to active stock.";
                        header('Location: ingredients.php');
                        exit;
                    }
                }

                $stmtIns = $pdo->prepare("INSERT INTO ingredients (name, unit, quantity, reorder_level, active) VALUES (?, ?, ?, ?, 1)");
                $stmtIns->execute([$name, $unit, $initQty, $reorderLevel]);
                $newId = (int)$pdo->lastInsertId();

                audit('Create Ingredient', 'ingredients', $newId, "Created ingredient '$name' ($unit) with initial stock " . number_format($initQty, 3) . ", reorder level " . number_format($reorderLevel, 3));
                $pdo->commit();
                $_SESSION['ingredient_message'] = "Ingredient '$name' added to catalog.";
            }

            header('Location: ingredients.php');
            exit;

        } elseif ($action === 'adjust_stock') {
            $id = (int)($_POST['ingredient_id'] ?? 0);
            $type = $_POST['adjust_type'] ?? 'add';
            $qty = (float)($_POST['quantity'] ?? 0);
            $reference = trim($_POST['reference_no'] ?? '');

            if ($id < 1 || $qty <= 0) {
                throw new Exception('Select an ingredient and enter a quantity greater than zero.');
            }

            $pdo->beginTransaction();
            $stmtLock = $pdo->prepare("SELECT id, name, unit, quantity FROM ingredients WHERE id=? AND active=1 FOR UPDATE");
            $stmtLock->execute([$id]);
            $ing = $stmtLock->fetch();
            if (!$ing) throw new Exception('Ingredient not found.');

            $oldStock = (float)$ing['quantity'];
            if ($type === 'subtract') {
                $newStock = max(0.0, $oldStock - $qty);
                $stmtAdj = $pdo->prepare("UPDATE ingredients SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
                $stmtAdj->execute([$qty, $id]);
                $actionLabel = 'Deduct Ingredient Stock';
                $detail = "Deducted " . number_format($qty, 3) . " {$ing['unit']} from '{$ing['name']}' (Previous: " . number_format($oldStock, 3) . ", New: " . number_format($newStock, 3) . ")" . ($reference ? "; Reason/Ref: $reference" : "");
                $userMsg = "Stock deducted for '{$ing['name']}': -" . number_format($qty, 3) . " {$ing['unit']}.";
            } else {
                $newStock = $oldStock + $qty;
                $stmtAdj = $pdo->prepare("UPDATE ingredients SET quantity = quantity + ? WHERE id = ?");
                $stmtAdj->execute([$qty, $id]);
                $actionLabel = 'Receive Ingredient Stock';
                $detail = "Received " . number_format($qty, 3) . " {$ing['unit']} for '{$ing['name']}' (Previous: " . number_format($oldStock, 3) . ", New: " . number_format($newStock, 3) . ")" . ($reference ? "; Ref: $reference" : "");
                $userMsg = "Stock received for '{$ing['name']}': +" . number_format($qty, 3) . " {$ing['unit']}.";
            }

            audit($actionLabel, 'ingredients', $id, $detail);
            $pdo->commit();
            $_SESSION['ingredient_message'] = $userMsg;
            header('Location: ingredients.php');
            exit;

        } elseif ($action === 'archive_ingredient') {
            if (!$isSupervisor) {
                throw new Exception('Only Supervisors and Administrators can archive ingredients.');
            }
            $id = (int)($_POST['ingredient_id'] ?? 0);
            $pdo->beginTransaction();
            $stmtArch = $pdo->prepare("SELECT id, name FROM ingredients WHERE id=? AND active=1 FOR UPDATE");
            $stmtArch->execute([$id]);
            $ing = $stmtArch->fetch();
            if (!$ing) throw new Exception('Ingredient not found.');

            $stmtUpd = $pdo->prepare("UPDATE ingredients SET active=0 WHERE id=?");
            $stmtUpd->execute([$id]);

            audit('Archive Ingredient', 'ingredients', $id, "Archived ingredient '{$ing['name']}' (#$id)");
            $pdo->commit();
            $_SESSION['ingredient_message'] = "Ingredient '{$ing['name']}' archived from inventory.";
            header('Location: ingredients.php');
            exit;
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $t->getMessage();
    }
}

// 3. Load lookups & filters
$unitsList = $pdo->query("SELECT DISTINCT unit FROM ingredients WHERE active=1 ORDER BY unit")->fetchAll(PDO::FETCH_COLUMN);
$allActiveIngredients = $pdo->query("
    SELECT i.id, i.name, i.unit, i.quantity, i.reorder_level,
           COUNT(pri.product_id) AS recipe_count
    FROM ingredients i
    LEFT JOIN product_recipe_items pri ON pri.ingredient_id = i.id
    WHERE i.active=1
    GROUP BY i.id
    ORDER BY i.name ASC
")->fetchAll();

$lowCount = 0;
$outCount = 0;
$linkedCount = 0;
foreach ($allActiveIngredients as $item) {
    $q = (float)$item['quantity'];
    $rl = (float)$item['reorder_level'];
    if ($q <= 0) $outCount++;
    elseif ($q <= $rl) $lowCount++;
    if ((int)$item['recipe_count'] > 0) $linkedCount++;
}

// Query Filters
$search = trim($_GET['search'] ?? '');
$filterUnit = trim($_GET['unit'] ?? '');
$filterStock = trim($_GET['stock_status'] ?? '');

$sql = "
    SELECT i.*, 
           COUNT(pri.product_id) AS recipe_count
    FROM ingredients i
    LEFT JOIN product_recipe_items pri ON pri.ingredient_id = i.id
    WHERE i.active = 1
";
$params = [];

if ($search !== '') {
    $sql .= " AND i.name LIKE ?";
    $params[] = "%$search%";
}
if ($filterUnit !== '') {
    $sql .= " AND i.unit = ?";
    $params[] = $filterUnit;
}
if ($filterStock === 'low') {
    $sql .= " AND i.quantity <= i.reorder_level AND i.quantity > 0";
} elseif ($filterStock === 'out') {
    $sql .= " AND i.quantity <= 0";
} elseif ($filterStock === 'ok') {
    $sql .= " AND i.quantity > i.reorder_level";
}

$sql .= " GROUP BY i.id ORDER BY i.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ingredientsList = $stmt->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Raw Ingredient Inventory</h2>
        <p class="muted" style="margin:0;">Track raw ingredients, monitor stock levels, receive deliveries, and manage recipe components.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn secondary" href="ingredients.php?export=csv">Download CSV</a>
        <button class="btn secondary" type="button" onclick="openStockAdjustModal()">+ Adjust / Receive Stock</button>
        <a class="btn secondary" href="recipes.php">Menu Recipes</a>
        <?php if ($isSupervisor): ?>
            <button class="btn primary" type="button" onclick="openIngredientModal()">+ Add New Ingredient</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert success" style="margin-bottom:16px;"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert danger" style="margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<!-- KPI Summary Cards -->
<div class="stats" style="margin-bottom:24px;">
    <div class="stat">
        <span>Active Ingredients</span>
        <strong><?= count($allActiveIngredients) ?></strong>
    </div>
    <div class="stat">
        <span>Low Stock Alert</span>
        <strong style="color:<?= $lowCount > 0 ? '#e65100' : 'var(--text)' ?>;"><?= $lowCount ?></strong>
    </div>
    <div class="stat">
        <span>Out of Stock</span>
        <strong style="color:<?= $outCount > 0 ? 'var(--danger)' : 'var(--text)' ?>;"><?= $outCount ?></strong>
    </div>
    <div class="stat">
        <span>Used in Recipes</span>
        <strong style="color:var(--primary);"><?= $linkedCount ?> item(s)</strong>
    </div>
</div>

<!-- Filter & Search Bar -->
<section class="card" style="margin-bottom:20px; padding:16px;">
    <form method="get" action="ingredients.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="flex:1; min-width:220px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search Ingredient</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="e.g. Milk, Espresso Beans, Syrup..." style="width:100%;">
        </label>
        <label style="width:160px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Stock Unit</span>
            <select name="unit" style="width:100%;">
                <option value="">All Units</option>
                <?php foreach ($unitsList as $u): ?>
                    <option value="<?= e($u) ?>" <?= $filterUnit === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="width:170px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Stock Status</span>
            <select name="stock_status" style="width:100%;">
                <option value="">All Levels</option>
                <option value="ok" <?= $filterStock === 'ok' ? 'selected' : '' ?>>Healthy Stock</option>
                <option value="low" <?= $filterStock === 'low' ? 'selected' : '' ?>>Low Stock</option>
                <option value="out" <?= $filterStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterUnit !== '' || $filterStock !== ''): ?>
                <a class="btn secondary" href="ingredients.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Ingredients Table -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Ingredients List (Showing <?= count($ingredientsList) ?>)</h3>
        <span class="muted" style="font-size:12px;">Raw Stock Inventory &amp; Recipe Components</span>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Ingredient Name</th>
                    <th style="text-align:center;">Stock Unit</th>
                    <th style="text-align:right;">On Hand Stock</th>
                    <th style="text-align:right;">Reorder Alert Level</th>
                    <th style="text-align:center;">Recipes Linked</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($ingredientsList): ?>
                    <?php foreach ($ingredientsList as $ing): ?>
                        <?php 
                        $stock = (float)$ing['quantity'];
                        $reorder = (float)$ing['reorder_level'];
                        $isOut = $stock <= 0;
                        $isLow = $stock <= $reorder && !$isOut;
                        $recipeCount = (int)$ing['recipe_count'];
                        ?>
                        <tr>
                            <td><strong><?= e($ing['name']) ?></strong></td>
                            <td style="text-align:center;">
                                <span class="badge" style="background:#f1f3f4; color:#37474f; font-weight:700;"><?= e($ing['unit']) ?></span>
                            </td>
                            <td style="text-align:right; font-weight:700; font-size:14px; color:<?= $isOut ? 'var(--danger)' : ($isLow ? '#e65100' : 'var(--primary)') ?>;">
                                <?= number_format($stock, 3, '.', '') ?> <?= e($ing['unit']) ?>
                            </td>
                            <td style="text-align:right; color:var(--muted); font-weight:600;">
                                <?= number_format($reorder, 3, '.', '') ?> <?= e($ing['unit']) ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($recipeCount > 0): ?>
                                    <a href="recipes.php" class="badge" style="background:#eef7f2; color:#176b5b; font-weight:600; text-decoration:none;" title="View recipes using this component">
                                        <?= $recipeCount ?> Recipe<?= $recipeCount > 1 ? 's' : '' ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted" style="font-size:12px;">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($isOut): ?>
                                    <span class="badge danger-badge" style="font-weight:700;">OUT OF STOCK</span>
                                <?php elseif ($isLow): ?>
                                    <span class="badge" style="background:#fff3e0; color:#e65100; font-weight:700;">LOW (≤ <?= number_format($reorder, 2) ?>)</span>
                                <?php else: ?>
                                    <span class="badge ok-badge">IN STOCK</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; white-space:nowrap;">
                                <?php if ($isSupervisor): ?>
                                    <button class="btn secondary btn-sm" type="button" 
                                            onclick="editIngredientModal(<?= htmlspecialchars(json_encode($ing), ENT_QUOTES, 'UTF-8') ?>)">
                                        Edit
                                    </button>
                                <?php endif; ?>
                                <button class="btn secondary btn-sm" type="button" 
                                        onclick="quickAdjustIngredient(<?= (int)$ing['id'] ?>, '<?= e(addslashes($ing['name'])) ?>', '<?= e(addslashes($ing['unit'])) ?>')">
                                    Adjust / Receive
                                </button>
                                <?php if ($isSupervisor): ?>
                                    <form method="post" action="ingredients.php" style="display:inline;" onsubmit="return confirm('Archive ingredient <?= e(addslashes($ing['name'])) ?>?');">
                                        <input type="hidden" name="action" value="archive_ingredient">
                                        <input type="hidden" name="ingredient_id" value="<?= (int)$ing['id'] ?>">
                                        <button class="btn secondary btn-sm" type="submit" style="color:var(--danger);" title="Archive Ingredient">&times;</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center; padding:30px;">No ingredients found matching your search criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal: Add / Edit Ingredient -->
<div class="modal" id="ingredient-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeIngredientModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:500px; padding:24px; border-radius:10px; background:#fff; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 id="ingredient-modal-title" style="margin:0;">Add New Ingredient</h3>
            <button type="button" onclick="closeIngredientModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="ingredients.php" class="form-grid">
            <input type="hidden" name="action" value="save_ingredient">
            <input type="hidden" name="ingredient_id" id="ing-id" value="0">

            <label>
                <span style="font-size:12px; font-weight:600;">Ingredient Name *</span>
                <input type="text" name="name" id="ing-name" required placeholder="e.g. Fresh Whole Milk, Espresso Beans, Caramel Syrup..." maxlength="120">
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Stock Unit *</span>
                    <input type="text" name="unit" id="ing-unit" list="unit-suggestions" required placeholder="ml, g, pcs, pump, pack..." maxlength="30">
                    <datalist id="unit-suggestions">
                        <option value="ml"></option>
                        <option value="g"></option>
                        <option value="kg"></option>
                        <option value="pcs"></option>
                        <option value="pump"></option>
                        <option value="scoop"></option>
                        <option value="can"></option>
                        <option value="pack"></option>
                        <option value="oz"></option>
                    </datalist>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Reorder Alert Level *</span>
                    <input type="number" name="reorder_level" id="ing-reorder" min="0" step="0.001" value="10" required>
                </label>
            </div>

            <div id="initial-stock-group" style="margin-top:4px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Opening On-Hand Stock</span>
                    <input type="number" name="initial_quantity" id="ing-initial-qty" min="0" step="0.001" value="0">
                    <small class="muted">Starting quantity in inventory upon creation.</small>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                <button class="btn secondary" type="button" onclick="closeIngredientModal()">Cancel</button>
                <button class="btn primary" type="submit" id="ing-submit-btn">Save Ingredient</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Quick Adjust / Receive Stock -->
<div class="modal" id="adjust-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeStockAdjustModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:480px; padding:24px; border-radius:10px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">Stock Adjustment &amp; Inbound</h3>
            <button type="button" onclick="closeStockAdjustModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="ingredients.php" class="form-grid">
            <input type="hidden" name="action" value="adjust_stock">

            <label>
                <span style="font-size:12px; font-weight:600;">Select Ingredient *</span>
                <select name="ingredient_id" id="adjust-ing-id" required style="width:100%;">
                    <?php foreach ($allActiveIngredients as $item): ?>
                        <option value="<?= (int)$item['id'] ?>" data-unit="<?= e($item['unit']) ?>">
                            <?= e($item['name']) ?> (Current: <?= number_format((float)$item['quantity'], 2) ?> <?= e($item['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label>
                    <span style="font-size:12px; font-weight:600;">Action Type</span>
                    <select name="adjust_type" id="adjust-type" required>
                        <option value="add">+ Receive / Add Stock</option>
                        <option value="subtract">- Deduct (Correction / Spoilage)</option>
                    </select>
                </label>
                <label>
                    <span style="font-size:12px; font-weight:600;">Quantity (<span id="adjust-unit-label">units</span>) *</span>
                    <input type="number" name="quantity" min="0.001" step="0.001" required placeholder="Amount...">
                </label>
            </div>

            <label>
                <span style="font-size:12px; font-weight:600;">Reference / Reason</span>
                <input type="text" name="reference_no" placeholder="e.g. Inbound PO #102, Morning count adjustment...">
            </label>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <button class="btn secondary" type="button" onclick="closeStockAdjustModal()">Cancel</button>
                <button class="btn primary" type="submit">Update Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
function openIngredientModal() {
    document.getElementById('ingredient-modal-title').textContent = 'Add New Raw Ingredient';
    document.getElementById('ing-id').value = '0';
    document.getElementById('ing-name').value = '';
    document.getElementById('ing-unit').value = '';
    document.getElementById('ing-reorder').value = '10';
    document.getElementById('ing-initial-qty').value = '0';
    document.getElementById('initial-stock-group').style.display = 'block';
    document.getElementById('ing-submit-btn').textContent = 'Create Ingredient';

    const modal = document.getElementById('ingredient-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => document.getElementById('ing-name').focus(), 50);
}

function editIngredientModal(data) {
    document.getElementById('ingredient-modal-title').textContent = 'Edit Ingredient Details';
    document.getElementById('ing-id').value = data.id;
    document.getElementById('ing-name').value = data.name;
    document.getElementById('ing-unit').value = data.unit;
    document.getElementById('ing-reorder').value = parseFloat(data.reorder_level || 0);
    document.getElementById('initial-stock-group').style.display = 'none';
    document.getElementById('ing-submit-btn').textContent = 'Update Ingredient';

    const modal = document.getElementById('ingredient-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => document.getElementById('ing-name').focus(), 50);
}

function closeIngredientModal() {
    const modal = document.getElementById('ingredient-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function openStockAdjustModal() {
    const modal = document.getElementById('adjust-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    syncAdjustUnit();
}

function closeStockAdjustModal() {
    const modal = document.getElementById('adjust-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function quickAdjustIngredient(id, name, unit) {
    const sel = document.getElementById('adjust-ing-id');
    sel.value = id;
    openStockAdjustModal();
    syncAdjustUnit();
}

function syncAdjustUnit() {
    const sel = document.getElementById('adjust-ing-id');
    const opt = sel.options[sel.selectedIndex];
    const unit = opt ? opt.getAttribute('data-unit') : 'units';
    document.getElementById('adjust-unit-label').textContent = unit || 'units';
}

document.getElementById('adjust-ing-id')?.addEventListener('change', syncAdjustUnit);

// Escape key to close modals
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeIngredientModal();
        closeStockAdjustModal();
    }
});
</script>

<?php include 'partials/footer.php'; ?>
