<?php
require 'config.php';
require_role(['Supervisor', 'Cashier', 'Administrator']);

$msg = $_SESSION['customer_message'] ?? '';
unset($_SESSION['customer_message']);
$error = '';

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->query("
        SELECT c.id, c.name, c.default_price_type, c.contact_no, c.address, c.created_at,
               COUNT(DISTINCT t.id) AS total_orders, 
               COALESCE(SUM(t.total_amount), 0) AS total_spent
        FROM customers c
        LEFT JOIN transactions t ON t.customer_name = c.name
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Customer Name', 'Default Price Tier', 'Contact No', 'Address', 'Total Orders', 'Total Spent (PHP)', 'Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['name'],
            $r['default_price_type'],
            $r['contact_no'] ?: '',
            $r['address'] ?: '',
            $r['total_orders'],
            number_format((float)$r['total_spent'], 2, '.', ''),
            $r['created_at']
        ]);
    }
    fclose($out);
    audit('Export Customers (CSV)', 'customers', null, 'Exported ' . count($rows) . ' customer records to CSV');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'archive_customer') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT name, active FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $cust = $stmt->fetch();
            if ($cust) {
                $newStatus = $cust['active'] ? 0 : 1;
                $upd = $pdo->prepare("UPDATE customers SET active = ? WHERE id = ?");
                $upd->execute([$newStatus, $id]);
                $statusLabel = $newStatus ? 'Activated' : 'Deactivated';
                audit($statusLabel . ' Customer', 'customers', $id, "$statusLabel customer '{$cust['name']}'");
                $_SESSION['customer_message'] = "Customer '{$cust['name']}' $statusLabel.";
            }
            header('Location: customers.php');
            exit;
        }

        if ($action === 'save_customer') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $priceType = $_POST['default_price_type'] ?? 'SRP';
            $contact = trim($_POST['contact_no'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '') {
                throw new Exception('Customer name is required.');
            }
            if (!in_array($priceType, ['SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER'], true)) {
                $priceType = 'SRP';
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET name=?, default_price_type=?, contact_no=?, address=? WHERE id=?");
                $stmt->execute([$name, $priceType, $contact ?: null, $address ?: null, $id]);
                audit('Edit Customer', 'customers', $id, "Updated customer $name ($priceType)");
                $_SESSION['customer_message'] = "Customer '$name' updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (name, default_price_type, contact_no, address) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $priceType, $contact ?: null, $address ?: null]);
                $newId = (int)$pdo->lastInsertId();
                audit('Create Customer', 'customers', $newId, "Added customer $name ($priceType)");
                $_SESSION['customer_message'] = "Customer '$name' added successfully.";
            }

            header('Location: customers.php');
            exit;
        }
    } catch (PDOException $e) {
        $error = ($e->errorInfo[1] ?? 0) === 1062
            ? 'A customer with this name already exists.'
            : 'Database error: ' . $e->getMessage();
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$filterTier = trim($_GET['tier'] ?? '');

$sql = "
    SELECT c.*, 
           COUNT(DISTINCT t.id) AS total_orders, 
           COALESCE(SUM(t.total_amount), 0) AS total_spent
    FROM customers c
    LEFT JOIN transactions t ON t.customer_name = c.name
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.contact_no LIKE ? OR c.address LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($filterTier !== '' && in_array($filterTier, ['SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER'], true)) {
    $sql .= " AND c.default_price_type = ?";
    $params[] = $filterTier;
}

$sql .= " GROUP BY c.id ORDER BY total_spent DESC, c.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customersList = $stmt->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Customer Directory</h2>
        <p class="muted" style="margin:0;">Manage client wholesale &amp; retail accounts and their default pricing tier.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn secondary" href="customers.php?export=csv">Download CSV</a>
        <button class="btn primary" type="button" onclick="openCustomerModal()">+ Add Customer Account</button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert success" style="margin-bottom:16px;"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert danger" style="margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<!-- Filters -->
<section class="card" style="margin-bottom:20px; padding:16px;">
    <form method="get" action="customers.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="flex:1; min-width:220px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Search Customer</span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Customer name, phone, address..." style="width:100%;">
        </label>
        <label style="width:180px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">Pricing Tier</span>
            <select name="tier" style="width:100%;">
                <option value="">All Tiers</option>
                <?php foreach (['SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER'] as $tier): ?>
                    <option value="<?= $tier ?>" <?= $filterTier === $tier ? 'selected' : '' ?>><?= $tier ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterTier !== ''): ?>
                <a class="btn secondary" href="customers.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Customer List Table -->
