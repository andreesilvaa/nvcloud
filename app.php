<?php

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
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ============================================================
// 2. FUNÇÕES AUXILIARES GERAIS
// ============================================================

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
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

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$pecaEdit = null;

if ($page === 'nova_peca' && $editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmt->execute([$editId]);
    $pecaEdit = $stmt->fetch();

    if (!$pecaEdit) {
      $_SESSION['mensagem_erro'] = 'Peça não encontrada.';
      header('Location: app.php?page=inventario');
      exit;
    }
}

// ============================================================
// 5. DADOS FIXOS DA APLICAÇÃO
// ============================================================

$pageTitles = [
  'dashboard' => 'Dashboard',
  'alertas' => 'Clientes',
  'inventario' => 'Inventário',
  'inventory' => 'Inventário',
  'nova_peca' => 'Nova Peça',
  'historico' => 'Histórico da Peça',
  'pats' => "Pat's",
  'envios' => 'Envios',
  'qrs' => "QR's",
  'qr' => "QR's",
  'encomendas' => 'Encomendas',
  'contas' =>'Contas',
  'auditoria' => 'Auditoria',
  'categorias' => 'Categorias',
  'estados' => 'Estados',
  'parceiros' => 'Parceiros',
  'fabricantes' => 'Fabricantes',
  'produtos' => 'Produtos',
  'nvi' => 'N-Vi',
  'configuracoes' => 'Configurações',
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

/* ----------------------------------------------------------------
   Listas antigas (hardcoded). Substituídas pelas tabelas da BD acima.
   Mantidas comentadas apenas como referência histórica.
$catalogoProdutosAntigo = [
    'Acetato',
    'Botões',
    'Botões WiFi',
    'Box Android',
    'Cabeçote Prima',
    'Cabeçote Proxima',
    'Cabeçote Vision',
    'Carta Controladora',
    'Cofre',
    'Dispensadora Prima',
    'Fonte de Alimentação',
    'Impressora',
    'Leitor de Cartões',
    'Mini PC',
    'Moedeiro',
    'Monitor',
    'Noteiro',
    'PC Windows',
    'Pinpad',
    'Router',
    'Selador 220V',
    'Transformador',
    'UPS',
    'Video Extender'
];

$catalogoProdutosAntigo2 = [
    'Acetato' => ['Acetatos Prima 12 (26 UNIDADES)',],
    'Botões' => [ 'eGo',],
    'Botões WiFi' => ['Botão WiFi',],
    'Box Android' => ['Box ETE3399',
                      'Box KP8-YB1',
                      'Box H068',
                      'Box D039',],
    'Cabeçote Prima' => ['Prima 12',
                         'Prima 15',],
    'Cabeçote Proxima' => ['Proxima',
                           'Proxima CGD',
                           'Proxima Unilabs',
                           'Proxima EPAL',
                           'Proxima TML',
                           'Proxima Windows',],
    'Cabeçote Vision' => ['Vision WiFi',
                          'Vision Ethernet',],
    'Carta Controladora' =>['Controladora Genérica',],
    'Cofre' =>['Echarge',
                'WBA',],
    'Dispensadora Prima' => ['Prima Teclas Vodafone',],
    'Fonte de Alimentação' =>['Fonte/UPS',
                              'Fonte Proxima',
                              'Fonte 24V Prateada',],
    'Impressora' => ['Nippon K3053',
                    'Echarge 80mm',
                    'Prima 12',
                    'Prima 15',
                    'Prima Teclas',],
    'Leitor de Cartões' => ['Leitor U900',
                            'Leitor SPU90',
                            'Leitor Spire',],
    'Mini PC' => ['D039',
                  'N105',],
    'Moedeiro' => ['Smart Hopper Recycler',
                   'Smart Hopper Validator',],
    'Monitor' => ['Seleniko Touch',
                  'LCD LD 32"',
                  'Hisense 40"',
                  'LCD Hisense 40"',
                  'Hisense TV 50"',
                  'LED 55" Profissional',
                  'KEE Touch 17"',
                  'MSM Box',
                  'RVM 10"',
                  'General Touch 17"',
                  'KEE Touch 19"',
                  'Hisense 43"',],
    'Noteiro' =>['UBA',
                 'Echarge',],
    'PC Windows' => ['Insys KP1-AB5',
                     'Giada F108D',
                     'Hard PC',
                     'IP4-NB20',
                     'IP7-T09',
                     'Prima Asus 410',
                     'Prima Asus 610',
                     'Prima Intel DG41',],
    'Pinpad' => ['U900',
                 'Spire',
                 'Ingénico',],
    'Router' => ['D-Link Eagle N300',
                 'TP-Link 4G',],
    'Selador 220V' => ['DepositVision',
                       '220V',],
    'Transformador' => ['Fonte/UPS',],
    'UPS' =>['UPS/APC',
             'Fonte/UPS',],
    'Video Extender' =>['VGA',
                        'VGA-JHA',
                        'Digitus HDMI DS-55529',
                        'VGA VE02ALR c/Transformador',],
];
---------------------------------------------------------------- */

// ============================================================
// 6. VALORES TEMPORÁRIOS DOS FORMULÁRIOS
// ============================================================

$formNovaPeca = $_SESSION['form_nova_peca'] ?? [];
unset($_SESSION['form_nova_peca']);

$valorCategoria = $formNovaPeca['categoria'] ?? ($pecaEdit['categoria'] ?? '');
$valorProduto = $formNovaPeca['produto'] ?? ($pecaEdit['produto'] ?? '');
$valorParceiro = $formNovaPeca['parceiro'] ?? ($pecaEdit['parceiro'] ?? '');
$valorEstado = $formNovaPeca['estado'] ?? ($pecaEdit['estado'] ?? '');
$valorSn = $_GET['sn'] ?? ($formNovaPeca['sn'] ?? ($pecaEdit['sn'] ?? ''));
$valorCodBarras = $_GET['cod_barras'] ?? ($formNovaPeca['cod_barras'] ?? ($pecaEdit['cod_barras'] ?? ''));

// ============================================================
// 7. PROCESSAMENTO POST: CRIAR / EDITAR PEÇA
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'nova_peca') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

    $categoria = trim($_POST['categoria'] ?? '');
    $produto = trim($_POST['produto'] ?? '');
    $sn = trim($_POST['sn'] ?? '');
    $cod_barras = trim($_POST['cod_barras'] ?? '');
    $parceiro = trim($_POST['parceiro'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
      if (!in_array($estado, $estados, true)){
        $_SESSION['mensagem_erro'] = 'O estado selecionado não é válido.';
        $_SESSION['form_nova_peca'] = $_POST;
        header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
        exit;
      }

    if (
        $categoria === '' ||
        !isset($catalogoProdutos[$categoria]) ||
        $produto === '' ||
        !in_array($produto, $catalogoProdutos[$categoria], true)
    ) {
        $_SESSION['mensagem_erro'] = 'A categoria e o produto selecionados não são válidos.';
        $_SESSION['form_nova_peca'] = $_POST;
         header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
          exit;
    }

    if ($editId > 0) {
        $stmtCheck = $pdo->prepare("SELECT id FROM pecas WHERE sn = ? AND id != ?");
        $stmtCheck->execute([$sn, $editId]);

        if ($sn !== '' && $stmtCheck->fetch()) {
            $_SESSION['mensagem_erro'] = 'Já existe uma peça registada com esse número de série.';
            $_SESSION['form_nova_peca'] = $_POST;
              header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
            exit;
        }
    } else {
        $stmtCheck = $pdo->prepare("SELECT id FROM pecas WHERE sn = ?");
        $stmtCheck->execute([$sn]);

        if ($sn !== '' && $stmtCheck->fetch()) {
            $_SESSION['mensagem_erro'] = 'Já existe uma peça registada com esse número de série.';
            $_SESSION['form_nova_peca'] = $_POST;
              header('Location: app.php?page=nova_peca');
            exit;
        }
    }

    if ($editId > 0) {
        $stmtAntes = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
        $stmtAntes->execute([$editId]);
        $pecaAntes = $stmtAntes->fetch();

        if (!$pecaAntes) {
            die('Peça não encontrada para atualizar.');
        }

        $stmt = $pdo->prepare("UPDATE pecas SET categoria = ?, produto = ?, sn = ?, cod_barras = ?, parceiro = ?, estado = ? WHERE id = ?");
        $stmt->execute([
            $categoria,
            $produto,
            $sn,
            $cod_barras,
            $parceiro,
            $estado,
            $editId
        ]);

        $camposAlterados = [
            'categoria' => $categoria,
            'produto' => $produto,
            'sn' => $sn,
            'cod_barras' => $cod_barras,
            'parceiro' => $parceiro,
            'estado' => $estado
        ];

        $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

        $stmtHistorico = $pdo->prepare("
            INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        foreach ($camposAlterados as $campo => $novoValor) {
            $valorAntigo = $pecaAntes[$campo] ?? '';

            if ((string)$valorAntigo !== (string)$novoValor) {
                $stmtHistorico->execute([
                    $editId,
                    $campo,
                    $valorAntigo,
                    $novoValor,
                    $utilizador
                ]);
            }
        }

    } else {
        $stmt = $pdo->prepare("INSERT INTO pecas (categoria, produto, sn, cod_barras, parceiro, estado, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $categoria,
            $produto,
            $sn,
            $cod_barras,
            $parceiro,
            $estado
        ]);

        $novoId = (int)$pdo->lastInsertId();
        $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

        $stmtHistorico = $pdo->prepare("
            INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $stmtHistorico->execute([$novoId, 'criação', '', 'Peça criada', $utilizador]);
        $stmtHistorico->execute([$novoId, 'categoria', '', $categoria, $utilizador]);
        $stmtHistorico->execute([$novoId, 'produto', '', $produto, $utilizador]);
        $stmtHistorico->execute([$novoId, 'sn', '', $sn, $utilizador]);
        $stmtHistorico->execute([$novoId, 'cod_barras', '', $cod_barras, $utilizador]);
        $stmtHistorico->execute([$novoId, 'parceiro', '', $parceiro, $utilizador]);
        $stmtHistorico->execute([$novoId, 'estado', '', $estado, $utilizador]);
    }
    
    if ($editId > 0) {
      $_SESSION['mensagem_sucesso'] = 'Peça atualizada com sucesso.';
    } else {
      $_SESSION['mensagem_sucesso'] = 'Peça criada com sucesso.';
    }

    header('Location: app.php?page=inventario');
    exit;
  }


// ============================================================
// 8. PROCESSAMENTO POST: CRIAR / EDITAR CONTA
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'nova_conta') {
    $contaId = isset($_POST['conta_id']) ? (int)$_POST['conta_id'] : 0;
    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $fotografiaPath = $_POST['fotografia_atual'] ?? '';

    if ($nome === '' || $email === '' || ($contaId === 0 && $password === '')) {
        $_SESSION['mensagem_erro'] = 'Preencher todos os campos obrigatórios.';
        header('Location: app.php?page=contas' . ($contaId > 0 ? '&edit_conta=' . $contaId : ''));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@newvision\.pt$/i', $email)) {
        $_SESSION['mensagem_erro'] = 'O email tem de ser válido';
        header('Location: app.php?page=contas' . ($contaId > 0 ? '&edit_conta=' . $contaId : ''));
        exit;
    }
    if ($contaId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE email = ? AND id != ?");
        $stmt->execute([$email, $contaId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE email = ?");
        $stmt->execute([$email]);
    }

    if ($stmt->fetch()) {
      $_SESSION['mensagem_erro'] = 'Já existe uma conta associada a esse email.';
      header('Location: app.php?page=contas' . ($contaId > 0 ? '&edit_conta=' . $contaId : ''));
      exit;
    }

    if ($contaId > 0) {
      if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE utilizadores SET nome = ?, email = ?, password = ?, fotografia = ? WHERE id = ?");
        $stmt->execute([$nome, $email, $passwordHash, $fotografiaPath, $contaId]);
      } else {
        $stmt = $pdo->prepare("UPDATE utilizadores SET nome = ?, email = ?, fotografia = ? WHERE id = ?");
        $stmt->execute([$nome, $email, $fotografiaPath, $contaId]);
      }
      $_SESSION['mensagem_sucesso'] = 'Conta atualizada com sucesso.';
    } else {
      $passwordHash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, email, password, fotografia, created_at) VALUES (?, ?, ?, ?, NOW())");
      $stmt->execute([$nome, $email, $passwordHash, $fotografiaPath]);

      $_SESSION['mensagem_sucesso'] = 'Conta criada com sucesso.';
    }

  $fotoCropada = $_POST['fotografia_cropada'] ?? '';

    if ($fotoCropada !== ''){
      $uploadDir = 'uploads/';

      if (!is_dir($uploadDir)){
        mkdir($uploadDir, 0777, true);
      }

    if (preg_match('/^data:image\/jpeg;base64,/', $fotoCropada) || preg_match('/^data:image\/png;base64,/', $fotoCropada)) {
      $fotoCropada = preg_replace('/^data:image\/\w+;base64,/', '', $fotoCropada);
      $fotoCropada = base64_decode($fotoCropada);

      $fileName = 'crop_' . time() . '.jpg';
      $targetFile = $uploadDir . $fileName;

      file_put_contents($targetFile, $fotoCropada);
      $fotografiaPath = $targetFile;
    }
  }

    if (!empty($_FILES['fotografia']['name'])) {
        $uploadDir = 'uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . basename($_FILES['fotografia']['name']);
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['fotografia']['tmp_name'], $targetFile)) {
            $fotografiaPath = $targetFile;
        }
    }

      header('Location: app.php?page=contas');
      exit;
    }

// ============================================================
// 9. PROCESSAMENTO POST: ELIMINAR CONTA
// ============================================================

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'eliminar_conta') {
    $deleteContaId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($deleteContaId > 0) {
      $stmt = $pdo->prepare("DELETE FROM utilizadores WHERE id = ?");
      $stmt->execute([$deleteContaId]);
      $_SESSION['mensagem_sucesso'] = 'Conta eliminada com sucesso.';
    }

    header('Location: app.php?page=contas');
    exit;
  }

