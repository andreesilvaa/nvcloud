<?php
// Ficheiro removido por segurança
header('HTTP/1.1 404 Not Found');
exit;

require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// 1. Categorias
$cats = $pdo->query("SELECT nome FROM categorias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);

// 2. Produtos por categoria
$prods = $pdo->query("
    SELECT c.nome AS cat, p.nome AS prod
    FROM produtos p JOIN categorias c ON c.id = p.categoria_id
    ORDER BY c.nome, p.nome
")->fetchAll();

// 3. Tabela de prefixos
$prefTable = false;
$prefixos  = [];
try {
    $prefixos  = $pdo->query("SELECT prefixo, categoria, produto FROM produto_sn_prefixos ORDER BY categoria, prefixo")->fetchAll();
    $prefTable = true;
} catch (Exception $e) {
    $prefTable = false;
}

// 4. Categorias que NÃO devem existir
$obsoletas = ['Botões WiFi','Transformador','UPS','Vídeo Extender'];
$existeObsoleta = $pdo->query(
    "SELECT nome FROM categorias WHERE nome IN ('Botões WiFi','Transformador','UPS','Vídeo Extender')"
)->fetchAll(PDO::FETCH_COLUMN);

?><!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Verificação do Catálogo</title>
<style>
body{font-family:system-ui,sans-serif;max-width:1100px;margin:30px auto;padding:0 20px;background:#f5f6fa}
h1{color:#1a1d23}h2{color:#374151;margin-top:30px}
.ok {color:#059669;font-weight:700} .err{color:#dc2626;font-weight:700} .warn{color:#d97706;font-weight:700}
table{border-collapse:collapse;width:100%;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06)}
th{background:#f3f4f6;padding:9px 12px;text-align:left;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}
td{padding:9px 12px;border-bottom:1px solid #f0f0f0;font-size:.9rem}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;background:#eef2ff;color:#3730a3;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:700}
.card{background:#fff;border-radius:12px;padding:20px 24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
ul{margin:6px 0;padding-left:20px} li{margin:3px 0}
</style>
</head>
<body>
<h1>🔍 Verificação do Catálogo — nvcloud</h1>

<!-- CATEGORIAS OBSOLETAS -->
<div class="card">
<h2>Categorias obsoletas (devem estar eliminadas)</h2>
<?php if (empty($existeObsoleta)): ?>
    <p class="ok">✓ Nenhuma categoria obsoleta encontrada. Tudo limpo.</p>
<?php else: ?>
    <p class="err">✗ As seguintes categorias ainda existem e deviam ter sido eliminadas:</p>
    <ul><?php foreach ($existeObsoleta as $o): ?><li class="err"><?= htmlspecialchars($o) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
</div>

<!-- CATEGORIAS ACTIVAS -->
<div class="card">
<h2>Categorias activas (<?= count($cats) ?>)</h2>
<?php
$esperadas = ['Acetato','Botões','Box Android','Cabeçote Prima','Cabeçote Proxima','Cabeçote Vision',
              'Carta Controladora','Cofre','Dispensadora Prima','Fonte de Alimentação','Impressora',
              'Leitor de Cartões','Mini PC','Moedeiro','Monitor','Noteiro','PC Windows','Pinpad','Router','Selador 220V'];
$faltam = array_diff($esperadas, $cats);
$extra  = array_diff($cats, $esperadas);
?>
<?php if (empty($faltam) && empty($extra)): ?>
    <p class="ok">✓ As 20 categorias estão correctas.</p>
<?php else: ?>
    <?php if (!empty($faltam)): ?>
        <p class="err">✗ Faltam: <?= implode(', ', array_map('htmlspecialchars', $faltam)) ?></p>
    <?php endif; ?>
    <?php if (!empty($extra)): ?>
        <p class="warn">⚠ Categorias extra (não previstas na lista): <?= implode(', ', array_map('htmlspecialchars', $extra)) ?></p>
    <?php endif; ?>
<?php endif; ?>
<ul style="column-count:3;gap:20px">
<?php foreach ($cats as $c): ?>
    <li><?= htmlspecialchars($c) ?></li>
<?php endforeach; ?>
</ul>
</div>

<!-- PRODUTOS POR CATEGORIA -->
<div class="card">
<h2>Produtos por categoria (<?= count($prods) ?> total)</h2>
<?php
$agrupados = [];
foreach ($prods as $p) $agrupados[$p['cat']][] = $p['prod'];

// Verificações críticas
$checks = [
    'Botões'           => ['eGo','Botões WiFi'],
    'Box Android'      => ['ETE3399','KP8-YB1','H068','D039'],
    'Cabeçote Proxima' => ['Proxima','Proxima CGD','Proxima Unilabs','Proxima EPAL','Proxima Windows','Proxima TML'],
    'Impressora'       => ['Nippon K3053','Echarge 80mm','Prima 12','Prima 15','Prima Teclas'],
    'Mini PC'          => ['D039','N105'],
];
$erros = [];
foreach ($checks as $cat => $prodEsperados) {
    $existentes = $agrupados[$cat] ?? [];
    foreach ($prodEsperados as $pe) {
        if (!in_array($pe, $existentes)) $erros[] = "\"$pe\" em \"$cat\"";
    }
}
if (empty($erros)) {
    echo '<p class="ok">✓ Todos os produtos críticos verificados.</p>';
} else {
    echo '<p class="err">✗ Produtos em falta: ' . implode(', ', array_map('htmlspecialchars', $erros)) . '</p>';
}
?>
<table>
<thead><tr><th>Categoria</th><th>Produtos</th></tr></thead>
<tbody>
<?php foreach ($agrupados as $cat => $lista): ?>
<tr>
    <td><strong><?= htmlspecialchars($cat) ?></strong></td>
    <td><?php foreach ($lista as $p): ?><span class="badge"><?= htmlspecialchars($p) ?></span> <?php endforeach; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- TABELA DE PREFIXOS -->
<div class="card">
<h2>Tabela produto_sn_prefixos</h2>
<?php if (!$prefTable): ?>
    <p class="err">✗ Tabela <code>produto_sn_prefixos</code> não existe. Executa primeiro o <code>nvcloud_catalogo.sql</code> no phpMyAdmin.</p>
<?php elseif (empty($prefixos)): ?>
    <p class="err">✗ A tabela existe mas está vazia.</p>
<?php else: ?>
    <p class="ok">✓ Tabela existe com <?= count($prefixos) ?> prefixos.</p>
    <table>
    <thead><tr><th>Prefixo</th><th>Categoria</th><th>Produto</th></tr></thead>
    <tbody>
    <?php foreach ($prefixos as $p): ?>
    <tr>
        <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($p['prefixo']) ?></td>
        <td><?= htmlspecialchars($p['categoria']) ?></td>
        <td><?= $p['produto'] !== '' ? htmlspecialchars($p['produto']) : '<span style="color:#9ca3af">— (só categoria)</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
<?php endif; ?>
</div>

<p style="color:#9ca3af;font-size:.8rem;margin-top:30px">⚠ Apaga este ficheiro depois de verificar: <code>C:\laragon\www\nvcloud\verificar_catalogo.php</code></p>
</body>
</html>