<section class="card">
    <div class="section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Client Accounts (<?= count($customersList) ?>)</h3>
    </div>
    <div class="table-wrap" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Account / Client Name</th>
                    <th>Default Price Tier</th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th style="text-align:right;">Orders</th>
                    <th style="text-align:right;">Total Spend</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customersList): ?>
                    <?php foreach ($customersList as $c): ?>
                        <tr>
                            <td><strong><?= e($c['name']) ?></strong></td>
                            <td>
                                <span class="badge" style="
                                    background: <?= $c['default_price_type'] === 'DEALER' ? '#e1f5fe; color:#0277bd;' : ($c['default_price_type'] === 'BULK' ? '#f3e5f5; color:#7b1fa2;' : ($c['default_price_type'] === 'WHOLESALE' ? '#e8f5e9; color:#2e7d32;' : '#fff3e0; color:#ef6c00;')) ?>;
                                    font-weight:600; font-size:11px; padding:3px 8px; border-radius:10px;">
                                    <?= e($c['default_price_type']) ?>
                                </span>
                            </td>
                            <td><?= e($c['contact_no'] ?: '—') ?></td>
                            <td><?= e($c['address'] ?: '—') ?></td>
                            <td style="text-align:right;"><?= number_format((int)$c['total_orders']) ?></td>
                            <td style="text-align:right; font-weight:600; color:var(--primary);">₱<?= number_format((float)$c['total_spent'], 2) ?></td>
                            <td style="text-align:center;">
                                <button class="btn secondary btn-sm" type="button" 
                                        onclick="editCustomer(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)">
                                    Edit
                                </button>
                                <a class="btn secondary btn-sm" href="transactions.php?customer=<?= urlencode($c['name']) ?>">
                                    Orders
                                </a>
                                <form method="post" action="customers.php" style="display:inline;" onsubmit="return confirm('<?= $c['active'] ? 'Deactivate' : 'Activate' ?> <?= e(addslashes($c['name'])) ?>?');">
                                    <input type="hidden" name="action" value="archive_customer">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn secondary btn-sm" type="submit" style="color:<?= $c['active'] ? 'var(--danger)' : 'var(--primary)' ?>;">
                                        <?= $c['active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center; padding:30px;">No customers found matching your criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal: Add/Edit Customer -->
<div class="modal" id="customer-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:999; align-items:center; justify-content:center;">
    <div class="modal-backdrop" onclick="closeCustomerModal()" style="position:absolute; inset:0; background:rgba(0,0,0,0.5);"></div>
    <div class="modal-panel card" style="position:relative; z-index:1000; width:100%; max-width:480px; padding:24px; border-radius:10px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 id="modal-title" style="margin:0;">Add Customer Account</h3>
            <button type="button" onclick="closeCustomerModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="customers.php">
            <input type="hidden" name="action" value="save_customer">
            <input type="hidden" name="id" id="cust-id" value="0">
            
            <label style="display:block; margin-bottom:12px;">
                <span style="font-size:12px; font-weight:600;">Customer / Business Name *</span>
                <input type="text" name="name" id="cust-name" required placeholder="e.g. NANDITOS, BONG, CBCO..." style="width:100%;">
            </label>

            <label style="display:block; margin-bottom:12px;">
                <span style="font-size:12px; font-weight:600;">Default Price Tier *</span>
                <select name="default_price_type" id="cust-price-type" required style="width:100%;">
                    <option value="SRP">SRP (Retail)</option>
                    <option value="WHOLESALE">WHOLESALE</option>
                    <option value="BULK">BULK</option>
                    <option value="DEALER">DEALER</option>
                    <option value="RESELLER">RESELLER</option>
                </select>
                <small class="muted">This tier will automatically apply when the cashier selects this client in the POS.</small>
            </label>

            <label style="display:block; margin-bottom:12px;">
                <span style="font-size:12px; font-weight:600;">Contact Number (optional)</span>
                <input type="text" name="contact_no" id="cust-contact" placeholder="e.g. 0917-123-4567" style="width:100%;">
            </label>

            <label style="display:block; margin-bottom:18px;">
                <span style="font-size:12px; font-weight:600;">Business Address (optional)</span>
                <input type="text" name="address" id="cust-address" placeholder="e.g. Davao City" style="width:100%;">
            </label>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button class="btn secondary" type="button" onclick="closeCustomerModal()">Cancel</button>
                <button class="btn primary" type="submit" id="cust-submit-btn">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCustomerModal() {
    document.getElementById('cust-id').value = 0;
    document.getElementById('cust-name').value = '';
    document.getElementById('cust-price-type').value = 'SRP';
    document.getElementById('cust-contact').value = '';
    document.getElementById('cust-address').value = '';
    document.getElementById('modal-title').textContent = 'Add Customer Account';
    document.getElementById('cust-submit-btn').textContent = 'Add Customer';
    const m = document.getElementById('customer-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function editCustomer(c) {
    document.getElementById('cust-id').value = c.id;
    document.getElementById('cust-name').value = c.name;
    document.getElementById('cust-price-type').value = c.default_price_type || 'SRP';
    document.getElementById('cust-contact').value = c.contact_no || '';
    document.getElementById('cust-address').value = c.address || '';
    document.getElementById('modal-title').textContent = 'Edit Customer: ' + c.name;
    document.getElementById('cust-submit-btn').textContent = 'Update Customer';
    const m = document.getElementById('customer-modal');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
}

function closeCustomerModal() {
    const m = document.getElementById('customer-modal');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}
</script>

<?php include 'partials/footer.php'; ?>
