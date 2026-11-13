<?php
require 'config.php';

if (is_logged()) {
	$user_id = (int) $_SESSION['user']['id'];
	try {
		audit('Logout', 'users', $user_id, 'User logged out');
	} catch (Throwable $error) {
		error_log('Failed to record logout audit event: ' . $error->getMessage());
	}
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
?>