// ============================================================
// 10. PROCESSAMENTO POST: ELIMINAR PEÇA
// ============================================================

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'eliminar_peca') {
    $deleteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  
    if ($deleteId <= 0) {
      $_SESSION['mensagem_erro'] = 'ID inválido para eliminar.';
      header('Location: app.php?page=inventario');
      exit;
    }

    $stmtPeca = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmtPeca->execute([$deleteId]);
    $peca = $stmtPeca->fetch();

    if (!$peca) {
        $_SESSION['mensagem_erro'] = 'Peça não encontrada para eliminar.';
        header('Location: app.php?page=inventario');
        exit;
    }

    $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

    $stmtHistorico = $pdo->prepare("
        INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmtHistorico->execute([$deleteId, 'eliminação', 'Peça existente', 'Peça eliminada', $utilizador]);

    $campos = ['categoria', 'produto', 'sn', 'cod_barras', 'parceiro', 'estado'];

    foreach ($campos as $campo){
      $stmtHistorico->execute([
        $deleteId,
        $campo,
        $peca[$campo] ?? '',
        '',
        $utilizador
      ]);
    }

    $stmtDelete = $pdo->prepare("DELETE FROM pecas WHERE id = ?");
    $stmtDelete->execute([$deleteId]);

    $_SESSION['mensagem_sucesso'] = 'Peça eliminada com sucesso.';
    header('Location: app.php?page=inventario');
    exit;
  }

// ============================================================
// 11. VALORES TEMPORÁRIOS DO FORMULÁRIO DE ENVIO
// ============================================================

    $formEnvio = $_SESSION['form_envio'] ?? [];
    unset($_SESSION['form_envio']);

    $valorDocumentoEnvio = $formEnvio['documento'] ?? '';
    $valorNumDocumentoEnvio = $formEnvio['num_documento'] ?? '';
    $valorDataDocumentoEnvio = $formEnvio['data_documento'] ?? '';
    $valorParceiroEnvio = $formEnvio['parceiro'] ?? '';
    $valorLinhasCategoriaEnvio = $formEnvio['linha_categoria'] ?? [];
    $valorLinhasProdutoEnvio = $formEnvio['linha_produto'] ?? [];
    $valorLinhasQuantidadeEnvio = $formEnvio['linha_quantidade'] ?? [];
    $valorLinhasNumSerieEnvio = $formEnvio['linha_num_serie'] ?? [];


    $parceirosInventario = $pdo->query("
    SELECT DISTINCT parceiro
    FROM pecas
    WHERE parceiro IS NOT NULL AND parceiro <> ''
    ORDER BY parceiro ASC
    ")->fetchAll(PDO::FETCH_COLUMN);


// ============================================================
// 12. PROCESSAMENTO POST: IMPORTAR GUIA DE ENVIO
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'importar_guia_envio') {
    if (!isset($_FILES['guia_pdf']) || !is_array($_FILES['guia_pdf'])) {
        $_SESSION['mensagem_erro'] = 'Nenhum ficheiro PDF foi enviado.';
        header('Location: app.php?page=envios');
        exit;
    }

    $ficheiro = $_FILES['guia_pdf'];

    if (($ficheiro['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION['mensagem_erro'] = 'Ocorreu um erro no upload do ficheiro.';
        header('Location: app.php?page=envios');
        exit;
    }

    $extensao = strtolower(pathinfo($ficheiro['name'] ?? '', PATHINFO_EXTENSION));
    if ($extensao !== 'pdf') {
        $_SESSION['mensagem_erro'] = 'O ficheiro tem de ser um PDF válido.';
        header('Location: app.php?page=envios');
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/guias/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $nomeTemporario = 'guia_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $caminhoPdf     = $uploadDir . $nomeTemporario;

    if (!move_uploaded_file($ficheiro['tmp_name'], $caminhoPdf)) {
        $_SESSION['mensagem_erro'] = 'Não foi possível guardar o PDF.';
        header('Location: app.php?page=envios');
        exit;
    }

    try {
        $textoExtraido = extrairTextoPdfNova($caminhoPdf);
        $dados = extrairDadosGuiaTransporteNova($textoExtraido, $pdo, $parceirosInventario, $catalogoProdutos);

        // Deteção de duplicados: se já existe rascunho com o mesmo Nº Documento, redireciona para ele
        if (($dados['num_documento'] ?? '') !== '') {
            $stmtDup = $pdo->prepare("SELECT id FROM envios WHERE num_documento = ? AND estado = 'Rascunho' LIMIT 1");
            $stmtDup->execute([$dados['num_documento']]);
            $dupRow = $stmtDup->fetch();
            if ($dupRow) {
                $_SESSION['mensagem_sucesso'] = 'Esta guia (N\u00ba ' . $dados['num_documento'] . ') j\u00e1 foi importada. A redirecionar para o rascunho existente.';
                header('Location: app.php?page=envios&ver=' . $dupRow['id']);
                exit;
            }
        }

        // Cria o rascunho diretamente na BD com os dados extra\u00eddos do PDF
        $pdo->beginTransaction();

        $pdo->prepare("
          INSERT INTO envios (documento, num_documento, data_documento, parceiro, criado_por, created_at, estado)
          VALUES (?, ?, ?, ?, ?, NOW(), 'Rascunho')
        ")->execute([
          $dados['documento']      ?? '',
          $dados['num_documento']  ?? '',
          ($dados['data_documento'] !== '' ? $dados['data_documento'] : null),
          $dados['parceiro']       ?? '',
          $_SESSION['user_nome']   ?? 'Sistema',
        ]);

        $envioId = (int)$pdo->lastInsertId();

        $stmtLinha = $pdo->prepare("
            INSERT INTO envios_linhas (envio_id, artigo, designacao, quantidade, num_serie)
            VALUES (?, ?, ?, ?, ?)
        ");

        $categorias  = $dados['linha_categoria']  ?? [];
        $produtos    = $dados['linha_produto']    ?? [];
        $quantidades = $dados['linha_quantidade'] ?? [];
        $numSeries   = $dados['linha_num_serie']  ?? [];

        foreach ($categorias as $i => $cat) {
            $stmtLinha->execute([
                $envioId,
                $cat,
                $produtos[$i]    ?? '',
                $quantidades[$i] ?? 1,
                $numSeries[$i]   ?? '',
            ]);
        }

        $pdo->commit();

        $_SESSION['mensagem_sucesso'] = 'Guia lida com sucesso. Revê os dados e confirma o envio.';
        // Redireciona para o rascunho criado — o formulário pré-preenche automaticamente
        header('Location: app.php?page=envios&ver=' . $envioId);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['mensagem_erro'] = 'Erro ao ler a guia: ' . $e->getMessage();
        header('Location: app.php?page=envios');
        exit;
    }
}

// ============================================================
// 13. PROCESSAMENTO POST: NOVO ENVIO
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'novo_envio') {
    $documento = trim($_POST['documento'] ?? '');
    $num_documento = trim($_POST['num_documento'] ?? '');
    $data_documento = trim($_POST['data_documento'] ?? '');
    $parceiro = trim($_POST['parceiro'] ?? '');

    $categoriasEnvio = $_POST['linha_categoria'] ?? [];
    $produtosEnvio = $_POST['linha_produto'] ?? [];
    $quantidades = $_POST['linha_quantidade'] ?? [];
    $numSeries = $_POST['linha_num_serie'] ?? [];

    if ($documento === '' || $num_documento === '' || $data_documento === '' || $parceiro === '') {
        $_SESSION['mensagem_erro'] = 'Preencher Documento, Nº Documento, Data e Parceiro.';
        $_SESSION['form_envio'] = $_POST;
        header('Location: app.php?page=envios');
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_documento)) {
        $_SESSION['mensagem_erro'] = 'A data do documento tem de estar no formato AAAA-MM-DD.';
        $_SESSION['form_envio'] = $_POST;
        header('Location: app.php?page=envios');
        exit;
    }

    $linhasValidas = [];

    foreach ($produtosEnvio as $i => $produtoEnvio) {
        $categoriaEnvio = trim($categoriasEnvio[$i] ?? '');
        $produtoEnvio = trim($produtoEnvio ?? '');
        $quantidade = trim($quantidades[$i] ?? '');
        $numSerie = trim($numSeries[$i] ?? '');

        if ($categoriaEnvio === '' && $produtoEnvio === '' && $quantidade === '' && $numSerie === '') {
            continue;
        }

        if ($categoriaEnvio === '' || $produtoEnvio === '' || $quantidade === '') {
            $_SESSION['mensagem_erro'] = 'Cada linha tem de ter Tipo, Nome da Peça e Quantidade.';
            $_SESSION['form_envio'] = $_POST;
            header('Location: app.php?page=envios');
            exit;
        }

        if (!isset($catalogoProdutos[$categoriaEnvio]) || !in_array($produtoEnvio, $catalogoProdutos[$categoriaEnvio], true)) {
            $_SESSION['mensagem_erro'] = 'A linha do envio contém um Tipo ou Nome da Peça inválido.';
            $_SESSION['form_envio'] = $_POST;
            header('Location: app.php?page=envios');
            exit;
        }

        $linhasValidas[] = [
            'categoria' => $categoriaEnvio,
            'produto' => $produtoEnvio,
            'quantidade' => (float)$quantidade,
            'num_serie' => $numSerie,
        ];
    }

    if (count($linhasValidas) === 0) {
        $_SESSION['mensagem_erro'] = 'O envio tem de conter pelo menos uma linha válida.';
        $_SESSION['form_envio'] = $_POST;
        header('Location: app.php?page=envios');
        exit;
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO envios (documento, num_documento, data_documento, parceiro, criado_por, created_at, estado)
            VALUES (?, ?, ?, ?, ?, NOW(), 'Ativa')
        ");
        $stmt->execute([
            $documento,
            $num_documento,
            $data_documento,
            $parceiro,
            $_SESSION['user_nome'] ?? 'Sistema'
        ]);

        $envioId = (int)$pdo->lastInsertId();

        $stmtLinha = $pdo->prepare("
            INSERT INTO envios_linhas (envio_id, artigo, designacao, quantidade, num_serie)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($linhasValidas as $linha) {
            $stmtLinha->execute([
                $envioId,
                $linha['categoria'],
                $linha['produto'],
                $linha['quantidade'],
                $linha['num_serie']
            ]);
        }

        $pdo->commit();
        $_SESSION['mensagem_sucesso'] = 'Envio criado com sucesso.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensagem_erro'] = 'Erro ao criar o envio: ' . $e->getMessage();
        $_SESSION['form_envio'] = $_POST;
    }

    header('Location: app.php?page=envios');
    exit;
}

// ============================================================
// Extrai o texto bruto de um PDF usando pdftotext
// Requer poppler-utils instalado no servidor (Laragon inclui)
// ============================================================
function extrairTextoPdfNova(string $caminhoPdf): string
{
    // Caminho com barras normais — mais fiável no Windows/PHP
    $pdftotext = 'C:/poppler/poppler-26.02.0/Library/bin/pdftotext.exe';

    // Constrói o comando com aspas manuais (evita problemas do escapeshellarg no Windows)
    $cmd = '"' . $pdftotext . '" -layout "' . $caminhoPdf . '" -';

    $descSpec = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout (texto extraído)
        2 => ['pipe', 'w'], // stderr (erros do pdftotext)
    ];

    $process = proc_open($cmd, $descSpec, $pipes);

    if (!is_resource($process)) {
        throw new Exception('Não foi possível iniciar o pdftotext. Verifica o caminho.');
    }

    fclose($pipes[0]);
    $texto = stream_get_contents($pipes[1]);
    $erros = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $codigo = proc_close($process);

    if ($codigo !== 0 || trim($texto) === '') {
        throw new Exception(
            'Erro ao extrair texto do PDF' .
            ($erros !== '' ? ': ' . trim($erros) : '. Verifica se o ficheiro não está corrompido.')
        );
    }

    return $texto;
}


// ============================================================
// Analisa o texto extraído e devolve os dados estruturados
// para preencher o formulário de envio
// ============================================================
function extrairDadosGuiaTransporteNova(string $texto, PDO $pdo, array $parceirosInventario, array $catalogoProdutos = []): array
{
  // ── Auxiliar: normaliza texto para comparações (remove acentos, minúsculas)
    $norm = static function (string $s): string {
    $mapa = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
        'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','Ä'=>'a',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'Ó'=>'o','Ò'=>'o','Õ'=>'o','Ô'=>'o','Ö'=>'o',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'Ç'=>'c','Ñ'=>'n',
    ];
    // mb_strtolower garante suporte correto a UTF-8
      return mb_strtolower(strtr($s, $mapa), 'UTF-8');
    };

    // O PDF tem 3 vias (Original, Duplicado, Triplicado) separadas por \f
    // Só a primeira página é necessária
    $paginas = preg_split('/\f/', $texto);
    $pagina  = $paginas[0] ?? $texto;
    $linhas  = array_values(array_filter(
        array_map('trim', explode("\n", $pagina)),
        static fn($l) => $l !== ''
    ));


    // ── 1. Tipo de documento ──────────────────────────────────────────────────
    // PDF: "G. Transp (said fornec)"  →  sistema: "G. Transp fornec"
    // PDF: "G. Transp (said cliente)" →  sistema: "G. Transp cliente"
    $documento = '';
    foreach ($linhas as $linha) {
        if (preg_match('/G\.\s*Transp.*said\s+fornec/i', $linha)) {
            $documento = 'G. Transp Fornec';
            break;
        }
        if (preg_match('/G\.\s*Transp.*said\s+cliente/i', $linha)) {
            $documento = 'G.Transp Cliente';
            break;
        }
    }


// ── 2. Nº Documento e Data ────────────────────────────────────────────────
// Método primário: linha ATCUD — ex: "ATCUD:J6NJKWCK-123"
// O número do documento é sempre o último segmento após o último "-"
$numDocumento  = '';
$dataDocumento = '';

foreach ($linhas as $linha) {
    if ($numDocumento === '' && preg_match('/ATCUD:[A-Z0-9]+-(\d+)/i', $linha, $m)) {
        $numDocumento = $m[1];
    }
    if ($dataDocumento === '' && preg_match('/(\d{4}-\d{2}-\d{2})/', $linha, $m)) {
        $dataDocumento = $m[1];
    }
    if ($numDocumento !== '' && $dataDocumento !== '') {
        break;
    }
}


// ── 3. Parceiro ───────────────────────────────────────────────────────────
// Regra 1: Guia de Cliente → parceiro é sempre "Field Service"
// Regra 2: Guia de Fornecedor → correspondência automática com os parceiros
//          registados na página Inventário ($parceirosInventario)
if ($documento === 'G. Transp cliente') {

    $parceiro = 'Field Service';

} else {

    $parceiro = '';
    foreach ($linhas as $linha) {
        $linhaNorm = $norm($linha);
        foreach ($parceirosInventario as $p) {
            $pNorm = $norm($p);
            if (strlen($pNorm) < 4) continue;

            if (str_contains($linhaNorm, $pNorm) || str_contains($pNorm, $linhaNorm)) {
                $parceiro = $p;
                break 2;
            }
        }
    }

    // Fallback: linha a seguir a "Exmo(s) Senhor(es)" se nenhum parceiro correspondeu
    if ($parceiro === '') {
        foreach ($linhas as $i => $linha) {
            if (stripos($linha, 'Exmo') !== false && stripos($linha, 'Senhor') !== false) {
                $parceiro = $linhas[$i + 1] ?? '';
                break;
            }
        }
    }

}


// ── 4. Catálogo para mapeamento designação PDF → categoria + produto ───────
// Usa o $catalogoProdutos passado como parâmetro (array estático do sistema)
// Formato: ['Box Android' => ['Box D039', 'Box ETE3399', ...], ...]
    $catalogoDb = $catalogoProdutos;
    


    // Mapeia a designação do PDF para [categoria, produto] do catálogo.
    // Estratégia: o nome do produto (normalizado) tem de estar CONTIDO
    // na designação do PDF (também normalizada). Ganha o match mais longo.
    // Ex: "CABECOTE PROXIMA CGD" contém "proxima cgd" → categoria "Cabeçote Proxima"
    $mapearProduto = static function (string $desPdf) use ($catalogoDb, $norm): array {
        $desNorm = $norm($desPdf);
        $melhor  = ['categoria' => '', 'produto' => $desPdf];
        $score   = 0;

        foreach ($catalogoDb as $categoria => $produtos) {
            foreach ($produtos as $produto) {
                $pNorm = $norm($produto);
                if ($pNorm === '') continue;

                if ($desNorm === $pNorm) {
                    // Match exato — retorna imediatamente
                    return ['categoria' => $categoria, 'produto' => $produto];
                }

                if (str_contains($desNorm, $pNorm) && strlen($pNorm) > $score) {
                    $score  = strlen($pNorm);
                    $melhor = ['categoria' => $categoria, 'produto' => $produto];
                }
            }
        }

        return $melhor;
    };


    // ── 5. Linhas de artigos ──────────────────────────────────────────────────
    // Formato: "ASSISTENCIA BOX D039 / ISD039X23A50415 1,00"
    //           ^artigo       ^designação  ^SN           ^qtd
    $linhaCategoria  = [];
    $linhaProduto    = [];
    $linhaQuantidade = [];
    $linhaNumSerie   = [];

    foreach ($linhas as $linha) {
        if (!preg_match(
            '/^ASSISTENCIA\s+(.+?)\s*\/\s*([A-Z0-9]+)\s+([\d]+[,.][\d]+)\s*$/i',
            $linha, $m
        )) {
            continue;
        }

        $designacao = trim($m[1]);
        $sn         = strtoupper(trim($m[2]));
        $qtd        = (float) str_replace(',', '.', $m[3]);

        // PRIORIDADE 1: procurar o SN na tabela pecas para obter categoria e produto exatos
        $categoria = '';
        $produto   = '';
        if ($sn !== '') {
            $stmtSn = $pdo->prepare("SELECT categoria, produto FROM pecas WHERE UPPER(TRIM(sn)) = ? LIMIT 1");
            $stmtSn->execute([$sn]);
            $pecaRow = $stmtSn->fetch();
            if ($pecaRow && trim((string)$pecaRow['categoria']) !== '') {
                $categoria = $pecaRow['categoria'];
                $produto   = $pecaRow['produto'];
            }
        }

        // PRIORIDADE 2: matching por nome (fallback)
        if ($categoria === '') {
            $mapeamento = $mapearProduto($designacao);
            $categoria  = $mapeamento['categoria'];
            $produto    = $mapeamento['produto'];
        }

        $linhaCategoria[]  = $categoria;
        $linhaProduto[]    = $produto;
        $linhaQuantidade[] = $qtd;
        $linhaNumSerie[]   = $sn;
    }


    return [
        'documento'        => $documento,
        'num_documento'    => $numDocumento,
        'data_documento'   => $dataDocumento,
        'parceiro'         => $parceiro,
        'linha_categoria'  => $linhaCategoria,
        'linha_produto'    => $linhaProduto,
        'linha_quantidade' => $linhaQuantidade,
        'linha_num_serie'  => $linhaNumSerie,
    ];
}

// ============================================================
// HANDLER: Guardar rascunho do envio (novo ou atualizar existente)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'guardar_rascunho_envio') {
    $envioId        = (int)($_POST['envio_id'] ?? 0);
    $documento      = trim($_POST['documento'] ?? '');
    $num_documento  = trim($_POST['num_documento'] ?? '');
    $data_documento = trim($_POST['data_documento'] ?? '');
    $parceiro       = trim($_POST['parceiro'] ?? '');

    $categoriasEnvio = $_POST['linha_categoria'] ?? [];
    $produtosEnvio   = $_POST['linha_produto']   ?? [];
    $quantidades     = $_POST['linha_quantidade'] ?? [];
    $numSeries       = $_POST['linha_num_serie']  ?? [];

    $redirBase = 'app.php?page=envios' . ($envioId > 0 ? '&ver=' . $envioId : '');

    if ($documento === '' || $num_documento === '' || $data_documento === '' || $parceiro === '') {
        $_SESSION['mensagem_erro'] = 'Preencher Documento, Nº Documento, Data e Parceiro.';
        header('Location: ' . $redirBase);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_documento)) {
        $_SESSION['mensagem_erro'] = 'A data tem de estar no formato AAAA-MM-DD.';
        header('Location: ' . $redirBase);
        exit;
    }

    $linhasValidas = [];
    foreach ($produtosEnvio as $i => $produtoEnvio) {
        $cat      = trim($categoriasEnvio[$i] ?? '');
        $prod     = trim($produtoEnvio ?? '');
        $qtd      = trim($quantidades[$i] ?? '');
        $numSerie = trim($numSeries[$i] ?? '');

        if ($cat === '' && $prod === '' && $qtd === '' && $numSerie === '') {
            continue; // linha vazia — ignorar
        }
        if ($cat === '' || $prod === '' || $qtd === '') {
            $_SESSION['mensagem_erro'] = 'Cada linha tem de ter Tipo, Nome da Peça e Quantidade.';
            header('Location: ' . $redirBase);
            exit;
        }

        $linhasValidas[] = [
            'categoria' => $cat,
            'produto'   => $prod,
            'quantidade'=> (float)$qtd,
            'num_serie' => $numSerie,
        ];
    }

    if (count($linhasValidas) === 0) {
        $_SESSION['mensagem_erro'] = 'O rascunho tem de conter pelo menos uma linha válida.';
        header('Location: ' . $redirBase);
        exit;
    }

    $pdo->beginTransaction();
    try {
        if ($envioId > 0) {
            // Atualizar rascunho existente — verificar que ainda está em Rascunho
            $stmtCheck = $pdo->prepare("SELECT id, estado FROM envios WHERE id = ?");
            $stmtCheck->execute([$envioId]);
            $envioExistente = $stmtCheck->fetch();

            if (!$envioExistente || $envioExistente['estado'] !== 'Rascunho') {
                $pdo->rollBack();
                $_SESSION['mensagem_erro'] = 'Rascunho não encontrado ou já confirmado.';
                header('Location: app.php?page=envios');
                exit;
            }

            $pdo->prepare("
                UPDATE envios
                SET documento = ?, num_documento = ?, data_documento = ?, parceiro = ?
                WHERE id = ?
            ")->execute([$documento, $num_documento, $data_documento, $parceiro, $envioId]);

            // Apagar linhas antigas e reinserir as novas
            $pdo->prepare("DELETE FROM envios_linhas WHERE envio_id = ?")->execute([$envioId]);

        } else {
            // Criar novo rascunho
            $pdo->prepare("
                INSERT INTO envios (documento, num_documento, data_documento, parceiro, criado_por, created_at, estado)
                VALUES (?, ?, ?, ?, ?, NOW(), 'Rascunho')
            ")->execute([
                $documento,
                $num_documento,
                $data_documento,
                $parceiro,
                $_SESSION['user_nome'] ?? 'Sistema'
            ]);
            $envioId = (int)$pdo->lastInsertId();
        }

        $stmtLinha = $pdo->prepare("
            INSERT INTO envios_linhas (envio_id, artigo, designacao, quantidade, num_serie)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($linhasValidas as $linha) {
            $stmtLinha->execute([
                $envioId,
                $linha['categoria'],
                $linha['produto'],
                $linha['quantidade'],
                $linha['num_serie'],
            ]);
        }

        $pdo->commit();
        $_SESSION['mensagem_sucesso'] = 'Rascunho guardado com sucesso.';
        header('Location: app.php?page=envios&ver=' . $envioId);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensagem_erro'] = 'Erro ao guardar o rascunho: ' . $e->getMessage();
        header('Location: ' . $redirBase);
        exit;
    }
}


// ============================================================
// HANDLER: Confirmar envio final — atualiza inventário
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'confirmar_envio_final') {
    $envioId = (int)($_POST['envio_id'] ?? 0);

    if ($envioId <= 0) {
        $_SESSION['mensagem_erro'] = 'ID do envio inválido.';
        header('Location: app.php?page=envios');
        exit;
    }

    $stmtEnvio = $pdo->prepare("SELECT * FROM envios WHERE id = ?");
    $stmtEnvio->execute([$envioId]);
    $envio = $stmtEnvio->fetch();

    if (!$envio) {
        $_SESSION['mensagem_erro'] = 'Envio não encontrado.';
        header('Location: app.php?page=envios');
        exit;
    }

    if ($envio['estado'] !== 'Rascunho') {
        $_SESSION['mensagem_erro'] = 'Este envio já foi confirmado ou cancelado.';
        header('Location: app.php?page=envios&ver=' . $envioId);
        exit;
    }

    $stmtLinhas = $pdo->prepare("SELECT * FROM envios_linhas WHERE envio_id = ? ORDER BY id ASC");
    $stmtLinhas->execute([$envioId]);
    $linhas = $stmtLinhas->fetchAll();

    // Guia Cliente → peça fica com estado 'Cliente'; Guia Fornecedor → 'Parceiro'
    $novoEstadoPeca  = ($envio['documento'] === 'G. Transp cliente') ? 'Cliente' : 'Parceiro';
    $novoParceiroPeca = $envio['parceiro'];
    $utilizador      = $_SESSION['user_nome'] ?? 'Sistema';

    $pdo->beginTransaction();
    try {
        $avisos = [];

        foreach ($linhas as $linha) {
            $sn = trim($linha['num_serie'] ?? '');

            if ($sn === '') {
                // Linha sem SN — não é possível identificar a peça no inventário
                $avisos[] = 'Linha "' . htmlspecialchars($linha['designacao'] ?? '?') . '" sem Nº Série — não atualizada no inventário.';
                continue;
            }

            $stmtPeca = $pdo->prepare("SELECT id, estado, parceiro FROM pecas WHERE sn = ? LIMIT 1");
            $stmtPeca->execute([$sn]);
            $peca = $stmtPeca->fetch();

            if (!$peca) {
                $avisos[] = 'SN "' . htmlspecialchars($sn) . '" não encontrado no inventário — linha ignorada.';
                continue;
            }

            $estadoAntigo   = $peca['estado'];
            $parceiroAntigo = $peca['parceiro'];

            $pdo->prepare("UPDATE pecas SET estado = ?, parceiro = ? WHERE id = ?")->execute([
                $novoEstadoPeca,
                $novoParceiroPeca,
                $peca['id']
            ]);

            $stmtHist = $pdo->prepare("
                INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            if ($estadoAntigo !== $novoEstadoPeca) {
                $stmtHist->execute([$peca['id'], 'estado', $estadoAntigo, $novoEstadoPeca, $utilizador]);
            }
            if ($parceiroAntigo !== $novoParceiroPeca) {
                $stmtHist->execute([$peca['id'], 'parceiro', $parceiroAntigo, $novoParceiroPeca, $utilizador]);
            }
            // Registo de rastreabilidade — liga o histórico da peça ao envio
            $stmtHist->execute([$peca['id'], 'envio', '', 'Envio #' . $envioId . ' confirmado', $utilizador]);
        }

        // Marcar o envio como Ativa (confirmado)
        $pdo->prepare("UPDATE envios SET estado = 'Ativa' WHERE id = ?")->execute([$envioId]);

        $pdo->commit();

        if (!empty($avisos)) {
            $_SESSION['mensagem_erro'] = 'Envio confirmado com avisos: ' . implode(' | ', $avisos);
        } else {
            $_SESSION['mensagem_sucesso'] = 'Envio confirmado. Inventário atualizado com sucesso.';
        }

        header('Location: app.php?page=envios');
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensagem_erro'] = 'Erro ao confirmar o envio: ' . $e->getMessage();
        header('Location: app.php?page=envios&ver=' . $envioId);
        exit;
    }
}


// ============================================================
// HANDLER: Apagar rascunho de envio
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'apagar_envio') {
    $envioId = (int)($_POST['envio_id'] ?? 0);

    if ($envioId <= 0) {
        $_SESSION['mensagem_erro'] = 'ID do envio inválido.';
        header('Location: app.php?page=envios');
        exit;
    }

    $stmtCheck = $pdo->prepare("SELECT id, estado FROM envios WHERE id = ?");
    $stmtCheck->execute([$envioId]);
    $envio = $stmtCheck->fetch();

    if (!$envio || $envio['estado'] !== 'Rascunho') {
        $_SESSION['mensagem_erro'] = 'Só é possível apagar guias em estado Rascunho.';
        header('Location: app.php?page=envios');
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM envios_linhas WHERE envio_id = ?")->execute([$envioId]);
        $pdo->prepare("DELETE FROM envios WHERE id = ?")->execute([$envioId]);
        $pdo->commit();

        $_SESSION['mensagem_sucesso'] = 'Guia apagada com sucesso.';
        header('Location: app.php?page=envios');
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensagem_erro'] = 'Erro ao apagar a guia: ' . $e->getMessage();
        header('Location: app.php?page=envios&ver=' . $envioId);
        exit;
    }
}

