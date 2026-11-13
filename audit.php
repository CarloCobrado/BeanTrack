<?php
require 'config.php';
require_role(['Supervisor', 'Administrator']);

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$from = trim($from);
$to = trim($to);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;

$params = [];
$countSql = 'SELECT COUNT(*) FROM audit_trail a';
$sql = 'SELECT a.id,a.action,a.table_name,a.record_id,a.description,a.created_at,u.name AS user_name FROM audit_trail a LEFT JOIN users u ON u.id=a.user_id';
$conditions = [];

if ($from !== '' && $to !== '') {
	$conditions[] = 'DATE(a.created_at) BETWEEN ? AND ?';
	$params[] = $from;
	$params[] = $to;
}

if ($conditions) {
	$where = ' WHERE ' . implode(' AND ', $conditions);
	$countSql .= $where;
	$sql .= $where;
}

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$totalRecords = (int) $countStatement->fetchColumn();
$totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $perPage) : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql .= ' ORDER BY a.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$statement = $pdo->prepare($sql);
$statement->execute($params);
$logs = $statement->fetchAll();

$startEntry = $totalRecords > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalRecords);
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);

include 'partials/header.php';
?>
<div class="page-head">
	<div>
		<h2>Audit Trail</h2>
	</div>
	<a class="btn secondary" href="audit_integrity.php">Verify Integrity</a>
</div>
<section class="card">
	<form class="filter" method="get">
		<input type="hidden" name="page" value="1">
		<label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
		<label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
		<button class="btn primary" type="submit">Apply Filter</button>
	</form>
</section>
<section class="card">
	<div class="section-head">
		<h3>Audit Entries</h3>
		<span class="muted"><?= $startEntry ?>-<?= $endEntry ?> of <?= $totalRecords ?> entries</span>
	</div>
	<div class="table-wrap">
		<table>
			<thead>
				<tr><th>Date</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Description</th></tr>
			</thead>
			<tbody>
				<?php foreach ($logs as $log): ?>
					<tr>
						<td><?= e(date('M d, Y h:i A', strtotime($log['created_at']))) ?></td>
						<td><?= e($log['user_name'] ?? 'Unknown User') ?></td>
						<td><?= e($log['action']) ?></td>
						<td><?= e($log['table_name'] ?? '-') ?></td>
						<td><?= e($log['record_id'] ?? '-') ?></td>
						<td><?= e($log['description'] ?? '-') ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="section-head" style="margin-top: 1rem;">
		<a class="btn secondary" href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $prevPage ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none; opacity:0.5;"' : '' ?>>Previous</a>
		<span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
		<a class="btn secondary" href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $nextPage ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none; opacity:0.5;"' : '' ?>>Next</a>
	</div>
</section>
<?php include 'partials/footer.php'; ?>
