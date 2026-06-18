<?php

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

$session_timeout = 8 * 60 * 60;

if (!isset($_SESSION['user_id'])) {
	header('Location: login.php');
	exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $session_timeout)) {
		$_SESSION = [];
		session_destroy();
		header('Location: login.php?expired=1');
		exit;
}

$_SESSION['LAST_ACTIVITY'] = time();

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
