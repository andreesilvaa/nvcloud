<?php
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


$host = 'localhost';
$db = 'stocks_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Exception $e) {
    die('Erro de ligação à base de dados: ' . $e->getMessage());
}

$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

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

$pageTitles = [
  'dashboard' => 'Dashboard',
  'alertas' => 'Clientes',
  'inventario' => 'Inventário',
  'nova_peca' => 'Nova Peça',
  'historico' => 'Histórico da Peça',
  'pats' => "Pat's",
  'envios' => 'Envios',
  'qrs' => "QR's",
  'encomendas' => 'Encomendas',
  'contas' =>'Contas',
  'auditoria' => 'Auditoria'
  ];

  $topbarTitle = $pageTitles[$page] ?? ucfirst($page);

$estados = [
    'Abater',
    'Cliente',
    'Desconhecido',
    'Devolução',
    'Disponível',
    'Fornecedor(Reparação)',
    'Laboratório',
    'OT',
    'Parceiro',
    'PAT',
    'Spares'
];

$estadoEnvio = [
  'Ativa',
  'Concluida',
  'Cancelada'
];

$parceiros = [
    'Assistencia 35',
    'Bravantic',
    'Cronotécnica',
    'Field Newvision',
    'Hisense',
    'Inforlandia',
    'J.H. Ornelas',
    'Konica Minolta',
    'MC Computadores',
    'Newnote',
    'NEWVISION-Technology Centre',
    'SVDI-RET(Ingenico)'
];

