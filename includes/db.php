<?php
require_once __DIR__ . '/../config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
	$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

	// As migrações em schema_migrate.php são idempotentes mas custam
	// sempre 2x SHOW COLUMNS + 1x CREATE TABLE IF NOT EXISTS à base de
	// dados — ao correrem em TODOS os pedidos HTTP, isso soma-se de
	// forma desnecessária em todas as páginas do site. Com este marker
	// de ficheiro, só correm uma única vez (até o ficheiro ser apagado
	// manualmente, por exemplo depois de um deploy com nova migração).
	$schemaMarker = __DIR__ . '/.schema_migrated';
	if (!file_exists($schemaMarker)) {
		require_once __DIR__ . '/schema_migrate.php';
		@file_put_contents($schemaMarker, date('c'));
	}
} catch (PDOException $e) {
	// Regista o detalhe técnico apenas no log do servidor
	error_log('[nvcloud] Erro de ligação à BD: ' . $e->getMessage());
	// Mostra uma mensagem neutra ao utilizador (sem detalhes internos)
	http_response_code(500);
	die('Serviço temporariamente indisponível. Tenta novamente mais tarde.');
}
