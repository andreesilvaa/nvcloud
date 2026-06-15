<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/config.php';
$dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$stmt = $pdo->query("SELECT id, categoria, produto, sn, cod_barras, parceiro, estado, created_at FROM pecas ORDER BY id DESC");
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventario_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($out, ['ID','Categoria','Produto','SN','Cód. Barras','Parceiro','Estado','Data Criação'], ';');

foreach ($rows as $r) {
	fputcsv($out, [
		$r['id'], $r['categoria'], $r['produto'], $r['sn'], $r['cod_barras'],
		$r['parceiro'], $r['estado'],
		$r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
	], ';');
}
fclose($out);
