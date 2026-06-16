<?php
// Ficheiro removido por segurança
header('HTTP/1.1 404 Not Found');
exit;

require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$log = [];
$erros = [];

function run(PDO $pdo, string $sql, string $desc, array &$log, array &$erros): void {
    try {
        $affected = $pdo->exec($sql);
        $log[] = ['ok', $desc . ($affected !== false ? " ($affected linhas)" : '')];
    } catch (PDOException $e) {
        $erros[] = $desc . ': ' . $e->getMessage();
        $log[] = ['err', $desc . ' → ERRO: ' . $e->getMessage()];
    }
}

$pdo->exec("SET foreign_key_checks = 0");

// ── 1. Deduplicar categorias ──────────────────────────────────
run($pdo, "
    UPDATE produtos p
    JOIN categorias c ON c.id = p.categoria_id
    JOIN (SELECT nome, MIN(id) AS keep_id FROM categorias GROUP BY nome) t ON t.nome = c.nome
    SET p.categoria_id = t.keep_id
", 'Reaponta produtos para a categoria canónica', $log, $erros);

run($pdo, "
    DELETE FROM categorias WHERE id NOT IN (
        SELECT keep_id FROM (SELECT MIN(id) AS keep_id FROM categorias GROUP BY nome) t
    )
", 'Remove categorias duplicadas', $log, $erros);

// ── 2. Adicionar UNIQUE KEY (ignora se já existir) ────────────
try {
    $pdo->exec("ALTER TABLE categorias ADD CONSTRAINT uk_cat_nome UNIQUE (nome)");
    $log[] = ['ok', 'Chave UNIQUE adicionada a categorias.nome'];
} catch (PDOException $e) {
    $log[] = ['warn', 'UNIQUE em categorias.nome já existe (ok)'];
}
try {
    $pdo->exec("ALTER TABLE produtos ADD CONSTRAINT uk_prod_nome_cat UNIQUE (nome, categoria_id)");
    $log[] = ['ok', 'Chave UNIQUE adicionada a produtos(nome, categoria_id)'];
} catch (PDOException $e) {
    $log[] = ['warn', 'UNIQUE em produtos já existe (ok)'];
}

// ── 3. Remover categorias obsoletas + extra ───────────────────
$obsoletas = ['Botões WiFi','Transformador','UPS','Vídeo Extender',
              'Cabecote Proxima','Controladora','Conversor','Cutter',
              'Peças Metálicas','Touchsscreen','Cabo'];
$in = implode(',', array_fill(0, count($obsoletas), '?'));
$stmt = $pdo->prepare("DELETE FROM produtos WHERE categoria_id IN (SELECT id FROM categorias WHERE nome IN ($in))");
$stmt->execute($obsoletas);
$log[] = ['ok', "Remove produtos das categorias obsoletas ({$stmt->rowCount()} linhas)"];

$stmt2 = $pdo->prepare("DELETE FROM categorias WHERE nome IN ($in)");
$stmt2->execute($obsoletas);
$log[] = ['ok', "Remove categorias obsoletas ({$stmt2->rowCount()} linhas)"];

// ── 4. Garantir as 20 categorias correctas ────────────────────
$cats = ['Acetato','Botões','Box Android','Cabeçote Prima','Cabeçote Proxima',
         'Cabeçote Vision','Carta Controladora','Cofre','Dispensadora Prima',
         'Fonte de Alimentação','Impressora','Leitor de Cartões','Mini PC',
         'Moedeiro','Monitor','Noteiro','PC Windows','Pinpad','Router','Selador 220V'];
$ins = $pdo->prepare("INSERT IGNORE INTO categorias (nome) VALUES (?)");
$catIns = 0;
foreach ($cats as $c) { $ins->execute([$c]); $catIns += $ins->rowCount(); }
$log[] = ['ok', "Categorias garantidas ($catIns novas inseridas)"];

// ── 5. Limpar e re-inserir produtos ──────────────────────────
run($pdo, "DELETE FROM produtos", 'Limpa todos os produtos', $log, $erros);

$produtos = [
    'Acetato'           => ['Prima 12 (26 UNI)'],
    'Botões'            => ['eGo','Botões WiFi'],
    'Box Android'       => ['ETE3399','KP8-YB1','H068','D039'],
    'Cabeçote Prima'    => ['Prima 12','Prima 15'],
    'Cabeçote Proxima'  => ['Proxima','Proxima CGD','Proxima Unilabs','Proxima EPAL','Proxima Windows','Proxima TML'],
    'Cabeçote Vision'   => ['Vision WiFi','Vision Ethernet'],
    'Carta Controladora'=> ['Controladora Genérica'],
    'Cofre'             => ['Echarge','WBA'],
    'Dispensadora Prima'=> ['Prima Teclas Vodafone'],
    'Fonte de Alimentação'=>['Fonte/UPS','Fonte Proxima','Fonte 24V Prateada'],
    'Impressora'        => ['Nippon K3053','Echarge 80mm','Prima 12','Prima 15','Prima Teclas'],
    'Leitor de Cartões' => ['Leitor U900','Leitor SPU90','Leitor Spire'],
    'Mini PC'           => ['D039','N105'],
    'Moedeiro'          => ['Smart Hopper Recycler','Smart Hopper Validator'],
    'Monitor'           => ['Seleniko Touch','General Touch 17"','KEE Touch 17"','KEE Touch 19"',
                            'LCD LD 32"','LCD Hisense 40"','Hisense 40"','Hisense 43"',
                            'Hisense TV 50"','LED 55" Profissional','MSM Box','RVM 10"'],
    'Noteiro'           => ['UBA','Echarge'],
    'PC Windows'        => ['Insys KP1-AB5','Giada F108D','Hard PC','IP4-NB20','IP7-T09',
                            'Prima Asus 410','Prima Asus 610','Prima Intel DG4'],
    'Pinpad'            => ['U900','Spire','Ingénico'],
    'Router'            => ['D-Link Eagle N300','TP-Link 4G'],
    'Selador 220V'      => ['Depositvision','220V'],
];

$stmtCatId = $pdo->prepare("SELECT id FROM categorias WHERE nome = ? LIMIT 1");
$stmtProd  = $pdo->prepare("INSERT IGNORE INTO produtos (nome, categoria_id) VALUES (?, ?)");
$totalProd = 0;
foreach ($produtos as $catNome => $prods) {
    $stmtCatId->execute([$catNome]);
    $catId = $stmtCatId->fetchColumn();
    if (!$catId) { $erros[] = "Categoria não encontrada: $catNome"; continue; }
    foreach ($prods as $prod) {
        $stmtProd->execute([$prod, $catId]);
        $totalProd += $stmtProd->rowCount();
    }
}
$log[] = ['ok', "Produtos inseridos: $totalProd"];

// ── 6. Tabela de prefixos SN ──────────────────────────────────
run($pdo, "
    CREATE TABLE IF NOT EXISTS produto_sn_prefixos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        prefixo   VARCHAR(30)  NOT NULL,
        categoria VARCHAR(100) NOT NULL DEFAULT '',
        produto   VARCHAR(100) NOT NULL DEFAULT '',
        UNIQUE KEY uk_prefixo (prefixo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'Cria tabela produto_sn_prefixos', $log, $erros);

run($pdo, "TRUNCATE TABLE produto_sn_prefixos", 'Limpa prefixos anteriores', $log, $erros);

$prefixos = [
    ['EBUTEG',    'Botões',               'eGo'],
    ['EBUTWL',    'Botões',               'Botões WiFi'],
    ['SDETE3399', 'Box Android',          'ETE3399'],
    ['WBETE3399', 'Box Android',          'ETE3399'],
    ['KP8YB1',    'Box Android',          'KP8-YB1'],
    ['ISH068',    'Box Android',          'H068'],
    ['INLPXM',    'Cabeçote Proxima',     ''],
    ['INLVSN',    'Cabeçote Vision',      ''],
    ['CONGEN',    'Carta Controladora',   'Controladora Genérica'],
    ['INLPRM',    'Dispensadora Prima',   'Prima Teclas Vodafone'],
    ['MODALI',    'Fonte de Alimentação', 'Fonte/UPS'],
    ['IMPINL4',   'Impressora',           'Prima 15'],
    ['IMPINL3',   'Impressora',           'Prima 12'],
    ['IMPSV4',    'Impressora',           'Echarge 80mm'],
    ['905A2311',  'Leitor de Cartões',    'Leitor U900'],
    ['905A2337',  'PC Windows',           'Prima Intel DG4'],
    ['905D',      'Leitor de Cartões',    'Leitor Spire'],
    ['905E',      'Leitor de Cartões',    'Leitor Spire'],
    ['U05A',      'Leitor de Cartões',    'Leitor SPU90'],
    ['U0760',     'Leitor de Cartões',    'Leitor SPU90'],
    ['ISN105',    'Mini PC',              'N105'],
    ['HRDPCV',    'PC Windows',           'Hard PC'],
    ['KP1AB5',    'PC Windows',           'Insys KP1-AB5'],
    ['K1647P',    'PC Windows',           'Giada F108D'],
    ['IP4NB2',    'PC Windows',           'IP4-NB20'],
    ['U59B0',     'Pinpad',               'U900'],
    ['U0830',     'Pinpad',               'Spire'],
    ['U0550',     'Pinpad',               'Spire'],
    ['19044U',    'Pinpad',               'Ingénico'],
    ['U89V24',    'Router',               'D-Link Eagle N300'],
    ['22487S',    'Router',               'TP-Link 4G'],
    ['224A5K',    'Router',               'TP-Link 4G'],
    ['DVDS01',    'Selador 220V',         'Depositvision'],
];
$stmtPref = $pdo->prepare("INSERT IGNORE INTO produto_sn_prefixos (prefixo, categoria, produto) VALUES (?,?,?)");
$totalPref = 0;
foreach ($prefixos as $p) { $stmtPref->execute($p); $totalPref += $stmtPref->rowCount(); }
$log[] = ['ok', "Prefixos inseridos: $totalPref / " . count($prefixos)];

$pdo->exec("SET foreign_key_checks = 1");

// ── Resumo final ──────────────────────────────────────────────
$catCount  = $pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn();
$prodCount = $pdo->query("SELECT COUNT(*) FROM produtos")->fetchColumn();
$prefCount = $pdo->query("SELECT COUNT(*) FROM produto_sn_prefixos")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Correcção do Catálogo</title>
<style>
body{font-family:system-ui,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;background:#f5f6fa}
h1{color:#1a1d23}.ok{color:#059669}.err{color:#dc2626}.warn{color:#d97706}
.card{background:#fff;border-radius:12px;padding:20px 24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.step{padding:7px 0;border-bottom:1px solid #f0f0f0;font-size:.9rem;display:flex;gap:10px}
.step:last-child{border-bottom:none}
.counts{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:16px}
.count{background:#f9fafb;border-radius:10px;padding:16px;text-align:center}
.count .n{font-size:2rem;font-weight:800;color:#2d3142}
.count .l{font-size:.8rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
a.btn{display:inline-block;margin-top:20px;background:#c9a14a;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700}
</style>
</head>
<body>
<h1>🔧 Correcção do Catálogo</h1>

<div class="card">
<h2>Passos executados</h2>
<?php foreach ($log as [$type, $msg]): ?>
<div class="step">
<span class="<?= $type ?>"><?= $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗') ?></span>
<span><?= htmlspecialchars($msg) ?></span>
</div>
<?php endforeach; ?>
</div>

<?php if (!empty($erros)): ?>
<div class="card" style="border:2px solid #dc2626">
<h2 class="err">Erros</h2>
<?php foreach ($erros as $e): ?><p class="err"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
<h2><?= empty($erros) ? '<span class="ok">✓ Concluído sem erros</span>' : '<span class="warn">⚠ Concluído com avisos</span>' ?></h2>
<div class="counts">
<div class="count"><div class="n"><?= $catCount ?></div><div class="l">Categorias</div></div>
<div class="count"><div class="n"><?= $prodCount ?></div><div class="l">Produtos</div></div>
<div class="count"><div class="n"><?= $prefCount ?></div><div class="l">Prefixos SN</div></div>
</div>
<a class="btn" href="verificar_catalogo.php">→ Verificar resultado</a>
</div>

<p style="color:#9ca3af;font-size:.8rem;margin-top:20px">
⚠ Apaga estes ficheiros:<br>
<code>C:\laragon\www\nvcloud\executar_fix.php</code><br>
<code>C:\laragon\www\nvcloud\verificar_catalogo.php</code>
</p>
</body>
</html>
