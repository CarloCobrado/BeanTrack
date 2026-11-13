<?php
require 'config.php';

if (is_logged()) {
  header('Location: index.php');
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';
  $statement = $pdo->prepare(
    'SELECT u.*,r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.is_active=1 LIMIT 1'
  );
  $statement->execute([$email]);
  $user = $statement->fetch();

  if ($user && hash_equals((string) $user['password'], $password)) {
    $_SESSION['user'] = [
      'id' => $user['id'],
      'name' => $user['name'],
      'email' => $user['email'],
      'role_name' => $user['role_name'],
    ];
    audit('Login', 'users', $user['id'], 'User logged in');
    header('Location: index.php');
    exit;
  }

  $error = 'Invalid email or password.';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>BEANTRACK | Login</title>
  <link rel="stylesheet" href="assets/style.css?v=2">
</head>
<body class="login-page">
  <div class="login-card">
    <img class="login-logo" src="assets/BeanTrack_Logo.png" alt="BeanTrack">
    <?php if ($error): ?>
      <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <label>Email</label>
      <input type="email" name="email" required placeholder="you@example.com">
      <label>Password</label>
      <input type="password" name="password" required placeholder="••••••••">
      <button class="btn primary full">Sign in</button>
    </form>
  </div>
</body>
</html>
