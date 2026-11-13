<?php
session_start();
date_default_timezone_set('Asia/Manila');

$host = '127.0.0.1';
$db = 'beantrack';
$user = 'root';
$pass = '';

try {
	$pdo = new PDO(
		"mysql:host=$host;dbname=$db;charset=utf8mb4",
		$user,
		$pass,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]
	);
	$pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
	die('Database connection failed: ' . $e->getMessage());
}

function e($v)
{
	return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function is_logged()
{
	return isset($_SESSION['user']);
}

function user_role()
{
	return $_SESSION['user']['role_name'] ?? '';
}

function require_login()
{
	if (!is_logged()) {
		header('Location: login.php');
		exit;
	}
}

function require_role($roles)
{
	require_login();

	if (!in_array(user_role(), $roles, true)) {
		http_response_code(403);
		die('Access denied.');
	}
}

function can_view_reports()
{
	return in_array(user_role(), ['Supervisor', 'Management'], true);
}

function can_create_reports()
{
	return user_role() === 'Supervisor';
}

function can_review_reports()
{
	return user_role() === 'Management';
}

function audit_chain_hash(array $entry)
{
	$canonical = json_encode(
		$entry,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
	);
	return hash('sha256', $canonical);
}

function audit($action, $table = null, $record = null, $desc = null)
{
	global $pdo;

	if (!is_logged()) {
		return;
	}

	$ownsTransaction = !$pdo->inTransaction();
	try {
		if ($ownsTransaction) {
			$pdo->beginTransaction();
		}

		$stateStatement = $pdo->query(
			'SELECT last_id, last_hash FROM audit_chain_state WHERE id=1 FOR UPDATE'
		);
		$chainState = $stateStatement->fetch();
		if (!$chainState) {
			throw new RuntimeException('Audit chain state is missing. Apply the audit hash-chain database migration.');
		}

		$previousHash = $chainState['last_hash'];
		$insert = $pdo->prepare(
			'INSERT INTO audit_trail(user_id,action,table_name,record_id,description,previous_hash,entry_hash) VALUES(?,?,?,?,?,?,?)'
		);
		$insert->execute([
			$_SESSION['user']['id'],
			$action,
			$table,
			$record,
			$desc,
			$previousHash,
			str_repeat('0', 64),
		]);
		$entryId = (int) $pdo->lastInsertId();

		$entryStatement = $pdo->prepare(
			'SELECT id,user_id,action,table_name,record_id,description,created_at,previous_hash FROM audit_trail WHERE id=?'
		);
		$entryStatement->execute([$entryId]);
		$entry = $entryStatement->fetch();
		$entryHash = audit_chain_hash($entry);

		$updateEntry = $pdo->prepare('UPDATE audit_trail SET entry_hash=? WHERE id=?');
		$updateEntry->execute([$entryHash, $entryId]);

		$updateState = $pdo->prepare('UPDATE audit_chain_state SET last_id=?,last_hash=? WHERE id=1');
		$updateState->execute([$entryId, $entryHash]);
		if ($ownsTransaction) {
			$pdo->commit();
		}
	} catch (Throwable $error) {
		if ($ownsTransaction && $pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw $error;
	}
}

function money($n)
{
	return '₱' . number_format((float) $n, 2);
}

function get_stock_units()
{
	return [
		'Weight' => [
			'g' => 'Grams (g)',
			'kg' => 'Kilograms (kg)',
			'mg' => 'Milligrams (mg)',
			'oz' => 'Ounces (oz)',
			'lb' => 'Pounds (lb)',
		],
		'Volume' => [
			'ml' => 'Milliliters (ml)',
			'l' => 'Liters (l)',
			'fl oz' => 'Fluid Ounces (fl oz)',
			'cup' => 'Cups (cup)',
			'tbsp' => 'Tablespoons (tbsp)',
			'tsp' => 'Teaspoons (tsp)',
		],
		'Count / Unit' => [
			'piece' => 'Piece (pc / piece)',
			'pack' => 'Pack (pack)',
			'can' => 'Can (can)',
			'bottle' => 'Bottle (bottle)',
			'box' => 'Box (box)',
			'pump' => 'Pump (pump)',
			'portion' => 'Portion / Serving (portion)',
			'slice' => 'Slice (slice)',
			'shot' => 'Shot (shot)',
		],
	];
}

function get_measurement_units()
{
	return [
		'g' => ['type' => 'mass', 'factor' => 1.0, 'name' => 'Grams (g)'],
		'kg' => ['type' => 'mass', 'factor' => 1000.0, 'name' => 'Kilograms (kg)'],
		'mg' => ['type' => 'mass', 'factor' => 0.001, 'name' => 'Milligrams (mg)'],
		'oz' => ['type' => 'mass', 'factor' => 28.3495, 'name' => 'Ounces (oz)'],
		'lb' => ['type' => 'mass', 'factor' => 453.592, 'name' => 'Pounds (lb)'],

		'ml' => ['type' => 'volume', 'factor' => 1.0, 'name' => 'Milliliters (ml)'],
		'l' => ['type' => 'volume', 'factor' => 1000.0, 'name' => 'Liters (l)'],
		'liter' => ['type' => 'volume', 'factor' => 1000.0, 'name' => 'Liters (l)'],
		'liters' => ['type' => 'volume', 'factor' => 1000.0, 'name' => 'Liters (l)'],
		'fl oz' => ['type' => 'volume', 'factor' => 29.5735, 'name' => 'Fluid Ounces (fl oz)'],
		'cup' => ['type' => 'volume', 'factor' => 240.0, 'name' => 'Cups (cup)'],
		'cups' => ['type' => 'volume', 'factor' => 240.0, 'name' => 'Cups (cup)'],
		'tbsp' => ['type' => 'volume', 'factor' => 15.0, 'name' => 'Tablespoons (tbsp)'],
		'tablespoon' => ['type' => 'volume', 'factor' => 15.0, 'name' => 'Tablespoons (tbsp)'],
		'tablespoons' => ['type' => 'volume', 'factor' => 15.0, 'name' => 'Tablespoons (tbsp)'],
		'tsp' => ['type' => 'volume', 'factor' => 5.0, 'name' => 'Teaspoons (tsp)'],
		'teaspoon' => ['type' => 'volume', 'factor' => 5.0, 'name' => 'Teaspoons (tsp)'],
		'teaspoons' => ['type' => 'volume', 'factor' => 5.0, 'name' => 'Teaspoons (tsp)'],

		'piece' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Piece (piece)'],
		'pieces' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Piece (piece)'],
		'pc' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Piece (pc)'],
		'pcs' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Pieces (pcs)'],
		'pump' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Pump (pump)'],
		'pumps' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Pumps (pumps)'],
		'shot' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Shot (shot)'],
		'shots' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Shots (shots)'],
		'pack' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Pack (pack)'],
		'packs' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Packs (packs)'],
		'bottle' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Bottle (bottle)'],
		'bottles' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Bottles (bottles)'],
		'can' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Can (can)'],
		'cans' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Cans (cans)'],
		'box' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Box (box)'],
		'boxes' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Boxes (boxes)'],
		'slice' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Slice (slice)'],
		'slices' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Slices (slices)'],
		'portion' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Portion (portion)'],
		'portions' => ['type' => 'count', 'factor' => 1.0, 'name' => 'Portions (portions)'],
	];
}

function convert_measurement($amount, $fromUnit, $toUnit)
{
	$fromKey = strtolower(trim($fromUnit));
	$toKey = strtolower(trim($toUnit));
	$amount = (float) $amount;

	if ($fromKey === $toKey) {
		return $amount;
	}

	$units = get_measurement_units();
	if (!isset($units[$fromKey], $units[$toKey])) {
		return null;
	}

	$fromDef = $units[$fromKey];
	$toDef = $units[$toKey];

	if ($fromDef['type'] !== $toDef['type']) {
		return null;
	}

	$baseValue = $amount * $fromDef['factor'];
	return $baseValue / $toDef['factor'];
}

function get_compatible_units_for($stockUnit)
{
	$key = strtolower(trim($stockUnit));
	$units = get_measurement_units();

	if (isset($units[$key])) {
		$targetType = $units[$key]['type'];
		$compatible = [];
		foreach ($units as $uKey => $uDef) {
			if ($uDef['type'] === $targetType) {
				$compatible[$uKey] = $uDef['name'];
			}
		}
		return $compatible;
	}

	return [$stockUnit => $stockUnit];
}

function nav_active($p)
{
	return basename($_SERVER['PHP_SELF']) === $p ? 'active' : '';
}
