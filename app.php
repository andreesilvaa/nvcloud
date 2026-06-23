<?php
ob_start();

require_once __DIR__ . '/bootstrap.php';

// ============================================================
// 1. SESSÃO E AUTENTICAÇÃO
// ============================================================

session_start();

$session_timeout = 8 * 60 * 60;

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $session_timeout)) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

$_SESSION['LAST_ACTIVITY'] = time();


// Token CSRF para ações sensíveis
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (\Random\RandomException $e) {
        error_log('[nvcloud] Falha ao gerar token CSRF: ' . $e->getMessage());
        http_response_code(500);
        die('Erro de segurança ao iniciar a sessão. Tentar novamente.');
    }

}
$csrfToken = $_SESSION['csrf_token'];

// ============================================================
// 2. FUNÇÕES AUXILIARES GERAIS
// ============================================================


#[\NoReturn]
function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function utilizadorEhAdmin(): bool
{
    return ($_SESSION['user_role'] ?? 'user') === 'admin';
}

#[\NoReturn]
function exigirAdmin(): void
{
    if (!utilizadorEhAdmin()) {
        flashError('Não tens permissão para executar esta ação.');
        redirectTo('app.php?page=dashboard');
    }
}

function flashSuccess(string $message): void
{
    $_SESSION['mensagem_sucesso'] = $message;
}

function flashError(string $message): void
{
    $_SESSION['mensagem_erro'] = $message;
}

function pullSessionArray(string $key): array
{
    $value = $_SESSION[$key] ?? [];
    unset($_SESSION[$key]);

    return is_array($value) ? $value : [];
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Procura morada e contacto na tabela clientes_contactos para uma entidade.
 * Tenta, por ordem: (1) match exato, (2) frase das 2 primeiras palavras,
 * (3) palavra a palavra (a mais longa primeiro). Prefere SEMPRE linhas que
 * tenham morada/telefone reais (muitas linhas da mesma conta estão a NULL).
 * Devolve ['morada'=>string, 'contacto'=>string] (vazios se nada encontrado).
 */
function nvEnriquecerCliente(PDO $pdo, string $entidade): array
{
    $vazio = ['morada' => '', 'contacto' => ''];
    $entidade = trim($entidade);
    if ($entidade === '') return $vazio;

    $cols  = "mailing_street, mailing_city, mailing_zip, mailing_country, phone, mobile, email";
    $ordem = "ORDER BY (mailing_street IS NOT NULL AND mailing_street <> '') DESC,
                       (phone IS NOT NULL AND phone <> '') DESC, id ASC";
    // Escapar wildcards de LIKE (\, %, _) — nomes com '_' davam falsos positivos.
    $esc = fn(string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);

    $stmtLike = $pdo->prepare("SELECT $cols FROM clientes_contactos WHERE account_name LIKE ? $ordem LIMIT 1");
    $cli = null;

    // 1. Match exato
    $st = $pdo->prepare("SELECT $cols FROM clientes_contactos WHERE account_name = ? $ordem LIMIT 1");
    $st->execute([$entidade]);
    $cli = $st->fetch() ?: null;

    // 2. Frase das 2 primeiras palavras (ex.: "EDP Comercial")
    if (!$cli) {
        $palavras = preg_split('/\s+/u', $entidade, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $frase = implode(' ', array_slice($palavras, 0, 2));
        if ($frase !== '') {
            $stmtLike->execute(['%' . $esc($frase) . '%']);
            $cli = $stmtLike->fetch() ?: null;
        }
    }

    // 3. Palavra a palavra (significativas: >= 4 letras, a mais longa primeiro)
    if (!$cli) {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $entidade, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, fn($w) => mb_strlen($w) >= 4));
        usort($tokens, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        foreach ($tokens as $w) {
            $stmtLike->execute(['%' . $esc($w) . '%']);
            $r = $stmtLike->fetch();
            if ($r) { $cli = $r; break; }
        }
    }

    if (!$cli) return $vazio;

    $morada = implode(', ', array_filter([
        $cli['mailing_street']  ?? '',
        $cli['mailing_city']    ?? '',
        $cli['mailing_zip']     ?? '',
        $cli['mailing_country'] ?? '',
    ]));
    $contacto = ($cli['phone'] ?? '') ?: ($cli['mobile'] ?? '') ?: ($cli['email'] ?? '') ?: '';

    return ['morada' => $morada, 'contacto' => $contacto];
}


// ============================================================
// 3. BASE DE DADOS
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/clientes.php';
require_once __DIR__ . '/includes/relatorios_parser.php';
require_once __DIR__ . '/includes/relatorios_reconciliar.php';
require_once __DIR__ . '/includes/relatorios_aplicar.php';
require_once __DIR__ . '/includes/fluxo_pecas.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Exception $e) {
    die('Erro de ligação à base de dados: ' . $e->getMessage());
}

// ============================================================
// 4. ESTADO DA PÁGINA / ROUTING SIMPLES
// ============================================================

$page = $_GET['page'] ?? 'dashboard';
// "Obriga" suave: 1.ª visita do mês leva os utilizadores de escritório à revisão.
$areasObrigadas = ['Escritorio','TI'];
if (in_array($_SESSION['user_area'] ?? '', $areasObrigadas, true)
        && $page !== 'revisao'
        && ($_SESSION['rev_lembrete_mes'] ?? '') !== date('Y-m')
        && empty($_GET['adiar'])) {
    require_once __DIR__ . '/includes/revisoes.php';
    nvGerarRevisoesDoMes($pdo, 30);
    if (nvRevisoesPendentes($pdo) > 0) {
        $_SESSION['rev_lembrete_mes'] = date('Y-m');   // só lembra uma vez por mês
        header('Location: app.php?page=revisao&from=auto');
        exit;
    }
}
$action = $_GET['action'] ?? '';
if (
    (($_GET['action'] ?? '') === 'importar_workorder' || ($_POST['action'] ?? '') === 'importar_workorder')
) {
    $tokenRecebido = $_GET['csrf'] ?? $_POST['csrf'] ?? '';
    $viaExtensao = ($_SERVER['HTTP_X_SOURCE'] ?? '') === 'nv-extension';
    if (!$viaExtensao && $tokenRecebido !== ($_SESSION['csrf_token'] ?? '')) {
        $_SESSION['mensagem_erro'] = 'Ação inválida.';
        header('Location: app.php?page=pats');
        exit;
    }
    $source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $numeroWo = trim($source['numero_wo'] ?? '');
    $entidade = trim($source['entidade'] ?? '');
    $localCliente = trim($source['local_cliente'] ?? $entidade);
    $contacto = trim($source['contacto'] ?? '');
    $tecnico = trim($source['tecnico'] ?? '');
    $morada = trim($source['morada'] ?? '');
    $descricao = trim($source['descricao'] ?? '');
    $dataRec = trim($source['data_recepcao'] ?? '');
    $dataLim = trim($source['data_limite'] ?? '');
    $prioridade = in_array($source['prioridade'] ?? '', ['Normal','Urgente'])
            ? $source['prioridade'] : 'Normal';


    // Garantir UTF-8 válido (a extensão lê o DOM do Salesforce e pode trazer
    // bytes mal-formados que, sob STRICT_TRANS_TABLES, rebentariam o INSERT).
    $utf8 = function ($s) {
        $s = (string)$s;
        return ($s === '' || mb_check_encoding($s, 'UTF-8'))
            ? $s
            : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    };
    $numeroWo     = $utf8($numeroWo);
    $entidade     = $utf8($entidade);
    $localCliente = $utf8($localCliente);
    $contacto     = $utf8($contacto);
    $tecnico      = $utf8($tecnico);
    $morada       = $utf8($morada);
    $descricao    = $utf8($descricao);

    if ($numeroWo === '') {
        $_SESSION['mensagem_erro'] = 'Nº Work Order em falta.';
        header('Location: app.php?page=pats');
        exit;
    }

    // Verificar duplicado
    $stmtDup = $pdo->prepare("SELECT id FROM pats WHERE numero_pat = ? LIMIT 1");
    $stmtDup->execute([$numeroWo]);
    $existente = $stmtDup->fetchColumn();
    if ($existente) {
        $_SESSION['mensagem_sucesso'] = 'Este WO já estava importado.';
        header('Location: app.php?page=pats&ver=' . (int)$existente);
        exit;
    }

    // "Contact" é a label do campo no Salesforce (campo vazio), não um contacto.
    if ($contacto === 'Contact') $contacto = '';

    // ── Enriquecer com clientes_contactos (isolado — nunca bloqueia o INSERT) ──
    try {
        if ($entidade !== '' && ($morada === '' || $contacto === '')) {
            $info = nvEnriquecerCliente($pdo, $entidade);
            if ($morada === ''   && $info['morada']   !== '') $morada   = $info['morada'];
            if ($contacto === '' && $info['contacto'] !== '') $contacto = $info['contacto'];
        }
    } catch (Throwable $eCli) {
        // Enriquecimento falhou — continua sem enriquecer, não bloqueia o PAT
        error_log('Erro enriquecimento clientes_contactos: ' . $eCli->getMessage());
    }

    // ── Converter datas ───────────────────────────────────
    $converterData = function(?string $iso): ?string {
        if (!$iso) return null;
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    };

    // ── INSERT do PAT ─────────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO pats
              (numero_pat, revisao, entidade, local_cliente, contacto, tecnico, morada,
               data_recepcao, data_limite, descricao,
               prioridade, estado, criado_por, created_at)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aberto', ?, NOW())
        ")->execute([
                $numeroWo,
                $entidade,
                $localCliente,
                $contacto,
                $tecnico,
                $morada,
                $converterData($dataRec),
                $converterData($dataLim),
                $descricao,
                $prioridade,
                $_SESSION['user_nome'] ?? 'Extensão',
        ]);

        $patId = (int)$pdo->lastInsertId();
        $_SESSION['mensagem_sucesso'] = 'PAT criado — WO ' . htmlspecialchars($numeroWo);
        header('Location: app.php?page=pats&ver=' . $patId);
        exit;

    } catch (Exception $e) {
        $_SESSION['mensagem_erro'] = 'Erro ao criar PAT: ' . $e->getMessage();
        header('Location: app.php?page=pats');
        exit;
    }
}


$vista = $_GET['lista'] ?? '0';
// ============================================================
// 5. DADOS FIXOS DA APLICAÇÃO
// ============================================================

$pageTitles = [
  'dashboard' => 'Dashboard',
  'clientes' => 'Clientes',
  'inventario' => 'Inventário',
  'inventory' => 'Inventário',
  'nova_peca' => 'Nova Peça',
  'historico' => 'Histórico da Peça',
  'pats' => "Pat's",
  'envios' => 'Envios',
  'qrs' => "QR's",
  'qr' => "QR's",
  'relatorios' => 'Relatórios',
  'contas' =>'Contas',
  'auditoria' => 'Auditoria',
  'categorias' => 'Categorias',
  'estados' => 'Estados',
  'parceiros' => 'Parceiros',
  'produtos' => 'Produtos',
  'nvi' => 'N-Vi',
  'configuracoes' => 'Configurações',
  'revisao' => 'Revisão',
  'analises' => 'Análises',
  ];

  $topbarTitle = $pageTitles[$page] ?? ucfirst($page);
  if ($page === 'envios') {
    $topbarTitle = $vista === '1' ? 'Lista de Envios' : 'Novo Envio';
  }

