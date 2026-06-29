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

$like    = '%' . $q . '%';
// Pesquisa por palavras individuais (ex: "K1647 ASUS" → busca cada token)
$tokens  = array_filter(array_map('trim', preg_split('/\s+/', $q)));
$results = [];
$seen    = [];

function addResult(array &$results, array &$seen, string $key, array $item): void {
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $results[]  = $item;
    }
}

// ── PATs ──────────────────────────────────────────────────────────────────
// 1. Match direto (LIKE)
$stmt = $pdo->prepare("
    SELECT id, numero_pat, revisao, entidade
    FROM pats
    WHERE numero_pat LIKE ? OR entidade LIKE ? OR revisao LIKE ?
    ORDER BY id DESC LIMIT 8
");
$stmt->execute([$like, $like, $like]);
foreach ($stmt->fetchAll() as $r) {
    addResult($results, $seen, 'pat:'.$r['id'], [
        'icon'  => 'bi-headset',
        'label' => $r['numero_pat'].'/'.$r['revisao'].' — '.($r['entidade'] ?? ''),
        'type'  => 'PAT',
        'url'   => 'app.php?page=pats&ver=' . (int)$r['id'],
    ]);
}
// 2. Tokens separados
if (count($tokens) > 1) {
    foreach ($tokens as $tok) {
        $tlike = '%'.$tok.'%';
        $stmt = $pdo->prepare("SELECT id, numero_pat, revisao, entidade FROM pats WHERE numero_pat LIKE ? OR entidade LIKE ? LIMIT 4");
        $stmt->execute([$tlike, $tlike]);
        foreach ($stmt->fetchAll() as $r) {
            addResult($results, $seen, 'pat:'.$r['id'], [
                'icon'  => 'bi-headset',
                'label' => $r['numero_pat'].'/'.$r['revisao'].' — '.($r['entidade'] ?? ''),
                'type'  => 'PAT',
                'url'   => 'app.php?page=pats&ver=' . (int)$r['id'],
            ]);
        }
    }
}

// ── Peças ─────────────────────────────────────────────────────────────────
// 1. Match direto
$stmt = $pdo->prepare("
    SELECT id, produto, sn, estado
    FROM pecas
    WHERE sn LIKE ? OR produto LIKE ? OR cod_barras LIKE ?
    ORDER BY id DESC LIMIT 8
");
$stmt->execute([$like, $like, $like]);
foreach ($stmt->fetchAll() as $r) {
    addResult($results, $seen, 'peca:'.$r['id'], [
        'icon'  => 'bi-box-seam',
        'label' => ($r['produto'] ?? '') . ($r['sn'] ? ' · SN: '.$r['sn'] : '') . ' · ' . ($r['estado'] ?? ''),
        'type'  => 'Peça',
        'url'   => 'app.php?page=nova_peca&edit=' . (int)$r['id'],
    ]);
}
// 2. Tokens separados
if (count($tokens) > 1) {
    foreach ($tokens as $tok) {
        $tlike = '%'.$tok.'%';
        $stmt = $pdo->prepare("SELECT id, produto, sn, estado FROM pecas WHERE sn LIKE ? OR produto LIKE ? LIMIT 4");
        $stmt->execute([$tlike, $tlike]);
        foreach ($stmt->fetchAll() as $r) {
            addResult($results, $seen, 'peca:'.$r['id'], [
                'icon'  => 'bi-box-seam',
                'label' => ($r['produto'] ?? '') . ($r['sn'] ? ' · SN: '.$r['sn'] : '') . ' · ' . ($r['estado'] ?? ''),
                'type'  => 'Peça',
                'url'   => 'app.php?page=nova_peca&edit=' . (int)$r['id'],
            ]);
        }
    }
}
// 3. SOUNDEX (resultados semelhantes fonéticos — só para strings curtas sem espaços)
if (count($tokens) === 1 && strlen($q) >= 3 && count($results) < 6) {
    $stmt = $pdo->prepare("
        SELECT id, produto, sn, estado
        FROM pecas
        WHERE SOUNDEX(sn) = SOUNDEX(?) OR SOUNDEX(produto) = SOUNDEX(?)
        ORDER BY id DESC LIMIT 4
    ");
    $stmt->execute([$q, $q]);
    foreach ($stmt->fetchAll() as $r) {
        addResult($results, $seen, 'peca:'.$r['id'], [
            'icon'  => 'bi-box-seam',
            'label' => ($r['produto'] ?? '') . ($r['sn'] ? ' · SN: '.$r['sn'] : '') . ' · ' . ($r['estado'] ?? '') . ' (semelhante)',
            'type'  => 'Peça',
            'url'   => 'app.php?page=nova_peca&edit=' . (int)$r['id'],
        ]);
    }
}

// ── Parceiros ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, empresa FROM parceiros WHERE empresa LIKE ? LIMIT 5");
$stmt->execute([$like]);
foreach ($stmt->fetchAll() as $r) {
    addResult($results, $seen, 'parceiro:'.$r['id'], [
        'icon'  => 'bi-building',
        'label' => $r['empresa'],
        'type'  => 'Parceiro',
        'url'   => 'app.php?page=parceiros&ver=' . (int)$r['id'],
    ]);
}
// SOUNDEX parceiros
if (count($results) < 6) {
    $stmt = $pdo->prepare("SELECT id, empresa FROM parceiros WHERE SOUNDEX(empresa) = SOUNDEX(?) LIMIT 3");
    $stmt->execute([$q]);
    foreach ($stmt->fetchAll() as $r) {
        addResult($results, $seen, 'parceiro:'.$r['id'], [
            'icon'  => 'bi-building',
            'label' => $r['empresa'] . ' (semelhante)',
            'type'  => 'Parceiro',
            'url'   => 'app.php?page=parceiros&ver=' . (int)$r['id'],
        ]);
    }
}

// ── Clientes ──────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id, nome FROM clientes WHERE nome LIKE ? LIMIT 4");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll() as $r) {
        addResult($results, $seen, 'cliente:'.$r['id'], [
            'icon'  => 'bi-person',
            'label' => $r['nome'],
            'type'  => 'Cliente',
            'url'   => 'app.php?page=clientes',
        ]);
    }
} catch (Throwable $e) { /* tabela ausente */ }

// Limitar total a 15 resultados
$results = array_slice($results, 0, 15);

echo json_encode(array_values($results));
