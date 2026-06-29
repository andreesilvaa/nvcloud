<?php
// clientes_lista_api.php — devolve a lista de clientes (account_name) em JSON.
// Usado para carregar o <select> de Cliente na página Análises só quando
// é mesmo necessário (em vez de embutir milhares de <option> em todas
// as visitas à página, o que pesava o HTML e o DOM sem necessidade).
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

require_once __DIR__ . '/config.php';
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->query("
    SELECT DISTINCT account_name FROM clientes
    WHERE account_name IS NOT NULL AND account_name <> ''
    ORDER BY account_name ASC
");
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
