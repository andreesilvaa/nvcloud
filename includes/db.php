<?php

require_once __DIR__ . '/../config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
		$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Exception $e) {
	die('Erro de ligação à base de dados: ' .$e->getMessage());
}
