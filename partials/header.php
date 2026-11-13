<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>BEANTRACK - Supply &amp; Distribution</title>
	<link rel="stylesheet" href="assets/style.css?v=7">
</head>
<body>
	<?php $role = user_role(); ?>
	<div class="app" data-app-shell>
		<aside class="sidebar" id="primary-navigation" aria-label="Primary navigation">
			<div class="logo">
				<img class="sidebar-logo" src="assets/BeanTrack_Logo-NoText.png" alt="BeanTrack">
			</div>
			<nav>
				<a class="<?= nav_active('index.php') ?>" href="index.php">Dashboard</a>
				<?php if (in_array($role, ['Supervisor', 'Cashier', 'Administrator'], true)): ?>
					<a class="<?= nav_active('transactions.php') ?>" href="transactions.php">Sales &amp; POS</a>
				<?php endif; ?>
				<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
					<a class="<?= nav_active('inventory.php') ?>" href="inventory.php">Inventory Catalog</a>
					<a class="<?= nav_active('deliveries.php') ?>" href="deliveries.php">Deliveries (P.O.)</a>
					<a class="<?= nav_active('spoilage.php') ?>" href="spoilage.php">Spoilage &amp; Waste</a>
				<?php endif; ?>
				<?php if (in_array($role, ['Supervisor', 'Administrator'], true)): ?>
					<a class="<?= nav_active('recipes.php') ?>" href="recipes.php">Menu Recipes</a>
				<?php endif; ?>
				<?php if (in_array($role, ['Supervisor', 'Inventory Staff', 'Administrator'], true)): ?>
					<a class="<?= nav_active('ingredients.php') ?>" href="ingredients.php">Ingredient Stock</a>
				<?php endif; ?>
				<?php if (in_array($role, ['Supervisor', 'Cashier', 'Administrator'], true)): ?>
					<a class="<?= nav_active('customers.php') ?>" href="customers.php">Customers</a>
				<?php endif; ?>
				<?php if (in_array($role, ['Supervisor', 'Administrator'], true)): ?>
					<a class="<?= nav_active('reports.php') ?>" href="reports.php">Reports &amp; Export</a>
				<?php endif; ?>
				<?php if ($role === 'Management'): ?>
					<a class="<?= nav_active('management_reports.php') ?>" href="management_reports.php">Review Reports</a>
				<?php endif; ?>
				<?php if (in_array($role, ['Supervisor', 'Administrator'], true)): ?>
					<a class="<?= nav_active('audit.php') ?>" href="audit.php">Audit Trail</a>
				<?php endif; ?>
				<?php if ($role === 'Administrator'): ?>
					<a class="<?= nav_active('users.php') ?>" href="users.php">Users</a>
					<a class="<?= nav_active('backup.php') ?>" href="backup.php">Backup &amp; Recovery</a>
				<?php endif; ?>
			</nav>
			<div class="sidebar-bottom">
				<div class="user-mini">
					<span><?= e($_SESSION['user']['name'] ?? 'User') ?></span>
					<small><?= e($role) ?></small>
				</div>
				<a href="logout.php">Logout</a>
			</div>
		</aside>
		<button class="sidebar-backdrop" type="button" data-close-navigation aria-label="Close navigation"></button>
		<main class="content">
			<header class="topbar">
				<button class="mobile-nav-toggle" type="button" aria-label="Open navigation" aria-controls="primary-navigation" aria-expanded="false" data-toggle-navigation>
					<span></span><span></span><span></span>
				</button>
				<div class="topbar-right">
					<span class="badge" style="background:#e8f4ec; color:#176b5b; font-weight:600; padding:4px 10px; border-radius:12px;">System Online</span>
				</div>
			</header>
			<div class="container">
