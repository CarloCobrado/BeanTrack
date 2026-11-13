<?php
require 'config.php';
require_role(['Supervisor', 'Administrator']);

$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->beginTransaction();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
try {
$state = $pdo->query(
	'SELECT last_id,chain_start_id,last_hash FROM audit_chain_state WHERE id=1'
)->fetch();

$is_valid = true;
$failure_id = null;
$integrity_details = null;
$legacy_count = 0;
$verified_count = 0;
$message = '';
$checkpoint_message = '';
$checkpoint_error = '';
$checkpoint_match = null;
$checkpoint_details = null;

if (!$state) {
	$is_valid = false;
	$message = 'Audit chain state is missing. Apply the database migration before using integrity verification.';
} else {
	$legacyStatement = $pdo->prepare(
		'SELECT COUNT(*) FROM audit_trail WHERE id < ? AND entry_hash IS NULL'
	);
	$legacyStatement->execute([$state['chain_start_id']]);
	$legacy_count = (int) $legacyStatement->fetchColumn();

	$statement = $pdo->prepare(
		'SELECT id,user_id,action,table_name,record_id,description,created_at,previous_hash,entry_hash FROM audit_trail WHERE id >= ? ORDER BY id ASC'
	);
	$statement->execute([$state['chain_start_id']]);
	$previousHash = str_repeat('0', 64);
	$lastId = (int) $state['chain_start_id'] - 1;
	$lastHash = $previousHash;

	while ($entry = $statement->fetch()) {
		$entryId = (int) $entry['id'];
		$storedPreviousHash = $entry['previous_hash'];
		$storedHash = $entry['entry_hash'];
		$entryForHash = $entry;
		unset($entryForHash['entry_hash']);
		$calculatedHash = audit_chain_hash($entryForHash);

		if ($storedPreviousHash !== $previousHash) {
			$is_valid = false;
			$failure_id = $entryId;
			$message = 'The previous-hash link does not match.';
			$integrity_details = [
				'type' => 'entry',
				'action' => $entry['action'],
				'created_at' => $entry['created_at'],
				'description' => $entry['description'],
				'stored_previous_hash' => $storedPreviousHash,
				'expected_previous_hash' => $previousHash,
				'stored_entry_hash' => $storedHash,
				'calculated_entry_hash' => $calculatedHash,
			];
			break;
		}

		if (!is_string($storedHash) || !hash_equals($storedHash, $calculatedHash)) {
			$is_valid = false;
			$failure_id = $entryId;
			$message = 'The entry contents do not match their stored hash.';
			$integrity_details = [
				'type' => 'entry',
				'action' => $entry['action'],
				'created_at' => $entry['created_at'],
				'description' => $entry['description'],
				'stored_previous_hash' => $storedPreviousHash,
				'expected_previous_hash' => $previousHash,
				'stored_entry_hash' => $storedHash,
				'calculated_entry_hash' => $calculatedHash,
			];
			break;
		}

		$previousHash = $storedHash;
		$lastHash = $storedHash;
		$lastId = $entryId;
		$verified_count++;
	}

	if ($is_valid && ((int) $state['last_id'] !== $lastId || !hash_equals($state['last_hash'], $lastHash))) {
		$is_valid = false;
		$message = 'The stored chain head does not match the last verified entry.';
		$failure_id = $lastId;
		$integrity_details = [
			'type' => 'chain_head',
			'stored_id' => (int) $state['last_id'],
			'expected_id' => $lastId,
			'stored_hash' => $state['last_hash'],
			'expected_hash' => $lastHash,
		];
	}

	if ($is_valid) {
		$message = 'All chained audit entries passed verification.';
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compare_checkpoint'])) {
	$upload = $_FILES['checkpoint'] ?? null;
	if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 16384) {
		$checkpoint_error = 'Select a valid checkpoint file smaller than 16 KB.';
	} else {
		$checkpoint = json_decode(file_get_contents($upload['tmp_name']), true);
		$validCheckpoint = is_array($checkpoint)
			&& ($checkpoint['format'] ?? null) === 'beantrack-audit-checkpoint'
			&& ($checkpoint['version'] ?? null) === 1
			&& ($checkpoint['database'] ?? null) === 'beantrack'
			&& filter_var($checkpoint['chain_start_id'] ?? null, FILTER_VALIDATE_INT) !== false
			&& filter_var($checkpoint['last_id'] ?? null, FILTER_VALIDATE_INT) !== false
			&& preg_match('/^[a-f0-9]{64}$/', (string) ($checkpoint['last_hash'] ?? '')) === 1;

		if (!$validCheckpoint) {
			$checkpoint_error = 'This is not a valid BeanTrack audit checkpoint.';
		} elseif (!$state || (int) $checkpoint['chain_start_id'] !== (int) $state['chain_start_id']) {
			$checkpoint_match = false;
			$checkpoint_error = 'Checkpoint mismatch: the audit chain was reset or replaced after this checkpoint.';
			$checkpoint_details = [
				'type' => 'chain_start',
				'saved_chain_start_id' => (int) $checkpoint['chain_start_id'],
				'current_chain_start_id' => $state ? (int) $state['chain_start_id'] : null,
			];
		} elseif ((int) $checkpoint['last_id'] < (int) $state['chain_start_id'] - 1) {
			$checkpoint_match = false;
			$checkpoint_error = 'Checkpoint mismatch: its chain position is invalid.';
			$checkpoint_details = [
				'type' => 'checkpoint_position',
				'saved_id' => (int) $checkpoint['last_id'],
				'chain_start_id' => (int) $state['chain_start_id'],
			];
		} elseif ((int) $state['last_id'] < (int) $checkpoint['last_id']) {
			$checkpoint_match = false;
			$checkpoint_error = 'Checkpoint mismatch: the database is behind the saved checkpoint.';
			$checkpoint_details = [
				'type' => 'database_behind',
				'saved_id' => (int) $checkpoint['last_id'],
				'current_chain_head_id' => (int) $state['last_id'],
			];
		} else {
			$checkpointLastId = (int) $checkpoint['last_id'];
			$prefixHash = str_repeat('0', 64);
			$prefixLastId = (int) $state['chain_start_id'] - 1;
			$prefixValid = true;
			$prefixFailure = null;
			if ($checkpointLastId > $prefixLastId) {
				$checkpointStatement = $pdo->prepare(
					'SELECT id,user_id,action,table_name,record_id,description,created_at,previous_hash,entry_hash FROM audit_trail WHERE id>=? AND id<=? ORDER BY id ASC'
				);
				$checkpointStatement->execute([$state['chain_start_id'], $checkpointLastId]);
				while ($checkpointEntry = $checkpointStatement->fetch()) {
					$checkpointEntryId = (int) $checkpointEntry['id'];
					$storedPreviousHash = $checkpointEntry['previous_hash'];
					$storedEntryHash = $checkpointEntry['entry_hash'];
					$entryForHash = $checkpointEntry;
					unset($entryForHash['entry_hash']);
					$calculatedEntryHash = audit_chain_hash($entryForHash);

					if ($storedPreviousHash !== $prefixHash
						|| !is_string($storedEntryHash)
						|| !hash_equals($storedEntryHash, $calculatedEntryHash)) {
						$prefixValid = false;
						$prefixFailure = [
							'type' => 'entry',
							'id' => $checkpointEntryId,
							'action' => $checkpointEntry['action'],
							'created_at' => $checkpointEntry['created_at'],
							'description' => $checkpointEntry['description'],
							'stored_previous_hash' => $storedPreviousHash,
							'expected_previous_hash' => $prefixHash,
							'stored_entry_hash' => $storedEntryHash,
							'calculated_entry_hash' => $calculatedEntryHash,
							'reason' => $storedPreviousHash !== $prefixHash ? 'Previous-hash link mismatch' : 'Entry contents do not match the stored hash',
						];
						break;
					}

					$prefixHash = $storedEntryHash;
					$prefixLastId = $checkpointEntryId;
				}
			}

			$checkpoint_match = $prefixValid
				&& $prefixLastId === $checkpointLastId
				&& hash_equals($checkpoint['last_hash'], $prefixHash);
			if ($checkpoint_match) {
				$checkpoint_message = $is_valid
					? 'The checkpoint matches through audit entry ' . $checkpointLastId . ', and the current chain also passes verification.'
					: 'The checkpoint matches only through audit entry ' . $checkpointLastId . ', but current chain verification failed: ' . $message . ' Entries after the saved checkpoint are not covered by it.';
			} else {
				$checkpoint_error = 'Checkpoint mismatch: the audit chain prefix through entry ' . $checkpointLastId . ' no longer matches the saved checkpoint.';
				if ($prefixFailure) {
					$checkpoint_details = $prefixFailure;
				} elseif ($prefixLastId !== $checkpointLastId) {
					$checkpoint_details = [
						'type' => 'missing_entry',
						'saved_id' => $checkpointLastId,
						'last_prefix_id' => $prefixLastId,
					];
				} else {
					$checkpoint_details = [
						'type' => 'checkpoint_hash',
						'entry_id' => $checkpointLastId,
						'saved_hash' => $checkpoint['last_hash'],
						'current_prefix_hash' => $prefixHash,
					];
				}
			}
		}
}
}

	$pdo->commit();
} catch (Throwable $error) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	throw $error;
}