// ------------------------------------------------------------
// Listas de referência — agora vêm das tabelas de gestão (BD),
// geríveis nas páginas Categorias / Estados / Parceiros / Produtos.
// ------------------------------------------------------------
$estados    = $pdo->query("SELECT nome FROM estados ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN);
$parceiros  = $pdo->query("SELECT empresa FROM parceiros ORDER BY empresa ASC")->fetchAll(PDO::FETCH_COLUMN);
$categorias = $pdo->query("SELECT nome FROM categorias ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN);

// Cascata categoria => [produtos], construída a partir da tabela `produtos`.
$catalogoProdutos = [];
foreach ($categorias as $catNome) {
    $catalogoProdutos[$catNome] = [];
}
foreach ($pdo->query(
    "SELECT c.nome AS categoria, p.nome AS produto
       FROM produtos p
       JOIN categorias c ON c.id = p.categoria_id
      ORDER BY c.nome ASC, p.nome ASC"
) as $linhaCat) {
    $catalogoProdutos[$linhaCat['categoria']][] = $linhaCat['produto'];
}

$estadoEnvio = [
  'Rascunho',
  'Ativa',
  'Concluida',
  'Cancelada'
];


function countQuery(\PDO $pdo, string $sql): int {
    return (int)$pdo->query($sql)->fetchColumn();
}

// Notificações
$notificacoes = [];

$stmtUrg = $pdo->query("
    SELECT id, numero_pat, revisao, entidade
    FROM pats
    WHERE prioridade = 'Urgente' AND estado NOT IN ('Resolvido','Concluído','Cancelado')
    ORDER BY created_at DESC LIMIT 10
");
foreach ($stmtUrg->fetchAll() as $p) {
    $notificacoes[] = [
        'tipo' => 'urgente',
        'msg' => 'PAT urgente: ' . htmlspecialchars($p['numero_pat'].'/'.$p['revisao']) . ' — ' . htmlspecialchars($p['entidade']),
        'link' => 'app.php?page=pats&ver=' . (int)$p['id'],
    ];
}

$stmtPrazo = $pdo->query("
    SELECT id, numero_pat, revisao, entidade, data_limite
    FROM pats
    WHERE data_limite IS NOT NULL
      AND data_limite BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
      AND estado NOT IN ('Resolvido','Concluído','Cancelado')
    ORDER BY data_limite ASC LIMIT 10
");
foreach ($stmtPrazo->fetchAll() as $p) {
    $notificacoes[] = [
       'tipo' => 'prazo',
       'msg' => 'Prazo a expirar: ' . htmlspecialchars($p['numero_pat'].'/'.$p['revisao']) . ' — ' . date('d/m/Y', strtotime($p['data_limite'])),
       'link' => 'app.php?page=pats&ver=' . (int)$p['id'],
    ];
}

// PEÇAS SUSPEITAS (estado "em curso" parado há muito tempo)
require_once __DIR__ . '/includes/pecas_suspeitas.php';
$pecasSuspeitas = nvPecasSuspeitas($pdo, ['dias' => 30]);
if (count($pecasSuspeitas) > 0) {
    $notificacoes[] = [
        'tipo' => 'suspeita',
        'msg' => count($pecasSuspeitas) . ' peça(s) por rever - estado parado há +30 dias',
        'link' => 'app.php?page=revisao',
    ];
}

require_once __DIR__ . '/includes/revisoes.php';
nvGerarRevisoesDoMes($pdo, 30);
$revPendentes = nvRevisoesPendentes($pdo);
if ($revPendentes > 0) {
    $notificacoes[] = [
            'tipo' => 'suspeita',
            'msg'  => $revPendentes . ' peça(s) por rever este mês',
            'link' => 'app.php?page=revisao',
    ];
}

// PATs com prazo ULTRAPASSADO (em atraso) — mais crítico que o aviso de 3 dias
$stmtAtraso = $pdo->query("
    SELECT id, numero_pat, revisao, entidade, data_limite
    FROM pats
    WHERE data_limite IS NOT NULL AND data_limite < NOW()
      AND estado NOT IN ('Resolvido','Concluído','Cancelado')
    ORDER BY data_limite ASC LIMIT 10
");
foreach ($stmtAtraso->fetchAll() as $p) {
    $notificacoes[] = [
        'tipo' => 'atraso',
        'msg'  => 'PAT em atraso: ' . htmlspecialchars($p['numero_pat'].'/'.$p['revisao']) . ' — prazo ' . date('d/m/Y', strtotime($p['data_limite'])),
        'link' => 'app.php?page=pats&ver=' . (int)$p['id'],
    ];
}

// Envios em rascunho por finalizar
try {
    $nDraft = (int)$pdo->query("SELECT COUNT(*) FROM envios WHERE estado = 'Rascunho'")->fetchColumn();
    if ($nDraft > 0) {
        $notificacoes[] = [
            'tipo' => 'envio',
            'msg'  => $nDraft . ' envio(s) em rascunho por finalizar',
            'link' => 'app.php?page=envios',
        ];
    }
} catch (Throwable $e) { /* tabela ausente — ignora */ }

// Relatórios à espera de validação
try {
    $nRel = (int)$pdo->query("SELECT COUNT(*) FROM relatorios WHERE estado IN ('por_confirmar','revisao_manual')")->fetchColumn();
    if ($nRel > 0) {
        $notificacoes[] = [
            'tipo' => 'relatorio',
            'msg'  => $nRel . ' relatório(s) à espera de validação',
            'link' => 'app.php?page=relatorios',
        ];
    }
} catch (Throwable $e) { /* tabela ausente — ignora */ }

// Qualidade de dados: peças sem estado atribuído
try {
    $nSemEstado = (int)$pdo->query("SELECT COUNT(*) FROM pecas WHERE estado IS NULL OR TRIM(estado) = ''")->fetchColumn();
    if ($nSemEstado > 0) {
        $notificacoes[] = [
            'tipo' => 'suspeita',
            'msg'  => $nSemEstado . ' peça(s) sem estado atribuído',
            'link' => 'app.php?page=inventario',
        ];
    }
} catch (Throwable $e) { /* ignora */ }

$totalNotif = count($notificacoes);

// ══════════════════════════════════════════════
// DADOS — PATs
// ══════════════════════════════════════════════

$estadoData = $pdo->query("SELECT estado, COUNT(*) total FROM pecas GROUP BY estado ORDER BY total DESC")->fetchAll();

$categoriaData = $pdo->query("SELECT categoria, COUNT(*) total FROM pecas GROUP BY categoria ORDER BY total DESC LIMIT 12")->fetchAll();

$parceiroData = $pdo->query("SELECT parceiro, COUNT(*) total FROM pecas GROUP BY parceiro ORDER BY total DESC LIMIT 10")->fetchAll();

$pendentesCliente = (int)$pdo->query("SELECT COUNT(*) FROM pecas WHERE cliente_pendente = 1")->fetchColumn();

$trendRows = $pdo->query("
    SELECT 
      DATE_FORMAT(created_at, '%Y-%m') AS mes_ordem,
      DATE_FORMAT(created_at, '%b %Y') AS mes,
      COUNT(*) AS total
    FROM pecas
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
    ORDER BY mes_ordem ASC
  ")->fetchAll();

$patTrendRows = $pdo->query("
    SELECT
      DATE_FORMAT(created_at, '%Y-%m') AS mes_ordem,
      DATE_FORMAT(created_at, '%b %Y') AS mes,
      COUNT(*) AS total
    FROM pats
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
    ORDER BY mes_ordem ASC
  ")->fetchAll();

$actividadeRecente = $pdo->query("
    SELECT
        h.peca_id, h.campo, h.antes, h.depois,
        h.utilizador, h.data_alteracao,
        p.produto, p.sn
    FROM historico h
    LEFT JOIN pecas p ON p.id = h.peca_id
    WHERE h.peca_id > 0
    ORDER BY h.data_alteracao DESC, h.id DESC
    LIMIT 18
")->fetchAll();

/*=================
  AUDITORIA
==================*/

function active(string $p, string $page): string {
    return $p === $page ? 'active-link' : '';
}

// Tradução do tipo de cliente para português (apenas apresentação;
// o valor original mantém-se na BD para KPIs e filtros).
function tipoPt(string $type): string {
    $type = trim($type);
    if ($type === '') {
        return 'Sem tipo';
    }
    $mapa = [
        'customer'                => 'Cliente',
        'end customer'            => 'Cliente Final',
        'own shop'                => 'Loja Própria',
        'prospect'                => 'Potencial Cliente',
        'exclusive agent'         => 'Agente Exclusivo',
        'partner'                 => 'Parceiro',
        'partner - portugal'      => 'Parceiro - Portugal',
        'partner - international'  => 'Parceiro - Internacional',
        'partner - spain'         => 'Parceiro - Espanha',
        'partner - latam'         => 'Parceiro - LATAM',
    ];
    return $mapa[mb_strtolower($type, 'UTF-8')] ?? $type;
}

// Cor de cada estado — MESMA palete do gráfico de pizza do Dashboard (estadoColors).
function estadoCorHex(string $estado): string {
    static $cores = [
        'Disponível'             => '#28a745',
        'PAT'                    => '#6f42c1',
        'Laboratório'            => '#2470dc',
        'Abater'                 => '#dc3545',
        'Cliente'                => '#20c997',
        'Desconhecido'           => '#ffc107',
        'Devolução'              => '#17a2b8',
        'Fornecedor(Reparação)'  => '#fd7e14',
        'Fornecedor (Reparação)' => '#fd7e14',
        'OT'                     => '#495057',
        'Parceiro'               => '#8c564b',
        'Spares'                 => '#47372A',
        'Trânsito'               => '#6c757d',
    ];
    return $cores[trim($estado)] ?? '#6c757d';
}

// Bolha (badge) colorida do estado para a tabela do Inventário.
function estadoBolha(string $estado): string {
    if (trim($estado) === '') {
        return '';
    }
    $bg = estadoCorHex($estado);
    $r = hexdec(substr($bg, 1, 2));
    $g = hexdec(substr($bg, 3, 2));
    $b = hexdec(substr($bg, 5, 2));
    $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b; // luminância → contraste
    $txt = $lum > 160 ? '#1c1f24' : '#ffffff';
    return '<span class="estado-bolha" style="background:' . $bg . '; color:' . $txt . ';">'
        . htmlspecialchars($estado) . '</span>';
}

// Célula de contactos/morada para a página Clientes (dados do Report .xlsx).
function contactoCelula(array $map, string $nome): string {
    $c = $map[$nome] ?? null;
    if (!$c) {
        return '<span style="color:#9ca3af;">—</span>';
    }
    $linhas = [];
    if (!empty($c['emails'])) {
        $linhas[] = '📧 ' . htmlspecialchars($c['emails']);
    }
    $tel = implode(', ', array_filter([$c['phones'] ?? '', $c['mobiles'] ?? '']));
    if ($tel !== '') {
        $linhas[] = '📞 ' . htmlspecialchars($tel);
    }
    $morada = implode(', ', array_filter([$c['street'] ?? '', $c['city'] ?? '', $c['zip'] ?? '', $c['country'] ?? '']));
    if ($morada !== '') {
        $linhas[] = '📍 ' . htmlspecialchars($morada);
    }
    if (!$linhas) {
        return '<span style="color:#9ca3af;">—</span>';
    }
    $extra = ((int)($c['n'] ?? 1) > 1)
        ? '<div style="color:#9ca3af; font-size:11px; margin-top:2px;">' . (int)$c['n'] . ' contactos</div>'
        : '';
    return '<div style="font-size:12px; line-height:1.5; max-width:340px; word-break:break-word;">'
        . implode('<br>', $linhas) . '</div>' . $extra;
}




// ============================================================

?>


<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>StockVision</title>
<link rel="icon" type="image/png" href="/assets/favicon.png">
<link rel="apple-touch-icon" href="/assets/favicon.png">
<link rel="stylesheet" href="fonts.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link 
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script
  src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"
  defer
></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://unpkg.com/html5-qrcode" defer></script>

<!-- SIDEBAR-180px/BARRA DOURADA/ --> 
<style>

html, body {
    width: 100%;
    overflow-x: hidden;
    overflow-y: auto;
    scrollbar-width: none; 
    }

html::-webkit-scrollbar,
body::-webkit-scrollbar{
    display: none;
    }

body{
    margin:0;
    font-family: 'Roboto', sans-serif;
    background: #f8f9fb;
    color: #222;
    }

:root{
  --sidebar-width: 180px;
  --sidebar-collapsed-width: 76px;
  }

.sidebar{
  position: fixed;
  left: 0;
  top: 0;
  width: var(--sidebar-width);
  height: 100vh;
  background: #343a40;
  color: #fff;
  padding-top: 0;
  overflow-y: auto;
  overflow-x: hidden;
  scrollbar-width: none;
  -ms-overflow-style: none;
  transition: width .25s ease;
  display: flex;
  flex-direction: column;
  }

.sidebar > *{ flex-shrink: 0; }

.estado-bolha{
  display:inline-block;
  padding:3px 12px;
  border-radius:999px;
  font-size:12px;
  font-weight:600;
  line-height:1.4;
  white-space:nowrap;
}

.sidebar .brand{
  height: 64px;
  box-sizing: border-box;
  padding: 0 22px;
  border-bottom: 1px solid rgba(255,255,255,.06);
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: flex-start;
  }

.menu-toggle{
  background: transparent;
  border: none;
  color: #cba35c;
  font-size: 20px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
}

.sidebar .brand img{
  width: 110px;
  height: auto;
  transition: opacity .2s ease, width .25s ease;
  }

.sidebar a{
  display: flex;
  align-items: center;
  gap: 14px;
  color: #fff;
  text-decoration: none;
  padding: 10px 26px;
  font-size: 15px;
  transition: all .25s ease;
  }

.sidebar a i{
  font-size: 18px;
  min-width: 20px;
  text-align: center;
  }

.sidebar a span{
  white-space: nowrap;
  }

.sidebar-group{
  width:100%;
}

.sidebar-parent{
  width:100%;
  background:none;
  border:none;
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:flex-start;
  padding:10px 26px;
  font-size:15px;
  cursor:pointer;
  text-align:left;
  transition:all .25s ease;
}

.sidebar-parent:hover{
  background:rgba(255,255,255,.06);
}

.sidebar-parent-left{
  display:flex;
  align-items:center;
  gap:14px;
}

.sidebar-parent-left i{
  font-size:18px;
  min-width:20px;
  text-align:center;
}

.sidebar-submenu{
  display:none;
  padding:4px 0 8px 52px;
}

.sidebar-submenu .submenu-link{
  display:block;
  color:#d9dde2;
  text-decoration:none;
  padding:10px 0;
  font-size:15px;
}

.sidebar-submenu .submenu-link::before{
  content: "•";
  margin-right: 10px;
  color: #cba35c;
  font-size: 10px;
}

.sidebar-submenu .submenu-link:hover{
  color:#ffffff;
}

.sidebar-group.open .sidebar-submenu{
  display:block;
}

.sidebar-arrow{
  margin-left: auto;
  font-size:12px;
  transition: transform .25s ease;
  color: #adb5bd;
}

.sidebar-group.open .sidebar-arrow{
  transform: rotate(180deg);
}

/* Link ativo dentro do submenu */

.topbar {
  position: fixed;
  left: var(--sidebar-width);
  right: 0;
  top: 0;
  height: 64px;
  background: #cba35c;
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  padding: 0 18px 0 22px;
  z-index: 10;
  transition: left .25s ease;
  }

.topbar-title{
  font-family: 'Poppins', system-ui;
  color: #fff;
  font-size: 20px;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
  justify-self: start;
  }

.topbar-right {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
  justify-self: end;
}

.search-bar-wrap {
  position: relative;
  width: 260px;
}

.topbar-search-panel {
  display: none;
  position: fixed;
  top: 64px;
  left: var(--sidebar-width);
  right: 0;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  box-shadow: 0 8px 24px rgba(0,0,0,.12);
  z-index: 999;
  transition: left .25s ease;
}

.topbar.collapsed ~ * .topbar-search-panel,
.topbar-search-panel { left: var(--sidebar-width); }

.topbar-search-panel.open { display: block; }

.topbar-search-input-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  border-bottom: 1px solid #f3f4f6;
}

.topbar-search-input-wrap i { color: #9ca3af; font-size: 18px; flex-shrink:0; }

.topbar-search-input-wrap input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 15px;
  background: transparent;
  height: auto;
  padding: 0;
  box-shadow: none;
}

.main{
  width: calc(100% - var(--sidebar-width));
  margin-left: var(--sidebar-width);
  padding: 80px 28px 28px 28px;
  box-sizing: border-box;
  transition: all .25s ease;
  }


.sidebar.collapsed .brand{
  padding: 16px 0 22px;
  justify-content: center;
  }

.sidebar.collapsed {
  width: var(--sidebar-collapsed-width);
  }

.topbar.collapsed {
  left: var(--sidebar-collapsed-width);
  }

.main.collapsed {
  width: calc(100% - var(--sidebar-collapsed-width));
  margin-left: var(--sidebar-collapsed-width);
  }

.sidebar.collapsed .brand img{
  display: none;
  }

.sidebar.collapsed a{
  justify-content: center;
  padding: 14px 0;
  gap: 0;
  }

.sidebar.collapsed a span{
  display: none;
  }

.sidebar::-webkit-scrollbar { display: none; }

.sidebar.collapsed .menu-toggle{
  font-size: 22px;
  }

.sidebar.collapsed a i{
  font-size: 20px;
  }

.sidebar.collapsed .sidebar-parent{
  justify-content:center;
  padding:14px 0;
}

.sidebar.collapsed .sidebar-parent-left span,
.sidebar.collapsed .sidebar-arrow,
.sidebar.collapsed .sidebar-submenu{
  display:none;
}

.sidebar.collapsed .sidebar-parent-left{
  gap:0;
}

.sidebar.collapsed .sidebar-parent-left i{
  font-size:20px;
}

.sidebar .footer-logo{
  margin-top: auto;
  padding: 16px 24px;
  border-top: 1px solid rgba(255,255,255,.06);
  opacity: .92;
  font-weight: 700;
  color: #d0d0d0;
  line-height: 1.1;
  }

.sidebar.collapsed .footer-logo{
  text-align: center;
  padding: 14px 6px;
  font-size: 9px;
  }

.sidebar.collapsed .footer-logo span{
  display: none;
  }



.user-box{display:flex;align-items:center;gap:10px;color:#fff;font-size:16px}

.user-avatar{
    width:34px;
    height:34px;
    border-radius:50%;
    background:#ddd;
    object-fit:cover;
    display:block;
    border:1px solid rgba(255,255,255,.35);
      }

.logout{
    background:#343a40;
    color:#fff;
    border:none;
    border-radius:4px;
    padding:6px 12px;
    text-decoration:none;
    display:inline-block;
      }



/*Dashboard Cartões */
.kpi-row{
         display:grid;
         grid-template-columns:repeat(7, 1fr);
         gap: 16px !important;
         margin-bottom: 10px;
         align-items:start;
        }

.kpi-card,.panel{
                background:#fff;
                border-radius:14px;
                box-shadow:0 2px 10px rgba(0,0,0,.06);
                }

.kpi-card{
          width:100% !important;
          box-sizing:border-box;
          aspect-ratio:1 / 1;
          min-width: 0;
          padding: 12px;
          text-align:center;
          display:flex;
          flex-direction:column;
          justify-content:center;
          align-items:center;
          overflow:hidden; 
         }

.kpi-card i{
            font-size:32px !important;
            color:#cba35c;
           }

.kpi-card .num{
              font-size:24px !important;
              font-weight:700;
              margin:8px 0 4px !important;
              line-height:1.1 !important;
              }

.kpi-card div:last-child{
   font-size:16px !important;
   line-height:1.2 !important;
   margin-top: 8px !important;
  }


.panel-grid .panel,
.panel-grid-2 .panel{
  width:100%;
  box-sizing:border-box;
}

.contas-layout{
  display:grid;
  grid-template-columns:380px 1fr;
  gap:24px;
  align-items:start;
  margin-top:20px;
}

.contas-layout .panel{
  margin-bottom:0 !important;
  width:100%;
  box-sizing:border-box;
}

@media (max-width: 1100px){
  .contas-layout{
    grid-template-columns:1fr;
  }
}

.panel h4{
  margin:0 0 14px;
  font-size:18px;
  min-height:24px;
  }

.panel canvas{
  max-width:100%;
  }

.table-responsive{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}
/* Variante com altura limitada e scroll vertical (scrollbar visível) */
.table-responsive.scroll-limitado{
    max-height:70vh;
}
.table-responsive.scroll-limitado thead th{ position:sticky; top:0; z-index:3; }
/* Variante com scroll vertical mas SEM barra de scroll visível (continua a poder fazer-se scroll) */
.table-responsive.scroll-oculto{
    max-height:420px;
    overflow-y:auto;
    scrollbar-width:none;
    -ms-overflow-style:none;
}
.table-responsive.scroll-oculto::-webkit-scrollbar{ display:none; }
.table-responsive.scroll-oculto thead th{ position:sticky; top:0; z-index:3; }

.table{
        width:100%;
        border-collapse:collapse;
        background:#fff;
        table-layout:auto;
      }
.table th,.table td{
      border:1px solid #e5e7eb;
      padding:8px 10px;
      text-align:left;
      vertical-align:middle;
      white-space:normal;
      word-break:break-word;
      font-size:13.5px;
}
.table th{
            background:#f6f7f9;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.03em;
            color:#4b5563;
            white-space:nowrap;
          }
.table td.nowrap,
.table th.nowrap{ white-space:nowrap; }
.table tbody tr:hover{ background:#fafbfc; }
body.dark-mode .table tbody tr:hover{ background:#26303f; }

/* Caixa de pesquisa rápida (filtro instantâneo do lado do cliente) — mesmo estilo dos restantes inputs do site */
.quick-search-wrap{ position:relative; max-width:300px; flex:1; min-width:220px; }
.quick-search-wrap i{
    position:absolute; left:14px; top:50%; transform:translateY(-50%);
    color:#9ca3af; font-size:16px; pointer-events:none;
}
.quick-search-wrap input{ padding-left:40px; }
.quick-search-wrap input:focus + i,
.quick-search-wrap:has(input:focus) i{ color:#cba35c; }
.table-empty-state{ text-align:center; color:#9ca3af; padding:34px 16px !important; background:#fff !important; }
.table-empty-state i{ font-size:30px; display:block; margin-bottom:8px; opacity:.5; }
.panel-header-row{
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px; margin-bottom:16px;
}
.panel-header-left{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.panel-count-badge{
    display:inline-flex; align-items:center; justify-content:center;
    min-width:24px; height:24px; padding:0 8px; border-radius:999px;
    background:#f3f4f6; color:#4b5563; font-size:12px; font-weight:700;
}
.panel-header-actions{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.btn{
      display:inline-block;
      padding:12px 16px;
      border-radius:7px;
      text-decoration:none;
      border:none;
      color:#fff;
      font-size:15px;
      cursor:pointer;
    }
.btn-teal{background:#1da1a1}

.btn-green{background:#59b94f}

.btn-blue{background:#3d82c4}

.btn-yellow{background:#f6bf26;color:#fff}

.btn-red{background:#dc3545}

.btn-grey{background:#6c757d}

.form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:20px;
          }
@media (max-width:768px){
  .form-grid{ grid-template-columns:1fr; }
}
.parceiro-contacto-col{
  background:#fafbfc;
  border:1px solid #eef0f3;
  border-radius:10px;
  padding:16px 18px;
}

/* -- Caixa de upload drag&drop (Envios, Relatórios, Contas) -- */
.upload-pdf-input{
    position:absolute; width:1px; height:1px;
    padding:0; margin:0; overflow:hidden;
    clip:rect(0,0,0,0); white-space:nowrap; border:0;
}
.upload-pdf-box{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:12px; text-align:center; padding:32px 20px;
    border:1.5px solid #f0e3c4; border-radius:16px;
    background:linear-gradient(180deg, #fffdf9 0%, #fbf2df 100%);
    cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.04);
    transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.upload-pdf-box:hover{
    transform:translateY(-3px);
    box-shadow:0 10px 24px rgba(201,161,74,.2);
    border-color:#c9a14a;
}
.upload-pdf-box.is-dragover{
    border-color:#c9a14a;
    background:linear-gradient(180deg,#fff8ea,#f7e8c4);
    transform:translateY(-3px);
    box-shadow:0 10px 24px rgba(201,161,74,.25);
}
.upload-pdf-icon{
    width:54px; height:54px; border-radius:50%;
    background:#fff; display:flex; align-items:center; justify-content:center;
    box-shadow:0 4px 10px rgba(201,161,74,.28);
}
.upload-pdf-icon i{ font-size:22px; color:#c9a14a; }
.upload-pdf-text{ font-size:13px; color:#6b7280; line-height:1.5; }
.upload-pdf-text strong{ color:#3d82c4; font-weight:700; }
.upload-pdf-filename{
    display:flex; align-items:center; gap:10px;
    font-size:13px; font-weight:600; color:#1e293b;
    background:#fff; border:1px solid #e5e7eb; border-radius:10px;
    padding:10px 16px; max-width:100%; box-shadow:0 1px 3px rgba(0,0,0,.05);
}
.upload-pdf-filename .bi-file-earmark-pdf-fill{ color:#c9a14a; font-size:17px; }
.upload-pdf-filename span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.upload-pdf-clear{ margin-left:2px; color:#9ca3af; cursor:pointer; flex-shrink:0; }
.upload-pdf-clear:hover{ color:#dc3545; }
.btn-ler-guia{
    display:flex; align-items:center; justify-content:center; gap:9px;
    width:100%; padding:14px 16px; margin-top:16px;
    background:linear-gradient(135deg,#4d92d9,#3672ab);
    color:#fff; font-size:15px; font-weight:600;
    border:none; border-radius:11px; cursor:pointer;
    box-shadow:0 4px 12px rgba(61,130,196,.32);
    transition:transform .15s ease, box-shadow .15s ease;
}
.btn-ler-guia i{ font-size:16px; }
.btn-ler-guia:hover{ transform:translateY(-2px); box-shadow:0 8px 18px rgba(61,130,196,.42); }
.btn-ler-guia:active{ transform:translateY(0); box-shadow:0 3px 8px rgba(61,130,196,.3); }

label{display:block;font-weight:700;margin:0 0 8px}

input,
select{
  width:100%;
  height:46px;
  padding:12px 14px;
  border:1px solid #d6dbe1;
  border-radius:10px;
  font-size:15px;
  box-sizing:border-box;
  background:#fff;
  color:#222;
  transition:border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
}

/* Checkboxes e radios não devem herdar width:100%/height:46px dos inputs de texto */
input[type="checkbox"],
input[type="radio"]{
  width:18px;
  height:18px;
  min-width:18px;
  padding:0;
  margin:0;
  border-radius:4px;
  accent-color:#cba35c;
  cursor:pointer;
  flex:0 0 auto;
}

input:focus,
select:focus{
  outline:none;
  border-color:#cba35c;
  box-shadow:0 0 0 4px rgba(203,163,92,.18);
}

input:hover,
select:hover{
  border-color:#b8c0c8;
}

select{
  appearance:none;
  -webkit-appearance:none;
  -moz-appearance:none;
  padding-right:42px;
  cursor:pointer;
  background-color:#fff;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M5 7.5L10 12.5L15 7.5' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 14px center;
  background-size:16px;
}

.filters{
          display:grid;
          grid-template-columns:1fr 1fr 1fr auto;
          gap:20px;
          align-items:end;
          margin:18px 0 8px;
        }
.filters2{
            display:grid;
            grid-template-columns:1fr 1fr auto;
            gap:20px;
            align-items:end;
            margin:0 0 20px;
          }
.badge{
        padding:7px 10px;
        border-radius:20px;
        color:#fff;
        font-size:13px;
        display:inline-block;
      }

.actions{
         white-space:nowrap;
       }
.actions .btn{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                padding:5px 8px;
                font-size:12px;
                margin-right:4px;
                margin-bottom:0;
                white-space:nowrap;
              }
.small-note{color:#6b7280;margin-top:6px}

@media (max-width: 1200px){
}

@media (max-width: 1366px){
  .kpi-row{gap:14px;}
  .kpi-card{padding:8px;}
  .kpi-card i{font-size:26px !important;}
  .kpi-card .num{font-size:18px !important;}
  .kpi-card div:last-child{font-size:14px !important;}
  }

.panel{
  padding:18px;
  display:flex;
  flex-direction:column;
  box-sizing:border-box;
}

.panel-estado{
  width:100%;
  margin:0 0 20px 0;
}

.estado-layout{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:80px;
  width:100%;
  padding-right:0;
  box-sizing:border-box;
}

.estado-chart-box{
  width:260px;
  flex-shrink:0;
  display:flex;
  justify-content:center;
}

.estado-chart-box canvas{
  width:260px !important;
  height:260px !important;
}

.legend-container{
  width:auto;
  min-width:0;
}

.legend-text{
  display:grid;
  grid-template-columns:repeat(2, minmax(150px, 240px));
  column-gap:60px;
  row-gap:4px;
  align-content:center;
}

.panel-grid,
.panel-grid-2{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:18px;
  width:100%;
  margin:0 0 20px 0;
  align-items:stretch;
  box-sizing:border-box;
}

.panel-grid canvas,
.panel-grid-2 canvas{
  width:100% !important;
  height:320px !important;
}

.legend-item{
  display:flex;
  align-items:center;
  gap:12px;
  font-size:18px;
  font-weight:700;
}

.legend-color{
  width:20px;
  height:20px;
  border-radius:4px;
  flex-shrink:0;
}

@media (max-width:1100px){
  .estado-layout{
    gap:32px;
    padding-right:20px;
  }

  .estado-chart-box{
    width:240px;
    max-width:240px;
  }

  .estado-chart-box canvas{
    width:240px !important;
    height:240px !important;
  }

  .legend-text{
    grid-template-columns:repeat(2, minmax(160px, 1fr));
    column-gap:18px;
    row-gap:12px;
    width:100%;
  }
}

@media (max-width:768px) {
    .estado-layout {
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 20px;
        padding-right: 0;
        width: 100%;
    }

    .legend-container {
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .legend-text {
        display: grid;
        grid-template-columns:repeat(2, minmax(150px, 190px));
        column-gap: 16px;
        row-gap: 10px;
        align-content: center;
    }
}

.auditoria-card {
    margin-top: 24px;
}

.auditoria-header h2 {
    margin: 0 0 6px;
    font-size: 26px;
    font-weight: 700;
    color: #1f2937;
}

.auditoria-header p {
    margin: 0 0 18px;
    font-size: 14px;
    color: #6b7280;
}

.auditoria-box {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 22px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.auditoria-title {
    margin: 0 0 20px;
    font-size: 17px;
    font-weight: 600;
    color: #1f2937;
}

.auditoria-filtros {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 14px;
    margin-bottom: 18px;
}

.auditoria-filtro {
    display: flex;
    flex-direction: column;
    min-width: 220px;
}

.auditoria-filtro label {
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
}

.auditoria-filtro select {
    height: 42px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0 12px;
    background: #fff;
    font-size: 14px;
    color: #111827;
}

.auditoria-botoes {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-left: auto;
    justify-content: flex-end;
}

.btn-audit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 42px;
    padding: 0 16px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s ease;
    box-sizing: border-box;
}

.btn-filtrar {
    background: #f4b400;
    color: #ffffff;
}

.btn-filtrar:hover {
    background: #dea406;
}

.btn-limpar {
    background: #6b7280;
    color: #ffffff;
}

.btn-limpar:hover {
    background: #575d68;
}

.btn-exportar {
    background: #0f9d8a;
    color: #ffffff;
}

.btn-exportar:hover {
    background: #0c8575;
}

.auditoria-tabela-wrap {
    overflow-x: auto;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}

.auditoria-tabela {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
    background: #ffffff;
}

.auditoria-tabela thead th {
    background: #eef1f5;
    color: #1f2937;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    text-align: left;
    padding: 12px 14px;
    border-bottom: 2px solid #cbd5e1;
    border-right: 1px solid #cbd5e1;
}

.auditoria-tabela thead th:last-child {
    border-right: none;
}

.auditoria-tabela tbody td {
    padding: 11px 14px;
    font-size: 14px;
    color: #1f2937;
    border-top: 1px solid #e5e9ef;
    border-right: 1px solid #dde2e8;
    vertical-align: top;
}

.auditoria-tabela tbody td:last-child {
    border-right: none;
}

.auditoria-tabela tbody tr:nth-child(even) {
    background: #f8fafc;
}

.auditoria-tabela tbody tr:hover {
    background: #eef2f7;
}

.auditoria-vazia {
    text-align: center;
    color: #6b7280;
    padding: 18px !important;
    background: #fff !important;
}

@media (max-width: 768px) {
    .auditoria-filtros {
        flex-direction: column;
        align-items: stretch;
    }

    .auditoria-filtro {
        min-width: 100%;
    }

    .auditoria-botoes {
        width: 100%;
    }

    .btn-audit {
        width: 100%;
    }
}

.alerta-erro{
  background: #f8d7da;
  color: #842029;
  border: 1px solid #f5c2c7;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
  font-size: 14px;
  }

.alerta-sucesso{
  background: #d1e7dd;
  color: #0f5132;
  border: 1px solid #badbcc;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
  font-size: 14px;
  }

.barcode-copy-wrap{
  display:flex;
  gap:10px;
  align-items:center;
  }

.barcode-copy-wrap input{
  flex:1;
  }

.btn-copy-sn{
  padding: 12px 14px;
  white-space: nowrap;
  }

#copySnFeedback{
  color:#198754;
  margin-top:8px;
  }

.envios-table{
  width:100%;
  border-collapse:separate;
    border-spacing:0;
  background:#fff;
  }

.envios-table th,
.envios-table td{
  padding:12px 14px;
  text-align:left;
  vertical-align:middle;
    border-bottom:1px solid #e5e7eb;
    white-space:normal;
    word-break:break-word;
  }

/* Algumas tabelas (ex.: Lista de Envios/Relatórios) beneficiam de colunas numa só linha;
   usar a classe utilitária abaixo apenas nessas, em vez de forçar nowrap globalmente. */
.envios-table td.nowrap,
.envios-table th.nowrap{ white-space:nowrap; }

.envios-table th{
  background:#f6f7f9;
  color:#222;
    font-weight:700;
    position:sticky;
    top:0;
    z-index:1;
  }

.envios-table tbody tr:hover{
    background:#f8f9fb;
}

.envios-table tbody tr:last-child td{
    border-bottom:none;
}

.envios-vazio{
  text-align:center;
  color:#6b7280;
  padding:18px !important;
  }

.envio-linhas-table th,
.envio-linhas-table td{
    padding:12px 14px;
    text-align:left;
    border-bottom:1px solid #e5e7eb;
    white-space:nowrap;
}

.envio-linhas-table th{
    background:#f6f7f9;
}

.envio-linhas-table tbody tr:last-child td{
    border-bottom:none;
}

.linha-envio-grid{
    display:grid;
    grid-template-columns:minmax(0, 1.1fr) minmax(0, 1.6fr) 120px minmax(0, 1.1fr) auto;
    gap:10px;
    align-items:end;
    padding:12px;
    border:1px solid #e5e7eb;
    border-radius:12px;
    background:#fafbfc;
}

.linha-envio-grid > *{
    min-width:0;
}

@media (max-width: 1180px){
}

@media (max-width: 900px){
    .linha-envio-grid{
        grid-template-columns:1fr 1fr;
    }

}

@media (max-width: 640px){
    .linha-envio-grid{
        grid-template-columns:1fr;
    }

}



.clientes-kpis{
  display:grid;
  grid-template-columns:repeat(5, minmax(0,1fr));
  gap:18px;
  margin-bottom:20px;
}

.cliente-kpi{
  background:#fff;
  border-radius:14px;
  box-shadow: 0 2px 10px rgba(0,0,0,.06);
  padding:18px;
}

.cliente-kpi .label{
  font-size:13px;
  color:#6b7280;
  margin-bottom:8px;
}

.cliente-kpi .valor{
  font-size:28px;
  font-weight:700;
  color:#1f2937;
}

.clientes-filtros{
  display:flex;
  flex-wrap:wrap;
  align-items:end;
  gap:16px;
  margin-bottom:4px;
}
.clientes-filtro{
  flex:1 1 200px;
  min-width:180px;
  display:flex;
  flex-direction:column;
}
.clientes-filtro label{
  font-size:11px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.05em;
  color:#9ca3af;
  margin:0 0 7px;
}
.clientes-filtro input,
.clientes-filtro select{
  height:44px;
  border:1px solid #e5e9ef;
  background:#f8fafc;
  border-radius:9px;
  font-size:14px;
}
.clientes-filtro input:focus,
.clientes-filtro select:focus{
  background:#fff;
  border-color:#cba35c;
}
.clientes-filtros-botoes{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-left:auto;
}

.clientes-table{
  width:100%;
  border-collapse:collapse;
  background:#fff;
}

.clientes-table th,
.clientes-table td{
  border:1px solid #e5e7eb;
  padding:12px;
  text-align:left;
  vertical-align:middle;
}

.clientes-table th{
  background:#f6f7f9;
}

.cliente-row-parent{
  background:#ffffff;
}

.cliente-row-child{
  background:#fbfcfe;
}

.cliente-child-name{
  padding-left:34px;
  position:relative;
}

.cliente-child-name::before{
  content:"└";
  position:absolute;
  left:14px;
  color:#9ca3af;
}

.cliente-toggle{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:28px;
  height:28px;
  border:none;
  border-radius:6px;
  background:#eef2f7;
  color:#374151;
  cursor:pointer;
  margin-right:8px;
  font-size:14px;
}

.cliente-toggle:hover{
  background:#e5e7eb;
}

.tipo-badge{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:600;
  white-space:nowrap;
}

.clientes-empty{
  color:#6b7280;
  text-align:center;
  padding:22px !important;
}

@media (max-width: 1200px) {
  .clientes-kpis{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }
}

@media (max-width: 768px){
  .clientes-kpis{
    grid-template-columns:1fr;
  }
  .clientes-filtro{
    flex-basis:100%;
  }
  .clientes-filtros-botoes{
    margin-left:0;
    width:100%;
  }
}

.conta-principal-badge{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:600;
  background:#eef2f7;
  color:#4b5563;
  white-space:nowrap;
}

/* -- Menu Açoes Dropdown --*/
.acao-wrap { position: relative; display: inline-block; }
.acao-btn  {
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 4px 10px;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    color: #374151;
    transition: background .15s;
}

acao-btn:hover {background: #e5e7eb; }

.acao-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4pc 16px rgba(0,0,0,.12);
    min-width: 140px;
    z-index: 100;
    overflow: hidden;
}

.acao-menu a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: #1f2937;
    text-decoration: none;
    white-space: nowrap;
    transition: background .12s;
}

.acao-menu a:hover { background: #f3f4f6; }
.acao-menu a i { font-size: 15px; }
.acao-wrap.open .acao-menu { display: block; }


/* -- Utilizador Dropdown --*/
.user-dropdown { position: relative; }
.user-trigger {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    background: none;
    border: none;
    color: #fff;
    font-size: 15px;
    padding: 6px 10px;
    border-radius: 8px;
    transition: background .15s;
}
.user-trigger:hover { background: rgba(0,0,0,.15); }
.user-dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 6px 24px rgba(0,0,0,.14);
    min-width: 180px;
    z-index: 200;
    overflow: hidden;
}
.user-dropdown-menu .dd-header {
    padding: 12px 16px 10px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 13px;
    color: #6b7280;
}
.user-dropdown-menu .dd-header strong {
    display: block;
    font-size: 14px;
    color: #111827;
    margin-bottom: 2px;
}
.user-dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    font-size: 14px;
    color: #1f2937;
    text-decoration: none;
    transition: background .12s;
}
.user-dropdown-menu a:hover { background: #f3f4f6; }
.user-dropdown-menu a.danger { color: #dc2626; }
.user-dropdown-menu a.danger:hover { background: #fef2f2; }
.user-dropdown.open .user-dropdown-menu { display: block; }


/* -- Modo Escuro -- */
body.dark-mode {
    background: #111827;
    color: #e5e7eb;
}
body.dark-mode .sidebar { background: #1f2937; }
body.dark-mode .sidebar a,
body.dark-mode .sidebar-parent { color: #d1d5db; }
body.dark-mode .main { background: #111827; }
body.dark-mode .kpi-card,
body.dark-mode .panel { background: #1f2937; color: #e5e7eb; }
body.dark-mode .table { background: #1f2937; color: #e5e7eb; }
body.dark-mode .table th { background: #374151; color: #d1d5db; }
body.dark-mode .table th,
body.dark-mode .table td { border-color: #374151; }
body.dark-mode input,
body.dark-mode select,
body.dark-mode textarea { background: #374151; color: #e5e7eb; border-color: #4b5563; }
body.dark-mode .panel h4 { color: #f3f4f6; }
body.dark-mode .user-dropdown-menu { background: #1f2937; border-color: #374151; }
body.dark-mode .user-dropdown-menu a { color: #e5e7eb; }
body.dark-mode .user-dropdown-menu a:hover {background: #374151; }
body.dark-mode .acao-menu { background: #1f2937; border-color: #374151; }
body.dark-mode .acao-menu a { color: #e5e7eb; }
body.dark-mode .acao-menu a:hover { background: #374151; }

/* -- Modo Escuro: componentes adicionados + fundos claros inline -- */
body.dark-mode .actions-dd-menu{ background:#1f2937; border-color:#374151; }
body.dark-mode .actions-dd-menu a, body.dark-mode .actions-dd-menu button{ color:#e5e7eb; }
body.dark-mode .actions-dd-menu a:hover, body.dark-mode .actions-dd-menu button:hover{ background:#374151; }
body.dark-mode .actions-dd-menu .dd-sep{ background:#374151; }
body.dark-mode .actions-dd-menu a.is-primary{ color:#f3f4f6; }
body.dark-mode .filter-chip{ background:#374151; border-color:#4b5563; color:#e5e7eb; }
body.dark-mode .filter-chip .lbl{ color:#9ca3af; }
body.dark-mode .seg-control{ background:#374151; border-color:#4b5563; }
body.dark-mode .seg-control a, body.dark-mode .seg-control button{ color:#e5e7eb; }
body.dark-mode .seg-control .seg-mid{ background:#1f2937; border-color:#4b5563; }
body.dark-mode .seg-control a:hover{ background:#4b5563; }
body.dark-mode .row-detail > td{ background:#111827 !important; }
body.dark-mode .row-detail-inner .rd-item .rd-val{ color:#e5e7eb; }
body.dark-mode .pats-filtros{ background:#1f2937; border-color:#374151; }
body.dark-mode .pats-search input, body.dark-mode .pats-filtros-row select{ background:#374151; border-color:#4b5563; color:#e5e7eb; }
body.dark-mode .env-tabs{ border-color:#374151; }
body.dark-mode .env-tab{ color:#9ca3af; }
body.dark-mode .env-tab.is-active{ color:#f3f4f6; }
body.dark-mode .env-tab .pill{ background:#374151; color:#d1d5db; }
body.dark-mode .env-stat{ background:#111827; border-color:#374151; }
body.dark-mode .rel-month-row td{ background:#374151 !important; color:#d1d5db; }
body.dark-mode .rel-preview{ background:#1f2937; border-color:#374151; }
body.dark-mode .rel-mes-filtro{ background:#374151; border-color:#4b5563; color:#e5e7eb; }
body.dark-mode .quick-search-wrap input{ background:#374151; color:#e5e7eb; }
body.dark-mode .clientes-filtro input, body.dark-mode .clientes-filtro select{ background:#374151; border-color:#4b5563; color:#e5e7eb; }
body.dark-mode .cli-contactos span{ color:#9ca3af; }
body.dark-mode .cli-contactos span i{ color:#6b7280; }
body.dark-mode .kpi-resumo-compact .kpi-card .kpi-lbl{ color:#9ca3af; }
body.dark-mode .tipo-badge{ filter:brightness(.92); }
body.dark-mode .clientes-table th{ background:#374151; color:#d1d5db; }
body.dark-mode .clientes-table td{ border-color:#374151; }
/* Apanhar fundos claros definidos inline (zonas que ficavam brancas) */
body.dark-mode [style*="background:#f8fafc"],
body.dark-mode [style*="background:#f8f9fa"],
body.dark-mode [style*="background:#f9fafb"],
body.dark-mode [style*="background:#fff"],
body.dark-mode [style*="background: #fff"],
body.dark-mode [style*="background:#f0fdf4"],
body.dark-mode [style*="background:#eef2f7"],
body.dark-mode [style*="background:#f3f4f6"]{ background:#1f2937 !important; }
body.dark-mode [style*="color:#1f2937"],
body.dark-mode [style*="color:#111827"],
body.dark-mode [style*="color:#374151"]{ color:#e5e7eb !important; }


/* -- Notificações -- */
.notif-wrap { position: relative; }
.notif-btn  {
    background: none; border: none; color: #fff;
    font-size: 20px; cursor: pointer;
    padding: 6px 8px; border-radius: 6px;
    transition: background .15s; position: relative;
}
.notif-btn:hover { background: rgba(0,0,0,.15); }
.notif-badge {
    position: absolute; top: 2px; right: 2px;
    background: #ef4444; color: #fff;
    border-radius: 999px; font-size: 10px; font-weight: 700;
    min-width: 16px; height: 16px;
    display: flex; align-items: center; justify-content: center; padding: 0 3px;
}
.notif-panel {
    display: none; position: absolute; right: 0; top: calc(100% + 6px);
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    box-shadow: 0 6px 24px rgba(0,0,0,.14); width: 320px; z-index: 200; overflow: hidden;
}
.notif-panel-header { padding: 12px 16px; font-weight: 700; font-size: 14px; border-bottom: 1px solid #f3f4f6; color: #111827; }
.notif-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 14px; text-decoration: none; color: #1f2937 !important;
    font-size: 13px; border-bottom: 1px solid #f9fafb; transition: background .12s;
}
.notif-item:hover { background: #f3f4f6; }
.notif-item:last-child { border-bottom: none; }
.notif-dot-urgente { color: #ef4444; font-size: 10px; margin-top: 3px; }
.notif-dot-prazo   { color: #f59e0b; font-size: 10px; margin-top: 3px; }
.notif-dot-suspeita  { color: #8b5cf6; font-size: 10px; margin-top: 3px; }
.notif-dot-atraso    { color: #dc2626; font-size: 10px; margin-top: 3px; }
.notif-dot-envio     { color: #06b6d4; font-size: 10px; margin-top: 3px; }
.notif-dot-relatorio { color: #2563eb; font-size: 10px; margin-top: 3px; }
.notif-dot-stock     { color: #16a34a; font-size: 10px; margin-top: 3px; }
body.dark-mode .notif-panel{ background:#1f2937; border-color:#374151; }
body.dark-mode .notif-panel-header{ color:#f3f4f6; border-color:#374151; }
body.dark-mode .notif-item{ color:#e5e7eb !important; border-color:#374151; }
body.dark-mode .notif-item:hover{ background:#374151; }
.notif-empty { padding: 18px; text-align: center; color: #6b7280; font-size: 13px; }
.notif-wrap.open .notif-panel { display: block; }


/* ── Pesquisa Global ── */
.search-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 999;
    align-items: flex-start; justify-content: center; padding-top: 120px;
}
.search-overlay.open { display: flex; }
.search-box {
    background: #fff; border-radius: 14px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    width: 560px; max-width: 90vw; overflow: hidden;
}
.search-input-wrap {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 20px; border-bottom: 1px solid #e5e7eb;
}
.search-input-wrap i { font-size: 20px; color: #9ca3af; }
.search-input-wrap input {
    flex: 1; border: none; outline: none;
    font-size: 16px; background: transparent; height: auto; padding: 0;
}
.search-results { max-height: 360px; overflow-y: auto; }
.search-result-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 20px; text-decoration: none; color: #1f2937;
    font-size: 14px; border-bottom: 1px solid #f9fafb; transition: background .1s;
}
.search-result-item:hover { background: #f3f4f6; }
.search-result-item i { font-size: 16px; color: #9ca3af; min-width: 20px; }
.search-result-type { font-size: 11px; color: #9ca3af; margin-left: auto; white-space: nowrap; }
.search-empty { padding: 24px; text-align: center; color: #9ca3af; font-size: 14px; }
.search-hint  { padding: 10px 20px; font-size: 12px; color: #9ca3af; border-top: 1px solid #f3f4f6; }


/* ── Responsividade Mobile ── */
@media (max-width: 768px) {
    :root {
        --sidebar-width: 0px;
        --sidebar-collapsed-width: 0px;
    }
    .sidebar {
        width: 240px;
        transform: translateX(-100%);
        transition: transform .25s ease;
        z-index: 500;
    }
    .sidebar.mobile-open { transform: translateX(0); }
    .topbar { left: 0 !important; }
    .main {
        width: 100% !important;
        margin-left: 0 !important;
        padding: 80px 16px 24px !important;
    }
    .sidebar-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.4); z-index: 499;
    }
    .sidebar-overlay.visible { display: block; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .kpi-row { grid-template-columns: repeat(2, 1fr) !important; gap: 12px !important; }
    .filters, .filters2 { grid-template-columns: 1fr !important; }
    .topbar button span:not(.sr-only) { display: none; }
}

/* FILTROS DO INVENTÁRIO MAIS COMPACTOS */
.filters .btn, .filters2 .btn { padding:8px 12px; font-size:13px; }
.filters select,
.filters2 select,
.filters2 input { height:38px; padding:8px 12px; font-size:14px; }
.filters label, .filters2 label { font-size:13px; margin-bottom:4px; }

/* ════════════════════════════════════════════════════════════════
   COMPONENTES PARTILHADOS DE UI (linguagem comum de tabelas/toolbars)
   Adicionado para uniformizar Inventário, PATs, Clientes, Envios, etc.
   ════════════════════════════════════════════════════════════════ */

/* Coluna de Ações sempre centrada horizontalmente */
.table td.actions, .table th.actions, td.actions, th.actions{ text-align:center; }
.table td.actions .btn, .table td.actions form{ vertical-align:middle; }

/* Toolbar compacta (filtros + ações numa só linha, com wrap responsivo) */
.toolbar-compact{ display:flex; flex-wrap:wrap; align-items:end; gap:12px; margin:4px 0 16px; }
.toolbar-compact .tb-grow{ flex:1 1 220px; min-width:200px; }
.toolbar-compact .tb-actions{ display:flex; gap:8px; align-items:end; margin-left:auto; }

/* Dropdown de ações reutilizável (toggle nativo <details>, sem dependências) */
.actions-dd{ position:relative; display:inline-block; }
.actions-dd > summary{ list-style:none; cursor:pointer; user-select:none; }
.actions-dd > summary::-webkit-details-marker{ display:none; }
.actions-dd > summary .bi-chevron-down{ font-size:11px; margin-left:2px; transition:transform .15s; }
.actions-dd[open] > summary .bi-chevron-down{ transform:rotate(180deg); }
.actions-dd-menu{
  position:absolute; right:0; top:calc(100% + 6px); z-index:60;
  background:#fff; border:1px solid #e5e9ef; border-radius:10px;
  box-shadow:0 10px 28px rgba(16,24,40,.14); min-width:212px; padding:6px;
  display:flex; flex-direction:column;
}
.actions-dd-menu a, .actions-dd-menu button{
  display:flex; align-items:center; gap:10px; padding:10px 12px;
  border:none; background:none; width:100%; text-align:left;
  border-radius:7px; text-decoration:none; color:#374151; font-size:14px; cursor:pointer;
}
.actions-dd-menu a:hover, .actions-dd-menu button:hover{ background:#f3f4f6; }
.actions-dd-menu a.is-primary{ color:#1a202c; font-weight:600; }
.actions-dd-menu a.is-primary i{ color:#c9a14a; }
.actions-dd-menu .dd-sep{ height:1px; background:#eef1f5; margin:4px 2px; }
.actions-dd-menu label.dd-check{ font-size:13px; color:#374151; padding:7px 10px; cursor:pointer; display:flex; gap:9px; align-items:center; }

/* Chips de filtro ativo (PATs Opção D / filtros visíveis) */
.filter-chips{ display:flex; flex-wrap:wrap; gap:8px; margin:2px 0 14px; }
.filter-chip{
  display:inline-flex; align-items:center; gap:7px; padding:5px 10px 5px 12px;
  background:#f3f4f6; border:1px solid #e5e9ef; border-radius:999px;
  font-size:12.5px; color:#374151; text-decoration:none;
}
.filter-chip .lbl{ color:#9ca3af; text-transform:uppercase; font-size:10.5px; font-weight:700; letter-spacing:.04em; }
.filter-chip a, .filter-chip button{ color:#9ca3af; border:none; background:none; cursor:pointer; line-height:1; padding:0; display:inline-flex; }
.filter-chip a:hover, .filter-chip button:hover{ color:#dc3545; }

/* Linha expansível de detalhe (Clientes Opção D) */
.row-toggle{ cursor:pointer; }
.row-toggle .exp-caret{ transition:transform .15s; display:inline-block; color:#9ca3af; }
tr.is-expanded .exp-caret{ transform:rotate(90deg); }
.row-detail > td{ background:#f8fafc !important; padding:0 !important; }
.row-detail-inner{ padding:14px 18px; display:flex; flex-wrap:wrap; gap:22px 40px; }
.row-detail-inner .rd-item{ display:flex; flex-direction:column; gap:2px; }
.row-detail-inner .rd-item .rd-lbl{ font-size:10.5px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; color:#9ca3af; }
.row-detail-inner .rd-item .rd-val{ font-size:13.5px; color:#1f2937; }

/* Segmented control (Revisão Opção D — navegação de data) */
.seg-control{ display:inline-flex; align-items:stretch; border:1px solid #e5e9ef; border-radius:9px; overflow:hidden; background:#f8fafc; }
.seg-control a, .seg-control button{
  display:inline-flex; align-items:center; gap:6px; padding:0 14px; height:42px;
  border:none; background:none; color:#374151; font-size:14px; text-decoration:none; cursor:pointer;
}
.seg-control .seg-mid{ font-weight:600; min-width:140px; justify-content:center; border-left:1px solid #e5e9ef; border-right:1px solid #e5e9ef; background:#fff; }
.seg-control a:hover, .seg-control button:hover{ background:#eef2f7; }

</style>
<script>
// Botões "← Voltar": em vez de irem sempre para um destino fixo, voltam
// para a página onde o utilizador estava antes (histórico do browser).
// Se não houver página anterior dentro da aplicação (ex.: link aberto
// diretamente), usa-se o destino definido no href como rede de segurança.
function nvVoltar(ev) {
    if (window.history.length > 1) {
        ev.preventDefault();
        history.back();
    }
}
</script>
</head>


<!--ESTRUTURA DO SITE-->
<body>
<div class="sidebar" id="sidebar">
    <div class="brand">
      <button id="toggleSidebar" class="menu-toggle" type="button" aria-label="Recolher menu">
       <i class="bi bi-list"></i>
      </button>

    <img src="stockvisionAI.png" alt="Stockvision">
  </div>

  <a class="<?=active('dashboard',$page)?>" href="app.php?page=dashboard">
    <i class="bi bi-speedometer2"></i><span>Dashboard</span>
  </a>

  <a class="<?=active('inventario',$page)?>" href="app.php?page=inventario">
    <i class="bi bi-box-seam"></i><span>Inventário</span>
  </a>

  <a class="<?=active('pats',$page)?>" href="app.php?page=pats">
    <i class="bi bi-headset"></i><span>Pat's</span>
  </a>

  <a class="<?= active('envios', $page) ?>" href="app.php?page=envios">
      <i class="bi bi-truck"></i><span>Envios</span>
  </a>

  <a class="<?=active('clientes',$page)?>" href="app.php?page=clientes">
    <i class="bi bi-people"></i><span>Clientes</span>
  </a>

  <a class="<?=active('qrs',$page)?>" href="app.php?page=qrs">
    <i class="bi bi-qr-code"></i><span>QR's</span>
  </a>

  <a class="<?=active('relatorios',$page)?>" href="app.php?page=relatorios">
    <i class="bi bi-cart"></i><span>Relatórios</span>
  </a>

  <a class="<?=active('revisao',$page)?>" href="app.php?page=revisao">
    <i class="bi bi-clipboard-check"></i><span>Revisão</span>
  </a>

  <div class="sidebar-group <?= in_array($page, ['categorias','estados','parceiros','produtos']) ? 'open' : '' ?>">
  <button class="sidebar-parent" type="button" id="tabelasToggle">
    <span class="sidebar-parent-left">
      <i class="bi bi-table"></i>
      <span>Tabelas</span>
    </span>
    <i class="bi bi-chevron-down sidebar-arrow"></i>
  </button>

  <div class="sidebar-submenu">
    <a class="submenu-link <?=active('categorias',$page)?>" href="app.php?page=categorias">
      <span>Categorias</span>
    </a>
    <a class="submenu-link <?=active('estados',$page)?>" href="app.php?page=estados">
      <span>Estados</span>
    </a>
    <a class="submenu-link <?=active('parceiros',$page)?>" href="app.php?page=parceiros">
      <span>Parceiros</span>
    </a>
    <a class="submenu-link <?=active('produtos',$page)?>" href="app.php?page=produtos">
      <span>Produtos</span>
    </a>
  </div>
</div>

  <a class="<?=active('nvi',$page)?>" href="app.php?page=nvi">
    <i class="bi bi-robot"></i><span>N-Vi</span>
  </a>

  <div class="sidebar-group <?= in_array($page, ['contas', 'auditoria', 'analises']) ? 'open' : '' ?>">
  <button class="sidebar-parent" type="button" id="configToggle">
    <span class="sidebar-parent-left">
      <i class="bi bi-gear"></i>
      <span>Configurações</span>
    </span>
    <i class="bi bi-chevron-down sidebar-arrow"></i>
  </button>

  <div class="sidebar-submenu">
    <a class="submenu-link <?=active('contas',$page)?>" href="app.php?page=contas">
      <span>Contas</span>
    </a>

    <a class="submenu-link <?=active('auditoria',$page)?>" href="app.php?page=auditoria">
      <span>Auditoria</span>
    </a>

    <a class="submenu-link <?=active('analises',$page)?>" href="app.php?page=analises">
      <span>Análises</span>
    </a>
  </div>
</div>

  <div class="footer-logo">NEWVISION<br><span style="font-size:12px;font-weight:400">technology centre</span></div>
</div>

<div class="topbar">
    <div class="topbar-title"><?= htmlspecialchars($topbarTitle) ?></div>

    <div class="search-bar-wrap" id="searchBarWrap">
        <button type="button" onclick="toggleTopbarSearch()" id="searchBarBtn"
                style="display:flex;align-items:center;gap:8px;background:rgba(0,0,0,.12);border:none;color:#fff;border-radius:8px;padding:7px 14px;font-size:14px;cursor:pointer;white-space:nowrap;"
                title="Pesquisa global (Ctrl+K)">
            <i class="bi bi-search"></i>
            <span style="opacity:.8;">Pesquisar...</span>
            <span style="font-size:11px;opacity:.6;margin-left:4px;">Ctrl+K</span>
        </button>
        <div class="topbar-search-panel" id="topbarSearchPanel">
            <div class="topbar-search-input-wrap">
                <i class="bi bi-search"></i>
                <input type="text" id="globalSearchInput"
                       placeholder="Pesquisar PATs, peças por SN, parceiros..."
                       oninput="runSearch(this.value)" autocomplete="off">
                <button onclick="closeTopbarSearch()" type="button"
                        style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;font-size:16px;">✕</button>
            </div>
            <div class="search-results" id="searchResults">
                <div class="search-empty">Começa a escrever para pesquisar</div>
            </div>
            <div class="search-hint">Ctrl+K abre · Esc fecha</div>
        </div>
    </div>

    <div class="topbar-right">
        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn" type="button" onclick="toggleNotif()" title="Notificações">
                <i class="bi bi-bell"></i>
                <?php if ($totalNotif > 0): ?>
                    <span class="notif-badge"><?= $totalNotif ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-panel">
                <div class="notif-panel-header">
                    Notificações <?php if ($totalNotif > 0): ?>
                        <span style="color:#6b7280;font-weight:400;">(<?= $totalNotif ?>)</span>
                    <?php endif; ?>
                </div>
                <?php if (empty($notificacoes)): ?>
                    <div class="notif-empty">Sem notificações</div>
                <?php else: ?>
                    <?php foreach ($notificacoes as $n): ?>
                        <a class="notif-item" href="<?= $n['link'] ?>">
                            <span class="notif-dot-<?= $n['tipo'] ?>">●</span>
                            <span><?= $n['msg'] ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <button type="button" id="darkToggle" onclick="toggleDark()"
            title="Modo escuro"
            style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:6px 8px;border-radius:6px;transition:background .15s;"
            onmouseenter="this.style.background='rgba(0,0,0,.15)'"
            onmouseleave="this.style.background='none'">
            <i class="bi bi-moon-fill" id="darkIcon"></i>
        </button>

        <div class="user-dropdown" id="userDropdown">
        <button class="user-trigger" type="button" onclick="toggleUserMenu()">
            <?php if (!empty($_SESSION['user_fotografia'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['user_fotografia']) ?>" alt="Foto" class="user-avatar">
            <?php else: ?>
                <div class="user-avatar" style="display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#555;">
                    <?= strtoupper(mb_substr($_SESSION['user_nome'] ?? 'U', 0, 1)) ?>
                </div>
            <?php endif; ?>
            <span><?= htmlspecialchars($_SESSION['user_nome']) ?></span>
            <i class="bi bi-chevron-down" style="font-size:12px;opacity:.7;"></i>
        </button>
        <div class="user-dropdown-menu">
            <div class="dd-header">
                <strong><?= htmlspecialchars($_SESSION['user_nome']) ?></strong>
                <?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>
            </div>
            <a href="app.php?page=contas"><i class="bi bi-person"></i> O meu perfil</a>
            <a href="logout.php" class="danger"><i class="bi bi-box-arrow-right"></i> Sair</a>
        </div>
    </div>
    </div>
</div>

<div class="main">
<?php
// Mensagens de sucesso/erro (flash) — renderizadas SEMPRE aqui, uma única vez,
// para a página atual (a que está em $_GET['page']). Isto garante que cada
// mensagem só aparece na página para onde a ação redirecionou, e nunca fica
// pendurada na sessão para aparecer, por engano, numa página seguinte não
// relacionada (bug anterior: algumas páginas — ex. Envios, Revisão, Relatórios,
// QR's — definiam a mensagem mas nunca a mostravam nem a limpavam).
if (!empty($_SESSION['mensagem_erro']) || !empty($_SESSION['mensagem_sucesso'])) {
    echo '<div class="flash-mensagens" style="margin-bottom:16px;">';
    if (!empty($_SESSION['mensagem_erro'])) {
        echo '<div class="alerta-erro flash-msg">' . htmlspecialchars($_SESSION['mensagem_erro']) . '</div>';
        unset($_SESSION['mensagem_erro']);
    }
    if (!empty($_SESSION['mensagem_sucesso'])) {
        echo '<div class="alerta-sucesso flash-msg">' . htmlspecialchars($_SESSION['mensagem_sucesso']) . '</div>';
        unset($_SESSION['mensagem_sucesso']);
    }
    echo '</div>';
    echo '<script>
        (function(){
            document.querySelectorAll(".flash-msg").forEach(function(el){
                setTimeout(function(){
                    el.style.transition = "opacity .4s ease";
                    el.style.opacity = "0";
                    setTimeout(function(){ el.remove(); }, 400);
                }, 5000);
            });
        })();
    </script>';
}
?>
<?php
// Banner do Laboratório: avisa os técnicos de peças paradas há +15 dias
if (($_SESSION['user_area'] ?? '') === 'Laboratorio') {
    require_once __DIR__ . '/includes/pecas_suspeitas.php';
    $pecasLab = nvPecasSuspeitas($pdo, ['apenas_estado' => 'Laboratório', 'dias' => 15]);
    if ($pecasLab) {
        $n = count($pecasLab);
        echo '<div class="alerta-erro" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">'
           . '<span><strong>' . $n . ' peça(s)</strong> no Laboratório paradas há +15 dias.</span>'
           . '<a href="app.php?page=revisao" style="color:inherit;font-weight:600;text-decoration:underline;">Rever agora →</a>'
           . '</div>';
    }
}
?>
<?php if ($page === 'dashboard'): ?>
  <?php require __DIR__ . '/includes/pages/dashboard.php'; ?>
<?php elseif ($page === 'inventario'): ?>
  <?php require __DIR__ . '/includes/pages/inventario.php'; ?>
<?php elseif ($page === 'nova_peca'): ?>
  <?php require __DIR__ . '/includes/pages/nova_peca.php'; ?>
<?php elseif ($page === 'historico'): ?>
  <?php require __DIR__ . '/includes/pages/historico.php'; ?>
<?php elseif ($page === 'envios'): ?>
  <?php require __DIR__ . '/includes/pages/envios.php'; ?>
<?php elseif ($page === 'qrs'): ?>
  <?php require __DIR__ . '/includes/pages/qrs.php'; ?>
<?php elseif ($page === 'contas'): ?>
  <?php require __DIR__ . '/includes/pages/contas.php'; ?>
<?php elseif ($page === 'auditoria'): ?>
  <?php require __DIR__ . '/includes/pages/auditoria.php'; ?>
<?php elseif ($page === 'clientes'): ?>
  <?php require __DIR__ . '/includes/pages/clientes.php'; ?>
<?php elseif ($page === 'pats'): ?>
  <?php require __DIR__ . '/includes/pages/pats.php'; ?>
<?php elseif ($page === 'relatorios'): ?>
  <?php require __DIR__ . '/includes/pages/relatorios.php'; ?>
<?php elseif ($page === 'categorias'): ?>
  <?php require __DIR__ . '/includes/pages/categorias.php'; ?>
<?php elseif ($page === 'estados'): ?>
  <?php require __DIR__ . '/includes/pages/estados.php'; ?>
<?php elseif ($page === 'produtos'): ?>
  <?php require __DIR__ . '/includes/pages/produtos.php'; ?>
<?php elseif ($page === 'parceiros'): ?>
  <?php require __DIR__ . '/includes/pages/parceiros.php'; ?>
<?php elseif ($page === 'nvi'): ?>
  <?php require __DIR__ . '/includes/pages/nvi.php'; ?>
<?php elseif ($page === 'revisao'): ?>
  <?php require __DIR__ . '/includes/pages/revisao.php'; ?>
<?php elseif ($page === 'analises'): ?>
  <?php require __DIR__ . '/includes/pages/analises.php'; ?>
<?php elseif ($page === 'etiqueta'): ?>
  <?php require __DIR__ . '/includes/pages/etiqueta.php'; ?>
<?php elseif ($page === 'peca'): ?>
  <?php require __DIR__ . '/includes/pages/peca.php'; ?>
<?php else: ?>
  <h1 class="section-title"><?=ucfirst($page)?></h1>
  <div class="panel">Módulo em preparação.</div>
<?php endif; ?>
</div>






<!--Java Script-->
<script>
const estadoLabels = <?= json_encode(array_column($estadoData,'estado')) ?>;
const estadoTotals = <?= json_encode(array_map('intval', array_column($estadoData,'total'))) ?>;
const categoriaLabels = <?= json_encode(array_column($categoriaData,'categoria')) ?>;
const categoriaTotals = <?= json_encode(array_map('intval', array_column($categoriaData,'total'))) ?>;
const parceiroLabels = <?= json_encode(array_column($parceiroData,'parceiro')) ?>;
const parceiroTotals = <?= json_encode(array_map('intval', array_column($parceiroData,'total'))) ?>;
const trendLabels = <?= json_encode(array_column($trendRows,'mes')) ?>;
const trendPecas = <?= json_encode(array_map('intval', array_column($trendRows,'total'))) ?>;
const trendPats = <?= json_encode(array_map('intval', array_column($patTrendRows,'total'))) ?>;

const estadoColors = {
  'Disponível': '#28a745',
  'PAT': '#6f42c1',
  'Laboratório': '#2470dc',
  'Abater': '#dc3545',
  'Cliente': '#20c997',
  'Desconhecido': '#ffc107',
  'Devolução': '#17a2b8',
  'Fornecedor(Reparação)': '#fd7e14',
  'OT': '#495057',
  'Parceiro': '#8c564b',
  'Spares':'#47372A'
};

const estadoChartColors = estadoLabels.map(label => estadoColors[label] || '#6c757d');

const palette = [
  '#1f8f5f',
  '#2470dc',
  '#6f42c1',
  '#dc3545',
  '#20c997',
  '#ffc107',
  '#fd7e14',
  '#495057',
  '#cba35c',
  '#2ca59a',
  '#6c757d',
  '#17a2b8'
];

if (document.getElementById('estadoChart')) {
  new Chart(document.getElementById('estadoChart'), {
    type: 'doughnut',
    data: {
      labels: estadoLabels,
      datasets: [{
        data: estadoTotals,
        backgroundColor: estadoChartColors,
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

if (document.getElementById('atividadeMensalChart')) {
  new Chart(document.getElementById('atividadeMensalChart'), {
    data: {
      labels: trendLabels,
      datasets: [
        {
          type: 'bar',
          label: 'Peças',
          data: trendPecas,
          backgroundColor: 'rgba(54,162,235,.55)',
          borderRadius: 6,
          order: 2
        },
        {
          type: 'line',
          label: 'PATs',
          data: trendPats,
          borderColor: '#ff6384',
          backgroundColor: '#ff6384',
          tension: .35,
          pointRadius: 4,
          pointBackgroundColor: '#ff6384',
          order: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } }
      },
      plugins: {
        legend: { display: true, position: 'top', align: 'end' }
      }
    }
  });
}

if (document.getElementById('topParceirosChart')) {
  new Chart(document.getElementById('topParceirosChart'), {
    type: 'bar',
    data: {
      labels: parceiroLabels,
      datasets: [{
        label: 'Peças',
        data: parceiroTotals,
        backgroundColor: palette,
        borderRadius: 6
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { beginAtZero: true, ticks: { precision: 0 } }
      },
      plugins: {
        legend: { display: false }
      }
    }
  });
}
</script>

<script>
    function closeMobileSidebar() {
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('sidebarOverlay');
        if (sb) sb.classList.remove('mobile-open');
        if (ov) ov.classList.remove('visible');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const sidebar       = document.getElementById('sidebar');
        const toggleSidebar = document.getElementById('toggleSidebar');
        const topbar        = document.querySelector('.topbar');
        const main          = document.querySelector('.main');

        // Restaurar estado guardado
        if (sidebar && topbar && main && localStorage.getItem('sv_sidebar') === 'collapsed') {
            sidebar.classList.add('collapsed');
            topbar.classList.add('collapsed');
            main.classList.add('collapsed');
        }

        if (sidebar && toggleSidebar && topbar && main) {
            toggleSidebar.addEventListener('click', function() {
                const isMobile = window.innerWidth <= 768;
                if (isMobile) {
                    sidebar.classList.toggle('mobile-open');
                    const ov = document.getElementById('sidebarOverlay');
                    if (ov) ov.classList.toggle('visible');
                } else {
                    sidebar.classList.toggle('collapsed');
                    topbar.classList.toggle('collapsed');
                    main.classList.toggle('collapsed');
                    // Guardar estado
                    localStorage.setItem('sv_sidebar',
                        sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded'
                    );
                }
            });
        }
    });
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnLimpar = document.getElementById('btnLimparAuditoria');
    const form = document.getElementById('auditoriaFiltrosForm');

    if (btnLimpar && form) {
        btnLimpar.addEventListener('click', function () {
            window.location.href = 'app.php?page=auditoria';
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const configToggle = document.getElementById('configToggle');
  const configGroup = configToggle.closest('.sidebar-group');
  const sidebar = document.getElementById('sidebar');

  if (configToggle && configGroup && sidebar) {
    configToggle.addEventListener('click', function () {
      if (!sidebar.classList.contains('collapsed')) {
        configGroup.classList.toggle('open');
      }
    });
  }
  const enviosToggle = document.getElementById('enviosToggle');
  const enviosGroup = document.getElementById('enviosGroup');

  if (enviosToggle && enviosGroup && sidebar) {
    enviosToggle.addEventListener('click', function () {
      if (!sidebar.classList.contains('collapsed')) {
        enviosGroup.classList.toggle('open');
      }
    });
  }

  const tabelasToggle = document.getElementById('tabelasToggle');
  const tabelasGroup = tabelasToggle ? tabelasToggle.closest('.sidebar-group') : null;

  if (tabelasToggle && tabelasGroup && sidebar) {
    tabelasToggle.addEventListener('click', function () {
      if (!sidebar.classList.contains('collapsed')) {
        tabelasGroup.classList.toggle('open');
      }
    });
  }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const categoriaSelect = document.getElementById('categoria');
  const produtoSelect = document.getElementById('produto');

  if (!categoriaSelect || !produtoSelect) return;

  const catalogoProdutos = <?= json_encode($catalogoProdutos, JSON_UNESCAPED_UNICODE) ?>;
  const produtoSelecionadoInicial = "<?= htmlspecialchars($valorProduto ?? '', ENT_QUOTES) ?>";

  function atualizarProdutos() {
    const categoria = categoriaSelect.value;
    const produtos = catalogoProdutos[categoria] || [];
    const valorAtual = produtoSelect.value;

    produtoSelect.innerHTML = '<option value="">-- Selecione o produto --</option>';

    produtos.forEach(function (produto) {
      const option = document.createElement('option');
      option.value = produto;
      option.textContent = produto;

      if (produto === valorAtual || produto === produtoSelecionadoInicial) {
        option.selected = true;
      }

      produtoSelect.appendChild(option);
    });
  }

  categoriaSelect.addEventListener('change', function () {
    atualizarProdutos();
  });

  atualizarProdutos();
});
</script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const snInput = document.getElementById('sn');
    const codInput = document.getElementById('cod_barras');
    const copiarBtn = document.getElementById('copiarSnBtn');
    const feedback = document.getElementById('copySnFeedback');

    if (!snInput || !codInput || !copiarBtn) return;

    copiarBtn.addEventListener('click', function () {
      const valorSn = snInput.value.trim();

      if (!valorSn) {
        alert('Primeiro preencher o Número de Série.');
        snInput.focus();
        return;
      }

      codInput.value = valorSn;
      codInput.focus();

      if (feedback) {
        feedback.style.display = 'block';
        setTimeout(function () {
        feedback.style.display = 'none';
      }, 2000);
    }
  });
});
</script>

 
<script>
document.addEventListener('DOMContentLoaded', function () {
  const readerEl = document.getElementById('reader');
  const qrInput = document.getElementById('qr_code');

  if (!readerEl || !qrInput || typeof Html5Qrcode === 'undefined') return;

  const html5QrCode = new Html5Qrcode("reader");
  let leituraFeita = false;

  Html5Qrcode.getCameras().then(cameras => {
    if (!cameras || !cameras.length) return;

    const cameraId = cameras[0].id;

    html5QrCode.start(
      cameraId,
      { fps: 10, qrbox: 220 },
      function (decodedText) {
        if (leituraFeita) return;
        leituraFeita = true;

        qrInput.value = decodedText;

        html5QrCode.stop().then(() => {
          qrInput.form.submit();
        }).catch(() => {
          qrInput.form.submit();
        });
      },
      function () {
      }
    );
  }).catch(() => {
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const input = document.getElementById('fotografiaInput');
  const cropArea = document.getElementById('cropArea');
  const cropPreview = document.getElementById('cropPreview');
  const aplicarBtn = document.getElementById('aplicarCropBtn');
  const cancelarBtn = document.getElementById('cancelarCropBtn');
  const hiddenInput = document.getElementById('fotografia_cropada');

  if (!input || !cropArea || !cropPreview || !aplicarBtn || !cancelarBtn || !hiddenInput) return;

  let cropper = null;

  input.addEventListener('change', function (e) {
  const file = e.target.files[0];
  if (!file) return;

  if (!file.type.startsWith('image/')) {
    alert('Seleciona um ficheiro válido.');
    input.value = '';
    return;
  }

  const reader = new FileReader();
  reader.onload = function (event) {
    cropPreview.src = event.target.result;
    cropArea.style.display = 'block';

    if (cropper) {
      cropper.destroy();
    }

    cropper = new Cropper(cropPreview, {
      aspectRatio: 1,
      viewMode: 1,
      autoCropArea: 1,
      dragMode: 'move'
    });
  };

  reader.readAsDataURL(file);
  });

  aplicarBtn.addEventListener('click', function () {
    if (!cropper) return;

    const canvas = cropper.getCroppedCanvas({
      width: 400,
      height: 400
    });

    hiddenInput.value = canvas.toDataURL('image/jpeg', 0.9);
    cropArea.style.display = 'none';
  });

  cancelarBtn.addEventListener('click', function () {
    if (cropper) {
      cropper.destroy();
      cropper = null;
    }

    input.value = '';
    cropPreview.src = '';
    hiddenInput.value = '';
    cropArea.style.display = 'none';
  });
});
</script>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const wrap = document.getElementById('linhasEnvioWrap');
    const btn = document.getElementById('adicionarLinhaEnvio');

    if (!wrap || !btn) return;

    btn.addEventListener('click', function () {
      const linha = document.createElement('div');
      linha.className = 'linha-envio-grid';
      linha.innerHTML = `
        <input type="text" name="linha_artigo[]" placeholder="Artigo" value="ASSISTENCIA" required>
        <input type="text" name="linha_designacao[]" placeholder="Designação" required>
        <input type="number" step="0.01" min="0" name="linha_quantidade[]" placeholder="Qtd." value="1.00" required>
        <input type="text" name="linha_num_series[]" placeholder="Nº Série">
        `;
        wrap.appendChild(linha);
    });
  });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const catalogoProdutos = <?= json_encode($catalogoProdutos, JSON_UNESCAPED_UNICODE) ?>;
        const wrap = document.getElementById('linhasEnvioWrap');
        const btn = document.getElementById('adicionarLinhaEnvio');

        function atualizarProdutosLinha(linha) {
            const categoriaSelect = linha.querySelector('.linha-categoria');
            const produtoSelect = linha.querySelector('.linha-produto');
        if(!categoriaSelect || !produtoSelect) return;

            const categoria = categoriaSelect.value;
            const produtos = catalogoProdutos[categoria] || [];
            const valorAtual = produtoSelect.dataset.select || '';

            produtoSelect.innerHTML = '<option value"">-- Nome da Peça --</option>';

            produtos.forEach(function (produto) {
                const option = document.createElement('option');
                option.value = produto;
                option.textContent = produto;
            if (produto === valorAtual) {
                option.selected = true;
            }
            produtoSelected.appendChild(option);
            });
        }

        function bindLinha(linha) {
            const categoriaSelect = linha.querySelector('.linha-categoria');
            if (!categoriaSelect) return;

            categoriaSelect.addEventListener('change', function () {
                const produtoSelect = linha.querySelector('.linha-produto');
                if (produtoSelect) {
                    produtoSelect.dataset.selected = '';
                }
                atualizarProdutosLinha(linha);
            });

            atualizarProdutosLinha(linha);
        }

        if (wrap) {
            wrap.querySelectorAll('.linha-envio-grid').forEach(bindLinha);
        }

        if (btn && wrap) {
            btn.addEventListener('click', function () {
                const linha = document.createElement('div');
                linha.className = 'linha-envio-grid';
                linha.innerHTML = `
                <select name="linha_categoria[]" class="linha-categoria" required>
                <option value="">-- Tipo --</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
                </select>

                <select name="linha_produto[]" class="linha-produto" required>
                    <option value="">-- Nome da Peça --</option>
                </select>

                <input type="number" step="0.01" min="0" name="linha_quantidade[]" placeholder="Qtd." value="1.00" required>
                <input type="text" name="linha_num_series[]" placeholder="Nº Série"> 
                `;
                wrap.appendChild(linha);
                blindLinha(linha);
            });
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const dataInput = document.getElementById('data_documento');
        if (!dataInput) return;

        dataInput.addEventListener('click', function () {
            if (typeof dataInput.showPicker === 'function') {
                dataInput.showPicker();
            }
        });

        dataInput.addEventListener('focus', function () {
            if (typeof dataInput.showPicker === 'function') {
                dataInput.showPicker();
            }
        });
    });
</script>

<?php if ($page === 'envios'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const catalogoInventarioReal = <?= json_encode($catalogoInventarioReal, JSON_UNESCAPED_UNICODE) ?>;
    const wrap = document.getElementById('linhasEnvioWrap');
    const btnAdicionar = document.getElementById('adicionarLinhaEnvio');
    const dataInput = document.getElementById('data_documento');
    const documentoSelect = document.getElementById('documento_envio');
    const parceiroSelect = document.getElementById('parceiro_envio');
    const parceirosInventario = <?= json_encode(array_values($parceirosInventario), JSON_UNESCAPED_UNICODE) ?>;

    if (dataInput) {
        dataInput.addEventListener('click', function () {
            if (typeof dataInput.showPicker === 'function') dataInput.showPicker();
        });

        dataInput.addEventListener('focus', function () {
            if (typeof dataInput.showPicker === 'function') dataInput.showPicker();
        });
    }

    function normalizarSn(sn) {
        return (sn || '').toUpperCase().replace(/[^A-Z0-9]/g, '').trim();
    }

    function similaridadeSimples(a, b) {
        a = normalizarSn(a);
        b = normalizarSn(b);

        if (!a || !b) return 0;
        if (a === b) return 100;

        let iguais = 0;
        const minLen = Math.min(a.length, b.length);

        for (let i = 0; i < minLen; i++) {
            if (a[i] === b[i]) iguais++;
        }

        return Math.round((iguais / Math.max(a.length, b.length)) * 100);
    }

    function aplicarRegraParceiro() {
        if (!documentoSelect || !parceiroSelect) return;

        if (documentoSelect.value === 'G.Transp Cliente') {
            parceiroSelect.innerHTML = '<option value="Field Service" selected>Field Service</option>';
            parceiroSelect.value = 'Field Service';
            parceiroSelect.setAttribute('readonly', 'readonly');
            parceiroSelect.setAttribute('data-mode', 'cliente');
        } else if (documentoSelect.value === 'G. Transp Fornec') {
            if (parceiroSelect.getAttribute('data-mode') === 'cliente') {
                parceiroSelect.innerHTML = '<option value="">-- Selecione o Parceiro --</option>';
                parceirosInventario.forEach(function (parceiro) {
                    const option = document.createElement('option');
                    option.value = parceiro;
                    option.textContent = parceiro;
                    parceiroSelect.appendChild(option);
                });
                parceiroSelect.removeAttribute('readonly');
                parceiroSelect.setAttribute('data-mode', 'fornecedor');
            }
        }
    }

    if (documentoSelect && parceiroSelect) {
        documentoSelect.addEventListener('change', aplicarRegraParceiro);
        aplicarRegraParceiro();
    }

    if (!wrap) return;

    function criarOpcoesCategoria() {
        return `<?php foreach ($categoriasInventarioReal as $cat): ?><option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat, ENT_QUOTES) ?></option><?php endforeach; ?>`;
    }

    function atualizarProdutosLinha(linha) {
        const categoriaSelect = linha.querySelector('.linha-categoria');
        const produtoSelect = linha.querySelector('.linha-produto');
        if (!categoriaSelect || !produtoSelect) return;

        const categoria = categoriaSelect.value;
        const produtos = catalogoInventarioReal[categoria] || [];
        const selecionado = produtoSelect.dataset.selected || produtoSelect.value || '';

        produtoSelect.innerHTML = '<option value="">-- Nome da Peça --</option>';

        produtos.forEach(function (produto) {
            const option = document.createElement('option');
            option.value = produto;
            option.textContent = produto;
            if (produto === selecionado) option.selected = true;
            produtoSelect.appendChild(option);
        });
    }

    function validarSnSemelhante(linhaAtual) {
        const inputAtual = linhaAtual.querySelector('.linha-num-serie');
        const avisoBox = linhaAtual.querySelector('.sn-avisos');
        if (!inputAtual || !avisoBox) return;

        const valorAtual = inputAtual.value || '';
        const snAtual = normalizarSn(valorAtual);
        avisoBox.innerHTML = '';

        if (!snAtual) return;

        const todasLinhas = wrap.querySelectorAll('.linha-envio-grid');
        const avisos = [];

        todasLinhas.forEach(function (linha, index) {
            if (linha === linhaAtual) return;

            const outroInput = linha.querySelector('.linha-num-serie');
            const outroValor = outroInput ? outroInput.value : '';
            const score = similaridadeSimples(snAtual, outroValor);

            if (score >= 80 && normalizarSn(outroValor) !== '') {
                avisos.push(`Semelhante ao SN da linha ${index + 1} (${score}%).`);
            }
        });

        avisos.forEach(function (texto) {
            const div = document.createElement('div');
            div.className = 'small-note';
            div.style.color = '#b26a00';
            div.textContent = texto;
            avisoBox.appendChild(div);
        });
    }

    function bindLinha(linha) {
        if (!linha || linha.dataset.bound === '1') return;

        const categoriaSelect = linha.querySelector('.linha-categoria');
        const produtoSelect = linha.querySelector('.linha-produto');
        const snInput = linha.querySelector('.linha-num-serie');

        if (categoriaSelect && produtoSelect) {
            categoriaSelect.addEventListener('change', function () {
                produtoSelect.dataset.selected = '';
                atualizarProdutosLinha(linha);
            });

            atualizarProdutosLinha(linha);
        }

        if (snInput) {
            snInput.addEventListener('input', function () {
                validarSnSemelhante(linha);
            });

            validarSnSemelhante(linha);
        }

        linha.dataset.bound = '1';
    }

    function criarNovaLinhaEnvio() {
        const linha = document.createElement('div');
        linha.className = 'linha-envio-grid';
        linha.innerHTML = `
            <select name="linha_categoria[]" class="linha-categoria" required>
                <option value="">-- Tipo --</option>
                ${criarOpcoesCategoria()}
            </select>

            <select name="linha_produto[]" class="linha-produto" data-selected="" required>
                <option value="">-- Nome da Peça --</option>
            </select>

            <input type="number" step="1" min="1" name="linha_quantidade[]" value="1" required>

            <input type="text" name="linha_num_serie[]" class="linha-num-serie" placeholder="Nº Série">

            <div class="sn-avisos"></div>
        `;

        wrap.appendChild(linha);
        bindLinha(linha);
    }

    wrap.querySelectorAll('.linha-envio-grid').forEach(function (linha) {
        bindLinha(linha);
    });

    if (btnAdicionar) {
        btnAdicionar.addEventListener('click', function () {
            criarNovaLinhaEnvio();
        });
    }
});
</script>
<?php endif; // page === 'envios' ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggles = document.querySelectorAll('.cliente-toggle');

    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.getAttribute('data-target');
            if (!target) return;

            const rows = document.querySelectorAll('.' + CSS.escape(target));
            const isOpen = btn.classList.contains('is-open');

            rows.forEach(function (row) {
                row.style.display = isOpen ? 'none' : 'table-row';
            });

            btn.classList.toggle('is-open', !isOpen);
            btn.textContent = isOpen ? '+' : '−';
        });
    });
});

// Expandir/colapsar todas as contas-filho da página Clientes de uma só vez.
function nvClientesExpandirTudo(abrir) {
    document.querySelectorAll('.cliente-toggle').forEach(function (btn) {
        const target = btn.getAttribute('data-target');
        if (!target) return;
        document.querySelectorAll('.' + CSS.escape(target)).forEach(function (row) {
            row.style.display = abrir ? 'table-row' : 'none';
        });
        btn.classList.toggle('is-open', abrir);
        btn.textContent = abrir ? '−' : '+';
    });
}
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        // ── Linhas dinâmicas genéricas ─────────────────────────
        function clonarUltimaLinha(tabela) {
            const tbody = tabela.querySelector('tbody');
            const linhas = tbody.querySelectorAll('tr');
            if (!linhas.length) return;
            const nova = linhas[linhas.length - 1].cloneNode(true);
            nova.querySelectorAll('input').forEach(function (inp) { inp.value = inp.type === 'number' ? 1 : ''; });
            tbody.appendChild(nova);
            bindRemover(nova);
        }

        function bindRemover(linha) {
            const btn = linha.querySelector('.btn-remover-linha');
            if (btn) btn.addEventListener('click', function () {
                const tbody = linha.parentElement;
                if (tbody.querySelectorAll('tr').length > 1) {
                    linha.remove();
                }
            });
        }

        // Bind remover nas linhas já existentes
        document.querySelectorAll('#tabelaModulos tbody tr, #tabelaComponentes tbody tr').forEach(bindRemover);

        // Botões de adicionar linha — Módulos
        const btnMod = document.querySelector('.btn-add-modulo');
        const tabelaMod = document.getElementById('tabelaModulos');
        if (btnMod && tabelaMod) {
            btnMod.addEventListener('click', function () { clonarUltimaLinha(tabelaMod); });
        }

        // Botões de adicionar linha — Componentes
        const btnComp = document.querySelector('.btn-add-comp');
        const tabelaComp = document.getElementById('tabelaComponentes');
        if (btnComp && tabelaComp) {
            btnComp.addEventListener('click', function () { clonarUltimaLinha(tabelaComp); });
        }
    });

    function toggleAcao(btn) {
        const wrap = btn.closest('.acao-wrap');
        const isOpen = wrap.classList.contains('open');
        // Fechar todos os outros Menus
        document.querySelectorAll('.acao-wrap.open').forEach(w => w.classList.remove('open'));
        if (!isOpen) wrap.classList.add('open');
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.acao-wrap')) {
            document.querySelectorAll('.acao-wrap.open').forEach(w => w.classList.remove('open'));
        }
    });

    // Filtro rápido (lado do cliente) para tabelas com caixa de pesquisa instantânea.
    // Uso: <input class="quick-search-input" data-table="#idDaTabela" data-empty="#idEstadoVazio">
    function nvFiltrarTabela(input) {
        const tabelaSel = input.getAttribute('data-table');
        const tabela = document.querySelector(tabelaSel);
        if (!tabela) return;
        const termo = input.value.trim().toLowerCase();
        const linhas = tabela.querySelectorAll('tbody tr');
        let visiveis = 0;
        linhas.forEach(function (tr) {
            if (tr.hasAttribute('data-no-filter')) { return; }
            const texto = tr.textContent.toLowerCase();
            const mostra = termo === '' || texto.includes(termo);
            tr.style.display = mostra ? '' : 'none';
            if (mostra) visiveis++;
        });
        const emptySel = input.getAttribute('data-empty');
        if (emptySel) {
            const emptyEl = document.querySelector(emptySel);
            if (emptyEl) emptyEl.style.display = (visiveis === 0 && termo !== '') ? '' : 'none';
        }
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.quick-search-input').forEach(function (input) {
            input.addEventListener('input', function () { nvFiltrarTabela(input); });
        });
    });
</script>

<script>
    // Dropdown Utilizador
    function toggleUserMenu() {
        document.getElementById('userDropdown').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('userDropdown');
        if (dd && !dd.contains(e.target)) dd.classList.remove('open');
    })
</script>

<script>
//Modo Escuro
function toggleDark() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('sv_dark', isDark ? '1' : '0');
    document.getElementById('darkIcon').className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
}
(function() {
    if (localStorage.getItem('sv_dark') === '1') {
        document.body.classList.add('dark-mode');
        const icon = document.getElementById('darkIcon');
        if (icon) icon.className = 'bi bi-sun-fill';
    }
})();
</script>

<script>
    function toggleNotif() {
        const wrap = document.getElementById('notifWrap');
        const isOpen = wrap.classList.contains('open');
        document.querySelectorAll('.notif-wrap.open, .user-dropdown.open').forEach(el => el.classList.remove('open'));
        if (!isOpen) wrap.classList.add('open');
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#notifWrap')) {
            const w = document.getElementById('notifWrap');
            if (w) w.classList.remove('open');
        }
    });
</script>

<script>
    function toggleTopbarSearch() {
        const panel = document.getElementById('topbarSearchPanel');
        const input = document.getElementById('globalSearchInput');
        const isOpen = panel.classList.contains('open');
        if (isOpen) {
            closeTopbarSearch();
        } else {
            panel.classList.add('open');
            setTimeout(() => input && input.focus(), 50);
        }
    }
    function closeTopbarSearch() {
        const panel = document.getElementById('topbarSearchPanel');
        const input = document.getElementById('globalSearchInput');
        if (panel) panel.classList.remove('open');
        if (input) input.value = '';
        const el = document.getElementById('searchResults');
        if (el) el.innerHTML = '<div class="search-empty">Começa a escrever para pesquisar</div>';
    }
    let searchTimer;
    function runSearch(q) {
        clearTimeout(searchTimer);
        if (q.length < 2) {
            document.getElementById('searchResults').innerHTML = '<div class="search-empty">Começa a escrever para pesquisar</div>';
            return;
        }
        searchTimer = setTimeout(async () => {
            const res  = await fetch('search_api.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            const el   = document.getElementById('searchResults');
            if (!data.length) {
                el.innerHTML = '<div class="search-empty">Sem resultados para "' + q + '"</div>';
                return;
            }
            el.innerHTML = data.map(r =>
                `<a class="search-result-item" href="${r.url}">
                <i class="bi ${r.icon}"></i>
                <span>${r.label}</span>
                <span class="search-result-type">${r.type}</span>
            </a>`
            ).join('');
        }, 200);
    }
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); toggleTopbarSearch(); }
        if (e.key === 'Escape') closeTopbarSearch();
    });
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('searchBarWrap');
        const panel = document.getElementById('topbarSearchPanel');
        if (panel && panel.classList.contains('open') && wrap && !wrap.contains(e.target)) {
            closeTopbarSearch();
        }
    });
</script>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>


<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:22px;max-width:380px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.2);">
        <p id="confirmMsg" style="margin:0 0 18px;font-size:15px;"></p>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" class="btn btn-grey" id="confirmNo">Cancelar</button>
            <button type="button" class="btn btn-red"  id="confirmYes">Confirmar</button>
        </div>
    </div>
</div>
<script>
    let _formAConfirmar = null;
    function nvConfirmar(form, msg){
        _formAConfirmar = form;
        document.getElementById('confirmMsg').textContent = msg || 'Tens a certeza?';
        document.getElementById('confirmModal').style.display = 'flex';
        return false; // impede o submit imediato
    }
    document.getElementById('confirmYes').addEventListener('click', () => {
        document.getElementById('confirmModal').style.display = 'none';
        if (_formAConfirmar) _formAConfirmar.submit();
    });
    document.getElementById('confirmNo').addEventListener('click', () => {
        document.getElementById('confirmModal').style.display = 'none';
        _formAConfirmar = null;
    });
</script>


</body>
</html>
<?php ob_end_flush(); ?>
