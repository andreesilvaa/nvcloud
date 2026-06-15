<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

require_once __DIR__ . '/config.php';
$dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$results = [];

$stmt = $pdo->prepare("SELECT id, numero_pat, revisao, entidade FROM pats WHERE numero_pat LIKE ? OR entidade LIKE ? LIMIT 5");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $r) {
	$results[] = [
		'icon'  => 'bi-headset',
		'label' => $r['numero_pat'].'/'.$r['revisao'].' — '.($r['entidade'] ?? ''),
		'type'  => 'PAT',
		'url'   => 'app.php?page=pats&ver=' . (int)$r['id'],
	];
}

$stmt = $pdo->prepare("SELECT id, produto, sn, estado FROM pecas WHERE sn LIKE ? OR produto LIKE ? LIMIT 5");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $r) {
	$results[] = [
		'icon'  => 'bi-box-seam',
		'label' => ($r['produto'] ?? '') . ($r['sn'] ? ' · SN: '.$r['sn'] : '') . ' · ' . ($r['estado'] ?? ''),
		'type'  => 'Peça',
		'url'   => 'app.php?page=nova_peca&edit=' . (int)$r['id'],
	];
}

$stmt = $pdo->prepare("SELECT id, empresa FROM parceiros WHERE empresa LIKE ? LIMIT 3");
$stmt->execute([$like]);
foreach ($stmt->fetchAll() as $r) {
	$results[] = [
		'icon'  => 'bi-building',
		'label' => $r['empresa'],
		'type'  => 'Parceiro',
		'url'   => 'app.php?page=parceiros&ver=' . (int)$r['id'],
	];
}

echo json_encode($results);