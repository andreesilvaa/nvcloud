<?php
<?php
require __DIR__ . '/includes/auth.php';            // exige sessão
if (($_SESSION['user_role'] ?? '') !== 'admin') { http_response_code(403); exit('Acesso negado.'); }
require_once __DIR__ . '/../config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
	$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
	// Regista o detalhe técnico apenas no log do servidor
	error_log('[nvcloud] Erro de ligação à BD: ' . $e->getMessage());
	// Mostra uma mensagem neutra ao utilizador (sem detalhes internos)
	http_response_code(500);
	die('Serviço temporariamente indisponível. Tenta novamente mais tarde.');
}
