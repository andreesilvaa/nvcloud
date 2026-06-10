<?php
/**
 * Importa o relatório de clientes em .xlsx (com coluna Type) para a tabela `clientes`.
 * Como o PHP não tem a extensão zip, o .xlsx tem de estar extraído (é um zip).
 * Indica a pasta extraída em XLSX_DIR.
 *
 *   XLSX_DIR=/caminho/extraido php github/importar_clientes_xlsx.php
 *
 * Colunas esperadas (por ordem):
 *   A=Last Activity  B=Account Name  C=Type  D=Last Modified Date  E=Parent Account
 */

$base   = getenv('XLSX_DIR') ?: 'C:/tmp/clx';
$shared = $base . '/xl/sharedStrings.xml';
$sheet  = $base . '/xl/worksheets/sheet1.xml';
if (!is_file($shared) || !is_file($sheet)) {
    fwrite(STDERR, "XML não encontrado em $base\n");
    exit(1);
}

// sharedStrings
$ss = [];
$dom = new DOMDocument();
$dom->load($shared);
foreach ($dom->getElementsByTagName('si') as $si) {
    $t = '';
    foreach ($si->getElementsByTagName('t') as $tn) {
        $t .= $tn->nodeValue;
    }
    $ss[] = $t;
}

// folha
$dom2 = new DOMDocument();
$dom2->load($sheet);
$linhas = [];
foreach ($dom2->getElementsByTagName('row') as $row) {
    $cells = ['A'=>'','B'=>'','C'=>'','D'=>'','E'=>''];
    $rowNum = 0;
    foreach ($row->getElementsByTagName('c') as $c) {
        $ref = $c->getAttribute('r');
        $col = preg_replace('/[0-9]+/', '', $ref);
        if ($rowNum === 0) {
            $rowNum = (int)preg_replace('/\D/', '', $ref);
        }
        if (!array_key_exists($col, $cells)) {
            continue;
        }
        $tp = $c->getAttribute('t');
        $vNode = $c->getElementsByTagName('v')->item(0);
        $val = $vNode ? $vNode->nodeValue : '';
        if ($tp === 's') {
            $val = $ss[(int)$val] ?? '';
        } elseif ($tp === 'inlineStr') {
            $isn = $c->getElementsByTagName('t')->item(0);
            $val = $isn ? $isn->nodeValue : '';
        }
        $cells[$col] = trim($val);
    }
    if ($rowNum <= 1 || $cells['B'] === '') {
        continue; // cabeçalho ou sem conta
    }
    $linhas[] = $cells;
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
foreach ($linhas as $l) {
    $stmt->execute([
        $l['B'],
        $l['C'] ?: null,
        $l['E'] ?: null,
        $l['A'] ?: null,
        $l['D'] ?: null,
    ]);
    $n++;
}
$pdo->commit();

echo "Importados $n clientes (com Type) para a tabela `clientes`.\n";
