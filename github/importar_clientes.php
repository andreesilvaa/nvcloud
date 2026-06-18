<?php
require __DIR__ . '/../includes/auth.php';
if (($_SESSION['user_role'] ?? '') !== 'admin') {
	http_response_code(403);
	exit('Acesso negado.');
}
/**
 * Importador (uso único, via CLI) do relatório de clientes
 * (report*.csv → tabela `clientes`).
 *
 *   php github/importar_clientes.php [caminho_do_csv]
 */

$csvPath = $argv[1] ?? (__DIR__ . '/../report1780499256737.csv');
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV não encontrado: $csvPath\n");
    exit(1);
}

$norm = static function ($v) {
    $v = trim((string)$v);
    $v = preg_replace('/^\xEF\xBB\xBF/', '', $v); // BOM
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return trim($v, "\"' \t\n\r\0\x0B");
};

$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle, 0, ';');
$map = [];
foreach ((array)$header as $i => $col) {
    $map[$norm($col)] = $i;
}
$find = static function (array $map, array $variants) {
    foreach ($variants as $v) {
        if (array_key_exists($v, $map)) return $map[$v];
    }
    return null;
};
$idxAccount  = $find($map, ['Account Name', 'Nome da Conta', 'Conta']);
$idxType     = $find($map, ['Type', 'Account Type', 'Tipo']);
$idxParent   = $find($map, ['Parent Account', 'Parent', 'Conta Principal', 'Conta-Mãe', 'Conta Mae']);
$idxActivity = $find($map, ['Last Activity']);
$idxModified = $find($map, ['Last Modified Date', 'Last Modified', 'Última Modificação']);

if ($idxAccount === null) {
    fwrite(STDERR, "Coluna 'Account Name' não encontrada.\n");
    exit(1);
}

$pdo = new PDO('mysql:host=localhost;dbname=stocks_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("
    CREATE TABLE IF NOT EXISTS clientes (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        account_name       VARCHAR(255) NOT NULL,
        type               VARCHAR(100) NULL,
        parent_account     VARCHAR(255) NULL,
        last_activity      VARCHAR(50)  NULL,
        last_modified_date VARCHAR(50)  NULL,
        KEY idx_account (account_name),
        KEY idx_parent (parent_account)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("TRUNCATE TABLE clientes");

$stmt = $pdo->prepare("
    INSERT INTO clientes (account_name, type, parent_account, last_activity, last_modified_date)
    VALUES (?, ?, ?, ?, ?)
");

$n = 0;
$pdo->beginTransaction();
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $account = $norm($row[$idxAccount] ?? '');
    if ($account === '') continue;
    $stmt->execute([
        $account,
        ($idxType     !== null ? $norm($row[$idxType] ?? '') : '') ?: null,
        ($idxParent   !== null ? $norm($row[$idxParent] ?? '') : '') ?: null,
        ($idxActivity !== null ? $norm($row[$idxActivity] ?? '') : '') ?: null,
        ($idxModified !== null ? $norm($row[$idxModified] ?? '') : '') ?: null,
    ]);
    $n++;
}
$pdo->commit();
fclose($handle);

echo "Importados $n clientes para a tabela `clientes`.\n";
