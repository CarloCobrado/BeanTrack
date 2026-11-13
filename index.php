<?php
require_once 'config.php';
require_login();

$role = user_role();
$isInventoryStaff = ($role === 'Inventory Staff');
$isSupervisorOrAdmin = in_array($role, ['Supervisor', 'Administrator'], true);

// Determine active dashboard view: 'inventory' or 'sales'
if ($isInventoryStaff) {
	$activeView = 'inventory';
} elseif ($isSupervisorOrAdmin) {
	$activeView = $_GET['view'] ?? ($_SESSION['dashboard_view'] ?? 'inventory');
	if (!in_array($activeView, ['inventory', 'sales'], true)) {
		$activeView = 'inventory';
	}
	$_SESSION['dashboard_view'] = $activeView;
} else {
	$activeView = 'sales';
}

$stats = [];

if ($activeView === 'inventory') {
	$stats['lowStock'] = (int)$pdo->query(
		"SELECT COUNT(*) v FROM products WHERE active=1 AND quantity <= reorder_level AND quantity > 0"
	)->fetch()['v'];

	$stats['outOfStock'] = (int)$pdo->query(
		"SELECT COUNT(*) v FROM products WHERE active=1 AND quantity <= 0"
	)->fetch()['v'];

	$stats['products'] = (int)$pdo->query(
		"SELECT COUNT(*) v FROM products WHERE active=1"
	)->fetch()['v'];

	$stats['deliveries'] = (int)$pdo->query(
		"SELECT COUNT(*) v FROM supplier_deliveries"
	)->fetch()['v'];

	$lowStockItems = $pdo->query(
		"SELECT p.id, p.brand, p.name, p.category, p.quantity, p.unit, p.reorder_level
		 FROM products p
		 WHERE p.active=1 AND p.quantity <= p.reorder_level
		 ORDER BY p.quantity ASC, p.name ASC
		 LIMIT 6"
	)->fetchAll();
} else {
	$stats['salesToday'] = $pdo->query(
		"SELECT COALESCE(SUM(total_amount),0) v FROM transactions WHERE DATE(created_at)=CURDATE() AND payment_status='Paid'"
	)->fetch()['v'];

	$stats['txToday'] = $pdo->query(
		'SELECT COUNT(*) v FROM transactions WHERE DATE(created_at)=CURDATE()'
	)->fetch()['v'];

	$stats['lowStock'] = $pdo->query(
		'SELECT (SELECT COUNT(*) FROM products p WHERE p.active=TRUE AND p.quantity<=p.reorder_level AND NOT EXISTS (SELECT 1 FROM product_recipe_items r WHERE r.product_id=p.id)) + (SELECT COUNT(*) FROM ingredients WHERE active=TRUE AND quantity<=reorder_level) v'
	)->fetch()['v'];

	$stats['products'] = $pdo->query(
		'SELECT COUNT(*) v FROM products WHERE active=TRUE'
	)->fetch()['v'];

	$recent = $pdo->query(
		'SELECT t.id,t.created_at,t.total_amount,t.payment_method,t.payment_status,u.name cashier FROM transactions t JOIN users u ON u.id=t.cashier_id ORDER BY t.id DESC LIMIT 6'
	)->fetchAll();
}

include 'partials/header.php';
?>
<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
	<div>
		<h2 style="margin:0 0 4px 0;">Dashboard</h2>
		<p class="muted" style="margin:0;">
			<?php if ($activeView === 'inventory'): ?>
				<?= $isInventoryStaff ? 'Inventory and stock operations dashboard. Welcome back, ' . e($_SESSION['user']['name']) . '.' : 'Inventory &amp; stock overview mode. Welcome back, ' . e($_SESSION['user']['name']) . '.' ?>
			<?php else: ?>
				Welcome back, <?= e($_SESSION['user']['name']) ?>.
			<?php endif; ?>
		</p>
	</div>
	<?php if ($isSupervisorOrAdmin): ?>
		<div style="display:flex; gap:8px; align-items:center;">
			<span class="muted" style="font-size:12px; font-weight:600; text-transform:uppercase;">View:</span>
			<a class="btn <?= $activeView === 'inventory' ? 'primary' : 'secondary' ?> btn-sm" href="index.php?view=inventory">
				📦 Inventory View
			</a>
			<a class="btn <?= $activeView === 'sales' ? 'primary' : 'secondary' ?> btn-sm" href="index.php?view=sales">
				📊 Sales View
			</a>
		</div>
	<?php endif; ?>
</div>

<?php if ($activeView === 'inventory'): ?>
	<div class="stats">
		<div class="stat">
			<span>Low Stock Alert</span>
			<strong style="color:<?= $stats['lowStock'] > 0 ? '#e65100' : 'var(--text)' ?>;"><?= e($stats['lowStock']) ?></strong>
		</div>
		<div class="stat">
			<span>Out of Stock</span>
			<strong style="color:<?= $stats['outOfStock'] > 0 ? 'var(--danger)' : 'var(--text)' ?>;"><?= e($stats['outOfStock']) ?></strong>
		</div>
		<div class="stat">
			<span>Active Catalog Items</span>
			<strong><?= e($stats['products']) ?></strong>
		</div>
		<div class="stat">
			<span>Inbound Deliveries</span>
			<strong><?= e($stats['deliveries']) ?></strong>
		</div>
	</div>
