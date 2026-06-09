<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$host    = 'localhost';
$db      = 'stocks_db';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn     = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Exception $e) {
    die('Erro de ligação à base de dados: ' . $e->getMessage());
}

$filtroUtilizador = trim($_GET['audit_user']   ?? '');
$filtroAcao       = trim($_GET['audit_action'] ?? '');

$where  = [];
$params = [];

if ($filtroUtilizador !== '') {
    $where[]  = 'utilizador = ?';
    $params[] = $filtroUtilizador;
}

if ($filtroAcao !== '') {
    $where[]  = 'campo = ?';
    $params[] = $filtroAcao;
}

$sql = 'SELECT id, peca_id, campo, antes, depois, utilizador, data_alteracao FROM historico';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY data_alteracao DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'auditoria_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

fputcsv($out, ['ID', 'Peça ID', 'Campo', 'Antes', 'Depois', 'Utilizador', 'Data'], ';');

foreach ($rows as $row) {
    fputcsv($out, [
        $row['id'],
        $row['peca_id'],
        $row['campo'],
        $row['antes'],
        $row['depois'],
        $row['utilizador'],
        $row['data_alteracao'],
    ], ';');
}

fclose($out);
exit;
