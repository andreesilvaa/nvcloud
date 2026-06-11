<?php
/**
 * Migração (uso único) da app antiga (http://nvcloud/stocks/admin) para a stocks_db.
 * Lê o cookie de sessão de /tmp/nvck.txt (curl cookie jar), faz scraping das
 * páginas e insere em pecas / utilizadores / historico.
 *
 *   php github/migrar_stocks.php pc    (peças + contas)
 *   php github/migrar_stocks.php aud   (auditoria)
 */

$fase = $argv[1] ?? 'pc';
$BASE = 'http://nvcloud/stocks/admin/index.php?page=';
$TEMP_PASS = 'nv2026';                 // password temporária das contas novas
$cookieFile = $argv[2] ?? 'C:/tmp/nvck.txt';

// ---- cookie de sessão ----
$cookie = '';
foreach (file($cookieFile) as $line) {
    $p = preg_split('/\s+/', trim($line));
    if (count($p) >= 7 && $p[5] === 'PHPSESSID') { $cookie = 'PHPSESSID=' . $p[6]; }
}
if ($cookie === '') { fwrite(STDERR, "Cookie PHPSESSID não encontrado em $cookieFile\n"); exit(1); }

function fetchHtml(string $url, string $cookie): string {
    $ctx = stream_context_create(['http' => [
        'header'  => "Cookie: $cookie\r\nUser-Agent: migrador\r\n",
        'timeout' => 25,
    ]]);
    return (string)@file_get_contents($url, false, $ctx);
}

function parseRows(string $html): array {
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="UTF-8">' . $html);
    $out = [];
    foreach ($d->getElementsByTagName('tr') as $tr) {
        $tds = $tr->getElementsByTagName('td');
        if ($tds->length === 0) continue;
        $c = [];
        foreach ($tds as $td) { $c[] = trim(preg_replace('/\s+/u', ' ', $td->textContent)); }
        $out[] = $c;
    }
    return $out;
}

$pdo = new PDO('mysql:host=localhost;dbname=stocks_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

if ($fase === 'pc') {
    // ===== PEÇAS (8 páginas) =====
    $insPeca = $pdo->prepare("INSERT IGNORE INTO pecas (categoria, produto, sn, cod_barras, parceiro, estado) VALUES (?,?,?,?,?,?)");
    $totPeca = 0; $pag = 0;
    for ($p = 1; $p <= 50; $p++) {
        $html = fetchHtml($BASE . 'pecas&pagina=' . $p, $cookie);
        $rows = parseRows($html);
        if (!$rows) break;                       // sem mais páginas
        // Sem NULL: a tabela original não tem nulos. "N/A"/vazio -> '' (célula em branco, sem avisos).
        $limpa = fn($v) => ($v !== '' && strtoupper($v) !== 'N/A') ? $v : '';
        foreach ($rows as $c) {
            if (count($c) < 9) continue;
            $insPeca->execute([
                $limpa($c[1]), $limpa($c[2]), $limpa($c[4]),
                $limpa($c[5]), $limpa($c[7]), $limpa($c[8]),
            ]);
            $totPeca += $insPeca->rowCount();
        }
        $pag = $p;
    }
    echo "Peças: $totPeca inseridas (de $pag páginas).\n";

    // ===== CONTAS =====
    $hash = password_hash($TEMP_PASS, PASSWORD_DEFAULT);
    $existe = $pdo->prepare("SELECT COUNT(*) FROM utilizadores WHERE email = ?");
    $insConta = $pdo->prepare("INSERT INTO utilizadores (nome, email, password, fotografia, role, created_at) VALUES (?,?,?,?, 'user', ?)");
    $html = fetchHtml($BASE . 'contas', $cookie);
    $rows = parseRows($html);
    $novas = 0; $saltadas = 0;
    foreach ($rows as $c) {
        if (count($c) < 3) continue;
        $nome = $c[0]; $email = $c[1]; $criado = $c[2] ?: null;
        if ($email === '' || strpos($email, '@') === false) continue;
        $existe->execute([$email]);
        if ((int)$existe->fetchColumn() > 0) { $saltadas++; continue; }
        $insConta->execute([$nome, $email, $hash, null, $criado]);
        $novas++;
    }
    echo "Contas: $novas novas (password temporária '$TEMP_PASS'), $saltadas já existentes.\n";
}

if ($fase === 'aud') {
    // ===== AUDITORIA (muitas páginas) -> historico =====
    $ins = $pdo->prepare("INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao) VALUES (0, ?, NULL, ?, ?, ?)");
    $tot = 0; $pag = 0;
    for ($p = 1; $p <= 400; $p++) {
        $html = fetchHtml($BASE . 'auditoria&pagina=' . $p, $cookie);
        $rows = parseRows($html);
        if (!$rows) break;
        foreach ($rows as $c) {
            if (count($c) < 5) continue;
            $acao = $c[2]; $detalhes = $c[3]; $utilizador = $c[1];
            $data = $c[4] ?: null;
            $ins->execute([$acao ?: null, $detalhes ?: null, $utilizador ?: null, $data]);
            $tot++;
        }
        $pag = $p;
        if ($p % 25 === 0) { fwrite(STDERR, "  ...auditoria página $p ($tot registos)\n"); }
    }
    echo "Auditoria: $tot registos inseridos (de $pag páginas).\n";
}
