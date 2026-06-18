<?php
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
    // Caminho centralizado no bootstrap.php (sobreponível no config.php / env)
    $pdftotext = PDFTOTEXT_BIN;

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

            $pdo->prepare("UPDATE pecas SET estado = ?, parceiro = ?, estado_desde = NOW() WHERE id = ?")->execute([
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
?>

<!-- == Linha Superior: Leitura Guia (Esquerda) + Formulario (Direita) == -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-bottom:20px;">
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
                    <form method="post" style="margin:0;" onsubmit="return nvConfirmar(this, 'Apagar esta Guia? Esta ação é irreversível.');">
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