$categorias = [
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

$catalogoProdutos = [
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

$formNovaPeca = $_SESSION['form_nova_peca'] ?? [];
unset($_SESSION['form_nova_peca']);

$valorCategoria = $formNovaPeca['categoria'] ?? ($pecaEdit['categoria'] ?? '');
$valorProduto = $formNovaPeca['produto'] ?? ($pecaEdit['produto'] ?? '');
$valorParceiro = $formNovaPeca['parceiro'] ?? ($pecaEdit['parceiro'] ?? '');
$valorEstado = $formNovaPeca['estado'] ?? ($pecaEdit['estado'] ?? '');
$valorSn = $_GET['sn'] ?? ($formNovaPeca['sn'] ?? ($pecaEdit['sn'] ?? ''));
$valorCodBarras = $_GET['cod_barras'] ?? ($formNovaPeca['cod_barras'] ?? ($pecaEdit['cod_barras'] ?? ''));


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
              header('Location: app.php?page=nova_peca' . ($editId > 0 ? 'edit=' . $editId : ''));
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

    $nomeOriginal = $ficheiro['name'] ?? '';
    $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

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
    $caminhoPdf = $uploadDir . $nomeTemporario;

    if (!move_uploaded_file($ficheiro['tmp_name'], $caminhoPdf)) {
        $_SESSION['mensagem_erro'] = 'Não foi possível guardar o PDF.';
        header('Location: app.php?page=envios');
        exit;
    }

    try {
        $textoExtraido = extrairTextoPdfNova($caminhoPdf);
        $dados = extrairDadosGuiaTransporteNova($textoExtraido, $pdo, $parceirosInventario);

        $_SESSION['form_envio'] = [
            'documento' => $dados['documento'] ?? '',
            'num_documento' => $dados['num_documento'] ?? '',
            'data_documento' => $dados['data_documento'] ?? '',
            'parceiro' => $dados['parceiro'] ?? '',
            'linha_categoria' => $dados['linha_categoria'] ?? [],
            'linha_produto' => $dados['linha_produto'] ?? [],
            'linha_quantidade' => $dados['linha_quantidade'] ?? [],
            'linha_num_serie' => $dados['linha_num_serie'] ?? [],
        ];

        $_SESSION['mensagem_sucesso'] = 'Guia lida com sucesso.';
        header('Location: app.php?page=envios');
        exit;
    } catch (Exception $e) {
        $_SESSION['mensagem_erro'] = 'Erro ao ler a guia: ' . $e->getMessage();
        header('Location: app.php?page=envios');
        exit;
    }
}


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




function countQuery(\PDO $pdo, string $sql): int {
    return (int)$pdo->query($sql)->fetchColumn();
}

$totalPecas = countQuery($pdo, "SELECT COUNT(*) FROM pecas");

$patsAtivos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Ativo'");

$ordensAtivas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Ativa'");

$ordensCanceladas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Cancelada'");

$ordensConcluidas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Concluida'");

$ultimoPat = $pdo->query("SELECT data_criacao FROM pats ORDER BY data_criacao DESC LIMIT 1")->fetchColumn() ?: null;

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
      DATE_FORMAT(data_criacao, '%Y-%m') AS mes_ordem,
      DATE_FORMAT(data_criacao, '%b %Y') AS mes,
      COUNT(*) AS total
    FROM pats
    WHERE data_criacao >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(data_criacao, '%Y-%m'), DATE_FORMAT(data_criacao, '%b %Y')
    ORDER BY mes_ordem ASC
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

  $sqlAuditoria = "SELECT id, peca_id, campo, antes, depois, utilizador, data_alteracao FROM historico";
    if ($whereAuditoria) {
      $sqlAuditoria .= " WHERE " . implode(" AND ", $whereAuditoria);
    }
    $sqlAuditoria .= " ORDER BY data_alteracao DESC, id DESC";

    $stmtAuditoria = $pdo->prepare($sqlAuditoria);
    $stmtAuditoria->execute($paramsAuditoria);
    $auditoriaLogs = $stmtAuditoria->fetchAll();
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
$envioVerId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$envioAtual = null;
$parceirosInventario = [];
$categoriasInventarioReal = [];
$catalogoInventarioReal = [];

if ($page === 'envios') {
  $stmt = $pdo->query("
    SELECT id, documento, num_documento, data_documento, parceiro, criado_por, created_at
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
    $csvPath = __DIR__ . '/report1780499256737.csv';

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
                
                return $value;
            };

            $headerMap = [];
            if (is_array($header)) {
                foreach ($header as $i => $col) {
                    $headerMap[$normalizeHeader($col)] = $i;
                }
            }

            $idxLastActivity = $headerMap['Last Activity'] ?? null;
            $idxAccountName = $headerMap['Account Name'] ?? null;
            $idxType = $headerMap['Type'] ?? null;
            $idxLastModified = $headerMap['Last Modified Date'] ?? null;
            $idxParent = $headerMap['Parent Account'] ?? null;



            $normalizeCsvValue = static function ($value) {
              $value = trim((string)$value);

              if ($value === '') {
                return '';
              }

              if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
              } else {
                $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                if (is_string($converted) && $converted !== '' && substr_count($converted, '�') < substr_count($value, '�')) {
                  $value = $converted;
                }
              }

              return $value;
            };



            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                $accountName = $normalizeCsvValue($row[$idxAccountName] ?? '');
                $type = $normalizeCsvValue($row[$idxType] ?? '');
                $parent = $normalizeCsvValue($row[$idxParent] ?? '');
                $lastActivity = $normalizeCsvValue($row[$idxLastActivity] ?? '');
                $lastModified = $normalizeCsvValue($row[$idxLastModified] ?? '');

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

            fclose($handle);
        }
    }

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
                $cliente['account_name'] . ' ' .
                $cliente['type'] . ' ' .
                $cliente['parent_account']
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

        if ($cliente['parent_account'] === '') {
            $clientesRoots[] = $cliente;
        }
    }

    usort($clientesRoots, static function ($a, $b) {
        return strcasecmp($a['account_name'], $b['account_name']);
    });

    foreach ($clientesChildrenMap as $parentName => &$children) {
        usort($children, static function ($a, $b) {
            return strcasecmp($a['account_name'], $b['account_name']);
        });
    }
    unset($children);
}


function active(string $p, string $page): string {
    return $p === $page ? 'active-link' : '';
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
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
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
  --sidebar-collapsed-width: 72px;
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
  transition: width .25s ease;
  }

.sidebar .brand{
  padding: 18px 22px;
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
  content:"-";
  margin-right:12px;
  color:#d9dde2;
}

.sidebar-submenu .submenu-link:hover{
  color:#ffffff;
}

.sidebar-group.open .sidebar-submenu{
  display:block;
}