<?php else: ?>
	<div class="stats">
		<div class="stat"><span>Today's Sales</span><strong><?= money($stats['salesToday']) ?></strong></div>
		<div class="stat"><span>Transactions</span><strong><?= e($stats['txToday']) ?></strong></div>
		<div class="stat"><span>Low Stock</span><strong><?= e($stats['lowStock']) ?></strong></div>
		<div class="stat"><span>Active Products</span><strong><?= e($stats['products']) ?></strong></div>
	</div>
<?php endif; ?>

<div class="grid-two">
	<?php if ($activeView === 'inventory'): ?>
		<section class="card">
			<div class="section-head" style="display:flex; justify-content:space-between; align-items:center;">
				<h3>Low Stock &amp; Reorder Alerts</h3>
				<a href="inventory.php?stock_status=low" style="font-size:13px;">View all in Inventory &rarr;</a>
			</div>
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th>Brand</th>
							<th>Product Name</th>
							<th style="text-align:right;">Stock Level</th>
							<th style="text-align:right;">Reorder Pt</th>
							<th style="text-align:center;">Status</th>
							<th style="text-align:center;">Action</th>
						</tr>
					</thead>
					<tbody>
						<?php if (!empty($lowStockItems)): ?>
							<?php foreach ($lowStockItems as $item): ?>
								<?php 
								$qty = (float)$item['quantity'];
								$reorder = (float)$item['reorder_level'];
								$isOut = $qty <= 0;
								?>
								<tr>
									<td><span class="badge" style="background:#f1f3f4; color:#37474f; font-weight:700;"><?= e($item['brand']) ?></span></td>
									<td><strong><?= e($item['name']) ?></strong></td>
									<td style="text-align:right; font-weight:700; color:<?= $isOut ? 'var(--danger)' : '#e65100' ?>;">
										<?= number_format($qty) ?> <?= e($item['unit'] ?? 'pc') ?>
									</td>
									<td style="text-align:right; color:var(--muted);"><?= number_format($reorder) ?></td>
									<td style="text-align:center;">
										<?php if ($isOut): ?>
											<span class="badge danger-badge" style="font-weight:700;">OUT OF STOCK</span>
										<?php else: ?>
											<span class="badge" style="background:#fff3e0; color:#e65100; font-weight:700;">LOW STOCK</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center;">
										<a class="btn secondary btn-sm" href="inventory.php?search=<?= urlencode($item['name']) ?>">Adjust</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else: ?>
							<tr>
								<td colspan="6" class="muted" style="text-align:center; padding:30px;">
									&#10004; All product stock levels are currently healthy!
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php else: ?>
		<section class="card">
			<div class="section-head">
				<h3><?= in_array($role, ['Management'], true) ? 'Business Overview' : 'Recent Transactions' ?></h3>
				<?php if (can_view_reports()): ?>
					<a href="<?= can_review_reports() ? 'management_reports.php' : 'reports.php' ?>"><?= can_review_reports() ? 'Open reports' : 'View all' ?></a>
				<?php endif; ?>
			</div>
			<div class="table-wrap">
				<table>
					<thead>
						<tr><th>#</th><th>Date</th><th>Cashier</th><th>Amount</th><th>Status</th></tr>
					</thead>
					<tbody>
						<?php foreach ($recent as $row): ?>
							<tr>
								<td>#<?= e($row['id']) ?></td>
								<td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
								<td><?= e($row['cashier']) ?></td>
								<td><?= money($row['total_amount']) ?></td>
								<td><span class="badge <?= strtolower($row['payment_status']) ?>"><?= e($row['payment_status']) ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>

	<section class="card">
		<div class="section-head"><h3>Quick Actions</h3></div>
		<div class="quick-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:10px;">
			<?php if (in_array($role, ['Supervisor', 'Cashier', 'Administrator'], true)): ?>
				<a class="quick" href="transactions.php">＋ New Sales Order</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Cashier', 'Administrator'], true)): ?>
				<a class="quick" href="customers.php">👥 Customers</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
				<a class="quick" href="inventory.php">📋 Inventory Catalog</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Administrator'], true)): ?>
				<a class="quick" href="recipes.php" style="background:#eef7f2; border-color:#b8d5c9; color:#176b5b;">🧪 Menu Recipes</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
				<a class="quick" href="ingredients.php">🌿 Ingredient Stock</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
				<a class="quick" href="deliveries.php">📦 Inbound Deliveries</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
				<a class="quick" href="spoilage.php">⚠️ Spoilage &amp; Waste</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
				<a class="quick" href="inventory.php?stock_status=low" style="background:#fff8e1; border-color:#ffe082; color:#b78103;">⚠️ Low Stock Filter</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Administrator'], true)): ?>
				<a class="quick" href="reports.php">📊 Analytics &amp; Reports</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Management', 'Administrator'], true)): ?>
				<a class="quick" href="management_reports.php">▤ Review Reports</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Supervisor', 'Administrator'], true)): ?>
				<a class="quick" href="audit.php">🧾 Audit Trail</a>
			<?php endif; ?>
			<?php if (in_array($role, ['Administrator'], true)): ?>
				<a class="quick" href="audit_integrity.php">🛡️ Audit Integrity</a>
				<a class="quick" href="users.php">👤 User Management</a>
				<a class="quick" href="backup.php">💾 Backup &amp; Recovery</a>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php include 'partials/footer.php'; ?>