if (isset($_GET['download_checkpoint'])) {
	if (!$is_valid || !$state) {
		http_response_code(409);
		exit('Cannot create a checkpoint because the current audit chain failed verification.');
	}

	$checkpoint = [
		'format' => 'beantrack-audit-checkpoint',
		'version' => 1,
		'database' => 'beantrack',
		'created_at' => date(DATE_ATOM),
		'chain_start_id' => (int) $state['chain_start_id'],
		'last_id' => $lastId,
		'last_hash' => $lastHash,
	];
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="beantrack-audit-checkpoint-' . date('Ymd-His') . '.json"');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('Pragma: no-cache');
	echo json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

include 'partials/header.php';
?>
<div class="page-head">
	<div>
		<h2>Audit Integrity</h2>
		<p class="muted">Checks the SHA-256 links between audit entries and the stored chain head.</p>
	</div>
	<a class="btn primary" href="audit_integrity.php?refresh=1">Refresh Check</a>
	<a class="btn secondary" href="audit.php">Back to Audit Trail</a>
</div>
<section class="card">
	<div class="alert <?= $is_valid ? 'success' : 'danger' ?>"><?= e($message) ?></div>
	<p>Chained entries verified: <strong><?= $verified_count ?></strong></p>
	<p>Pre-chain entries not cryptographically verified: <strong><?= $legacy_count ?></strong></p>
	<?php if ($failure_id !== null): ?>
		<p>First failing entry ID: <strong><?= e($failure_id) ?></strong></p>
	<?php endif; ?>
	<?php if ($integrity_details): ?>
		<div class="integrity-details">
			<h3>Mismatch Details</h3>
			<?php if ($integrity_details['type'] === 'entry'): ?>
				<p>Audit entry: <strong><?= e($failure_id) ?></strong></p>
				<p>Action: <?= e($integrity_details['action']) ?></p>
				<p>Date: <?= e($integrity_details['created_at']) ?></p>
				<p>Current description: <?= e($integrity_details['description'] ?? 'NULL') ?></p>
				<p>Stored previous hash: <code><?= e($integrity_details['stored_previous_hash'] ?? 'NULL') ?></code></p>
				<p>Expected previous hash: <code><?= e($integrity_details['expected_previous_hash']) ?></code></p>
				<p>Stored entry hash: <code><?= e($integrity_details['stored_entry_hash'] ?? 'NULL') ?></code></p>
				<p>Calculated entry hash: <code><?= e($integrity_details['calculated_entry_hash']) ?></code></p>
			<?php else: ?>
				<p>Stored chain-head entry ID: <?= e($integrity_details['stored_id']) ?></p>
				<p>Last verified entry ID: <?= e($integrity_details['expected_id']) ?></p>
				<p>Stored chain-head hash: <code><?= e($integrity_details['stored_hash']) ?></code></p>
				<p>Calculated chain-head hash: <code><?= e($integrity_details['expected_hash']) ?></code></p>
			<?php endif; ?>
			<p class="muted">These are the values currently stored and calculated. The original contents of an overwritten audit entry cannot be reconstructed unless you saved a prior checkpoint or separate copy.</p>
		</div>
	<?php endif; ?>
	<?php if ($checkpoint_message): ?>
		<div class="alert <?= $checkpoint_match && $is_valid ? 'success' : 'danger' ?>"><?= e($checkpoint_message) ?></div>
	<?php endif; ?>
	<?php if ($checkpoint_error): ?>
		<div class="alert danger"><?= e($checkpoint_error) ?></div>
	<?php endif; ?>
	<?php if ($checkpoint_details): ?>
		<div class="integrity-details">
			<h3>Checkpoint Difference Details</h3>
			<?php if ($checkpoint_details['type'] === 'entry'): ?>
				<p>First mismatching entry: <strong><?= e($checkpoint_details['id']) ?></strong></p>
				<p>Reason: <?= e($checkpoint_details['reason']) ?></p>
				<p>Action: <?= e($checkpoint_details['action']) ?></p>
				<p>Date: <?= e($checkpoint_details['created_at']) ?></p>
				<p>Current description: <?= e($checkpoint_details['description'] ?? 'NULL') ?></p>
				<p>Stored previous hash: <code><?= e($checkpoint_details['stored_previous_hash'] ?? 'NULL') ?></code></p>
				<p>Expected previous hash: <code><?= e($checkpoint_details['expected_previous_hash']) ?></code></p>
				<p>Stored entry hash: <code><?= e($checkpoint_details['stored_entry_hash'] ?? 'NULL') ?></code></p>
				<p>Calculated entry hash: <code><?= e($checkpoint_details['calculated_entry_hash']) ?></code></p>
			<?php elseif ($checkpoint_details['type'] === 'checkpoint_hash'): ?>
				<p>Checkpoint entry ID: <strong><?= e($checkpoint_details['entry_id']) ?></strong></p>
				<p>Hash saved in checkpoint: <code><?= e($checkpoint_details['saved_hash']) ?></code></p>
				<p>Hash calculated from current prefix: <code><?= e($checkpoint_details['current_prefix_hash']) ?></code></p>
			<?php elseif ($checkpoint_details['type'] === 'chain_start'): ?>
				<p>Chain start ID in checkpoint: <?= e($checkpoint_details['saved_chain_start_id']) ?></p>
				<p>Current chain start ID: <?= e($checkpoint_details['current_chain_start_id'] ?? 'missing') ?></p>
			<?php elseif ($checkpoint_details['type'] === 'checkpoint_position'): ?>
				<p>Saved checkpoint entry ID: <?= e($checkpoint_details['saved_id']) ?></p>
				<p>Current chain start ID: <?= e($checkpoint_details['chain_start_id']) ?></p>
			<?php elseif ($checkpoint_details['type'] === 'database_behind'): ?>
				<p>Saved checkpoint entry ID: <?= e($checkpoint_details['saved_id']) ?></p>
				<p>Current chain-head entry ID: <?= e($checkpoint_details['current_chain_head_id']) ?></p>
			<?php else: ?>
				<p>Saved checkpoint entry ID: <?= e($checkpoint_details['saved_id']) ?></p>
				<p>Last entry found in current prefix: <?= e($checkpoint_details['last_prefix_id']) ?></p>
			<?php endif; ?>
			<p class="muted">The checkpoint stores hashes, not a copy of the original audit descriptions, so it can identify a mismatch but cannot restore overwritten values.</p>
		</div>
	<?php endif; ?>
	<?php if ($is_valid): ?>
		<a class="btn secondary" href="audit_integrity.php?download_checkpoint=1">Download Checkpoint</a>
	<?php endif; ?>
	<form method="post" enctype="multipart/form-data" class="form-grid" style="margin-top: 1rem;">
		<label>Compare saved checkpoint
			<input type="file" name="checkpoint" accept="application/json,.json" required>
		</label>
		<button class="btn secondary" type="submit" name="compare_checkpoint" value="1">Check Checkpoint</button>
	</form>
	<p class="muted">Store the downloaded checkpoint somewhere outside this database. Upload it here later to check whether the database still matches the saved chain hash.</p>
</section>
<?php include 'partials/footer.php'; ?>