.topbar {
  position: fixed;
  left: var(--sidebar-width);
  right: 0;
  top: 0;
  height: 64px;
  background: #cba35c;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 18px 0 22px;
  z-index: 10;
  transition: left .25s ease;
  }

.topbar.collapsed{
  left: var(--sidebar-collapsed-width);
  }

.topbar-title{
  color: #000;
  font-size: 24px;
  font-weight:700;
  line-height: 1;
  white-space: nowrap;
  }

.main{
  width: calc(100% - var(--sidebar-width));
  margin-left: var(--sidebar-width);
  padding: 80px 44px 28px 28px;
  box-sizing: border-box;
  transition: all .25s ease;
  }

.main.collapsed{
  width: calc(100% - var(--sidebar-collapsed-width));
  margin-left: var(--sidebar-collapsed-width);
  padding: 80px 44px 28px 28px;
  }

.sidebar.collapsed{
  width: var(--sidebar-collapsed-width);
  }

.sidebar.collapsed .brand{
  padding: 16px 0 22px;
  justify-content: center;
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
  position: absolute;
  bottom: 18px;
  left: 24px;
  opacity: .92;
  font-weight: 700;
  color: #d0d0d0;
  line-height: 1.1;
  }

.sidebar.collapsed .footer-logo{
  left: 50%;
  transform: translateX(-50%);
  width: 100%;
  text-align: center;
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


.pat-grid{
          grid-template-columns:repeat(2,90px) !important;
          max-width:164px !important;
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
.s-Disponível{background:#28a745}
.s-PAT{background:#6f42c1}
.s-Laboratório{background:#2470dc}
.s-Parceiro{background:#8c564b}
.s-Abater{background:#dc3545}
.s-Cliente{background:#20c997}
.s-Desconhecido{background:#ffc107;color:#222}
.s-Devolução{background:#17a2b8}
.s-Fornecedor\(Reparação\){background:#fd7e14}
.s-OT{background:#495057}
.s-Spares{background:#47372A}
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
      .card-grid,
      .panel-grid, 
      .filters,
      .filters2,
      .form-grid
    {grid-template-columns:1fr 1fr;}
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

.envios-layout{
  display:grid;
  grid-template-columns: minmax(620px, 1.45fr) minmax(420px, 1fr);
  gap:24px;
  align-items:start;
  margin-top:20px;
  }

.envio-form-panel,
.envio-lista-panel{
  width:100%;
  min-width:0;
  box-sizing:border-box;
  }

.envios-table{
  width:100%;
  border-collapse:collapse;
  background:#fff;
  }

.envios-table th,
.envios-table td{
  padding:12px;
  text-align:left;
  vertical-align:middle;
  }

.envios-table th{
  background:#f6f7f9;
  color:#222;
  border:1px solid #e5e7eb;
  }

.envios-table td{
  border:1px solid #e5e7eb;
  }

.envios-table tbody tr:hover{
  background:#f8f9fb;
  }

.envios-vazio{
  text-align:center;
  color:#6b7280;
  padding:18px !important;
  }

.linha-envio-grid{
  display:grid;
  grid-template-columns:1fr 2fr 120px 1.4fr;
  gap:10px;
  margin-bottom:10px;
  }

@media (max-width: 1100px) {
  .linha-envio-grid{
    grid-template-columns:1fr 1fr;
  }
}

@media (max-width: 900px) {
  .envios-layout{
    grid-template-columns:1fr;
  }

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

.cliente-toggle.is-open{
  background:#cba35c;
  color:#000;
}

.tipo-badge{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:600;
  white-space:nowrap;
}

.tipo-customer{ background:#d1fae5; color:#065f46; }
.tipo-prospect{ background:#fef3c7; color:#92400e; }
.tipo-partner{ background:#dbeafe; color:#1d4ed8; }
.tipo-other{ background:#e5e7eb; color:#374151; }

.clientes-empty{
  color:#6b7280;
  text-align:center;
  padding:22px !important;
}

@media (max-width: 1200px) {
  .clientes-kpis{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }
  .cliente-filtros{
    grid-template-columns:1fr 1fr;
  }
}

@media (max_width: 768px){
  .clientes-kpis,
  .clientes-filtro{
    grid-template-columns:1fr;
  }
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

  <a class="<?=active('alertas',$page)?>" href="app.php?page=alertas">
    <i class="bi bi-exclamation-octagon"></i><span>Clientes</span>
  </a>

  <a class="<?=active('inventario',$page)?>" href="app.php?page=inventario">
    <i class="bi bi-box-seam"></i><span>Inventário</span>
  </a>

  <a class="<?=active('pats',$page)?>" href="app.php?page=pats">
    <i class="bi bi-headset"></i><span>Pat's</span>
  </a>

  <a class="<?=active('envios',$page)?>" href="app.php?page=envios">
    <i class="bi bi-truck"></i><span>Envios</span>
  </a>

  <a class="<?=active('qrs',$page)?>" href="app.php?page=qrs">
    <i class="bi bi-qr-code"></i><span>QR's</span>
  </a>

  <a class="<?=active('encomendas',$page)?>" href="app.php?page=encomendas">
    <i class="bi bi-cart"></i><span>Encomendas</span>
  </a>

  <div class="sidebar-group <?= in_array($page, ['contas', 'auditoria']) ? 'open' : '' ?>">
  <button class="sidebar-parent" type="button" id="configToggle">
    <span class="sidebar-parent-left">
      <i class="bi bi-gear"></i>
      <span>Configurações</span>
    </span>
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
  <div class="topbar-title"><?= htmlspecialchars($topbarTitle) ?>
    </div>
  <div class="user-box">
    <span><?= htmlspecialchars($_SESSION['user_nome']) ?></span>

    <?php if (!empty($_SESSION['user_fotografia'])): ?>
      <img src="<?= htmlspecialchars($_SESSION['user_fotografia']) ?>" alt="Foto de perfil" class="user-avatar">
    <?php else: ?>
      <div class="user-avatar"></div>
    <?php endif; ?>

    <a href="logout.php" class="logout">Sair</a>
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

  <!-- Linha de baixo com os outros dois gráficos -->
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

<div class="panel-grid">
  <div class="panel">
    <h4>Estado das Peças</h4>
    <canvas id="estadoBarChart"></canvas>
  </div>

  <div class="panel">
    <h4>Peças por Parceiro</h4>
    <canvas id="parceiroChart"></canvas>
  </div>
</div>





<?php elseif ($page === 'inventario'): ?>
  <div style="margin-bottom:18px">
    <a class="btn btn-teal" href="app.php?page=nova_peca">Adicionar Peça</a>
    <a class="btn btn-green" href="app.php?page=qrs">Ler</a>
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
        <div><label>Tipo:</label><select name="categoria">
          <option value="">-- Todos --</option>
            <?php foreach($categorias as $cat): ?>
            <option value="<?=$cat?>" <?= $filters['categoria']===$cat?'selected':'' ?>><?=$cat?></option><?php endforeach; ?></select>
        </div>

      <div><label>Estado:</label><select name="estado">
        <option value="">-- Todos --</option>
          <?php foreach($estados as $estado): ?>
          <option value="<?=$estado?>" <?= $filters['estado']===$estado?'selected':'' ?>><?=$estado?></option><?php endforeach; ?></select>
      </div>

      <div><label>Parceiro:</label><select name="parceiro">
        <option value="">-- Todos --</option>
          <?php foreach($parceiros as $parceiro): ?>
          <option value="<?=$parceiro?>" <?= $filters['parceiro']===$parceiro?'selected':'' ?>><?=$parceiro?></option>
          <?php endforeach; ?></select>
      </div>

      <div><button class="btn btn-blue" type="submit"><i class="bi bi-search"></i> Filtrar</button></div>
    </div>

    <div class="filters2">
      <div><label>SN (N.º de série):</label>
            <input type="text" name="sn" value="<?=htmlspecialchars($filters['sn'])?>" placeholder="ex.: ABC12345">
      </div>

<div>
  <label>Nome da peça:</label>
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
          <td>
        <span class="badge s-<?= preg_replace('/[^A-Za-zÀ-ÿ()]/u', '', $p['estado']) ?>"><?= htmlspecialchars($p['estado']) ?>
        </span>
          </td>
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
        <select name="categoria" id="categoria" required>
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
        <select name="produto" id="produto" required>
          <option value="">-- Selecione o produto --</option>
        </select>
      </div>

      <div>
        <label>Parceiro:*</label>
        <select name="parceiro" required>
          <option value="">-- Selecione o parceiro --</option>
          <?php foreach ($parceiros as $parceiro): ?>
            <option value="<?= htmlspecialchars($parceiro) ?>" <?= ($valorParceiro === $parceiro) ? 'selected' : '' ?>>
              <?= htmlspecialchars($parceiro) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Estado:*</label>
        <select name="estado" required>
          <option value="">-- Selecione o estado --</option>
          <?php foreach ($estados as $estado): ?>
            <option value="<?= htmlspecialchars($estado) ?>" <?= ($valorEstado === $estado) ? 'selected' : '' ?>>
              <?= htmlspecialchars($estado) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Número de Série (S_Number):*</label>
          <input type="text" name="sn" id="sn" value="<?= htmlspecialchars($valorSn) ?>" required>
      </div>
      
      <div>
        <label>Código de Barras:*</label>
        <div class="barcode-copy-wrap">
          <input type="text" name="cod_barras" id="cod_barras" value="<?= htmlspecialchars($valorCodBarras) ?>" required>
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

<?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
    <?php unset($_SESSION['mensagem_erro']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
    <?php unset($_SESSION['mensagem_sucesso']); ?>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px;">
    <h4 style="margin-bottom:14px;">Leitura de Guia de Transporte</h4>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="form_type" value="importar_guia_envio">

    <label>Guia PDF</label>
    <input type="file" name="guia_pdf" accept=".pdf,application/pdf" required>

    <button type="submit" class="btn btn-blue">Ler Guia</button>
  </form>
</div>

<div class="envios-layout">
    <div class="panel envio-form-panel">
        <h4 style="margin-bottom:16px;">
            <?= $envioAtual ? 'Rascunho / Validação do Envio' : 'Novo Envio' ?>
        </h4>

        <?php if ($envioAtual): ?>
            <?php
                $isCliente = (($envioAtual['documento'] ?? '') === 'G. Transp cliente');
                $isFornecedor = (($envioAtual['documento'] ?? '') === 'G. Transp fornec');
            ?>

            <div class="small-note" style="margin-bottom:12px;">
                Estado atual: <strong><?= htmlspecialchars($envioAtual['estado']) ?></strong>
            </div>

            <div class="small-note" style="margin-bottom:14px; color:#0f5132;">
                Leitura da Guia de Transporte finalizada com sucesso. Revê os dados antes de confirmar o envio.
            </div>

            <?php if (!empty($envioAtual['observacoes'])): ?>
                <div class="small-note" style="margin-bottom:16px;">
                    <?= htmlspecialchars($envioAtual['observacoes']) ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="form_type" value="guardar_rascunho_envio">
                <input type="hidden" name="envio_id" value="<?= (int)$envioAtual['id'] ?>">

                <div class="form-grid">
                    <div>
                        <label>Documento</label>
                        <select name="documento" id="documento_envio" required>
                            <option value="">-- Selecione o Documento --</option>
                            <option value="G. Transp fornec" <?= $isFornecedor ? 'selected' : '' ?>>G. Transp fornec</option>
                            <option value="G. Transp cliente" <?= $isCliente ? 'selected' : '' ?>>G. Transp cliente</option>
                        </select>
                    </div>

                    <div>
                        <label>Nº Documento</label>
                        <input type="text" name="num_documento" value="<?= htmlspecialchars($envioAtual['num_documento'] ?? '') ?>" required>
                    </div>

                    <div>
                        <label>Data</label>
                        <input type="date" name="data_documento" id="data_documento" value="<?= htmlspecialchars($envioAtual['data_documento'] ?? '') ?>" required>
                    </div>

                    <div>
                        <label>Parceiro</label>
                        <?php if ($isCliente): ?>
                            <select name="parceiro" id="parceiro_envio" required>
                                <option value="Field Service" selected>Field Service</option>
                            </select>
                            <div class="small-note">Guia Cliente: parceiro forçado a Field Service.</div>
                        <?php else: ?>
                            <select name="parceiro" id="parceiro_envio" required>
                                <option value="">-- Selecione o Parceiro --</option>
                                <?php foreach ($parceirosInventario as $parceiroInv): ?>
                                    <option value="<?= htmlspecialchars($parceiroInv) ?>" <?= (($envioAtual['parceiro'] ?? '') === $parceiroInv) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($parceiroInv) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small-note">Guia Fornecedor: parceiro identificado a partir da guia e limitado aos parceiros do Inventário.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <h4 style="margin:22px 0 12px;">Linhas do Envio</h4>

                <div id="linhasEnvioWrap">
                    <?php if (empty($envioLinhas)): ?>
                        <div class="linha-envio-grid">
                            <select name="linha_categoria[]" class="linha-categoria" required>
                                <option value="">-- Tipo --</option>
                                <?php foreach ($categoriasInventarioReal as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="linha_produto[]" class="linha-produto" data-selected="" required>
                                <option value="">-- Nome da Peça --</option>
                            </select>

                            <input type="number" step="1" min="1" name="linha_quantidade[]" value="1" required>
                            <input type="text" name="linha_num_serie[]" class="linha-num-serie" placeholder="Nº Série">
                            <div class="sn-avisos"></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($envioLinhas as $i => $linha): ?>
                            <div class="linha-envio-grid" data-linha-index="<?= (int)$i ?>">
                                <select name="linha_categoria[]" class="linha-categoria" required>
                                    <option value="">-- Tipo --</option>
                                    <?php foreach ($categoriasInventarioReal as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= (($linha['artigo'] ?? '') === $cat) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="linha_produto[]" class="linha-produto" data-selected="<?= htmlspecialchars($linha['designacao'] ?? '') ?>" required>
                                    <option value="">-- Nome da Peça --</option>
                                </select>

                                <input type="number" step="1" min="1" name="linha_quantidade[]" value="<?= htmlspecialchars($linha['quantidade'] ?? 1) ?>" required>

                                <input type="text" name="linha_num_serie[]" class="linha-num-serie" value="<?= htmlspecialchars($linha['num_serie'] ?? '') ?>" placeholder="Nº Série">

                                <div class="sn-avisos">
                                    <?php if (!empty($linha['observacoes'])): ?>
                                        <div class="small-note" style="color:#b26a00;"><?= htmlspecialchars($linha['observacoes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-grey" id="adicionarLinhaEnvio">Adicionar Linha</button>
                    <button type="submit" class="btn btn-blue">Guardar como Rascunho</button>
                </div>
            </form>

            <form method="post" style="margin-top:14px;">
                <input type="hidden" name="form_type" value="confirmar_envio_final">
                <input type="hidden" name="envio_id" value="<?= (int)$envioAtual['id'] ?>">
                <button type="submit" class="btn btn-green">Confirmar e Guardar Envio</button>
            </form>

        <?php else: ?>
            <div class="small-note">
                Ainda não existe nenhum rascunho aberto. Faz primeiro a leitura da guia para abrir a página de validação.
            </div>
        <?php endif; ?>
    </div>

    <div class="panel envio-lista-panel">
        <h4 style="margin-bottom:18px;">Lista de Envios</h4>

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
                    <tr>
                        <td colspan="8" class="envios-vazio">Nenhum envio registado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($envios as $e): ?>
                        <tr>
                            <td><?= (int)$e['id'] ?></td>
                            <td><?= htmlspecialchars($e['documento']) ?></td>
                            <td><?= htmlspecialchars($e['num_documento']) ?></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($e['data_documento']))) ?></td>
                            <td><?= htmlspecialchars($e['parceiro']) ?></td>
                            <td><?= htmlspecialchars($e['estado']) ?></td>
                            <td><?= htmlspecialchars($e['criado_por']) ?></td>
                            <td class="actions">
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

  <div class="panel" style="max-width: 1000px;">
    <h4 style="margin-bottom:18px;">Leitor de QR / Código de Barras</h4>

    <div class="form-grid" style="align-items:start;">
      <div>
        <label>Leitura automática</label>
        <div id="reader" style="width:100%; max-width:420px; border:1px solid #d6dbe1; border-radius:10px; overflow:hidden; background:#fff;"></div>
        <div class="small-note">Permite acesso à câmara e aponta para o código.</div>
      </div>

      <div>
        <form method="get" class="panel" style="padding:0; box-shadow:none; background:transparent;">
          <input type="hidden" name="page" value="qrs">

          <div style="margin-bottom:14px;">
            <label for="qr_code">Valor lido</label>
            <input type="text" name="qr_code" id="qr_code" value="<?= htmlspecialchars($qrTermo) ?>" placeholder="SN ou Código de Barras">
          </div>

          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit" class="btn btn-blue">Procurar</button>
            <a href="app.php?page=qrs" class="btn btn-grey">Limpar</a>
          </div>
        </form>

        <?php if ($qrTermo !== ''): ?>
          <div class="panel" style="margin-top:18px;">
            <h4 style="margin-bottom:14px;">Resultado</h4>

            <?php if ($qrResultado): ?>
              <table class="table">
                <tr><th>ID</th><td><?= (int)$qrResultado['id'] ?></td></tr>
                <tr><th>Categoria</th><td><?= htmlspecialchars($qrResultado['categoria']) ?></td></tr>
                <tr><th>Produto</th><td><?= htmlspecialchars($qrResultado['produto']) ?></td></tr>
                <tr><th>SN</th><td><?= htmlspecialchars($qrResultado['sn']) ?></td></tr>
                <tr><th>Código de Barras</th><td><?= htmlspecialchars($qrResultado['cod_barras']) ?></td></tr>
                <tr><th>Parceiro</th><td><?= htmlspecialchars($qrResultado['parceiro']) ?></td></tr>
                <tr><th>Estado</th><td><?= htmlspecialchars($qrResultado['estado']) ?></td></tr>
              </table>

              <div style="margin-top:16px;">
                <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?= (int)$qrResultado['id'] ?>">Editar</a>
                <a class="btn btn-grey" href="app.php?page=historico&id=<?= (int)$qrResultado['id'] ?>">Histórico</a>
              </div>

            <?php else: ?>
              <div class="alerta-erro" style="margin-bottom:14px;">
                Não foi encontrada nenhuma peça com esse SN ou Código de Barras.
              </div>

              <a 
                class="btn btn-teal" 
                href="app.php?page=nova_peca&sn=<?= urlencode($qrTermo) ?>&cod_barras=<?= urlencode($qrTermo) ?>"
                >
                 Criar nova peça com este SN
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>


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
          <input type="text" name="nome" required autocomplete="off" value="<?= htmlspecialchars($contaEdit['nome'] ?? '') ?>">
        </div>

        <div style="margin-bottom:14px;">
          <label>Email</label>
          <input 
            type="email"
            name="email" 
            required 
            autocomplete="off"
            pattern=".+@newvision\.pt"
            value="<?= htmlspecialchars($contaEdit['email'] ?? '') ?>"
          >
        </div>

        <div style="margin-bottom:14px;">
          <label>Password<?= $contaEdit ? ' (deixar em branco para manter a atual)' : '' ?> 
            </label>
          <input type="password" name="password" <?= $contaEdit ? '' : 'required' ?> autocomplete="new-password">
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
                              <td>Peça #<?= (int)$log['peca_id'] ?>
                    <?php if ($log['antes'] !== '' || $log['depois'] !== ''): ?>
                      — antes: <span style="color:#d9534f;"><?= htmlspecialchars($log['antes']) ?></span>
                      | depois: <span style="color:#28a745;"><?= htmlspecialchars($log['depois']) ?></span>
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
    </div>
</section>



<?php elseif ($page === 'alertas') : ?>
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
          <input type="text" name="q" value="<?= htmlspecialchars($clientesFiltros['q']) ?>" placeholder="Nome da conta, conta-mãe ou tipo">
        </div>

        <div>
          <label>Tipo</label>
          <select name="type">
            <option value="">-- Todos --</option>
            <?php foreach ($clientesTipos as $tipo): ?>
              <option value="<?= htmlspecialchars($tipo) ?>"
                <?= $clientesFiltros['type'] === $tipo ? 'selected' : '' ?>>
                <?= htmlspecialchars($tipo) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Hierarquia</label>
          <select name="hierarquia">
            <option value="">-- Todas --</option>
            <option value="com_parent" <?= $clientesFiltros['hierarquia'] === 'com_parent' ? 'selected' : '' ?>>Só Contas-Filhas</option>
            <option value="so_pais" <?= $clientesFiltros['hierarquia'] === 'so_pais' ? 'selected' : '' ?>>Só Contas-Mãe</option>
            <option value="so_sem_parent" <?= $clientesFiltros['hierarquia'] === 'so_sem_parent' ? 'selected' : '' ?>>Sem Conta-Mãe</option>
          </select>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit" class="btn btn-blue">Filtrar</button>
          <a href="app.php?page=alertas" class="btn btn-grey">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <div class="planel">
    <h4 style="margin-bottom:16px;">Lista de Clientes</h4>

    <div style="overflow-x:auto;">
      <table class="clientes-table">
        <thead>
          <tr>
            <th style="width:34%;">Conta</th>
            <th style="width:16%;">Tipo</th>
            <th style="width:24%;">Conta-Mãe</th>
            <th style="width:13%;">Última Atividade</th>
            <th style="width:13%;">Última Modificação</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clientesRoots)): ?>
            <tr>
              <td colspan="5" class="clientes-empty">Nenhum Cliente encontrado.</td>
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
                  <?= htmlspecialchars($cliente['type'] !== '' ? $cliente['type'] : 'Sem tipo') ?>
                </span>
              </td>
              <td><?= htmlspecialchars($cliente['parent_account'] !== '' ? $cliente['parent_account'] : '-') ?></td>
              <td><?= htmlspecialchars($cliente['last_activity'] !== '' ? $cliente['last_activity'] : '-') ?></td>
              <td><?= htmlspecialchars($cliente['last_modified_date'] !== '' ? $cliente['last_modified_date'] : '-') ?></td>
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
                    <span class="tipo-badge <?= $childTypeClass ?>"> <?= htmlspecialchars($filho['type'] !== '' ? $filho['type'] : 'Sem tipo') ?> </span>
                  </td>
                  <td>
                    <?= htmlspecialchars($filho['parent_account'] !== '' ? $filho['parent_account'] : '-') ?></td>
                  <td>
                    <?= htmlspecialchars($filho['last_activity'] !== '' ? $filho['last_activity'] : '-') ?></td>
                  <td>
                    <?= htmlspecialchars($filho['last_modified_date'] !== '' ? $filho['last_modified_date'] : '-') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

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

if (document.getElementById('estadoBarChart')) {
  new Chart(document.getElementById('estadoBarChart'), {
    type: 'bar',
    data: {
      labels: estadoLabels,
      datasets: [{
        label: 'Peças',
        data: estadoTotals,
        backgroundColor: '#cba35c'
      }]
    },
    options: {
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

if (document.getElementById('parceiroChart')) {
  new Chart(document.getElementById('parceiroChart'), {
    type: 'bar',
    data: {
      labels: parceiroLabels,
      datasets: [{
        label: 'Peças',
        data: parceiroTotals,
        backgroundColor: '#2ca59a'
      }]
    },
    options: {
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
const sidebar = document.getElementById('sidebar');
const toggleSidebar = document.getElementById('toggleSidebar');
const topbar = document.querySelector('.topbar');
const main = document.querySelector('.main');

if (sidebar && toggleSidebar && topbar && main){
  toggleSidebar.addEventListener('click', function (){
    sidebar.classList.toggle('collapsed');
    topbar.classList.toggle('collapsed');
    main.classList.toggle('collapsed');
  });
}
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
  const configGroup = document.querySelector('.sidebar-group');
  const sidebar = document.getElementById('sidebar');

  if (configToggle && configGroup && sidebar) {
    configToggle.addEventListener('click', function () {
      if (!sidebar.classList.contains('collapsed')) {
        configGroup.classList.toggle('open');
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

</body>
</html>
