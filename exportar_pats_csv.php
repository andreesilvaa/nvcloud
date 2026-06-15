<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/config.php';
$dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$stmt = $pdo->query("
    SELECT numero_pat, revisao, entidade, local_cliente, tecnico,
           data_recepcao, data_limite, prioridade, estado, criado_por, created_at
    FROM pats ORDER BY created_at DESC
");
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="pats_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 para Excel

fputcsv($out, ['Nº PAT','Revisão','Entidade','Local','Técnico','Receção','Limite','Prioridade','Estado','Criado por','Data Criação'], ';');

foreach ($rows as $r) {
	fputcsv($out, [
		$r['numero_pat'], $r['revisao'], $r['entidade'], $r['local_cliente'], $r['tecnico'],
		$r['data_recepcao'] ? date('d/m/Y H:i', strtotime($r['data_recepcao'])) : '',
		$r['data_limite']   ? date('d/m/Y H:i', strtotime($r['data_limite']))   : '',
		$r['prioridade'], $r['estado'], $r['criado_por'],
		$r['created_at']    ? date('d/m/Y H:i', strtotime($r['created_at']))    : '',
	], ';');
}
fclose($out);
