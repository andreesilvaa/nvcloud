<?php
require __DIR__ . '/../includes/auth.php';
if (($_SESSION['user_role'] ?? '') !== 'admin') {
	http_response_code(403);
	exit('Acesso negado.');
}
/**
 * Importador (uso único, via CLI) do relatório de contactos de clientes
 * (Report-*.xlsx → tabela `clientes_contactos`).
 *
 * Como o PHP não tem a extensão zip, o .xlsx tem de estar previamente
 * extraído (é um zip). Ajusta $base para a pasta extraída.
 *
 *   php github/importar_contactos.php
 */

$base = getenv('XLSX_DIR') ?: 'C:/tmp/xlsxread';
$shared = $base . '/xl/sharedStrings.xml';
$sheet  = $base . '/xl/worksheets/sheet1.xml';

if (!is_file($shared) || !is_file($sheet)) {
    fwrite(STDERR, "Ficheiros XML não encontrados em $base\n");
    exit(1);
}

// 1) sharedStrings
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

// 2) folha → linhas
$dom2 = new DOMDocument();
$dom2->load($sheet);
$linhas = [];
foreach ($dom2->getElementsByTagName('row') as $row) {
    $cells = ['A'=>'','B'=>'','C'=>'','D'=>'','E'=>'','F'=>'','G'=>'','H'=>''];
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
        $t = $c->getAttribute('t');
        $vNode = $c->getElementsByTagName('v')->item(0);
        $val = $vNode ? $vNode->nodeValue : '';
        if ($t === 's') {
            $val = $ss[(int)$val] ?? '';
        } elseif ($t === 'inlineStr') {
            $isn = $c->getElementsByTagName('t')->item(0);
            $val = $isn ? $isn->nodeValue : '';
        }
        $cells[$col] = trim($val);
    }
    if ($rowNum <= 1) {
        continue; // saltar cabeçalho
    }
    if ($cells['A'] === '') {
        continue; // sem nome de conta
    }
    $linhas[] = $cells;
}

// 3) BD
$pdo = new PDO('mysql:host=localhost;dbname=stocks_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS clientes_contactos (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        account_name   VARCHAR(255) NOT NULL,
        mailing_street VARCHAR(255) NULL,
        mailing_city   VARCHAR(150) NULL,
        mailing_zip    VARCHAR(50)  NULL,
        mailing_country VARCHAR(100) NULL,
        phone          VARCHAR(80)  NULL,
        mobile         VARCHAR(80)  NULL,
        email          VARCHAR(150) NULL,
        KEY idx_account (account_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("TRUNCATE TABLE clientes_contactos");

$stmt = $pdo->prepare("
    INSERT INTO clientes_contactos
      (account_name, mailing_street, mailing_city, mailing_zip, mailing_country, phone, mobile, email)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$n = 0;
$pdo->beginTransaction();
foreach ($linhas as $l) {
    $stmt->execute([
        $l['A'],
        $l['B'] ?: null,
        $l['C'] ?: null,
        $l['D'] ?: null,
        $l['E'] ?: null,
        $l['F'] ?: null,
        $l['G'] ?: null,
        $l['H'] ?: null,
    ]);
    $n++;
}
$pdo->commit();

echo "Importados $n contactos para clientes_contactos.\n";