// ══════════════════════════════════════════════
// HANDLER: Criar / Editar PAT
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_type'] ?? '', ['criar_pat', 'editar_pat'])) {
    $isEdicao = ($_POST['form_type'] === 'editar_pat');
    $editId = (int)($_POST['pat_id'] ?? 0);

    $numeroPat = trim($_POST['numero_pat'] ?? '');
    $revisao = max(1, (int)($_POST['revisao'] ?? 1));
    $entidade = trim($_POST['entidade'] ?? '');
    $local = trim($_POST['local_cliente'] ?? '');
    $contacto = trim($_POST['contacto'] ?? '');
    $morada = trim($_POST['morada'] ?? '');
    // Normaliza datas: vazio -> NULL; aceita o formato "Y-m-dTH:i" do datetime-local.
    $normDt = fn($v) => ($v !== '' && strtotime($v)) ? date('Y-m-d H:i:s', strtotime($v)) : null;
    $dataRec = $normDt(trim($_POST['data_recepcao'] ?? ''));
    $dataLim = $normDt(trim($_POST['data_limite'] ?? ''));
    $garantia = isset($_POST['garantia']) ? 1 : 0;
    $contrato = isset($_POST['contrato_manutencao']) ? 1 : 0;
    $descricao = trim($_POST['descricao'] ?? '');
    $tecnico = trim($_POST['tecnico'] ?? '');
    $comentarios = trim($_POST['comentarios'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');

    // Se o campo tiver HTML do xdebug gravado por engano, LIMPAR
    $limparXdebug = fn(string $s): string =>
        (str_contains($s, 'xdebug-error') || str_contains($s, '<font size=')) ? '' : $s;
    $comentarios = $limparXdebug($comentarios);
    $observacoes = $limparXdebug($observacoes);
    $dataIni = $normDt(trim($_POST['data_inicio'] ?? ''));
    $dataFim = $normDt(trim($_POST['data_fim'] ?? ''));
    $tecnicos = trim($_POST['tecnicos_presentes'] ?? '');
    $prioridade = in_array($_POST['prioridade'] ?? '', ['Normal','Urgente']) ? $_POST['prioridade'] : 'Normal';
    $estado = in_array($_POST['estado'] ?? '', ['Aberto','Em Curso','Concluído','Cancelado']) ? $_POST['estado'] : 'Aberto';

    // Garantir UTF-8 válido em todos os campos de texto. Sob STRICT_TRANS_TABLES
    // qualquer byte inválido (ex.: dados lidos do DOM do Salesforce pela extensão)
    // faria o INSERT/UPDATE rebentar com erro 1366 (Incorrect string value).
    $utf8 = function ($s) {
        $s = (string)$s;
        return ($s === '' || mb_check_encoding($s, 'UTF-8'))
            ? $s
            : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    };
    $numeroPat   = $utf8($numeroPat);
    $entidade    = $utf8($entidade);
    $local       = $utf8($local);
    $contacto    = $utf8($contacto);
    $morada      = $utf8($morada);
    $descricao   = $utf8($descricao);
    $tecnico     = $utf8($tecnico);
    $comentarios = $utf8($comentarios);
    $observacoes = $utf8($observacoes);
    $tecnicos    = $utf8($tecnicos);

    if ($numeroPat === '') {
        $_SESSION['mensagem_erro'] = 'O número do PAT é obrigatório.';
        header('Location: app.php?page=pats' . ($isEdicao ? '&ver=' . $editId : '&acao=novo'));
        exit;
    }

    // Módulos e Componentes
    $modSolucoes = $_POST['mod_solucao'] ?? [];
    $modModelos = $_POST['mod_modelo'] ?? [];
    $modSeries = $_POST['mod_serie'] ?? [];
    $compRemovidos = $_POST['comp_removido'] ?? [];
    $comSnRem = $_POST['comp_sn_rem'] ?? [];
    $compColocados = $_POST['comp_colocado'] ?? [];
    $compSnCol = $_POST['comp_sn_col'] ?? [];
    $compQtds = $_POST['comp_qtd'] ?? [];

    $pdo->beginTransaction();
    try {
        $campos = [
            $numeroPat, $revisao, $entidade, $local, $contacto, $morada,
            $dataRec, $dataLim, $garantia, $contrato, $descricao,
            $tecnico, $comentarios, $dataIni, $dataFim, $tecnicos,
            $observacoes, $prioridade, $estado,
        ];

        if (!$isEdicao) {
            $campos[] = $_SESSION['user_nome'] ?? 'Sistema';
            $pdo->prepare("
                INSERT INTO pats
                 (numero_pat, revisao, entidade, local_cliente, contacto, morada,
                  data_recepcao, data_limite, garantia, contrato_manutencao, descricao,
                  tecnico, comentarios, data_inicio, data_fim, tecnicos_presentes,
                  observacoes, prioridade, estado, criado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute($campos);
            $patId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
               UPDATE pats SET
                 numero_pat=?, revisao=?, entidade=?, local_cliente=?, contacto=?, morada=?,
                 data_recepcao=?, data_limite=?, garantia=?, contrato_manutencao=?, descricao=?,
                 tecnico=?, comentarios=?, data_inicio=?, data_fim=?, tecnicos_presentes=?,
                 observacoes=?, prioridade=?, estado=?
               WHERE id=?
            ")->execute(array_merge($campos, [$editId]));
            $patId = $editId;
            $pdo->prepare("DELETE FROM pats_modulos    WHERE pat_id = ?")->execute([$patId]);
            $pdo->prepare("DELETE FROM pats_componentes WHERE pat_id = ?")->execute([$patId]);
        }

        // Reinserir módulos
        $stmtMod = $pdo->prepare("
            INSERT INTO pats_modulos (pat_id, solucao_equipamento, modelo, num_serie)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($modSolucoes as $i => $sol) {
            $sol = $utf8(trim($sol));
            $mod = $utf8(trim($modModelos[$i] ?? ''));
            $ser = $utf8(trim($modSeries[$i] ?? ''));
            if ($sol === '' && $mod === '' && $ser === '') continue;
            $stmtMod->execute([$patId, $sol, $mod, $ser]);
        }

        // Reinserir componentes
        $stmtComp = $pdo->prepare("
            INSERT INTO pats_componentes (pat_id, removido, sn_removido, colocado, sn_colocado, quantidade)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($compRemovidos as $i => $rem) {
            $rem = $utf8(trim($rem));
            $snr = $utf8(trim($comSnRem[$i] ?? ''));
            $col = $utf8(trim($compColocados[$i] ?? ''));
            $snc = $utf8(trim($compSnCol[$i] ?? ''));
            $qtd = max(1, (int)($compQtds[$i] ?? 1));
            if ($rem === '' && $col === '') continue;
            $stmtComp->execute([$patId, $rem, $snr, $col, $snc, $qtd]);
        }

        $pdo->commit();
        $_SESSION['mensagem_sucesso'] = $isEdicao ? 'PAT atualizado.' : 'PAT criado com sucesso.';
        header('Location: app.php?page=pats&ver=' . $patId);
        exit;
    } catch (Throwable $e) {
        // Erro ao gravar: reverter, registar e voltar ao formulário SEM perder
        // os dados nem matar a página (antes fazia die() e parecia que o PAT
        // não era criado). A mensagem fica visível no topo da página PATs.
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Erro ao guardar PAT: ' . $e->getMessage());
        $_SESSION['mensagem_erro'] = 'Não foi possível guardar o PAT: ' . $e->getMessage();
        header('Location: app.php?page=pats' . ($isEdicao ? '&ver=' . $editId : '&acao=novo'));
        exit;
    }
}

// ══════════════════════════════════════════════
// HANDLER: Apagar PAT
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'apagar_pat') {
    $patId = (int)($_POST['pat_id'] ?? 0);
    if ($patId > 0) {
        $pdo->prepare("DELETE FROM pats WHERE id = ?")->execute([$patId]);
    }
    $_SESSION['mensagem_sucesso'] = 'PAT apagado.';
    header('Location: app.php?page=pats');
    exit;
}


function countQuery(\PDO $pdo, string $sql): int {
    return (int)$pdo->query($sql)->fetchColumn();
}

$totalPecas = countQuery($pdo, "SELECT COUNT(*) FROM pecas");

$patsAtivos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Ativo'");

$ordensAtivas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Ativa'");

$ordensCanceladas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Cancelada'");

$ordensConcluidas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Concluida'");

$ultimoPat = $pdo->query("SELECT created_at FROM pats ORDER BY created_at DESC LIMIT 1")->fetchColumn() ?: null;

// Notificações
$notificacoes = [];

$stmtUrg = $pdo->query("
    SELECT id, numero_pat, revisao, entidade
    FROM pats
    WHERE prioridade = 'Urgente' AND estado NOT IN ('Concluído','Cancelado')
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
      AND estado NOT IN ('Concluído','Cancelado')
    ORDER BY data_limite ASC LIMIT 10
");
foreach ($stmtPrazo->fetchAll() as $p) {
    $notificacoes[] = [
       'tipo' => 'prazo',
       'msg' => 'Prazo a expirar: ' . htmlspecialchars($p['numero_pat'].'/'.$p['revisao']) . ' — ' . date('d/m/Y', strtotime($p['data_limite'])),
       'link' => 'app.php?page=pats&ver=' . (int)$p['id'],
    ];
}

$totalNotif = count($notificacoes);

// ══════════════════════════════════════════════
// DADOS — PATs
// ══════════════════════════════════════════════
$patsList = [];
$patDetalhe = null;
$patModulos = [];
$patComp = [];
$patAcao = $_GET['acao'] ?? '';
$patVerId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;

if ($page === 'pats') {

    //Filtros Lista
    $patFiltros = [
       'q' => trim($_GET['q']  ?? ''),
       'estado' => trim($_GET['estado'] ?? ''),
       'prioridade' => trim($_GET['prioridade'] ?? ''),
    ];

    $patWhere = [];
    $patParams = [];
    if ($patFiltros['q'] !== '') {
        $patWhere[] = '(numero_pat LIKE ? OR entidade LIKE ? OR tecnico LIKE ?)';
        $patParams[] = '%' . $patFiltros['q'] . '%';
        $patParams[] = '%' . $patFiltros['q'] . '%';
        $patParams[] = '%' . $patFiltros['q'] . '%';
    }
    if ($patFiltros['estado'] !== '') {
        $patWhere[] = 'estado = ?';
        $patParams[] = $patFiltros['estado'];
    }
    if ($patFiltros['prioridade'] !== '') {
        $patWhere[] = 'prioridade = ?';
        $patParams[] = $patFiltros['prioridade'];
    }

    $patSql = "SELECT * FROM pats"
            . ($patWhere ? ' WHERE ' . implode(' AND ', $patWhere) : '')
            . " ORDER BY created_at DESC";
    $patStmt = $pdo->prepare($patSql);
    $patStmt->execute($patParams);
    $patsList = $patStmt->fetchAll();

    // Detalhe de 1 PAT
    if ($patVerId > 0) {
        $s = $pdo->prepare("SELECT * FROM pats WHERE id = ?");
        $s->execute([$patVerId]);
        $patDetalhe = $s->fetch();
        if ($patDetalhe) {
            // Converter NULL para '' - evita erros de htmlspecialchars no PHP 8.1+
            $patDetalhe = array_map(fn($v) => is_null($v) ? '' : $v, $patDetalhe);
        }

        if ($patDetalhe) {
            $m = $pdo->prepare("SELECT * FROM pats_modulos WHERE pat_id = ? ORDER BY id");
            $m->execute([$patVerId]);
            $patModulos = $m->fetchAll();

            $sc = $pdo->prepare("SELECT * FROM pats_componentes WHERE pat_id = ? ORDER BY id");
            $sc->execute([$patVerId]);
            $patComp = $sc->fetchAll();
        }
    }
}

$contas = [];
    if ($page === 'contas') {
      $stmt = $pdo->query("SELECT id, nome, email, fotografia, created_at FROM utilizadores ORDER BY id DESC");
        $contas = $stmt->fetchAll();
      }

  $contaEdit = null;
  $editContaId = isset($_GET['edit_conta']) ? (int)$_GET['edit_conta'] : 0;

  if ($page === 'contas' && $editContaId > 0) {
    $stmt = $pdo->prepare("SELECT id, nome, email, fotografia FROM utilizadores WHERE id = ?");
      $stmt->execute([$editContaId]);
      $contaEdit = $stmt->fetch();

      if (!$contaEdit) {
        $_SESSION['mensagem_erro'] = 'Conta não encontrada.';
        header('Location: app.php?page=contas');
        exit;
      }
  }

$estadoData = $pdo->query("SELECT estado, COUNT(*) total FROM pecas GROUP BY estado ORDER BY total DESC")->fetchAll();

$categoriaData = $pdo->query("SELECT categoria, COUNT(*) total FROM pecas GROUP BY categoria ORDER BY total DESC LIMIT 12")->fetchAll();

$parceiroData = $pdo->query("SELECT parceiro, COUNT(*) total FROM pecas GROUP BY parceiro ORDER BY total DESC LIMIT 10")->fetchAll();

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

$filters = [
    'categoria' => $_GET['categoria'] ?? '',
    'estado' => $_GET['estado'] ?? '',
    'parceiro' => $_GET['parceiro'] ?? '',
    'sn' => $_GET['sn'] ?? '',
    'produto' => $_GET['produto'] ?? ''
];

$where = [];
$params = [];
if ($filters['categoria']) { $where[] = 'categoria = ?'; $params[] = $filters['categoria']; }
if ($filters['estado']) { $where[] = 'estado = ?'; $params[] = $filters['estado']; }
if ($filters['parceiro']) { $where[] = 'parceiro = ?'; $params[] = $filters['parceiro']; }
if ($filters['sn']) { $where[] = 'sn LIKE ?'; $params[] = '%' . $filters['sn'] . '%'; }
if ($filters['produto']) { 
    $where[] = 'produto = ?'; 
    $params[] = $filters['produto']; 
    }

$sql = "SELECT * FROM pecas" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pecas = $stmt->fetchAll();

$historico = [];
$pecaHist = null;

if ($page === 'historico' && isset($_GET['id'])) {
    $historicoId = (int)$_GET['id'];

    $stmt = $pdo->prepare("SELECT * FROM historico WHERE peca_id = ? ORDER BY data_alteracao DESC");
    $stmt->execute([$historicoId]);
    $historico = $stmt->fetchAll();

    $stmtPecaHist = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmtPecaHist->execute([$historicoId]);
    $pecaHist = $stmtPecaHist->fetch();

    if (!$pecaHist && !empty($historico)) {
        $pecaHist = [
            'id' => $historicoId,
            'produto' => '',
            'sn' => '',
            'categoria' => '',
            'cod_barras' => '',
            'parceiro' => '',
            'estado' => ''
        ];

        foreach ($historico as $h) {
            if ($h['campo'] === 'produto' && $pecaHist['produto'] === '' && $h['antes'] !== '') {
                $pecaHist['produto'] = $h['antes'];
            }

            if ($h['campo'] === 'produto' && $pecaHist['produto'] === '' && $h['depois'] !== '') {
                $pecaHist['produto'] = $h['depois'];
            }

            if ($h['campo'] === 'sn' && $pecaHist['sn'] === '' && $h['antes'] !== '') {
                $pecaHist['sn'] = $h['antes'];
            }

            if ($h['campo'] === 'sn' && $pecaHist['sn'] === '' && $h['depois'] !== '') {
                $pecaHist['sn'] = $h['depois'];
            }

            if ($h['campo'] === 'categoria' && $pecaHist['categoria'] === '' && $h['antes'] !== '') {
                $pecaHist['categoria'] = $h['antes'];
            }

            if ($h['campo'] === 'categoria' && $pecaHist['categoria'] === '' && $h['depois'] !== '') {
                $pecaHist['categoria'] = $h['depois'];
            }

            if ($h['campo'] === 'cod_barras' && $pecaHist['cod_barras'] === '' && $h['antes'] !== '') {
                $pecaHist['cod_barras'] = $h['antes'];
            }

            if ($h['campo'] === 'cod_barras' && $pecaHist['cod_barras'] === '' && $h['depois'] !== '') {
                $pecaHist['cod_barras'] = $h['depois'];
            }

            if ($h['campo'] === 'parceiro' && $pecaHist['parceiro'] === '' && $h['antes'] !== '') {
                $pecaHist['parceiro'] = $h['antes'];
            }

            if ($h['campo'] === 'parceiro' && $pecaHist['parceiro'] === '' && $h['depois'] !== '') {
                $pecaHist['parceiro'] = $h['depois'];
            }

            if ($h['campo'] === 'estado' && $pecaHist['estado'] === '' && $h['antes'] !== '') {
                $pecaHist['estado'] = $h['antes'];
            }

            if ($h['campo'] === 'estado' && $pecaHist['estado'] === '' && $h['depois'] !== '') {
                $pecaHist['estado'] = $h['depois'];
            }
        }
    }
}

/*=================
  AUDITORIA
==================*/

$utilizadoresAuditoria = [];
$acoesAuditoria = [];
$auditoriaLogs = [];
$filtroUtilizador = '';
$filtroAcao = '';

if ($page === 'auditoria') {
  $filtroUtilizador = trim($_GET['audit_user'] ?? '');
  $filtroAcao = trim($_GET['audit_action'] ?? '');

  $utilizadoresAuditoria = $pdo->query("
    SELECT DISTINCT utilizador
    FROM  historico
    WHERE utilizador IS NOT NULL AND utilizador <> ''
    ORDER BY utilizador ASC
  ")->fetchAll(PDO::FETCH_COLUMN);

  $acoesAuditoria = $pdo->query("
    SELECT DISTINCT campo
    FROM historico
    WHERE campo IS NOT NULL AND campo <> ''
    ORDER BY campo ASC
  ")->fetchAll(PDO::FETCH_COLUMN);

  $whereAuditoria = [];
  $paramsAuditoria =[];

  if ($filtroUtilizador !== '') {
    $whereAuditoria[] = "utilizador = ?";
    $paramsAuditoria[] = $filtroUtilizador;
  }

  if ($filtroAcao !== '') {
    $whereAuditoria[] = "campo = ?";
    $paramsAuditoria[] = $filtroAcao; 
  }

  $whereSqlAud = $whereAuditoria ? (" WHERE " . implode(" AND ", $whereAuditoria)) : "";

  // Paginação (50 por página)
  $audPerPage = 50;
  $audPag     = max(1, (int)($_GET['p'] ?? 1));
  $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM historico" . $whereSqlAud);
  $stmtCnt->execute($paramsAuditoria);
  $audTotal   = (int)$stmtCnt->fetchColumn();
  $audPaginas = max(1, (int)ceil($audTotal / $audPerPage));
  if ($audPag > $audPaginas) { $audPag = $audPaginas; }
  $audOffset  = ($audPag - 1) * $audPerPage;

  $sqlAuditoria = "SELECT id, peca_id, campo, antes, depois, utilizador, data_alteracao FROM historico"
                . $whereSqlAud
                . " ORDER BY data_alteracao DESC, id DESC"
                . " LIMIT $audPerPage OFFSET $audOffset";

    $stmtAuditoria = $pdo->prepare($sqlAuditoria);
    $stmtAuditoria->execute($paramsAuditoria);
    $auditoriaLogs = $stmtAuditoria->fetchAll();

  // querystring dos filtros para manter na paginação
  $audExtra = '';
  if ($filtroUtilizador !== '') { $audExtra .= '&audit_user=' . urlencode($filtroUtilizador); }
  if ($filtroAcao !== '')       { $audExtra .= '&audit_action=' . urlencode($filtroAcao); }
}

$qrResultado = null;
$qrTermo = trim($_GET['qr_code'] ?? '');

  if ($page === 'qrs' && $qrTermo !== ''){
    $stmtQr = $pdo->prepare("
      SELECT *
      FROM pecas
      WHERE sn = ? OR cod_barras = ?
      ORDER BY id DESC
      LIMIT 1
    ");
  $stmtQr->execute([$qrTermo, $qrTermo]);
  $qrResultado = $stmtQr->fetch();

  if (!$qrResultado) {
    $_SESSION['mensagem_erro'] = 'Nenhuma peça encontrada. Preenche os restantes dados para criar uma nova peça.';
    $_SESSION['form_nova_peca'] = [
      'sn' =>$qrTermo,
      'cod_barras' => $qrTermo
    ];

    header('Location: app.php?page=nova_peca');
    exit;
  }
}

$envios = [];
$envioLinhas = [];
$envioVerId = isset($_GET['ver']) ? (int)$_GET['ver'] : (isset($_GET['draft']) ? (int)$_GET['draft'] : 0);
$envioAtual = null;
$parceirosInventario = [];
$categoriasInventarioReal = [];
$catalogoInventarioReal = [];

if ($page === 'envios') {
  $stmt = $pdo->query("
    SELECT id, documento, num_documento, data_documento, parceiro, criado_por, created_at, estado
    FROM envios
    ORDER BY created_at DESC
  ");
  $envios = $stmt->fetchAll();

  if ($envioVerId > 0) {
    $stmtEnvio = $pdo->prepare("SELECT * FROM envios WHERE id = ?");
    $stmtEnvio->execute([$envioVerId]);
    $envioAtual = $stmtEnvio->fetch();

    $stmtLinhas = $pdo->prepare("
      SELECT artigo, designacao, quantidade, num_serie
      FROM envios_linhas
      WHERE envio_id = ?
      ORDER BY id ASC
    ");
    $stmtLinhas->execute([$envioVerId]);
    $envioLinhas = $stmtLinhas->fetchAll();
  }

  $stmtParceiros = $pdo->query("SELECT DISTINCT parceiro FROM pecas WHERE parceiro IS NOT NULL AND parceiro <> '' ORDER BY parceiro ASC");
  $parceirosInventario = $stmtParceiros->fetchAll(PDO::FETCH_COLUMN);

  $stmtCategorias = $pdo->query("SELECT DISTINCT categoria FROM pecas WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria ASC");
  $categoriasInventarioReal = $stmtCategorias->fetchAll(PDO::FETCH_COLUMN);

  $stmtCatalogo = $pdo->query("SELECT categoria, produto FROM pecas WHERE categoria IS NOT NULL AND categoria <> '' AND produto IS NOT NULL AND produto <> '' ORDER BY categoria ASC, produto ASC");
  $catalogoRows = $stmtCatalogo->fetchAll();
  foreach ($catalogoRows as $row) {
    if (!isset($catalogoInventarioReal[$row['categoria']])) {
      $catalogoInventarioReal[$row['categoria']] = [];
    }
    if (!in_array($row['produto'], $catalogoInventarioReal[$row['categoria']])) {
      $catalogoInventarioReal[$row['categoria']][] = $row['produto'];
    }
  }
}




$clientes = [];
$clientesStats = [
    'total' => 0,
    'customers' => 0,
    'prospects' => 0,
    'partners' => 0,
    'com_parent' => 0,
    'grupos_parent' => 0
];
$clientesFiltros = [
    'q' => trim($_GET['q'] ?? ''),
    'type' => trim($_GET['type'] ?? ''),
    'hierarquia' => trim($_GET['hierarquia'] ?? '')
];
$clientesTipos = [];
$clientesPais = [];
$clientesRoots = [];
$clientesChildrenMap = [];

if ($page === 'alertas') {
    // Lista de clientes — a partir da tabela `clientes`
    // (importada do CSV via github/importar_clientes.php).
    try {
        $rows = $pdo->query("SELECT account_name, type, parent_account, last_activity, last_modified_date, activity_count FROM clientes ORDER BY account_name ASC")->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
        $clientesStats['csv_error'] = 'Tabela de clientes não encontrada. Corre o importador: php github/importar_clientes.php';
    }
    foreach ($rows as $r) {
        $accountName = (string)$r['account_name'];
        if ($accountName === '') { continue; }
        $type   = (string)($r['type'] ?? '');
        $parent = (string)($r['parent_account'] ?? '');
        $lastActivity = (string)($r['last_activity'] ?? '');
        $lastModified = (string)($r['last_modified_date'] ?? '');

        $cliente = [
            'account_name'       => $accountName,
            'type'               => $type,
            'parent_account'     => $parent,
            'last_activity'      => $lastActivity,
            'last_modified_date' => $lastModified,
            'is_child'           => $parent !== '',
            'activity_count'     => (int)($r['activity_count'] ?? 0),
        ];
        $clientes[] = $cliente;
        $clientesStats['total']++;
        if (strcasecmp($type, 'Customer') === 0) {
            $clientesStats['customers']++;
        } elseif (strcasecmp($type, 'Prospect') === 0) {
            $clientesStats['prospects']++;
        } elseif (stripos($type, 'Partner') !== false) {
            $clientesStats['partners']++;
        }
        if ($parent !== '') {
            $clientesStats['com_parent']++;
            if (!isset($clientesChildrenMap[$parent])) {
                $clientesChildrenMap[$parent] = [];
            }
            $clientesChildrenMap[$parent][] = $cliente;
        }
        if ($type !== '' && !in_array($type, $clientesTipos, true)) {
            $clientesTipos[] = $type;
        }
    }

    /* ===== BLOCO ANTIGO (leitura do CSV) — substituído pela BD acima. Mantido comentado como referência.
    $csvPath = __DIR__ . '/report1780499256737.csv';

    $clientesStats['debug'] = [
        'csv_path' => $csvPath,
        'csv_exists' => is_file($csvPath),
        'csv_readable' => is_readable($csvPath),
        'headers' => [],
        'detected_columns' => [],
        'csv_error' => '',
    ];

    if (is_file($csvPath) && is_readable($csvPath)) {
        $handle = fopen($csvPath, 'r');

        if ($handle !== false) {
            $header = fgetcsv($handle, 0, ';');

            $normalizeHeader = static function ($value) {
                $value = (string)$value;
                $value = trim($value);
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

                if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }

                $value = trim($value, "\"' \t\n\r\0\x0B");
                return $value;
            };

            $normalizeCsvValue = static function ($value) {
                $value = trim((string)$value);

                if ($value === '') {
                    return '';
                }

                if (!mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }

                $value = trim($value, "\"' \t\n\r\0\x0B");
                return $value;
            };

            $headerMap = [];

            if (is_array($header)) {
                foreach ($header as $i => $col) {
                    $normalized = $normalizeHeader($col);
                    $headerMap[$normalized] = $i;
                    $clientesStats['debug']['headers'][] = $normalized;
                }
            }

            $findColumn = static function (array $headerMap, array $variants) {
                foreach ($variants as $variant) {
                    if (array_key_exists($variant, $headerMap)) {
                        return $headerMap[$variant];
                    }
                }
                return null;
            };

            $idxLastActivity = $findColumn($headerMap, [
                'Last Activity'
            ]);

            $idxAccountName = $findColumn($headerMap, [
                'Account Name',
                'Nome da Conta',
                'Conta'
            ]);

            $idxLastModified = $findColumn($headerMap, [
                'Last Modified Date',
                'Last Modified',
                'Data da Última Modificação',
                'Última Modificação'
            ]);

            $idxType = $findColumn($headerMap, [
                'Type',
                'Account Type',
                'Tipo'
            ]);

            $idxParent = $findColumn($headerMap, [
                'Parent Account',
                'Parent',
                'Conta Principal',
                'Conta-Mãe',
                'Conta Mae'
            ]);

            $clientesStats['debug']['detected_columns'] = [
                'Last Activity' => $idxLastActivity,
                'Account Name' => $idxAccountName,
                'Last Modified Date' => $idxLastModified,
                'Type' => $idxType,
                'Parent Account' => $idxParent,
            ];

            $csvError = [];

            if ($idxAccountName === null) {
                $csvError[] = "Coluna 'Account Name' não encontrada no CSV.";
            }

            if ($idxParent === null) {
                $csvError[] = "Coluna 'Parent Account' (ou variantes) não encontrada no CSV.";
            }

            if ($idxType === null) {
                $csvError[] = "Coluna 'Type' (ou variantes) não encontrada no CSV. A importação vai continuar, mas os tipos ficarão vazios.";
            }

            if ($idxAccountName !== null && $idxParent !== null) {
                while (($row = fgetcsv($handle, 0, ';')) !== false) {
                    $accountName = $normalizeCsvValue($row[$idxAccountName] ?? '');
                    $type = $idxType !== null ? $normalizeCsvValue($row[$idxType] ?? '') : '';
                    $parent = $normalizeCsvValue($row[$idxParent] ?? '');
                    $lastActivity = $idxLastActivity !== null ? $normalizeCsvValue($row[$idxLastActivity] ?? '') : '';
                    $lastModified = $idxLastModified !== null ? $normalizeCsvValue($row[$idxLastModified] ?? '') : '';

                    if ($accountName === '') {
                        continue;
                    }

                    $cliente = [
                        'account_name' => $accountName,
                        'type' => $type,
                        'parent_account' => $parent,
                        'last_activity' => $lastActivity,
                        'last_modified_date' => $lastModified,
                        'is_child' => $parent !== '',
                    ];

                    $clientes[] = $cliente;
                    $clientesStats['total']++;

                    if (strcasecmp($type, 'Customer') === 0) {
                        $clientesStats['customers']++;
                    } elseif (strcasecmp($type, 'Prospect') === 0) {
                        $clientesStats['prospects']++;
                    } elseif (stripos($type, 'Partner') !== false) {
                        $clientesStats['partners']++;
                    }

                    if ($parent !== '') {
                        $clientesStats['com_parent']++;

                        if (!isset($clientesChildrenMap[$parent])) {
                            $clientesChildrenMap[$parent] = [];
                        }

                        $clientesChildrenMap[$parent][] = $cliente;
                    }

                    if ($type !== '' && !in_array($type, $clientesTipos, true)) {
                        $clientesTipos[] = $type;
                    }
                }
            }

            if (!empty($csvError)) {
                $clientesStats['csv_error'] = implode("\n", $csvError);
                $clientesStats['debug']['csv_error'] = implode("\n", $csvError);
            }

            fclose($handle);
        } else {
            $clientesStats['csv_error'] = 'Não foi possível abrir o CSV.';
            $clientesStats['debug']['csv_error'] = 'Não foi possível abrir o CSV.';
        }
    } else {
        $clientesStats['csv_error'] = 'CSV não encontrado ou sem permissões de leitura.';
        $clientesStats['debug']['csv_error'] = 'CSV não encontrado ou sem permissões de leitura.';
    }
    ===== FIM BLOCO ANTIGO ===== */

    sort($clientesTipos, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($clientesChildrenMap as $parentName => $children) {
        $clientesStats['grupos_parent']++;
    }

    $clientesIndex = [];

    foreach ($clientes as $cliente) {
        $clientesIndex[$cliente['account_name']] = $cliente;
    }

    foreach ($clientes as $cliente) {
        $nome = $cliente['account_name'];
        $temFilhos = isset($clientesChildrenMap[$nome]) && count($clientesChildrenMap[$nome]) > 0;

        $matchTexto = true;
        if ($clientesFiltros['q'] !== '') {
            $q = mb_strtolower($clientesFiltros['q']);
            $haystack = mb_strtolower(
                $cliente['account_name'] . ' ' . $cliente['type'] . ' ' . $cliente['parent_account']
            );
            $matchTexto = mb_strpos($haystack, $q) !== false;
        }

        $matchType = $clientesFiltros['type'] === '' || $cliente['type'] === $clientesFiltros['type'];

        $matchHierarquia = true;
        if ($clientesFiltros['hierarquia'] === 'com_parent') {
            $matchHierarquia = $cliente['parent_account'] !== '';
        } elseif ($clientesFiltros['hierarquia'] === 'so_pais') {
            $matchHierarquia = $temFilhos;
        } elseif ($clientesFiltros['hierarquia'] === 'so_sem_parent') {
            $matchHierarquia = $cliente['parent_account'] === '';
        }

        if (!$matchTexto || !$matchType || !$matchHierarquia) {
            continue;
        }

        // Contas-Filhas também aparecem quando o filtro de hierarquia as pede
        if ($cliente['parent_account'] === '' || $clientesFiltros['hierarquia'] === 'com_parent') {
            $clientesRoots[] = $cliente;
        }


    }

    usort($clientesRoots, static function ($a, $b) {
        $diff = ($b['activity_count'] ?? 0) - ($a['activity_count'] ?? 0);
        return $diff !== 0 ? $diff : strcasecmp($a['account_name'], $b['account_name']);
    });

    foreach ($clientesChildrenMap as $parentName => &$children) {
        usort($children, static function ($a, $b) {
            return strcasecmp($a['account_name'], $b['account_name']);
        });
    }
    unset($children);

    // Contactos/moradas (do Report .xlsx importado para clientes_contactos),
    // agregados por conta (uma conta pode ter vários contactos).
    $contactosMap = [];
    try {
        $stmtCt = $pdo->query("
            SELECT account_name,
                   GROUP_CONCAT(DISTINCT NULLIF(email,'')  SEPARATOR ', ') AS emails,
                   GROUP_CONCAT(DISTINCT NULLIF(phone,'')  SEPARATOR ', ') AS phones,
                   GROUP_CONCAT(DISTINCT NULLIF(mobile,'') SEPARATOR ', ') AS mobiles,
                   MIN(NULLIF(mailing_street,''))  AS street,
                   MIN(NULLIF(mailing_city,''))    AS city,
                   MIN(NULLIF(mailing_zip,''))     AS zip,
                   MIN(NULLIF(mailing_country,'')) AS country,
                   COUNT(*) AS n
              FROM clientes_contactos
             GROUP BY account_name
        ");
        foreach ($stmtCt as $ct) {
            $contactosMap[$ct['account_name']] = $ct;
        }
    } catch (Throwable $e) {
        $contactosMap = []; // tabela ainda não existe — ignora
    }
}


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

// Cor de cada estado — MESMA paleta do gráfico de pizza do Dashboard (estadoColors).
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
// N-Vi — assistente (linguagem natural -> SQL read-only, por regras)
// ============================================================
function nviSemAcento(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
    ]);
}

/**
 * Interpreta a pergunta e devolve um SELECT (read-only) com parâmetros.
 * Devolve ['erro'=>'...'] se não reconhecer.
 */
function nviInterpretar(string $perguntaOriginal, array $estados): array {
    $q = nviSemAcento($perguntaOriginal);
    $tem = function (string ...$ks) use ($q): bool {
        foreach ($ks as $k) {
            if (strpos($q, $k) !== false) return true;
        }
        return false;
    };

    // estado mencionado: 1.º entre aspas (ex.: 'Trânsito'); 2.º por palavra inteira,
    // ignorando as expressões de agrupamento ("por parceiro/categoria/estado")
    $estadoMatch = null;
    if (preg_match("/['\"]([^'\"]+)['\"]/u", $perguntaOriginal, $mq)) {
        $alvo = nviSemAcento(trim($mq[1]));
        foreach ($estados as $e) {
            if (nviSemAcento((string)$e) === $alvo) { $estadoMatch = $e; break; }
        }
    }
    if ($estadoMatch === null) {
        $qScan = preg_replace('/\bpor\s+(parceiro|categoria|tipo|estado)\b/u', ' ', $q);
        foreach ($estados as $e) {
            $en = nviSemAcento((string)$e);
            if ($en !== '' && preg_match('/\b' . preg_quote($en, '/') . '\b/u', $qScan)) {
                $estadoMatch = $e;
                break;
            }
        }
    }

    // 1) Top N tipos/categorias (opcionalmente "disponíveis")
    if ($tem('top') && $tem('tipo', 'categoria')) {
        $lim = preg_match('/top\s*(\d+)/', $q, $m) ? max(1, (int)$m[1]) : 5;
        $disp = $tem('disponive');
        return [
            'erro'   => '',
            'titulo' => "Top $lim tipos" . ($disp ? ' disponíveis' : ''),
            'sql'    => "SELECT categoria AS tipo, COUNT(*) AS total FROM pecas"
                      . ($disp ? " WHERE estado = 'Disponível'" : "")
                      . " GROUP BY categoria ORDER BY total DESC LIMIT $lim",
            'params' => [],
        ];
    }
    // 2) Últimas N peças
    if ($tem('ultima', 'recente') && $tem('peca')) {
        $lim = preg_match('/(\d+)/', $q, $m) ? min(200, max(1, (int)$m[1])) : 20;
        return [
            'erro'   => '',
            'titulo' => "Últimas $lim peças",
            'sql'    => "SELECT id, categoria, produto, sn, parceiro, estado, created_at
                         FROM pecas ORDER BY created_at DESC, id DESC LIMIT $lim",
            'params' => [],
        ];
    }
    // 3) Autor da ordem/envio mais recente
    if ($tem('autor', 'quem criou', 'criado por', 'quem') && $tem('ordem', 'envio')) {
        return [
            'erro'   => '',
            'titulo' => 'Autor da ordem mais recente',
            'sql'    => "SELECT criado_por, documento, num_documento, data_documento, created_at
                         FROM envios ORDER BY created_at DESC, id DESC LIMIT 1",
            'params' => [],
        ];
    }
    // 4) Peças por parceiro (com estado opcional)
    if ($tem('peca') && $tem('parceiro')) {
        if ($estadoMatch !== null) {
            return [
                'erro'   => '',
                'titulo' => "Peças em '$estadoMatch' por parceiro",
                'sql'    => "SELECT parceiro, COUNT(*) AS total FROM pecas WHERE estado = ? GROUP BY parceiro ORDER BY total DESC",
                'params' => [$estadoMatch],
            ];
        }
        return [
            'erro'   => '',
            'titulo' => 'Peças por parceiro',
            'sql'    => "SELECT parceiro, COUNT(*) AS total FROM pecas GROUP BY parceiro ORDER BY total DESC",
            'params' => [],
        ];
    }
    // 5) Peças por categoria/tipo
    if ($tem('peca') && $tem('categoria', 'tipo')) {
        return [
            'erro'   => '',
            'titulo' => 'Peças por categoria',
            'sql'    => "SELECT categoria, COUNT(*) AS total FROM pecas GROUP BY categoria ORDER BY total DESC",
            'params' => [],
        ];
    }
    // 6) Peças por estado (visão geral)
    if ($tem('peca') && $tem('estado') && $estadoMatch === null) {
        return [
            'erro'   => '',
            'titulo' => 'Peças por estado',
            'sql'    => "SELECT estado, COUNT(*) AS total FROM pecas GROUP BY estado ORDER BY total DESC",
            'params' => [],
        ];
    }
    // 7) Quantas peças (total ou num estado)
    if ($tem('quant') && $tem('peca')) {
        if ($estadoMatch !== null) {
            return [
                'erro'   => '',
                'titulo' => "Nº de peças em '$estadoMatch'",
                'sql'    => "SELECT COUNT(*) AS total FROM pecas WHERE estado = ?",
                'params' => [$estadoMatch],
            ];
        }
        return ['erro'=>'', 'titulo'=>'Nº total de peças', 'sql'=>"SELECT COUNT(*) AS total FROM pecas", 'params'=>[]];
    }
    // 8) PATs
    if ($tem('pat')) {
        if ($tem('estado')) {
            return ['erro'=>'', 'titulo'=>'PATs por estado', 'sql'=>"SELECT estado, COUNT(*) AS total FROM pats GROUP BY estado ORDER BY total DESC", 'params'=>[]];
        }
        return ['erro'=>'', 'titulo'=>'Nº total de PATs', 'sql'=>"SELECT COUNT(*) AS total FROM pats", 'params'=>[]];
    }
    // 9) Contactos de um cliente específico
    if ($tem('email', 'contacto', 'telefone', 'telemovel', 'morada')) {
        if (preg_match('/(?:de|do|da|dos|das)\s+(.+)$/u', $perguntaOriginal, $m)) {
            $nome = trim($m[1], " ?.\t\n");
            if ($nome !== '') {
                return [
                    'erro'   => '',
                    'titulo' => "Contactos de \"$nome\"",
                    'sql'    => "SELECT account_name, email, phone, mobile, mailing_city, mailing_country
                                 FROM clientes_contactos WHERE account_name LIKE ? ORDER BY account_name LIMIT 50",
                    'params' => ['%' . $nome . '%'],
                ];
            }
        }
    }
    // 10) Clientes (visão geral, a partir da tabela `clientes`)
    if ($tem('cliente')) {
        if ($tem('tipo')) {
            return [
                'erro'   => '',
                'titulo' => 'Clientes por tipo',
                'sql'    => "SELECT CASE LOWER(TRIM(COALESCE(type,'')))
                                      WHEN 'customer'                THEN 'Cliente'
                                      WHEN 'end customer'            THEN 'Cliente Final'
                                      WHEN 'own shop'                THEN 'Loja Própria'
                                      WHEN 'prospect'                THEN 'Potencial Cliente'
                                      WHEN 'exclusive agent'         THEN 'Agente Exclusivo'
                                      WHEN 'partner'                 THEN 'Parceiro'
                                      WHEN 'partner - portugal'      THEN 'Parceiro - Portugal'
                                      WHEN 'partner - international'  THEN 'Parceiro - Internacional'
                                      WHEN 'partner - spain'         THEN 'Parceiro - Espanha'
                                      WHEN 'partner - latam'         THEN 'Parceiro - LATAM'
                                      ELSE 'Sem tipo'
                                    END AS tipo,
                                    COUNT(*) AS total
                             FROM clientes GROUP BY tipo ORDER BY total DESC",
                'params' => [],
            ];
        }
        return [
            'erro'   => '',
            'titulo' => 'Nº de clientes',
            'sql'    => "SELECT COUNT(*) AS total_clientes,
                                SUM(parent_account IS NOT NULL AND parent_account <> '') AS com_conta_mae
                         FROM clientes",
            'params' => [],
        ];
    }

    return ['erro' => 'Não consegui interpretar a pergunta. Experimenta um dos exemplos rápidos (ex.: "Top 5 tipos disponíveis", "Últimas 20 peças", "Autor da ordem mais recente"), ou pergunta por peças/PATs/clientes por estado, categoria ou parceiro.'];
}

// Processamento da pergunta ao N-Vi (só na página nvi).
$nvi = ['pergunta'=>'', 'sql'=>'', 'titulo'=>'', 'colunas'=>[], 'linhas'=>[], 'erro'=>'', 'executou'=>false];
if ($page === 'nvi') {
    $nvi['pergunta'] = trim($_POST['pergunta'] ?? ($_GET['q'] ?? ''));
    if ($nvi['pergunta'] !== '') {
        $interp = nviInterpretar($nvi['pergunta'], $estados);
        if (!empty($interp['erro'])) {
            $nvi['erro'] = $interp['erro'];
        } else {
            // Salvaguarda: só permitir SELECT (read-only)
            if (stripos(ltrim($interp['sql']), 'select') !== 0) {
                $nvi['erro'] = 'Apenas são permitidas consultas de leitura.';
            } else {
                $nvi['sql']    = $interp['sql'];
                $nvi['titulo'] = $interp['titulo'];
                try {
                    $st = $pdo->prepare($interp['sql']);
                    $st->execute($interp['params'] ?? []);
                    $nvi['linhas']  = $st->fetchAll();
                    $nvi['colunas'] = $nvi['linhas'] ? array_keys($nvi['linhas'][0]) : [];
                    $nvi['executou'] = true;
                } catch (Throwable $e) {
                    $nvi['erro'] = 'Erro ao executar a consulta: ' . $e->getMessage();
                }
            }
        }
    }
}

// ============================================================
// TABELAS DE GESTÃO
// (categorias, estados, parceiros, fabricantes, produtos)
// ============================================================

// ---- Handlers POST: guardar / eliminar ----
// Helpers de validação das tabelas de gestão
function tabNomeDuplicado(PDO $pdo, string $tabela, string $coluna, string $valor, int $excluirId = 0, ?int $catId = -1): bool {
    $sql = "SELECT COUNT(*) FROM `$tabela` WHERE `$coluna` = ?";
    $params = [$valor];
    if ($catId !== -1) { $sql .= " AND categoria_id <=> ?"; $params[] = $catId; }
    if ($excluirId > 0) { $sql .= " AND id <> ?"; $params[] = $excluirId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}
function tabContar(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}
function tabValor(PDO $pdo, string $sql, array $params): string {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (string)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ft = $_POST['form_type'] ?? '';

    // ----- CATEGORIAS -----
    if ($ft === 'guardar_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            flashError('O nome da categoria é obrigatório.');
            redirectTo('app.php?page=categorias&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'categorias', 'nome', $nome, $id)) {
            flashError('Já existe uma categoria com esse nome.');
            redirectTo('app.php?page=categorias&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE categorias SET nome = ? WHERE id = ?");
            $stmt->execute([$nome, $id]);
            flashSuccess('Categoria atualizada com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO categorias (nome) VALUES (?)");
            $stmt->execute([$nome]);
            flashSuccess('Categoria criada com sucesso.');
        }
        redirectTo('app.php?page=categorias');
    }
    if ($ft === 'eliminar_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeCat = tabValor($pdo, "SELECT nome FROM categorias WHERE id = ?", [$id]);
        $emUso = tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE categoria = ?", [$nomeCat])
               + tabContar($pdo, "SELECT COUNT(*) FROM produtos WHERE categoria_id = ?", [$id]);
        if ($emUso > 0) {
            flashError('Não é possível eliminar esta categoria: está a ser usada em peças ou produtos.');
            redirectTo('app.php?page=categorias');
        }
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Categoria eliminada com sucesso.');
        redirectTo('app.php?page=categorias');
    }

    // ----- ESTADOS -----
    if ($ft === 'guardar_estado') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        if ($nome === '') {
            flashError('O nome do estado é obrigatório.');
            redirectTo('app.php?page=estados&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'estados', 'nome', $nome, $id)) {
            flashError('Já existe um estado com esse nome.');
            redirectTo('app.php?page=estados&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE estados SET nome = ?, descricao = ? WHERE id = ?");
            $stmt->execute([$nome, ($descricao !== '' ? $descricao : null), $id]);
            flashSuccess('Estado atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO estados (nome, descricao) VALUES (?, ?)");
            $stmt->execute([$nome, ($descricao !== '' ? $descricao : null)]);
            flashSuccess('Estado criado com sucesso.');
        }
        redirectTo('app.php?page=estados');
    }
    if ($ft === 'eliminar_estado') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeEst = tabValor($pdo, "SELECT nome FROM estados WHERE id = ?", [$id]);
        if (tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE estado = ?", [$nomeEst]) > 0) {
            flashError('Não é possível eliminar este estado: está a ser usado em peças.');
            redirectTo('app.php?page=estados');
        }
        $stmt = $pdo->prepare("DELETE FROM estados WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Estado eliminado com sucesso.');
        redirectTo('app.php?page=estados');
    }

    // ----- FABRICANTES -----
    if ($ft === 'guardar_fabricante') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            flashError('O nome do fabricante é obrigatório.');
            redirectTo('app.php?page=fabricantes&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'fabricantes', 'nome', $nome, $id)) {
            flashError('Já existe um fabricante com esse nome.');
            redirectTo('app.php?page=fabricantes&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fabricantes SET nome = ? WHERE id = ?");
            $stmt->execute([$nome, $id]);
            flashSuccess('Fabricante atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO fabricantes (nome) VALUES (?)");
            $stmt->execute([$nome]);
            flashSuccess('Fabricante criado com sucesso.');
        }
        redirectTo('app.php?page=fabricantes');
    }
    if ($ft === 'eliminar_fabricante') {
        $id = (int)($_POST['id'] ?? 0);
        if (tabContar($pdo, "SELECT COUNT(*) FROM produtos WHERE fabricante_id = ?", [$id]) > 0) {
            flashError('Não é possível eliminar este fabricante: está associado a produtos.');
            redirectTo('app.php?page=fabricantes');
        }
        $stmt = $pdo->prepare("DELETE FROM fabricantes WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Fabricante eliminado com sucesso.');
        redirectTo('app.php?page=fabricantes');
    }

    // ----- PRODUTOS -----
    if ($ft === 'guardar_produto') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $catId = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $fabId = (int)($_POST['fabricante_id'] ?? 0) ?: null;
        if ($nome === '') {
            flashError('O nome do produto é obrigatório.');
            redirectTo('app.php?page=produtos&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'produtos', 'nome', $nome, $id, $catId)) {
            flashError('Já existe um produto com esse nome nessa categoria.');
            redirectTo('app.php?page=produtos&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, categoria_id = ?, fabricante_id = ? WHERE id = ?");
            $stmt->execute([$nome, $catId, $fabId, $id]);
            flashSuccess('Produto atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO produtos (nome, categoria_id, fabricante_id) VALUES (?, ?, ?)");
            $stmt->execute([$nome, $catId, $fabId]);
            flashSuccess('Produto criado com sucesso.');
        }
        redirectTo('app.php?page=produtos');
    }
    if ($ft === 'eliminar_produto') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeProd = tabValor($pdo, "SELECT nome FROM produtos WHERE id = ?", [$id]);
        if (tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE produto = ?", [$nomeProd]) > 0) {
            flashError('Não é possível eliminar este produto: está a ser usado em peças.');
            redirectTo('app.php?page=produtos');
        }
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Produto eliminado com sucesso.');
        redirectTo('app.php?page=produtos');
    }

    // ----- PARCEIROS -----
    if ($ft === 'guardar_parceiro') {
        $id = (int)($_POST['id'] ?? 0);
        $empresa = trim($_POST['empresa'] ?? '');
        $campos = [
            'morada'            => trim($_POST['morada'] ?? ''),
            'contato1_nome'     => trim($_POST['contato1_nome'] ?? ''),
            'contato1_email'    => trim($_POST['contato1_email'] ?? ''),
            'contato1_telefone' => trim($_POST['contato1_telefone'] ?? ''),
            'contato2_nome'     => trim($_POST['contato2_nome'] ?? ''),
            'contato2_email'    => trim($_POST['contato2_email'] ?? ''),
            'contato2_telefone' => trim($_POST['contato2_telefone'] ?? ''),
        ];
        if ($empresa === '') {
            flashError('O nome da empresa é obrigatório.');
            redirectTo('app.php?page=parceiros&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'parceiros', 'empresa', $empresa, $id)) {
            flashError('Já existe um parceiro com esse nome de empresa.');
            redirectTo('app.php?page=parceiros&' . ($id ? "edit=$id" : 'nova=1'));
        }
        $vals = array_map(fn($v) => ($v !== '' ? $v : null), array_values($campos));
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE parceiros SET empresa = ?, morada = ?, contato1_nome = ?, contato1_email = ?, contato1_telefone = ?, contato2_nome = ?, contato2_email = ?, contato2_telefone = ? WHERE id = ?");
            $stmt->execute(array_merge([$empresa], $vals, [$id]));
            flashSuccess('Parceiro atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO parceiros (empresa, morada, contato1_nome, contato1_email, contato1_telefone, contato2_nome, contato2_email, contato2_telefone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute(array_merge([$empresa], $vals));
            flashSuccess('Parceiro criado com sucesso.');
        }
        redirectTo('app.php?page=parceiros');
    }
    if ($ft === 'eliminar_parceiro') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeParc = tabValor($pdo, "SELECT empresa FROM parceiros WHERE id = ?", [$id]);
        if (tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE parceiro = ?", [$nomeParc]) > 0) {
            flashError('Não é possível eliminar este parceiro: está a ser usado em peças.');
            redirectTo('app.php?page=parceiros');
        }
        $stmt = $pdo->prepare("DELETE FROM parceiros WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Parceiro eliminado com sucesso.');
        redirectTo('app.php?page=parceiros');
    }
}

// ---- Leitura de dados (lista + paginação + edição) ----
$tabPerPage  = 10;
$tabPag      = max(1, (int)($_GET['p'] ?? 1));
$tabOffset   = ($tabPag - 1) * $tabPerPage;
$tabListas   = [];
$tabPaginas  = 1;
$tabEdit     = null;
$parceiroVer = null;
$listaCategorias  = [];
$listaFabricantes = [];

function carregarTabela(PDO $pdo, string $sqlBase, int $perPage, int $offset, int &$paginas): array
{
    $total = (int)$pdo->query("SELECT COUNT(*) FROM ($sqlBase) t")->fetchColumn();
    $paginas = (int)ceil($total / $perPage);
    if ($paginas < 1) $paginas = 1;
    $stmt = $pdo->prepare("$sqlBase LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

if ($page === 'categorias') {
    $tabListas = carregarTabela($pdo, "SELECT * FROM categorias ORDER BY nome ASC", $tabPerPage, $tabOffset, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM categorias WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'estados') {
    $tabListas = carregarTabela($pdo, "SELECT * FROM estados ORDER BY nome ASC", $tabPerPage, $tabOffset, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM estados WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'fabricantes') {
    $tabListas = carregarTabela($pdo, "SELECT * FROM fabricantes ORDER BY nome ASC", $tabPerPage, $tabOffset, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM fabricantes WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'produtos') {
    $tabListas = carregarTabela(
        $pdo,
        "SELECT p.*, c.nome AS categoria_nome, f.nome AS fabricante_nome
           FROM produtos p
           LEFT JOIN categorias c ON c.id = p.categoria_id
           LEFT JOIN fabricantes f ON f.id = p.fabricante_id
          ORDER BY p.nome ASC",
        $tabPerPage, $tabOffset, $tabPaginas
    );
    $listaCategorias  = $pdo->query("SELECT id, nome FROM categorias ORDER BY nome ASC")->fetchAll();
    $listaFabricantes = $pdo->query("SELECT id, nome FROM fabricantes ORDER BY nome ASC")->fetchAll();
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'parceiros') {
    $tabListas = carregarTabela($pdo, "SELECT * FROM parceiros ORDER BY empresa ASC", $tabPerPage, $tabOffset, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM parceiros WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
    if (($_GET['ver'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM parceiros WHERE id = ?");
        $stmt->execute([(int)$_GET['ver']]);
        $parceiroVer = $stmt->fetch() ?: null;
    }
}

// Pager numerado reutilizável (estilo das capturas)
function paginacaoTabela(string $pageName, int $totalPaginas, int $atual, string $extra = ''): void
{
    if ($totalPaginas <= 1) return;
    echo '<div style="display:flex;gap:6px;margin-top:18px;">';
    for ($i = 1; $i <= $totalPaginas; $i++) {
        $cls = $i === $atual ? 'btn btn-blue' : 'btn btn-grey';
        echo '<a class="' . $cls . '" href="app.php?page=' . $pageName . '&p=' . $i . $extra . '">' . $i . '</a>';
    }
    echo '</div>';
}

?>


<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>StockVision</title>
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
  padding: 14px 26px;
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
  padding:14px 26px;
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
  font-size: 25px;
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
  font-size:25px;
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
         gap: 36px !important; 
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

.table{
        width:100%;
        border-collapse:collapse;
        background:#fff;
      }
.table th,.table td{
                      border:1px solid #e5e7eb;
                      padding:12px;
                      text-align:left;
                      vertical-align:middle;
                    }
.table th{
            background:#f6f7f9;
          }
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
          margin:18px 0;
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
                padding:8px 12px;
                font-size:14px;
                margin-right:6px;
                margin-bottom:0;
                white-space:nowrap;
              }
.small-note{color:#6b7280;margin-top:6px}

@media (max-width: 1200px){
}

@media (max-width: 1366px){
  .kpi-row{gap:24px;}
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
  display:grid;
  grid-template-columns:260px 1fr;
  column-gap:40px;
  align-items:center;
  width:100%;
}

.estado-chart-box{
  width:260px;
  display:flex;
  justify-content:center;
}

.estado-chart-box canvas{
  width:260px !important;
  height:260px !important;
}

.legend-container{
  width:100%;
  min-width:0;
}

.legend-text{
  display:grid;
  grid-template-columns:repeat(2, minmax(180px, 1fr));
  column-gap:24px;
  row-gap:14px;
  width:100%;
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
    grid-template-columns:240px 1fr;
    column-gap:32px;
    align-items:center;
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

@media (max-width:768px){
  .estado-layout{
    grid-template-columns:1fr;
    justify-items:center;
    row-gap:20px;
    width:100%;
  }

  .legend-container{
    width:100%;
    display:flex;
    justify-content:center;
  } 

  .legend-text{
    grid-template-columns:1fr;
    width:max-content;
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
    background: #f8fafc;
    color: #374151;
    font-size: 14px;
    font-weight: 700;
    text-align: left;
    padding: 12px 10px;
    border-bottom: 1px solid #dfe3e8;
    border-right: 1px solid #e5e7eb;
}

.auditoria-tabela thead th:last-child {
    border-right: none;
}

.auditoria-tabela tbody td {
    padding: 11px 10px;
    font-size: 14px;
    color: #1f2937;
    border-top: 1px solid #eef1f4;
    border-right: 1px solid #eef1f4;
    vertical-align: top;
}

.auditoria-tabela tbody td:last-child {
    border-right: none;
}

.auditoria-tabela tbody tr:nth-child(odd) {
    background: #fff8e8;
}

.auditoria-tabela tbody tr:nth-child(even) {
    background: #eefaf4;
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

.alerta-erro {
  background: #f8d7da;
  color: #842029;
  border: 1px solid #f5c2c7;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
  font-size: 14px;
  }

.alerta-sucesso {
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
    min-width:760px;
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
    white-space:nowrap;
  }

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
  display:grid;
  grid-template-columns:1.4fr 1fr 1fr auto;
  gap:18px;
  align-items:end;
  margin-bottom:20px;
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
  .clientes-kpis,
  .clientes-filtros{
    grid-template-columns:1fr;
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
    transiction: background .12s;
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
    cursor: 10px;
    background: none;
    border: none;
    color: #fff;
    font-size: 15px;
    padding: 6px 10px;
    border-radius: 8px;
    transitions: background .15s;
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
    transiction: background .12s;
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
body.dark-mode .main { backgroud: #111827; }
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
    padding: 10px 14px; text-decoration: none; color: #1f2937;
    font-size: 13px; border-bottom: 1px solid #f9fafb; transition: background .12s;
}
.notif-item:hover { background: #f3f4f6; }
.notif-item:last-child { border-bottom: none; }
.notif-dot-urgente { color: #ef4444; font-size: 10px; margin-top: 3px; }
.notif-dot-prazo   { color: #f59e0b; font-size: 10px; margin-top: 3px; }
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

</style>
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

  <a class="<?=active('alertas',$page)?>" href="app.php?page=alertas">
    <i class="bi bi-people"></i><span>Clientes</span>
  </a>

  <a class="<?=active('qrs',$page)?>" href="app.php?page=qrs">
    <i class="bi bi-qr-code"></i><span>QR's</span>
  </a>

  <a class="<?=active('encomendas',$page)?>" href="app.php?page=encomendas">
    <i class="bi bi-cart"></i><span>Encomendas</span>
  </a>

  <div class="sidebar-group <?= in_array($page, ['categorias','estados','parceiros','fabricantes','produtos']) ? 'open' : '' ?>">
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
    <a class="submenu-link <?=active('fabricantes',$page)?>" href="app.php?page=fabricantes">
      <span>Fabricantes</span>
    </a>
    <a class="submenu-link <?=active('produtos',$page)?>" href="app.php?page=produtos">
      <span>Produtos</span>
    </a>
  </div>
</div>

  <a class="<?=active('nvi',$page)?>" href="app.php?page=nvi">
    <i class="bi bi-robot"></i><span>N-Vi</span>
  </a>

  <div class="sidebar-group <?= in_array($page, ['contas', 'auditoria']) ? 'open' : '' ?>">
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
<?php if ($page === 'dashboard'): ?>

<!-- Dashboard-Quadrados --> 
  <div class="kpi-row">
    <div class="kpi-card">
      <i class="bi bi-box"></i>
        <div class="num"> <?=$totalPecas?></div>
          <div>Total Peças</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-clipboard"></i>
        <div class="num"><?=$patsAtivos?></div>
          <div>PATs Ativos</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-send"></i>
        <div class="num"><?=$ordensAtivas?></div>
          <div>Ordens Ativas</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-x-circle" style="color:#e05d57"></i>
        <div class="num"><?=$ordensCanceladas?></div>
          <div>Ordens Canceladas</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-check-circle" style="color:#2ca59a"></i>
        <div class="num"><?=$ordensConcluidas?></div>
          <div>Ordens Concluídas</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-stopwatch"></i>
        <div class="num" style="font-size:24px;line-height:1.1">1.5h</div>
          <div>~ PAT→Execução</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-calendar3"></i>
        <div class="num" style="font-size:18px;line-height:1.1"><?= $ultimoPat ? date('d/m', strtotime($ultimoPat)) : '-' ?></div>
          <div>Último PAT</div>    
    </div>
  </div>




<!-- Dashboard-PIZZA -->
  <!-- Painel grande do gráfico circular -->
<div class="panel panel-estado">
  <h4>Estados das Peças</h4>

  <div class="estado-layout">
    <div class="estado-chart-box">
      <canvas id="estadoChart"></canvas>
    </div>

    <div class="legend-container">

      <div class="legend-text">
        <div class="legend-item">
          <div class="legend-color" style="background: #28a745;"></div>
          <span>Disponível</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #6f42c1;"></div>
          <span>PAT</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #2470dc;"></div>
          <span>Laboratório</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #dc3545;"></div>
          <span>Abater</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #20c997;"></div>
          <span>Cliente</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #ffc107;"></div>
          <span>Desconhecido</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #17a2b8;"></div>
          <span>Devolução</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #fd7e14;"></div>
          <span>Fornecedor(Reparação)</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #495057;"></div>
          <span>OT</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #8c564b;"></div>
          <span>Parceiro</span>
        </div>
        
        <div class="legend-item">
          <div class="legend-color" style="background: #47372A;"></div>
          <span>Spares</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <h4 style="margin:0;">Atividade Recente — Inventário</h4>
        <a href="app.php?page=auditoria" style="font-size:13px; color:#cba35c; text-decoration:none;">Ver tudo →</a>
    </div>
    <?php if (empty($actividadeRecente)): ?>
        <div style="text-align:center; color:#9ca3af; padding:24px; font-size:14px;">
            <i class="bi bi-clock-history" style="font-size:28px; display:block; margin-bottom:8px; opacity:.4;"></i>
            Sem atividade registada.
        </div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px;">
        <?php foreach ($actividadeRecente as $a):
            $icons = [
                'criação'    => ['bi-plus-circle-fill', '#22c55e'],
                'estado'     => ['bi-arrow-left-right', '#3b82f6'],
                'parceiro'   => ['bi-building',          '#8b5cf6'],
                'eliminação' => ['bi-trash3-fill',       '#ef4444'],
                'produto'    => ['bi-tag',                '#f59e0b'],
                'categoria'  => ['bi-folder',             '#0ea5e9'],
                'sn'         => ['bi-upc-scan',           '#6366f1'],
                'cod_barras' => ['bi-barcode',            '#64748b'],
                'envio'      => ['bi-send',               '#06b6d4'],
            ];
            [$ico, $cor] = $icons[$a['campo']] ?? ['bi-pencil', '#6b7280'];
            $nome = $a['produto'] ?: ('Peça #' . $a['peca_id']);
            $diff = time() - strtotime($a['data_alteracao']);
            $ago  = $diff < 60   ? 'agora mesmo'
                  : ($diff < 3600  ? round($diff/60).'min atrás'
                  : ($diff < 86400 ? round($diff/3600).'h atrás'
                  : date('d/m/Y H:i', strtotime($a['data_alteracao']))));
            $descricao = match($a['campo']) {
                'criação'    => 'Adicionada ao inventário',
                'eliminação' => 'Removida do inventário',
                'estado'     => htmlspecialchars($a['antes']) . ' → ' . htmlspecialchars($a['depois']),
                'parceiro'   => 'Parceiro: ' . htmlspecialchars($a['depois']),
                'produto'    => 'Nome: ' . htmlspecialchars($a['depois']),
                'categoria'  => 'Categoria: ' . htmlspecialchars($a['depois']),
                'sn'         => 'SN: ' . htmlspecialchars($a['depois']),
                'cod_barras' => 'Cód: ' . htmlspecialchars($a['depois']),
                'envio'      => htmlspecialchars($a['depois']),
                default      => ucfirst(htmlspecialchars($a['campo'])) . ': ' . htmlspecialchars($a['depois']),
            };
        ?>
        <div style="display:flex; gap:10px; padding:12px 14px; background:#f9fafb; border:1px solid #f0f0f0; border-radius:10px; border-left:3px solid <?= $cor ?>;">
            <div style="width:34px; height:34px; border-radius:50%; background:<?= $cor ?>1a; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="bi <?= $ico ?>" style="font-size:16px; color:<?= $cor ?>;"></i>
            </div>
            <div style="flex:1; min-width:0;">
                <div style="font-size:13px; font-weight:700; color:#111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?= htmlspecialchars($nome) ?>
                </div>
                <?php if ($a['sn']): ?>
                <div style="font-size:11px; color:#9ca3af; margin-top:1px; font-family:monospace;">
                    SN: <?= htmlspecialchars($a['sn']) ?>
                </div>
                <?php endif; ?>
                <div style="font-size:12px; color:#374151; margin-top:4px; font-weight:500;">
                    <?= $descricao ?>
                </div>
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">
                    <?= htmlspecialchars($a['utilizador']) ?> · <?= $ago ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel-grid-2">
  <div class="panel">
    <h4>Tendência (6 meses)</h4>
    <canvas id="trendChart"></canvas>
  </div>

  <div class="panel">
    <h4>Stock por Categorias</h4>
    <canvas id="categoriaChart"></canvas>
  </div>
</div>



<?php elseif ($page === 'inventario'): ?>
  <div style="margin-bottom:18px">
    <a class="btn btn-teal" href="app.php?page=nova_peca">Adicionar Peça</a>
    <a class="btn btn-green" href="app.php?page=qrs">Ler</a>
    <a href="exportar_inventario_csv.php" class="btn btn-green" style="padding:12px 16px;">
        <i class="bi bi-download"></i> Exportar CSV
    </a>
  </div>

  <?php if (!empty($_SESSION['mensagem_erro'])) {
      echo '<div class="alerta-erro">' . htmlspecialchars($_SESSION['mensagem_erro']) . '</div>';
      unset($_SESSION['mensagem_erro']);
  }
  ?>

  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?> 
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
    <?php unset($_SESSION['mensagem_sucesso']); ?>
    <?php endif; ?>

  <form method="get">
    <input type="hidden" name="page" value="inventario">
      <div class="filters">
        <div><label>Tipo:</label><label>
                <select name="categoria">
                  <option value="">-- Todos --</option>
                    <?php foreach($categorias as $cat): ?>
                    <option value="<?=$cat?>" <?= $filters['categoria']===$cat?'selected':'' ?>><?=$cat?></option><?php endforeach; ?></select>
            </label>
        </div>

      <div><label>Estado:</label><label>
              <select name="estado">
                <option value="">-- Todos --</option>
                  <?php foreach($estados as $estado): ?>
                  <option value="<?=$estado?>" <?= $filters['estado']===$estado?'selected':'' ?>><?=$estado?></option><?php endforeach; ?></select>
          </label>
      </div>

      <div><label>Parceiro:</label><label>
              <select name="parceiro">
                <option value="">-- Todos --</option>
                  <?php foreach($parceiros as $parceiro): ?>
                  <option value="<?=$parceiro?>" <?= $filters['parceiro']===$parceiro?'selected':'' ?>><?=$parceiro?></option>
                  <?php endforeach; ?></select>
          </label>
      </div>

      <div><button class="btn btn-blue" type="submit"><i class="bi bi-search"></i> Filtrar</button></div>
    </div>

    <div class="filters2">
      <div><label>SN (N.º de série):</label>
          <label>
              <input type="text" name="sn" value="<?=htmlspecialchars($filters['sn'])?>" placeholder="ex.: ABC12345">
          </label>
      </div>

<div>
  <label>Nome da peça:</label>
    <label>
        <select name="produto">
          <option value="">-- Todos --</option>
          <?php foreach ($catalogoProdutos as $categoriaCatalogo => $produtos): ?>
            <optgroup label="<?= htmlspecialchars($categoriaCatalogo) ?>">
              <?php foreach ($produtos as $produto): ?>
                <option value="<?= htmlspecialchars($produto) ?>" <?= $filters['produto'] === $produto ? 'selected' : '' ?>>
                  <?= htmlspecialchars($produto) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
    </label>
</div>


      <div><a class="btn btn-blue" href="app.php?page=inventario">Limpar / Mostrar tudo</a>
      </div>
    </div>
  </form>

  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th>Categoria</th>
      <th>Produto</th>
      <th>SN</th>
      <th>Cod. Barras</th>
      <th>PAT</th>
      <th>Parceiro</th>
      <th>Estado</th>
      <th>Ações</th>
          </tr>
    </thead>


  <tbody>
    <?php foreach($pecas as $p): ?>
      <tr>
        <td><?=$p['id']?></td>
        <td><?=htmlspecialchars($p['categoria'])?></td>
        <td><?=htmlspecialchars($p['produto'])?></td>
        <td><?=htmlspecialchars($p['sn'])?></td>
        <td><?=htmlspecialchars($p['cod_barras'])?></td>
        <td>N/A</td>
        <td><?=htmlspecialchars($p['parceiro'])?></td>
          <td><?= estadoBolha($p['estado']) ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?=$p['id']?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar peça?');">
                <input type="hidden" name="form_type" value="eliminar_peca">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
              <a class="btn btn-grey" href="app.php?page=historico&id=<?=$p['id']?>">Histórico</a>
            </td>
        </tr>

      <?php endforeach; ?>
    </tbody>
  </table>



<?php elseif ($page === 'nova_peca'): ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); ?>
  <?php endif; ?>
  
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); ?>
<?php endif; ?>

  <form method="post" class="panel">
    <input type="hidden" name="form_type" value="nova_peca">
    <?php if ($pecaEdit): ?>
      <input type="hidden" name="edit_id" value="<?= (int)$pecaEdit['id'] ?>">
    <?php endif; ?>

    <div class="form-grid">
      <div>
        <label>Categoria:*</label>
          <label for="categoria"></label><select name="categoria" id="categoria" required>
          <option value="">-- Selecione a categoria --</option>
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= ($valorCategoria === $cat) ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Nome do Produto:*</label>
          <label for="produto"></label><select name="produto" id="produto" required>
          <option value="">-- Selecione o produto --</option>
        </select>
      </div>

      <div>
        <label>Parceiro:*</label>
          <label>
              <select name="parceiro" required>
                <option value="">-- Selecione o parceiro --</option>
                <?php foreach ($parceiros as $parceiro): ?>
                  <option value="<?= htmlspecialchars($parceiro) ?>" <?= ($valorParceiro === $parceiro) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($parceiro) ?>
                  </option>
                <?php endforeach; ?>
              </select>
          </label>
      </div>

      <div>
        <label>Estado:*</label>
          <label>
              <select name="estado" required>
                <option value="">-- Selecione o estado --</option>
                <?php foreach ($estados as $estado): ?>
                  <option value="<?= htmlspecialchars($estado) ?>" <?= ($valorEstado === $estado) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($estado) ?>
                  </option>
                <?php endforeach; ?>
              </select>
          </label>
      </div>

      <div>
        <label>Número de Série (S_Number):*</label>
          <label for="sn"></label><input type="text" name="sn" id="sn" value="<?= htmlspecialchars($valorSn) ?>" required>
      </div>
      
      <div>
        <label>Código de Barras:*</label>
        <div class="barcode-copy-wrap">
            <label for="cod_barras"></label><input type="text" name="cod_barras" id="cod_barras" value="<?= htmlspecialchars($valorCodBarras) ?>" required>
          <button type="button" class="btn btn-grey btn-copy-sn" id="copiarSnBtn">Copiar SN</button>
        </div>
        <div class="small-note" id="copySnFeedback" style="display:none;">SN copiado para o Código de Barras.</div>
      </div>
        
    <div style="margin-top:20px">
      <button class="btn btn-blue" type="submit"><?= $pecaEdit ? 'Atualizar' : 'Guardar' ?></button>
      <a class="btn btn-yellow" href="app.php?page=inventario">← Voltar à lista de peças</a>
    </div>
  </form>



<?php elseif ($page === 'historico'): ?>
  <h1 class="section-title">
  Histórico da Peça #<?= htmlspecialchars($pecaHist['id'] ?? $_GET['id'] ?? '') ?>
  <?php if (!empty($pecaHist['produto'])): ?>
    (<?= htmlspecialchars($pecaHist['produto']) ?>)
  <?php endif; ?>
</h1>

<div class="small-note">
  Número de Série (SN):
  <strong><?= htmlspecialchars($pecaHist['sn'] ?? 'Sem registo') ?></strong>
</div>

  <table class="table" style="margin-top:18px">
    <thead>
      <tr>
        <th>Data</th>
        <th>Campo</th>
        <th>Antes</th>
        <th>Depois</th>
        <th>Utilizador</th>
      </tr>
    </thead>

  <tbody>
      <?php foreach($historico as $h): ?>
        <tr>
          <td><?=date('d/m/Y H:i', strtotime($h['data_alteracao']))?></td>
          <td><?= htmlspecialchars($h['campo']) ?></td>
          <td style="color:#d9534f"><?= htmlspecialchars($h['antes']) ?></td>
          <td style="color:#28a745"><?= htmlspecialchars($h['depois']) ?></td>
          <td><?= htmlspecialchars($h['utilizador']) ?></td>
        </tr>
      <?php endforeach; ?>
  </tbody>
  </table>

  <div style="margin-top:16px">
    <a class="btn btn-yellow" href="app.php?page=inventario">← Voltar à lista de peças</a>
  </div>


<?php elseif ($page === 'envios'): ?>

<!-- == Linha Superior: Leitura Guia (Esquerda) + Formulario (Direita) == -->
<div style="display="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-bottom:20px;">
   <div class="panel" style="height:100%;">
      <h4 style="margin-bottom:16px;"><i class="bi bi-file-earmark-pdf" style="margin-right:6px; color:#c9a14a;"></i>Leitura de Guia de Transporte</h4>
      <form method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="form_type" value="importar_guia_envio">
          <div style="margin-bottom:14px;">
              <label style="margin-bottom:5px; display:block;">PDF da Guia</label>
              <input type="file" name="guia_pdf" accept=".pdf,application/pdf" required style="width:100%;">
          </div>
          <button type="submit" class="btn btn-blue" style="width:100%;">Ler Guia</button>
      </form>
</div>

    <!-- PAINEL DIREITO: Formulário / Rascunho -->
    <div class="panel" style="height:100%;">
        <h4 style="margin-bottom:16px;">
            <i class="bi bi-send" style="margin-right:6px; color:#c9a14a;"></i>
            <?= $envioAtual ? 'Rascunho / Validação do Envio' : 'Novo Envio' ?>
            <?php if ($envioAtual): ?>
                <span style="font-size:12px; font-weight:500; color:#6b7280; margin-left:10px;">
                Estado: <strong style="color:<?= ($envioAtual['estado'] === 'Rascunho') ? '#b45309' : '#16a34a' ?>">
                    <?= htmlspecialchars($envioAtual['estado']) ?>
                </strong>
            </span>
            <?php endif; ?>
        </h4>

        <?php if ($envioAtual): ?>
            <?php
            $docAtual     = strtolower(trim($envioAtual['documento'] ?? ''));
            $isCliente    = ($docAtual === 'g.transp cliente' || $docAtual === 'g. transp cliente');
            $isFornecedor = ($docAtual === 'g. transp fornec');
            ?>

            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:18px; font-size:13px; color:#15803d;">
                Guia lida com sucesso. Necessário rever os dados!
            </div>

            <form method="post" autocomplete="off">
                <input type="hidden" name="form_type" value="guardar_rascunho_envio">
                <input type="hidden" name="envio_id" value="<?= (int)$envioAtual['id'] ?>">

                <div class="form-grid">
                    <div>
                        <label>Documento</label>
                        <select name="documento" id="documento_envio" required>
                            <option value="">-- Selecione --</option>
                            <option value="G. Transp Fornec" <?= $isFornecedor ? 'selected' : '' ?>>G. Transp Fornec</option>
                            <option value="G.Transp Cliente" <?= $isCliente ? 'selected' : '' ?>>G. Transp Cliente</option>
                        </select>
                    </div>
                    <div>
                        <label>Nº Documento</label>
                        <input type="text" name="num_documento" value="<?= htmlspecialchars($envioAtual['num_documento'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Data</label>
                        <input type="date" name="data_documento" value="<?= htmlspecialchars($envioAtual['data_documento'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Parceiro</label>
                        <?php if ($isCliente): ?>
                            <select name="parceiro" required>
                                <option value="Field Service" selected>Field Service</option>
                            </select>
                            <span class="small-note">Guia Cliente -> SEMPRE FIELD!</span>
                        <?php else: ?>
                            <select name="parceiro" required>
                                <option value="">-- Selecione --</option>
                                <?php foreach ($parceirosInventario as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>" <?= (($envioAtual['parceiro'] ?? '') === $p) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <h4 style="margin:22px 0 12px;">Linhas do Envio</h4>
                <div id="linhasEnvioWrap">
                    <?php if (empty($envioLinhas)): ?>
                        <div class="linha-envio-grid">
                            <label><select name="linha_categoria[]" class="linha-categoria" required>
                                    <option value="">-- Tipo --</option>
                                    <?php foreach ($categoriasInventarioReal as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                            <label><select name="linha_produto[]" class="linha-produto" data-selected="" required>
                                    <option value="">-- Nome da Peça --</option>
                                </select></label>
                            <label><input type="number" step="1" min="1" name="linha_quantidade[]" value="1" required></label>
                            <label><input type="text" name="linha_num_serie[]" class="linha-num-serie" placeholder="Nº Série"></label>
                            <div class="sn-avisos"></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($envioLinhas as $i => $linha): ?>
                            <div class="linha-envio-grid" data-linha-index="<?= (int)$i ?>">
                                <label><select name="linha_categoria[]" class="linha-categoria" required>
                                        <option value="">-- Tipo --</option>
                                        <?php foreach ($categoriasInventarioReal as $cat): ?>
                                            <option value="<?= htmlspecialchars($cat) ?>" <?= (($linha['artigo'] ?? '') === $cat) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select></label>
                                <label><select name="linha_produto[]" class="linha-produto" data-selected="<?= htmlspecialchars($linha['designacao'] ?? '') ?>" required>
                                        <option value="">-- Nome da Peça --</option>
                                    </select></label>
                                <label><input type="number" step="1" min="1" name="linha_quantidade[]" value="<?= htmlspecialchars($linha['quantidade'] ?? 1) ?>" required></label>
                                <label><input type="text" name="linha_num_serie[]" class="linha-num-serie" value="<?= htmlspecialchars($linha['num_serie'] ?? '') ?>" placeholder="Nº Série"></label>
                                <div class="sn-avisos">
                                    <?php if (!empty($linha['observacoes'])): ?>
                                        <div class="small-note" style="color:#b26a00;"><?= htmlspecialchars($linha['observacoes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <button type="button" class="btn btn-grey" id="adicionarLinhaEnvio">+ Linha</button>
                    <button type="submit" class="btn btn-blue">Guardar Rascunho</button>
                </div>
            </form>

            <div style="margin-top:14px; padding-top:14px; border-top:1px solid #e5e7eb; display:flex; gap:10px; flex-wrap:wrap;">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="form_type" value="confirmar_envio_final">
                    <input type="hidden" name="envio_id" value="<?= (int)$envioAtual['id'] ?>">
                    <button type="submit" class="btn btn-green">✓ Confirmar e Guardar Envio</button>
                </form>
                <?php if (($envioAtual['estado'] ?? '') === 'Rascunho'): ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Tem a certeza que quer apagar a Guia?');">
                        <input type="hidden" name="form_type" value="apagar_envio">
                        <input type="hidden" name="envio_id" value="<?= (int)$envioAtual['id'] ?>">
                        <button type="submit" class="btn btn-red">Apagar Guia</button>
                    </form>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div style="text-align:center; padding:40px 20px; color:#6b7280;">
                <div style="font-size:40px; margin-bottom:12px;">📄</div>
                <p style="font-size:15px; font-weight:500; margin-bottom:6px;">Nenhum rascunho aberto</p>
                <p style="font-size:13px;">Faz a leitura de uma Guia para começar.</p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- fim grid superior -->


<!-- ══ LINHA INFERIOR: Lista de Envios (largura total) ══ -->
<div class="panel">
    <h4 style="margin-bottom:18px;"><i class="bi bi-list-ul" style="margin-right:6px; color:#c9a14a;"></i>Lista de Envios</h4>
    <div style="overflow-x:auto;">
        <table class="table envios-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Documento</th>
                <th>Nº Documento</th>
                <th>Data</th>
                <th>Parceiro</th>
                <th>Estado</th>
                <th>Criado Por</th>
                <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($envios)): ?>
                <tr><td colspan="8" class="envios-vazio">Nenhum envio registado.</td></tr>
            <?php else: ?>
                <?php foreach ($envios as $e): ?>
                    <tr>
                        <td><?= (int)$e['id'] ?></td>
                        <td><?= htmlspecialchars($e['documento']) ?></td>
                        <td><?= htmlspecialchars($e['num_documento']) ?></td>
                        <td><?= htmlspecialchars($e['data_documento'] ? date('d/m/Y', strtotime($e['data_documento'])) : '—') ?></td>
                        <td><?= htmlspecialchars($e['parceiro']) ?></td>
                        <td>
                       <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;
                               background:<?= $e['estado']==='Rascunho' ? '#fef3c7' : ($e['estado']==='Ativa' ? '#dcfce7' : ($e['estado']==='Concluida' ? '#dbeafe' : '#f3f4f6')) ?>;
                               color:<?= $e['estado']==='Rascunho' ? '#92400e' : ($e['estado']==='Ativa' ? '#15803d' : ($e['estado']==='Concluida' ? '#1d4ed8' : '#374151')) ?>;">
                         <?= htmlspecialchars($e['estado']) ?>
                       </span>
                        </td>
                        <td><?= htmlspecialchars($e['criado_por']) ?></td>
                        <td>
                            <?php if (($e['estado'] ?? '') === 'Rascunho'): ?>
                                <a class="btn btn-yellow" href="app.php?page=envios&draft=<?= (int)$e['id'] ?>">Abrir Rascunho</a>
                            <?php else: ?>
                                <a class="btn btn-grey" href="app.php?page=envios&ver=<?= (int)$e['id'] ?>">Ver</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<?php elseif ($page === 'qrs'): ?>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">

        <!-- PAINEL ESQUERDO: Câmara -->
        <div class="panel">
            <h4 style="margin-bottom:4px;"><i class="bi bi-camera" style="color:#c9a14a; margin-right:6px;"></i>Leitura Automática</h4>
            <p style="font-size:12px; color:#6b7280; margin-bottom:14px;">Permite o acesso à câmara e aponta para o código.</p>
            <div id="reader" style="width:100%; border-radius:10px; overflow:hidden; background:#000; aspect-ratio:1;"></div>
        </div>

        <!-- PAINEL DIREITO: Pesquisa manual + Resultado -->
        <div style="display:flex; flex-direction:column; gap:16px;">

            <!-- Caixa de pesquisa -->
            <div class="panel">
                <h4 style="margin-bottom:14px;"><i class="bi bi-search" style="color:#c9a14a; margin-right:6px;"></i>Pesquisa Manual</h4>
                <form method="get">
                    <input type="hidden" name="page" value="qrs">
                    <div style="display:flex; gap:10px;">
                        <input type="text" name="qr_code" id="qr_code"
                               value="<?= htmlspecialchars($qrTermo) ?>"
                               placeholder="SN ou Código de Barras"
                               style="flex:1;">
                        <button type="submit" class="btn btn-blue">Procurar</button>
                        <?php if ($qrTermo !== ''): ?>
                            <a href="app.php?page=qrs" class="btn btn-grey">✕</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Resultado -->
            <?php if ($qrTermo !== ''): ?>
                <div class="panel">
                    <?php if ($qrResultado): ?>
                        <!-- ✅ Peça encontrada -->
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #e5e7eb;">
                            <div style="width:44px; height:44px; background:#dcfce7; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;">✅</div>
                            <div>
                                <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($qrResultado['produto']) ?></div>
                                <div style="font-size:12px; color:#6b7280; font-family:monospace;"><?= htmlspecialchars($qrResultado['sn']) ?></div>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:18px;">
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">ID</div>
                                <div style="font-weight:600;">#<?= (int)$qrResultado['id'] ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Categoria</div>
                                <div style="font-weight:600;"><?= htmlspecialchars($qrResultado['categoria']) ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Parceiro</div>
                                <div style="font-weight:600;"><?= htmlspecialchars($qrResultado['parceiro'] ?: '—') ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Estado</div>
                                <div style="font-weight:600;"><?= htmlspecialchars($qrResultado['estado']) ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px; grid-column:1/-1;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Código de Barras</div>
                                <div style="font-weight:600; font-family:monospace;"><?= htmlspecialchars($qrResultado['cod_barras'] ?: '—') ?></div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <a class="btn btn-yellow" style="flex:1; text-align:center;" href="app.php?page=nova_peca&edit=<?= (int)$qrResultado['id'] ?>">
                                <i class="bi bi-pencil"></i> Editar
                            </a>
                            <a class="btn btn-grey" style="flex:1; text-align:center;" href="app.php?page=historico&id=<?= (int)$qrResultado['id'] ?>">
                                <i class="bi bi-clock-history"></i> Histórico
                            </a>
                        </div>

                    <?php else: ?>
                        <!-- ❌ Não encontrado -->
                        <div style="text-align:center; padding:24px 16px;">
                            <div style="font-size:40px; margin-bottom:12px;">🔍</div>
                            <p style="font-weight:600; margin-bottom:4px;">Nenhuma peça encontrada</p>
                            <p style="font-size:13px; color:#6b7280; margin-bottom:18px;">
                                Não existe nenhuma peça com o SN/código <strong><?= htmlspecialchars($qrTermo) ?></strong>.
                            </p>
                            <a class="btn btn-teal" href="app.php?page=nova_peca&sn=<?= urlencode($qrTermo) ?>&cod_barras=<?= urlencode($qrTermo) ?>">
                                <i class="bi bi-plus-circle"></i> Criar nova peça com este SN
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div><!-- fim coluna direita -->
    </div><!-- fim grid -->


  <?php elseif ($page === 'contas'): ?>

    <?php if (!empty($_SESSION['mensagem_erro'])): ?>
      <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?>
      </div>
    <?php unset($_SESSION['mensagem_erro']); ?>
    <?php endif; ?>
    
    <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
      <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
    <?php unset($_SESSION['mensagem_sucesso']); ?>
    <?php endif; ?>

    <div class="contas-layout">

    <div class="panel">
      <h4 style="margin-bottom:18px;"><?= $contaEdit ? 'Editar Conta' : 'Criar Nova Conta' ?></h4>

      <form method="post" enctype="multipart/form-data" autocomplete="off">

        <input type="hidden" name="form_type" value="nova_conta">


        <?php if ($contaEdit): ?>
          <input type="hidden" name="conta_id" value="<?= (int)$contaEdit['id'] ?>">
          <input type="hidden" name="fotografia_atual" value="<?= htmlspecialchars($contaEdit['fotografia'] ?? '') ?>">
        <?php endif; ?>

        <div style="margin-bottom:14px;">
          <label>Nome</label>
            <label>
                <input type="text" name="nome" required autocomplete="off" value="<?= htmlspecialchars($contaEdit['nome'] ?? '') ?>">
            </label>
        </div>

        <div style="margin-bottom:14px;">
          <label>Email</label>
            <label>
                <input
                  type="email"
                  name="email"
                  required
                  autocomplete="off"
                  pattern=".+@newvision\.pt"
                  value="<?= htmlspecialchars($contaEdit['email'] ?? '') ?>"
                >
            </label>
        </div>

        <div style="margin-bottom:14px;">
          <label>Password<?= $contaEdit ? ' (deixar em branco para manter a atual)' : '' ?> 
            </label>
            <label>
                <input type="password" name="password" <?= $contaEdit ? '' : 'required' ?> autocomplete="new-password">
            </label>
        </div>

        <div style="margin-bottom:18px;">
          <label>Fotografia</label>
          <input type="file" name="fotografia" id="fotografiaInput" accept="image/*">

          <input type="hidden" name="fotografia_cropada" id="fotografia_cropada">

          <div id="cropArea" style="display:none; margin-top:14px;">
            <div style="max-width:420px; border:1px solid #d6dbe1; border-radius:10px; overflow:hidden; background:#fff;">
              <img id="cropPreview" alt="Pré-visualização para recorte." style="display:block; max-width:100%;">
          </div>

          <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-blue" id="aplicarCropBtn">Aplicar Recorte</button>
            <button type="button" class="btn btn-grey" id="cancelarCropBtn">Cancelar</button>
        </div>
      </div>

      <?php if (!empty($contaEdit['fotografia'])): ?>
        <div style="margin-top:12px;">
          <div class="small-note">Fotografia atual</div>
          <img src="<?= htmlspecialchars($contaEdit['fotografia']) ?>" alt="Fotografia atual" 
              style="width:80px;height:80px;border-radius:10px;object-fit:cover;border:1px solid #d6dbe1;">
          </div>
      <?php endif; ?>
      </div>

        <button type="submit" class="btn btn-teal"><?= $contaEdit ? 'Atualizar Conta' : 'Criar Conta' ?>
        </button>
      </form>
    </div>

  <div class="panel">
    <h4 style="margin-bottom:18px;">Contas Existentes</h4>

    <table class="table">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Email</th>
          <th>Data Criação</th>
          <th>Imagem</th>
          <th>Ações</th>
        </tr>
      </thead>

  <tbody>
    <?php foreach ($contas as $c): ?>
    <tr>
      <td><?= htmlspecialchars($c['nome']) ?></td>
      <td><?= htmlspecialchars($c['email']) ?></td>
      <td><?= htmlspecialchars($c['created_at']) ?></td>
      <td>
        <?php if (!empty($c['fotografia'])): ?>
          <img src="<?= htmlspecialchars($c['fotografia']) ?>" alt="Foto" style="width:42px;height:42px;border-radius:6px;object-fit:cover;">
        <?php else: ?>
          Sem foto
        <?php endif; ?>
      </td>
      <td class="actions">
        <a class="btn btn-yellow" href="app.php?page=contas&edit_conta=<?= (int)$c['id'] ?>">Editar</a>
      
        <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar esta conta?');">
        <input type="hidden" name="form_type" value="eliminar_conta">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button type="submit" class="btn btn-red">Eliminar</button>
      </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
    </table>
  </div>
  </div>



<?php elseif ($page === 'auditoria'): ?>

  <section class="config-card auditoria-card">
    <div class="auditoria-header">
    </div>

    <div class="auditoria-box">
        <h3 class="auditoria-title">Registo de Atividades</h3>

        <form method="get" class="auditoria-filtros" id="auditoriaFiltrosForm">
          <input type="hidden" name="page" value="auditoria">
            <div class="auditoria-filtro">
                <label for="audit_user">Utilizador</label>
                <select name="audit_user" id="audit_user">
                    <option value="">Todos</option>
                    <?php foreach ($utilizadoresAuditoria as $utilizador): ?>
                        <option value="<?= htmlspecialchars($utilizador) ?>" <?= $filtroUtilizador === $utilizador ? 'selected' : '' ?>>
                            <?= htmlspecialchars($utilizador) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            
            <div class="auditoria-filtro">
                <label for="audit_action">Ação</label>
                <select name="audit_action" id="audit_action">
                    <option value="">Todas</option>
                    <?php foreach ($acoesAuditoria as $acao): ?>
                        <option value="<?= htmlspecialchars($acao) ?>" <?= $filtroAcao === $acao ? 'selected' : '' ?>>
                            <?= htmlspecialchars($acao) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="auditoria-botoes">
                <button type="submit" class="btn-audit btn-filtrar">🔍 Filtrar</button>
                <button type="button" class="btn-audit btn-limpar" id="btnLimparAuditoria">Limpar filtros</button>
                <a href="exportar_auditoria_csv.php?audit_user=<?= urlencode($filtroUtilizador) ?>&audit_action=<?= urlencode($filtroAcao) ?>" class="btn-audit btn-exportar">Exportar CSV</a>
            </div>
        </form>

        <div class="auditoria-tabela-wrap">
            <table class="auditoria-tabela">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th style="width: 220px;">Utilizador</th>
                        <th style="width: 240px;">Ação</th>
                        <th>Detalhes</th>
                        <th style="width: 180px;">Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($auditoriaLogs)): ?>
                    <?php foreach ($auditoriaLogs as $log): ?>
                            <tr>
                              <td>#<?= (int)$log['id'] ?></td>
                              <td><?= htmlspecialchars($log['utilizador']) ?></td>
                              <td>
                                <?php
                                if ($log['campo'] === 'criação' || $log['campo'] === 'eliminação') {
                                  echo htmlspecialchars(ucfirst($log['campo']));
                                } else {
                                  echo 'Alteração de ' . htmlspecialchars($log['campo']);
                                }
                                ?>
                              </td>
                              <td>
                    <?php
                      $audAntes  = (string)($log['antes'] ?? '');
                      $audDepois = (string)($log['depois'] ?? '');
                      if ((int)$log['peca_id'] > 0):
                    ?>
                      Peça #<?= (int)$log['peca_id'] ?>
                      <?php if ($audAntes !== '' || $audDepois !== ''): ?>
                        — antes: <span style="color:#d9534f;"><?= htmlspecialchars($audAntes) ?></span>
                        | depois: <span style="color:#28a745;"><?= htmlspecialchars($audDepois) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <?= htmlspecialchars($audDepois !== '' ? $audDepois : $audAntes) ?>
                    <?php endif; ?>
                  </td>
    <td><?= date('d/m/Y H:i', strtotime($log['data_alteracao'])) ?></td>
</tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="auditoria-vazia">Não foram encontrados registos para os filtros selecionados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($audPaginas > 1): ?>
        <div style="display:flex; gap:6px; flex-wrap:wrap; justify-content:center; align-items:center; margin-top:18px;">
          <?php
            $audWin = 2;
            $ini = max(1, $audPag - $audWin);
            $fim = min($audPaginas, $audPag + $audWin);
            $audLink = function (int $n, string $txt, bool $atual = false) use ($audExtra) {
                $cls = $atual ? 'btn btn-blue' : 'btn btn-grey';
                return '<a class="' . $cls . '" href="app.php?page=auditoria&p=' . $n . $audExtra . '">' . $txt . '</a>';
            };
            if ($audPag > 1)         { echo $audLink(1, '«') . $audLink($audPag - 1, '‹'); }
            if ($ini > 1)            { echo '<span style="color:#9ca3af;">…</span>'; }
            for ($i = $ini; $i <= $fim; $i++) { echo $audLink($i, (string)$i, $i === $audPag); }
            if ($fim < $audPaginas)  { echo '<span style="color:#9ca3af;">…</span>'; }
            if ($audPag < $audPaginas) { echo $audLink($audPag + 1, '›') . $audLink($audPaginas, '»'); }
          ?>
          <span style="color:#6b7280; font-size:13px; margin-left:8px;">
            Página <?= $audPag ?> de <?= $audPaginas ?> · <?= (int)$audTotal ?> registos
          </span>
        </div>
        <?php endif; ?>
    </div>
</section>



<?php elseif ($page === 'alertas') : ?>

<?php if (!empty($clientesStats['csv_error'])): ?>
<div class="alerta-erro" style="margin-bottom: 20px;">
    <strong>Erro ao carregar dados do CSV:</strong><br>
    <?= nl2br(htmlspecialchars($clientesStats['csv_error'])) ?>
</div>
<?php endif; ?>

<div class="clientes-kpis">
    <div class="cliente-kpi">
        <div class="label">Total de Contas</div>
        <div class="valor"><?= (int)$clientesStats['total'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Clientes</div>
        <div class="valor"><?= (int)$clientesStats['customers'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Perspetivas</div>
        <div class="valor"><?= (int)$clientesStats['prospects'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Parceiros</div>
        <div class="valor"><?= (int)$clientesStats['partners'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Contas com Conta-Mãe</div>
        <div class="valor"><?= (int)$clientesStats['com_parent'] ?></div>
    </div>
</div>

  <div class="panel" style="margin-bottom:20px;">
    <form method="get">
      <input type="hidden" name="page" value="alertas">

      <div class="clientes-filtros">
        <div>
          <label>Pesquisar</label>
            <label>
                <input type="text" name="q" value="<?= htmlspecialchars($clientesFiltros['q']) ?>" placeholder="Nome da conta, conta-mãe ou tipo">
            </label>
        </div>

        <div>
          <label>Tipo</label>
            <label>
                <select name="type">
                  <option value="">-- Todos --</option>
                  <?php foreach ($clientesTipos as $tipo): ?>
                    <option value="<?= htmlspecialchars($tipo) ?>"
                      <?= $clientesFiltros['type'] === $tipo ? 'selected' : '' ?>>
                      <?= htmlspecialchars(tipoPt($tipo)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div>
          <label>Hierarquia</label>
            <label>
                <select name="hierarquia">
                  <option value="">-- Todas --</option>
                  <option value="com_parent" <?= $clientesFiltros['hierarquia'] === 'com_parent' ? 'selected' : '' ?>>Só Contas-Filhas</option>
                  <option value="so_pais" <?= $clientesFiltros['hierarquia'] === 'so_pais' ? 'selected' : '' ?>>Só Contas-Mãe</option>
                  <option value="so_sem_parent" <?= $clientesFiltros['hierarquia'] === 'so_sem_parent' ? 'selected' : '' ?>>Sem Conta-Mãe</option>
                </select>
            </label>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit" class="btn btn-blue">Filtrar</button>
          <a href="app.php?page=alertas" class="btn btn-grey">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <div class="panel">
    <h4 style="margin-bottom:16px;">Lista de Clientes</h4>

    <div style="overflow-x:auto;">
      <table class="clientes-table">
        <thead>
          <tr>
            <th style="width:24%;">Conta</th>
            <th style="width:11%;">Tipo</th>
            <th style="width:17%;">Conta-Mãe</th>
            <th style="width:11%;">Última Atividade</th>
            <th style="width:11%;">Última Modificação</th>
            <th style="width:26%;">Contactos / Morada</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clientesRoots)): ?>
            <tr>
              <td colspan="6" class="clientes-empty">Nenhum Cliente encontrado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($clientesRoots as $i => $cliente): ?>
              <?php
                $nomeConta = $cliente['account_name'];
                $filhos = $clientesChildrenMap[$nomeConta] ?? [];
                $temFilhos = count($filhos) > 0;

                $typeClass = 'tipo-other';
                if (strcasecmp($cliente['type'], 'Customer') === 0) {
                  $typeClass = 'tipo-customer';
                } elseif (strcasecmp($cliente['type'], 'Prospect') ===0) {
                  $typeClass = 'tipo-prospect';
                } elseif (stripos($cliente['type'], 'Partner') !== false) {
                  $typeClass = 'tipo-partner';
                }

                $rowId = 'cliente-parent-' . $i; ?>
         <tr class="cliente-row-parent">
          <td>
            <?php if ($temFilhos): ?>
              <button type="button" class="cliente-toggle" data-target="<?= htmlspecialchars($rowId) ?>">+</button>
            <?php else: ?>
              <span style="display:inline-block; width:32px;"></span>
            <?php endif; ?>
              <strong><?= htmlspecialchars($cliente['account_name']) ?></strong>
          </td>
          <td>
              <span class="tipo-badge <?= $typeClass ?>">
            <?= htmlspecialchars(tipoPt($cliente['type'])) ?>
              </span>
          </td>
          <td>
            <?php if ($cliente['parent_account'] !== ''): ?>
            <?= htmlspecialchars($cliente['parent_account']) ?>
            <?php else: ?>
              <span class="conta-principal-badge">Conta Principal</span>
            <?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars($cliente['last_activity'] !== '' ? $cliente['last_activity'] : '-') ?>
          </td>
          <td>
            <?= htmlspecialchars($cliente['last_modified_date'] !== '' ? $cliente['last_modified_date'] : '-') ?>
          </td>
          <td><?= contactoCelula($contactosMap ?? [], $cliente['account_name']) ?></td>
        </tr>

            <?php if ($temFilhos): ?>
              <?php foreach ($filhos as $filho): ?>
                <?php $childTypeClass = 'tipo-other';
                      if (strcasecmp($filho['type'], 'Customer') === 0) {
                        $childTypeClass = 'tipo-customer';
                      } elseif (strcasecmp($filho['type'], 'Prospect') === 0) {
                        $childTypeClass = 'tipo-prospect';
                      } elseif (stripos($filho['type'], 'Partner') !== false) {
                        $childTypeClass = 'tipo-partner';
                      }
                ?>
                <tr class="cliente-row-child cliente-child-group <?= htmlspecialchars($rowId) ?>" style="display:none;">
                  <td class="cliente-child-name"><?= htmlspecialchars($filho['account_name']) ?></td>
                  <td>
                    <span class="tipo-badge <?= $childTypeClass ?>"> <?= htmlspecialchars(tipoPt($filho['type'])) ?> </span>
                  </td>
                  <td>
                    <?php if ($filho['parent_account'] !== ''): ?>
                      <?= htmlspecialchars($filho['parent_account']) ?>
                    <?php else: ?>
                      <span class="conta-principal-badge">Conta Principal</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= htmlspecialchars($filho['last_activity'] !== '' ? $filho['last_activity'] : '-') ?></td>
                  <td>
                    <?= htmlspecialchars($filho['last_modified_date'] !== '' ? $filho['last_modified_date'] : '-') ?></td>
                  <td><?= contactoCelula($contactosMap ?? [], $filho['account_name']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<?php elseif ($page === 'pats'): ?>

<?php
// KPIs rápidos para o topo da página
$kpiPatsTotal = countQuery($pdo, "SELECT COUNT(*) FROM pats");
$kpiPatsAbertos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Aberto'");
$kpiPatsEmCurso = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Em Curso'");
$kpiPatsConcluidos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Concluído'");
$kpiPatsUrgentes = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE prioridade='Urgente' AND estado NOT IN ('Concluído','Cancelado')");
?>

<?php if (!empty($_SESSION['mensagem_erro'])): ?>
  <div class="alerta-erro" style="margin-bottom:16px;"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
<?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
  <div class="alerta-sucesso" style="margin-bottom:16px;"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

<?php if ($patVerId > 0 && $patDetalhe): ?>
    ════════════════════════════════════════════
    VISTA: DETALHE / EDIÇÃO DO PAT
    ════════════════════════════════════════════

<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
  <a href="app.php?page=pats" class="btn btn-grey">← Voltar à lista</a>
  <h3 style="margin:0; font-size:17px;">
      <?= htmlspecialchars($patDetalhe['numero_pat']) ?>/<?= (int)$patDetalhe['revisao'] ?>
  </h3>
  <span style="
    padding:3px 12px; border-radius:20px; font-size:12px; font-weight:600;
    background:<?= $patDetalhe['estado']==='Aberto' ? '#dbeafe' : ($patDetalhe['estado']==='Em Curso' ? '#fef3c7' : ($patDetalhe['estado']==='Concluído' ? '#dcfce7' : '#f3f4f6')) ?>;
    color:<?= $patDetalhe['estado']==='Aberto' ? '#1d4ed8' : ($patDetalhe['estado']==='Em Curso' ? '#92400e' : ($patDetalhe['estado']==='Concluído' ? '#15803d' : '#374151')) ?>;">
    <?= htmlspecialchars($patDetalhe['estado']) ?>
  </span>
<?php if ($patDetalhe['prioridade'] === 'Urgente'): ?>
   <span style="padding:3px 12px; border-radius:20px; font-size:12px; font-weight:600; background:#fee2e2; color:#dc2626;">Urgente</span>
<?php endif; ?>
   <div style="margin-left:auto; display:flex; gap:10px;">
     <a href="workorder.php?id=<?= (int)$patDetalhe['id'] ?>" target="_blank" class="btn btn-blue">📄 Folha de Obra</a>
   </div>
</div>

<form method="post" autocomplete="off">
  <input type="hidden" name="form_type" value="editar_pat">
  <input type="hidden" name="pat_id" value="<?= (int)$patDetalhe['id'] ?>">

  <!-- Cliente -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Cliente</h4>
     <div class="form-grid">
       <div>
         <label>Nº PAT</label>
           <label>
               <input type="text" name="numero_pat" value="<?= htmlspecialchars($patDetalhe['numero_pat']) ?>" required>
           </label>
       </div>
       <div>
         <label>Revisão</label>
           <label>
               <input type="number" name="revisao" min="1" value="<?= (int)$patDetalhe['revisao'] ?>">
           </label>
       </div>
       <div>
         <label>Entidade</label>
           <label>
               <input type="text" name="entidade" value="<?= htmlspecialchars($patDetalhe['entidade']) ?>">
           </label>
       </div>
       <div>
         <label>Local</label>
           <label>
               <input type="text" name="local_cliente" value="<?= htmlspecialchars($patDetalhe['local_cliente']) ?>">
           </label>
       </div>
       <div>
         <label>Contacto</label>
           <label>
               <input type="text" name="contacto" value="<?= htmlspecialchars($patDetalhe['contacto']) ?>">
           </label>
       </div>
       <div>
         <label>Morada</label>
           <label>
               <input type="text" name="morada" value="<?= htmlspecialchars($patDetalhe['morada']) ?>">
           </label>
       </div>
     </div>
  </div>

  <!-- Pedido -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Pedido de Assistência</h4>
    <div class="form-grid">
      <div>
        <label>Data de Receção</label>
          <label>
              <input type="datetime-local" name="data_recepcao"
                value="<?= $patDetalhe['data_recepcao'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_recepcao'])) : '' ?>">
          </label>
      </div>
      <div>
        <label>Data Limite</label>
          <label>
              <input type="datetime-local" name="data_limite"
                value="<?= $patDetalhe['data_limite'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_limite'])) : '' ?>">
          </label>
      </div>
      <div style="display:flex; gap:28px; align-items:center; flex-wrap:wrap; padding-top:22px;">
        <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
          <input type="checkbox" name="garantia" value="1" <?= $patDetalhe['garantia'] ? 'checked' : '' ?>>
           Ao Abrigo da Garantia
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
          <input type="checkbox" name="contrato_manutencao" value="1" <?= $patDetalhe['contrato_manutencao'] ? 'checked' : '' ?>>
           Ao Abrigo do Contrato de Manutenção
        </label>
      </div>
      <div style="grid-column:1/-1;">
        <label>Descrição do Pedido</label>
          <label>
              <textarea name="descricao" rows="4" style="width:100%; resize:vertical;"><?= htmlspecialchars($patDetalhe['descricao']) ?></textarea>
          </label>
      </div>
    </div>
  </div>

  <!-- NewVision -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">NewVision</h4>
    <div class="form-grid">
      <div>
        <label>Técnico Responsável</label>
          <label>
              <input type="text" name="tecnico" value="<?= htmlspecialchars($patDetalhe['tecnico']) ?>">
          </label>
      </div>
      <div>
        <label>Prioridade</label>
          <label>
              <select name="prioridade">
                  <option value="Normal"  <?= $patDetalhe['prioridade']==='Normal'  ? 'selected' : '' ?>>Normal</option>
                  <option value="Urgente" <?= $patDetalhe['prioridade']==='Urgente' ? 'selected' : '' ?>>Urgente</option>
              </select>
          </label>
      </div>
      <div>
        <label>Estado</label>
          <label>
              <select name="estado">
                <?php foreach (['Aberto','Em Curso','Concluído','Cancelado'] as $est): ?>
                  <option value="<?= $est ?>" <?= $patDetalhe['estado']===$est ? 'selected' : '' ?>><?= $est ?></option>
                <?php endforeach; ?>
              </select>
          </label>
      </div>
  </div>
  </div>

  <!-- Intervenção -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Intervenção</h4>
    <div class="form-grid">
      <div>
        <label>Data / Hora Início</label>
          <label>
              <input type="datetime-local" name="data_inicio"
                 value="<?= $patDetalhe['data_inicio'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_inicio'])) : '' ?>">
          </label>
      </div>
      <div>
        <label>Data / Hora Fim</label>
          <label>
              <input type="datetime-local" name="data_fim"
                value="<?= $patDetalhe['data_fim'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_fim'])) : '' ?>">
          </label>
      </div>
      <div style="grid-column:1/-1;">
        <label>Técnicos Presentes</label>
          <label>
              <input type="text" name="tecnicos_presentes" value="<?= htmlspecialchars($patDetalhe['tecnicos_presentes']) ?>"
          </label>
      </div>
    </div>
  </div>

  <!-- Componentes Trocados -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Componentes Trocados</h4>
    <div style="overflow-x:auto;">
      <table class="table" id="tabelaComponentes" style="margin-bottom:10px; min-width:700px;">
        <thead>
          <tr>
            <th>Removido</th>
            <th>Nº de Série Removido</th>
            <th>Colocado</th>
            <th>Nº de Série Colocado</th>
            <th style="width:70px;">Qtd</th>
            <th style="width:48px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $compParaRender = !empty($patComp) ? $pat : [['removido'=>'','sn_removido'=>'','colocado'=>'','sn_colocado'=>'','quantidade'=>1]];
          foreach ($compParaRender as $comp): ?>
            <tr>
              <td><label>
                      <input type="text" name="comp_removido[]"  value="<?= htmlspecialchars($comp['removido']) ?>"   style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="text" name="comp_sn_rem[]"    value="<?= htmlspecialchars($comp['sn_removido']) ?>" style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="text" name="comp_colocado[]"  value="<?= htmlspecialchars($comp['colocado']) ?>"   style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="text" name="comp_sn_col[]"    value="<?= htmlspecialchars($comp['sn_colocado']) ?>" style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="number" name="comp_qtd[]"     value="<?= (int)$comp['quantidade'] ?>" min="1" style="width:100%;">
                  </label></td>
              <td><button type="button" class="btn btn-red btn-remover-linha" style="padding:4px 10px;">✕</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-grey btn-add-comp">+ Linha</button>
  </div>

  <!-- Observações -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Observações</h4>
      <label>
          <textarea name="observacoes" rows="3" style="width:100%; resize:vertical;"><?= htmlspecialchars($patDetalhe['observacoes']) ?></textarea>
      </label>
  </div>

  <!-- Ações -->
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
        <button type="submit" class="btn btn-blue">💾 Guardar Alterações</button>
    </div>
</form>

        <form method="post" style="margin:0;" onsubmit="return confirm('Apagar este PAT permanentemente?');">
            <input type="hidden" name="form_type" value="apagar_pat">
            <input type="hidden" name="pat_id"    value="<?= (int)$patDetalhe['id'] ?>">
            <button type="submit" class="btn btn-red">Apagar PAT</button>
        </form>

    <?php elseif ($patAcao === 'novo'): ?>
    <!-- ════════════════════════════════════════════
         VISTA: NOVO PAT
    ════════════════════════════════════════════ -->

    <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
        <a href="app.php?page=pats" class="btn btn-grey">← Voltar</a>
        <h3 style="margin:0;">Novo PAT</h3>
    </div>

    <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="criar_pat">

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Cliente</h4>
            <div class="form-grid">
                <div>
                    <label>Nº PAT <span style="color:red;">*</span></label>
                    <label>
                        <input type="text" name="numero_pat" placeholder="Ex: PAT-00102514" required>
                    </label>
                </div>
                <div>
                    <label>Revisão</label>
                    <label>
                        <input type="number" name="revisao" min="1" value="1">
                    </label>
                </div>
                <div>
                    <label>Entidade</label>
                    <label>
                        <input type="text" name="entidade" placeholder="Ex: UNILABS">
                    </label>
                </div>
                <div>
                    <label>Local</label>
                    <label>
                        <input type="text" name="local_cliente">
                    </label>
                </div>
                <div>
                    <label>Contacto</label>
                    <label>
                        <input type="text" name="contacto">
                    </label>
                </div>
                <div>
                    <label>Morada</label>
                    <label>
                        <input type="text" name="morada">
                    </label>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Pedido de Assistência</h4>
            <div class="form-grid">
                <div>
                    <label>Data de Receção</label>
                    <label>
                        <input type="datetime-local" name="data_recepcao">
                    </label>
                </div>
                <div>
                    <label>Data Limite</label>
                    <label>
                        <input type="datetime-local" name="data_limite">
                    </label>
                </div>
                <div style="display:flex; gap:20px; align-items:center; padding-top:22px;">
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
                        <input type="checkbox" name="garantia" value="1"> Garantia
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
                        <input type="checkbox" name="contrato_manutencao" value="1"> Contrato de Manutenção
                    </label>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Descrição</label>
                    <label>
                        <textarea name="descricao" rows="4" style="width:100%; resize:vertical;"></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">NewVision</h4>
            <div class="form-grid">
                <div>
                    <label>Técnico Responsável</label>
                    <label>
                        <input type="text" name="tecnico">
                    </label>
                </div>
                <div>
                    <label>Prioridade</label>
                    <label>
                        <select name="prioridade">
                            <option value="Normal">Normal</option>
                            <option value="Urgente">Urgente</option>
                        </select>
                    </label>
                </div>
                <div>
                    <label>Estado</label>
                    <label>
                        <select name="estado">
                            <option value="Aberto">Aberto</option>
                            <option value="Em Curso">Em Curso</option>
                            <option value="Concluído">Concluído</option>
                            <option value="Cancelado">Cancelado</option>
                        </select>
                    </label>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Comentários / Instruções</label>
                    <label>
                        <textarea name="comentarios" rows="3" style="width:100%; resize:vertical;"></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Módulos para Assistência</h4>
            <table class="table" id="tabelaModulos" style="margin-bottom:10px;">
                <thead>
                <tr><th>Solução / Equipamento</th><th>Modelo</th><th>Nº de Série</th><th style="width:48px;"></th></tr>
                </thead>
                <tbody>
                <tr>
                    <td><label>
                            <input type="text" name="mod_solucao[]" style="width:100%;">
                        </label></td>
                    <td><label>
                            <input type="text" name="mod_modelo[]"  style="width:100%;">
                        </label></td>
                    <td><label>
                            <input type="text" name="mod_serie[]"   style="width:100%;">
                        </label></td>
                    <td><button type="button" class="btn btn-red btn-remover-linha" style="padding:4px 10px;">✕</button></td>
                </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-grey btn-add-modulo">+ Linha</button>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Componentes Trocados</h4>
            <div style="overflow-x:auto;">
                <table class="table" id="tabelaComponentes" style="margin-bottom:10px; min-width:700px;">
                    <thead>
                    <tr>
                        <th>Removido</th><th>Nº Série Removido</th>
                        <th>Colocado</th><th>Nº Série Colocado</th>
                        <th style="width:70px;">Qtd</th><th style="width:48px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><label>
                                <input type="text" name="comp_removido[]"  style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="text" name="comp_sn_rem[]"    style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="text" name="comp_colocado[]"  style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="text" name="comp_sn_col[]"    style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="number" name="comp_qtd[]" value="1" min="1" style="width:100%;">
                            </label></td>
                        <td><button type="button" class="btn btn-red btn-remover-linha" style="padding:4px 10px;">✕</button></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-grey btn-add-comp">+ Linha</button>
        </div>

        <div style="margin-bottom:20px;">
            <button type="submit" class="btn btn-blue">Criar PAT</button>
        </div>
    </form>

    <?php else: ?>
    <!-- ════════════════════════════════════════════
         VISTA: LISTA DE PATs
    ════════════════════════════════════════════ -->

    <!-- KPIs -->
    <div class="clientes-kpis" style="margin-bottom:20px;">
        <div class="cliente-kpi">
            <div class="label">Total</div>
            <div class="valor"><?= $kpiPatsTotal ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Abertos</div>
            <div class="valor" style="color:#1d4ed8;"><?= $kpiPatsAbertos ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Em Curso</div>
            <div class="valor" style="color:#92400e;"><?= $kpiPatsEmCurso ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Concluídos</div>
            <div class="valor" style="color:#15803d;"><?= $kpiPatsConcluidos ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Urgentes Ativos</div>
            <div class="valor" style="color:#dc2626;"><?= $kpiPatsUrgentes ?></div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="panel" style="margin-bottom:20px;">
        <form method="get">
            <input type="hidden" name="page" value="pats">
            <div class="clientes-filtros">
                <div>
                    <label>Pesquisar</label>
                    <label>
                        <input type="text" name="q" value="<?= htmlspecialchars($patFiltros['q']) ?>" placeholder="Nº PAT, entidade ou técnico">
                    </label>
                </div>
                <div>
                    <label>Estado</label>
                    <label>
                        <select name="estado">
                            <option value="">-- Todos --</option>
                            <?php foreach (['Aberto','Em Curso','Concluído','Cancelado'] as $est): ?>
                            <option value="<?= $est ?>" <?= $patFiltros['estado']===$est ? 'selected' : '' ?>><?= $est ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div>
                    <label>Prioridade</label>
                    <label>
                        <select name="prioridade">
                            <option value="">-- Todas --</option>
                            <option value="Normal"  <?= $patFiltros['prioridade']==='Normal'  ? 'selected' : '' ?>>Normal</option>
                            <option value="Urgente" <?= $patFiltros['prioridade']==='Urgente' ? 'selected' : '' ?>>Urgente</option>
                        </select>
                    </label>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-blue">Filtrar</button>
                    <a href="app.php?page=pats" class="btn btn-grey">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Botão novo PAT + Tabela -->
    <div class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h4 style="margin:0;">Lista de PATs</h4>
            <a href="app.php?page=pats&acao=novo" class="btn btn-blue">+ Novo PAT</a>
            <a href="exportar_pats_csv.php" class="btn btn-green" style="padding:8px 14px; font-size:13px;">
                <i class="bi bi-download"></i> Exportar CSV
            </a>
        </div>

        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                <tr>
                    <th>Nº PAT</th>
                    <th>Entidade</th>
                    <th>Técnico</th>
                    <th>Receção</th>
                    <th>Limite</th>
                    <th>Prioridade</th>
                    <th>Estado</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($patsList)): ?>
                    <tr><td colspan="8" style="text-align:center; color:#6b7280; padding:30px;">Nenhum PAT encontrado.</td></tr>
                <?php else: ?>
          <?php foreach ($patsList as $pat): ?>
            <?php
                $estCores = [
                    'Aberto'    => ['bg'=>'#dbeafe','color'=>'#1d4ed8'],
                    'Em Curso'  => ['bg'=>'#fef3c7','color'=>'#92400e'],
                    'Concluído' => ['bg'=>'#dcfce7','color'=>'#15803d'],
                ];
                $estCor = $estCores[$pat['estado']] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pat['numero_pat']) ?>/<?= (int)$pat['revisao'] ?></strong></td>
                    <td><?= htmlspecialchars($pat['entidade']) ?></td>
                    <td><?= htmlspecialchars($pat['tecnico']) ?></td>
                    <td><?= $pat['data_recepcao'] ? date('d/m/Y H:i', strtotime($pat['data_recepcao'])) : '—' ?></td>
                    <td><?= $pat['data_limite']   ? date('d/m/Y H:i', strtotime($pat['data_limite']))   : '—' ?></td>
                    <td>
                        <?php if ($pat['prioridade'] === 'Urgente'): ?>
                            <span style="padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#fee2e2; color:#dc2626;">Urgente</span>
                        <?php else: ?>
                            <span style="color:#6b7280; font-size:12px;">Normal</span>
                        <?php endif; ?>
                    </td>
                    <td>
                <span style="padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;
                        background:<?= $estCor['bg'] ?>; color:<?= $estCor['color'] ?>;">
                  <?= htmlspecialchars($pat['estado']) ?>
                </span>
                    </td>
                    <td>
                      <div class="acao-wrap">
                        <button class="acao-btn" type="button" onclick="toggleAcao(this)">⋮</button>
                        <div class="acao-menu">
                          <a href="app.php?page=pats&ver=<?= (int)$pat['id'] ?>">
                            <i class="bi bi-pencil-square"></i> Editar
                          </a>
                          <a href="workorder.php?id=<?= (int)$pat['id'] ?>" target="_blank">
                             <i class="bi bi-file-earmark-text"></i> Folha de Obra
                          </a>
                        </div>
                      </div>
                    </td>
                </tr>
                <?php endforeach; ?>
        <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif;  ?>
    <!-- ════════════════════════════════════════════
         FIM VISTA: LISTA DE PATs
    ════════════════════════════════════════════ -->

<?php elseif ($page === 'categorias'): ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Categoria' : 'Nova Categoria' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_categoria">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Categoria</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=categorias">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista de Categorias</h1>
    <a class="btn btn-teal" href="app.php?page=categorias&nova=1" style="margin-bottom:18px;display:inline-block;">Nova Categoria</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Categoria</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=categorias&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar esta categoria?');">
                <input type="hidden" name="form_type" value="eliminar_categoria">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="3">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('categorias', $tabPaginas, $tabPag); ?>
  <?php endif; ?>

<?php elseif ($page === 'estados'): ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Estado' : 'Novo Estado' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_estado">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Estado</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <div style="margin-bottom:14px;">
          <label>Descrição</label>
          <label><input type="text" name="descricao" value="<?= htmlspecialchars($tabEdit['descricao'] ?? '') ?>"></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=estados">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista dos Estados</h1>
    <a class="btn btn-teal" href="app.php?page=estados&nova=1" style="margin-bottom:18px;display:inline-block;">Novo Estado</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Estado</th><th>Descrição</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td><?= htmlspecialchars($row['descricao'] ?? '') ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=estados&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar este estado?');">
                <input type="hidden" name="form_type" value="eliminar_estado">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="4">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('estados', $tabPaginas, $tabPag); ?>
  <?php endif; ?>

<?php elseif ($page === 'fabricantes'): ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Fabricante' : 'Novo Fabricante' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_fabricante">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Fabricante</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=fabricantes">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista de Fabricantes</h1>
    <a class="btn btn-teal" href="app.php?page=fabricantes&nova=1" style="margin-bottom:18px;display:inline-block;">Novo Fabricante</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Fabricante</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=fabricantes&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar este fabricante?');">
                <input type="hidden" name="form_type" value="eliminar_fabricante">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="3">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('fabricantes', $tabPaginas, $tabPag); ?>
  <?php endif; ?>

<?php elseif ($page === 'produtos'): ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Produto' : 'Novo Produto' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_produto">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Produto</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <div style="margin-bottom:14px;">
          <label>Categoria</label>
          <label><select name="categoria_id">
            <option value="">— Sem categoria —</option>
            <?php foreach ($listaCategorias as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)($tabEdit['categoria_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
            <?php endforeach; ?>
          </select></label>
        </div>
        <div style="margin-bottom:14px;">
          <label>Fabricante</label>
          <label><select name="fabricante_id">
            <option value="">— Sem fabricante —</option>
            <?php foreach ($listaFabricantes as $f): ?>
              <option value="<?= (int)$f['id'] ?>" <?= ((int)($tabEdit['fabricante_id'] ?? 0) === (int)$f['id']) ? 'selected' : '' ?>><?= htmlspecialchars($f['nome']) ?></option>
            <?php endforeach; ?>
          </select></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=produtos">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista de Produtos</h1>
    <a class="btn btn-teal" href="app.php?page=produtos&nova=1" style="margin-bottom:18px;display:inline-block;">Novo Produto</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Produto</th><th>Categoria</th><th>Fabricante</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td><?= htmlspecialchars($row['categoria_nome'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['fabricante_nome'] ?? '') ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=produtos&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar este produto?');">
                <input type="hidden" name="form_type" value="eliminar_produto">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="5">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('produtos', $tabPaginas, $tabPag); ?>
  <?php endif; ?>

<?php elseif ($page === 'parceiros'): ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

  <?php if ($parceiroVer): ?>
    <h1 class="section-title">Visualizar informação do Parceiro</h1>
    <div class="panel" style="max-width:520px;">
      <div style="margin-bottom:14px;"><label><strong>Empresa:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['empresa']) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Morada:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['morada'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Nome do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato1_nome'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Email do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato1_email'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Telefone do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato1_telefone'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Nome do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato2_nome'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Email do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato2_email'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:18px;"><label><strong>Telefone do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato2_telefone'] ?? '') ?>" readonly></label></div>
      <a class="btn btn-yellow" href="app.php?page=parceiros">← Voltar à lista de parceiros</a>
    </div>

  <?php elseif (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Parceiro' : 'Novo Parceiro' ?></h1>
    <div class="panel" style="max-width:520px;">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_parceiro">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;"><label>Empresa</label>
          <label><input type="text" name="empresa" required value="<?= htmlspecialchars($tabEdit['empresa'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Morada</label>
          <label><input type="text" name="morada" value="<?= htmlspecialchars($tabEdit['morada'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Nome do 1º Contato</label>
          <label><input type="text" name="contato1_nome" value="<?= htmlspecialchars($tabEdit['contato1_nome'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Email do 1º Contato</label>
          <label><input type="email" name="contato1_email" value="<?= htmlspecialchars($tabEdit['contato1_email'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Telefone do 1º Contato</label>
          <label><input type="text" name="contato1_telefone" value="<?= htmlspecialchars($tabEdit['contato1_telefone'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Nome do 2º Contato</label>
          <label><input type="text" name="contato2_nome" value="<?= htmlspecialchars($tabEdit['contato2_nome'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Email do 2º Contato</label>
          <label><input type="email" name="contato2_email" value="<?= htmlspecialchars($tabEdit['contato2_email'] ?? '') ?>"></label></div>
        <div style="margin-bottom:18px;"><label>Telefone do 2º Contato</label>
          <label><input type="text" name="contato2_telefone" value="<?= htmlspecialchars($tabEdit['contato2_telefone'] ?? '') ?>"></label></div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=parceiros">← Voltar à lista</a>
      </form>
    </div>

  <?php else: ?>
    <?php $det = isset($_GET['det']); ?>
    <h1 class="section-title">Lista de Parceiros</h1>
    <div style="margin-bottom:18px;">
      <a class="btn btn-teal" href="app.php?page=parceiros&nova=1">Novo Parceiro</a>
      <a class="btn btn-grey" href="app.php?page=parceiros<?= $det ? '' : '&det=1' ?>"><?= $det ? 'Ocultar detalhes' : 'Mostrar detalhes' ?></a>
    </div>
    <table class="table">
      <thead><tr>
        <th>ID</th><th>Parceiro</th>
        <th>1º Contato</th><?php if ($det): ?><th>Email</th><?php endif; ?><th>Telm.</th>
        <th>2º Contato</th><?php if ($det): ?><th>Email</th><?php endif; ?><th>Telm.</th>
        <th>Ações</th>
      </tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['empresa']) ?></td>
            <td><?= htmlspecialchars($row['contato1_nome'] ?? '') ?></td>
            <?php if ($det): ?><td><?= htmlspecialchars($row['contato1_email'] ?? '') ?></td><?php endif; ?>
            <td><?= htmlspecialchars($row['contato1_telefone'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['contato2_nome'] ?? '') ?></td>
            <?php if ($det): ?><td><?= htmlspecialchars($row['contato2_email'] ?? '') ?></td><?php endif; ?>
            <td><?= htmlspecialchars($row['contato2_telefone'] ?? '') ?></td>
            <td class="actions">
              <a class="btn btn-blue" href="app.php?page=parceiros&ver=<?= (int)$row['id'] ?>">Ver +</a>
              <a class="btn btn-yellow" href="app.php?page=parceiros&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Eliminar este parceiro?');">
                <input type="hidden" name="form_type" value="eliminar_parceiro">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="<?= $det ? 9 : 7 ?>">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('parceiros', $tabPaginas, $tabPag, $det ? '&det=1' : ''); ?>
  <?php endif; ?>

<?php elseif ($page === 'nvi'): ?>

  <h1 class="section-title">Olá sou o 🤖 N-Vi, o assistente AI virtual da Newvision. Como posso ajudar?</h1>

  <form method="post" action="app.php?page=nvi">
    <div style="margin-bottom:14px;">
      <label style="font-weight:600;">Pergunta:</label>
      <textarea id="nviPergunta" name="pergunta" rows="4"
                style="width:100%; padding:12px; border:1px solid #d6dbe1; border-radius:8px; resize:vertical;"
                placeholder="ex.: Quantas peças estão em 'Trânsito' por parceiro?"><?= htmlspecialchars($nvi['pergunta']) ?></textarea>
    </div>

    <p style="color:#6b7280; font-size:13px; margin:6px 0;">
      Dicas: pergunta em linguagem natural. O assistente gera uma <strong>RESPOSTA (read-only)</strong>, validada e executada.<br>
      Não utilizes as respostas como formais em nome da Newvision sem validares por outras fontes.
    </p>

    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:14px 0;">
      <span style="color:#6b7280; font-size:13px;">Exemplos rápidos:</span>
      <button type="button" class="btn btn-grey" onclick="nviExemplo('Top 5 tipos disponíveis')">Top 5 tipos disponíveis</button>
      <button type="button" class="btn btn-grey" onclick="nviExemplo('Últimas 20 peças')">Últimas 20 peças</button>
      <button type="button" class="btn btn-grey" onclick="nviExemplo('Autor da ordem mais recente')">Autor da ordem mais recente</button>
    </div>

    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn btn-blue">Executar</button>
      <a class="btn btn-yellow" href="app.php?page=dashboard">← Voltar ao Dashboard</a>
    </div>
  </form>

  <?php if ($nvi['erro'] !== ''): ?>
    <div class="alerta-erro" style="margin-top:20px;"><?= htmlspecialchars($nvi['erro']) ?></div>
  <?php elseif ($nvi['executou']): ?>
    <div class="panel" style="margin-top:20px;">
      <h4 style="margin:0 0 10px;"><?= htmlspecialchars($nvi['titulo']) ?></h4>
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-family:monospace; font-size:12px; color:#334155; margin-bottom:14px; white-space:pre-wrap;">
        <?= htmlspecialchars(preg_replace('/\s+/', ' ', $nvi['sql'])) ?>
      </div>
      <?php if (empty($nvi['linhas'])): ?>
        <p style="color:#6b7280;">Sem resultados para esta pergunta.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="table">
            <thead><tr>
              <?php foreach ($nvi['colunas'] as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
              <?php foreach ($nvi['linhas'] as $linha): ?>
                <tr><?php foreach ($linha as $valor): ?><td><?= htmlspecialchars((string)$valor) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p style="color:#9ca3af; font-size:12px; margin-top:10px;"><?= count($nvi['linhas']) ?> resultado(s).</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <script>
    function nviExemplo(t){ var ta=document.getElementById('nviPergunta'); if(ta){ ta.value=t; ta.form.submit(); } }
  </script>

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

if (document.getElementById('trendChart')) {
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: trendLabels,
      datasets: [
        {
          label: 'Peças',
          data: trendPecas,
          borderColor: '#36a2eb',
          backgroundColor: 'rgba(54,162,235,.2)',
          tension: .35
        },
        {
          label: 'PATs',
          data: trendPats,
          borderColor: '#ff6384',
          backgroundColor: 'rgba(255,99,132,.2)',
          tension: .35
        }
      ]
    },
    options: {
      responsive: true
    }
  });
}

if (document.getElementById('categoriaChart')) {
  new Chart(document.getElementById('categoriaChart'), {
    type: 'bar',
    data: {
      labels: categoriaLabels,
      datasets: [{
        label: 'Total',
        data: categoriaTotals,
        backgroundColor: '#28a745'
      }]
    },
    options: {
      indexAxis: 'y',
      plugins: {
        legend: {
          display: false
        }
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

        if (documentoSelect.value === 'G. Transp cliente') {
            parceiroSelect.innerHTML = '<option value="Field Service" selected>Field Service</option>';
            parceiroSelect.value = 'Field Service';
            parceiroSelect.setAttribute('readonly', 'readonly');
            parceiroSelect.setAttribute('data-mode', 'cliente');
        } else if (documentoSelect.value === 'G. Transp fornec') {
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
        if (!e.target.closest('acao-wrap')) {
            document.querySelectorAll('.acao-wrap.open').forEach(w => w.classList.remove('open'));
        }
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

</body>
</html>
