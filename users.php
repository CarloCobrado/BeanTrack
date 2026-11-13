<?php
require 'config.php';
require_role(['Administrator']);

$message = '';
$error = '';
$edit_id = (int) ($_GET['edit'] ?? 0);

$admin_count = (int) $pdo->query(
	"SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='Administrator' AND u.is_active=1"
)->fetchColumn();
$protected_admin_id = 0;
if ($admin_count === 1) {
	$protected_admin = $pdo->query(
		"SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='Administrator' AND u.is_active=1 ORDER BY u.id ASC LIMIT 1"
	)->fetchColumn();
	$protected_admin_id = $protected_admin ? (int) $protected_admin : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$user_id = (int) ($_POST['user_id'] ?? 0);
	$name = trim($_POST['name'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$password = $_POST['password'] ?? '';
	$role_id = (int) ($_POST['role_id'] ?? 0);

	try {
		if ($action === 'save') {
			if ($admin_count === 1 && $user_id === $protected_admin_id) {
				throw new Exception('The last remaining administrator account cannot be edited.');
			}

			if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $role_id < 1) {
				throw new Exception('Enter a valid name, email, and role.');
			}

			$role_statement = $pdo->prepare('SELECT role_name FROM roles WHERE id=?');
			$role_statement->execute([$role_id]);
			$selected_role = $role_statement->fetchColumn();
			if (!$selected_role) {
				throw new Exception('Selected role does not exist.');
			}

			if ($user_id > 0) {
				$current_role_statement = $pdo->prepare(
					'SELECT u.name,u.email,u.role_id,u.is_active,r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?'
				);
				$current_role_statement->execute([$user_id]);
				$current_user = $current_role_statement->fetch();
				if (!$current_user) {
					throw new Exception('User account was not found.');
				}

				if ($current_user && $current_user['role_name'] === 'Administrator' && (int) $current_user['is_active'] === 1 && $selected_role !== 'Administrator') {
					$admin_count = (int) $pdo->query(
						"SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='Administrator' AND u.is_active=1"
					)->fetchColumn();
					if ($admin_count <= 1) {
						throw new Exception('At least one administrator must remain in the system.');
					}
				}

				if ($password !== '' && strlen($password) < 6) {
					throw new Exception('Password must be at least 6 characters.');
				}
				if ($password !== '') {
					$statement = $pdo->prepare('UPDATE users SET name=?,email=?,password=?,role_id=? WHERE id=?');
					$statement->execute([$name, $email, $password, $role_id, $user_id]);
				} else {
					$statement = $pdo->prepare('UPDATE users SET name=?,email=?,role_id=? WHERE id=?');
					$statement->execute([$name, $email, $role_id, $user_id]);
				}
				$changes = [];
				if ($current_user && $current_user['name'] !== $name) {
					$changes['Name'] = ['from' => $current_user['name'], 'to' => $name];
				}
				if ($current_user && $current_user['email'] !== $email) {
					$changes['Email'] = ['from' => $current_user['email'], 'to' => $email];
				}
				if ($current_user && (int) $current_user['role_id'] !== $role_id) {
					$changes['Role'] = ['from' => $current_user['role_name'], 'to' => $selected_role];
				}
				if ($password !== '') {
					$changes['Password'] = 'changed; value not recorded';
				}
				if ($changes) {
					$change_details = json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
					audit('Edit User', 'users', $user_id, 'Field changes: ' . $change_details);
				}
				$message = 'User updated successfully.';
				$edit_id = 0;
			} else {
				if ($password === '') {
					throw new Exception('A password is required for a new user.');
				}
				$statement = $pdo->prepare('INSERT INTO users(name,email,password,role_id) VALUES(?,?,?,?)');
				$statement->execute([$name, $email, $password, $role_id]);
				$new_user_id = (int) $pdo->lastInsertId();
				audit('Create User', 'users', $new_user_id, 'Created user; name=' . $name . '; email=' . $email . '; role=' . $selected_role . '; password not recorded.');
				$message = 'User created successfully.';
			}
		} elseif ($action === 'delete' || $action === 'deactivate') {
			if ($user_id < 1 || $user_id === (int) $_SESSION['user']['id']) {
				throw new Exception('You cannot deactivate your own account.');
			}
			$role_statement = $pdo->prepare(
				'SELECT u.name,u.email,u.is_active,r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?'
			);
			$role_statement->execute([$user_id]);
			$user_row = $role_statement->fetch();
			if (!$user_row) {
				throw new Exception('User account was not found.');
			}
			if (!(int) $user_row['is_active']) {
				throw new Exception('This account is already inactive.');
			}
			if ($user_row['role_name'] === 'Administrator') {
				$admin_count = (int) $pdo->query(
					"SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='Administrator' AND u.is_active=1"
				)->fetchColumn();
				if ($admin_count <= 1) {
					throw new Exception('At least one administrator must remain in the system.');
				}
			}
			$statement = $pdo->prepare('UPDATE users SET is_active=0 WHERE id=?');
			$statement->execute([$user_id]);
			audit('Deactivate User', 'users', $user_id, "Deactivated user {$user_row['name']} ({$user_row['email']}); historical records retained.");
			$message = 'User deactivated. Historical records have been retained.';
		} elseif ($action === 'reactivate') {
			if ($user_id < 1) {
				throw new Exception('Select a valid user account.');
			}
			$statement = $pdo->prepare('SELECT name,email,is_active FROM users WHERE id=?');
			$statement->execute([$user_id]);
			$user_row = $statement->fetch();
			if (!$user_row) {
				throw new Exception('User account was not found.');
			}
			if ((int) $user_row['is_active']) {
				throw new Exception('This account is already active.');
			}
			$statement = $pdo->prepare('UPDATE users SET is_active=1 WHERE id=?');
			$statement->execute([$user_id]);
			audit('Reactivate User', 'users', $user_id, "Reactivated user {$user_row['name']} ({$user_row['email']}).");
			$message = 'User reactivated successfully.';
		}
	} catch (PDOException $e) {
		$error = (int) ($e->errorInfo[1] ?? 0) === 1062
			? 'That email address is already in use.'
			: 'The user change could not be saved. Please try again.';
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}

$roles = $pdo->query('SELECT id,role_name FROM roles ORDER BY id')->fetchAll();
$edit_user = null;
if ($edit_id > 0) {
	$statement = $pdo->prepare('SELECT id,name,email,role_id FROM users WHERE id=?');
	$statement->execute([$edit_id]);
	$edit_user = $statement->fetch();
}
$users = $pdo->query(
	'SELECT u.id,u.name,u.email,u.role_id,u.is_active,r.role_name,u.created_at FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id'
)->fetchAll();
include 'partials/header.php';
?>
<div class="page-head">
	<div>
		<h2>Users</h2>
	</div>
</div>
<?php if ($message): ?>
	<div class="alert success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
	<div class="alert danger"><?= e($error) ?></div>
<?php endif; ?>
<section class="card">
	<h3><?= $edit_user ? 'Edit User' : 'Add User' ?></h3>
	<form method="post" class="form-grid">
		<input type="hidden" name="action" value="save">
		<input type="hidden" name="user_id" value="<?= $edit_user['id'] ?? 0 ?>">
		<label>Name
			<input name="name" required value="<?= e($edit_user['name'] ?? '') ?>">
		</label>
		<label>Email
			<input type="email" name="email" required value="<?= e($edit_user['email'] ?? '') ?>">
		</label>
		<label>Password<?= $edit_user ? ' (leave blank to keep current)' : '' ?>
			<input type="password" name="password" <?= $edit_user ? '' : 'required' ?> minlength="6">
		</label>
		<label>Role
			<select name="role_id" required>
				<option value="">Select role</option>
				<?php foreach ($roles as $role): ?>
					<option value="<?= $role['id'] ?>" <?= (int) ($edit_user['role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['role_name']) ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<div class="form-actions">
			<button class="btn primary"><?= $edit_user ? 'Update User' : 'Create User' ?></button>
			<?php if ($edit_user): ?>
				<a class="btn secondary" href="users.php">Cancel</a>
			<?php endif; ?>
		</div>
	</form>
</section>
<section class="card">
	<div class="section-head">
		<h3>System Users</h3>
		<span class="muted"><?= count($users) ?> accounts</span>
	</div>
	<div class="table-wrap">
		<table>
			<thead>
				<tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
			</thead>
			<tbody>
				<?php foreach ($users as $user): ?>
					<tr>
						<td><?= e($user['name']) ?></td>
						<td><?= e($user['email']) ?></td>
						<td><span class="badge neutral"><?= e($user['role_name']) ?></span></td>
						<td><span class="badge neutral"><?= (int) $user['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
						<td><?= e(date('M d, Y', strtotime($user['created_at']))) ?></td>
						<td class="row-actions">
							<?php if ((int) $user['is_active'] === 1 && (int) $user['id'] === $protected_admin_id): ?>
								<span class="badge neutral">Protected</span>
							<?php else: ?>
								<a class="btn secondary" href="users.php?edit=<?= $user['id'] ?>">Edit</a>
								<?php if ((int) $user['is_active'] === 1 && (int) $user['id'] !== (int) $_SESSION['user']['id']): ?>
									<form method="post" onsubmit="return confirm('Delete this user?');">
										<input type="hidden" name="action" value="deactivate">
										<input type="hidden" name="user_id" value="<?= $user['id'] ?>">
										<button class="btn danger-btn">Deactivate</button>
									</form>
								<?php elseif ((int) $user['is_active'] === 0): ?>
									<form method="post" onsubmit="return confirm('Reactivate this user?');">
										<input type="hidden" name="action" value="reactivate">
										<input type="hidden" name="user_id" value="<?= $user['id'] ?>">
										<button class="btn secondary">Reactivate</button>
									</form>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php include 'partials/footer.php'; ?>