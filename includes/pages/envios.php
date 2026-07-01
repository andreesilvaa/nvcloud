<?php
// ============================================================
// 11. VALORES TEMPORÁRIOS DO FORMULÁRIO DE ENVIO
// ============================================================

$formEnvio = $_SESSION["form_envio"] ?? [];
unset($_SESSION["form_envio"]);

$valorDocumentoEnvio = $formEnvio["documento"] ?? "";
$valorNumDocumentoEnvio = $formEnvio["num_documento"] ?? "";
$valorDataDocumentoEnvio = $formEnvio["data_documento"] ?? "";
$valorParceiroEnvio = $formEnvio["parceiro"] ?? "";
$valorLinhasCategoriaEnvio = $formEnvio["linha_categoria"] ?? [];
$valorLinhasProdutoEnvio = $formEnvio["linha_produto"] ?? [];
$valorLinhasQuantidadeEnvio = $formEnvio["linha_quantidade"] ?? [];
$valorLinhasNumSerieEnvio = $formEnvio["linha_num_serie"] ?? [];

$parceirosInventario = $pdo
    ->query(
        "
    SELECT DISTINCT parceiro
    FROM pecas
    WHERE parceiro IS NOT NULL AND parceiro <> ''
    ORDER BY parceiro ASC
    ",
    )
    ->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// 12. PROCESSAMENTO POST: IMPORTAR GUIA DE ENVIO
// ============================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "importar_guia_envio"
) {
    if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf"] ?? "")) {
        $_SESSION["mensagem_erro"] = "Ação inválida.";
        header("Location: app.php?page=envios");
        exit();
    }
    if (!isset($_FILES["guia_pdf"]) || !is_array($_FILES["guia_pdf"])) {
        $_SESSION["mensagem_erro"] = "Nenhum ficheiro PDF foi enviado.";
        header("Location: app.php?page=envios");
        exit();
    }

    $ficheiro = $_FILES["guia_pdf"];

    if (($ficheiro["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION["mensagem_erro"] = "Ocorreu um erro no upload do ficheiro.";
        header("Location: app.php?page=envios");
        exit();
    }

    $extensao = strtolower(
        pathinfo($ficheiro["name"] ?? "", PATHINFO_EXTENSION),
    );
    if ($extensao !== "pdf") {
        $_SESSION["mensagem_erro"] = "O ficheiro tem de ser um PDF válido.";
        header("Location: app.php?page=envios");
        exit();
    }

    $uploadDir = dirname(__DIR__, 2) . "/uploads/guias/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $nomeTemporario =
        "guia_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . ".pdf";
    $caminhoPdf = $uploadDir . $nomeTemporario;

    if (!move_uploaded_file($ficheiro["tmp_name"], $caminhoPdf)) {
        $_SESSION["mensagem_erro"] = "Não foi possível guardar o PDF.";
        header("Location: app.php?page=envios");
        exit();
    }

    try {
        $textoExtraido = extrairTextoPdfNova($caminhoPdf);

        // Catálogo de matching construído a partir da tabela `pecas` — a MESMA
        // fonte usada pelas dropdowns de exibição (categoriasInventarioReal /
        // catalogoInventarioReal). Garante que o Tipo e o Nome da Peça extraídos
        // existem nas listas e ficam automaticamente selecionados no formulário.
        $catalogoPecas = [];
        foreach (
            $pdo->query(
                "SELECT DISTINCT categoria, produto FROM pecas
              WHERE categoria IS NOT NULL AND categoria <> ''
                AND produto   IS NOT NULL AND produto   <> ''
              ORDER BY categoria ASC, produto ASC",
            )
            as $rowCat
        ) {
            $catalogoPecas[$rowCat["categoria"]][] = $rowCat["produto"];
        }

        $dados = extrairDadosGuiaTransporteNova(
            $textoExtraido,
            $pdo,
            $parceirosInventario,
            $catalogoPecas,
        );

        // Deteção de duplicados: se já existe rascunho com o mesmo Nº Documento, redireciona para ele
        if (($dados["num_documento"] ?? "") !== "") {
            $stmtDup = $pdo->prepare(
                "SELECT id FROM envios WHERE num_documento = ? AND estado = 'Rascunho' LIMIT 1",
            );
            $stmtDup->execute([$dados["num_documento"]]);
            $dupRow = $stmtDup->fetch();
            if ($dupRow) {
                $_SESSION["mensagem_sucesso"] =
                    "Esta guia (N\u00ba " .
                    $dados["num_documento"] .
                    ") j\u00e1 foi importada. A redirecionar para o rascunho existente.";
                header("Location: app.php?page=envios&ver=" . $dupRow["id"]);
                exit();
            }
        }

        // Cria o rascunho diretamente na BD com os dados extra\u00eddos do PDF
        $pdo->beginTransaction();

        $paramsEnvio = [
            $dados["documento"] ?? "",
            $dados["num_documento"] ?? "",
            $dados["data_documento"] !== "" ? $dados["data_documento"] : null,
            $dados["parceiro"] ?? "",
            "uploads/guias/" . $nomeTemporario,
            $_SESSION["user_nome"] ?? "Sistema",
        ];
        try {
            $pdo->prepare(
                "
              INSERT INTO envios (documento, num_documento, data_documento, parceiro, ficheiro_path, criado_por, created_at, estado)
              VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Rascunho')
            ",
            )->execute($paramsEnvio);
        } catch (PDOException $pe) {
            $pdo->prepare(
                "
              INSERT INTO envios (documento, num_documento, data_documento, parceiro, criado_por, created_at, estado)
              VALUES (?, ?, ?, ?, ?, NOW(), 'Rascunho')
            ",
            )->execute(array_slice($paramsEnvio, 0, 5));
        }

        $envioId = (int) $pdo->lastInsertId();

        $stmtLinha = $pdo->prepare("
            INSERT INTO envios_linhas (envio_id, artigo, designacao, quantidade, num_serie)
            VALUES (?, ?, ?, ?, ?)
        ");

        $categorias = $dados["linha_categoria"] ?? [];
        $produtos = $dados["linha_produto"] ?? [];
        $quantidades = $dados["linha_quantidade"] ?? [];
        $numSeries = $dados["linha_num_serie"] ?? [];

        foreach ($categorias as $i => $cat) {
            $stmtLinha->execute([
                $envioId,
                $cat,
                $produtos[$i] ?? "",
                $quantidades[$i] ?? 1,
                $numSeries[$i] ?? "",
            ]);
        }

        $pdo->commit();

        $_SESSION["mensagem_sucesso"] =
            "Guia lida com sucesso. Revê os dados e confirma o envio.";
        // Redireciona para o rascunho criado — o formulário pré-preenche automaticamente
        header("Location: app.php?page=envios&ver=" . $envioId);
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION["mensagem_erro"] = "Erro ao ler a guia: " . $e->getMessage();
        header("Location: app.php?page=envios");
        exit();
    }
}

// ============================================================
// 13. PROCESSAMENTO POST: NOVO ENVIO
// ============================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "novo_envio"
) {
    $documento = trim($_POST["documento"] ?? "");
    $num_documento = trim($_POST["num_documento"] ?? "");
    $data_documento = trim($_POST["data_documento"] ?? "");
    $parceiro = trim($_POST["parceiro"] ?? "");

    $categoriasEnvio = $_POST["linha_categoria"] ?? [];
    $produtosEnvio = $_POST["linha_produto"] ?? [];
    $quantidades = $_POST["linha_quantidade"] ?? [];
    $numSeries = $_POST["linha_num_serie"] ?? [];

    if (
        $documento === "" ||
        $num_documento === "" ||
        $data_documento === "" ||
        $parceiro === ""
    ) {
        $_SESSION["mensagem_erro"] =
            "Preencher Documento, Nº Documento, Data e Parceiro.";
        $_SESSION["form_envio"] = $_POST;
        header("Location: app.php?page=envios");
        exit();
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_documento)) {
        $_SESSION["mensagem_erro"] =
            "A data do documento tem de estar no formato AAAA-MM-DD.";
        $_SESSION["form_envio"] = $_POST;
        header("Location: app.php?page=envios");
        exit();
    }

    $linhasValidas = [];

    foreach ($produtosEnvio as $i => $produtoEnvio) {
        $categoriaEnvio = trim($categoriasEnvio[$i] ?? "");
        $produtoEnvio = trim($produtoEnvio ?? "");
        $quantidade = trim($quantidades[$i] ?? "");
        $numSerie = trim($numSeries[$i] ?? "");

        if (
            $categoriaEnvio === "" &&
            $produtoEnvio === "" &&
            $quantidade === "" &&
            $numSerie === ""
        ) {
            continue;
        }

        if (
            $categoriaEnvio === "" ||
            $produtoEnvio === "" ||
            $quantidade === ""
        ) {
            $_SESSION["mensagem_erro"] =
                "Cada linha tem de ter Tipo, Nome da Peça e Quantidade.";
            $_SESSION["form_envio"] = $_POST;
            header("Location: app.php?page=envios");
            exit();
        }

        if (
            !isset($catalogoProdutos[$categoriaEnvio]) ||
            !in_array($produtoEnvio, $catalogoProdutos[$categoriaEnvio], true)
        ) {
            $_SESSION["mensagem_erro"] =
                "A linha do envio contém um Tipo ou Nome da Peça inválido.";
            $_SESSION["form_envio"] = $_POST;
            header("Location: app.php?page=envios");
            exit();
        }

        $linhasValidas[] = [
            "categoria" => $categoriaEnvio,
            "produto" => $produtoEnvio,
            "quantidade" => (float) $quantidade,
            "num_serie" => $numSerie,
        ];
    }

    if (count($linhasValidas) === 0) {
        $_SESSION["mensagem_erro"] =
            "O envio tem de conter pelo menos uma linha válida.";
        $_SESSION["form_envio"] = $_POST;
        header("Location: app.php?page=envios");
        exit();
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
            $_SESSION["user_nome"] ?? "Sistema",
        ]);

        $envioId = (int) $pdo->lastInsertId();

        $stmtLinha = $pdo->prepare("
            INSERT INTO envios_linhas (envio_id, artigo, designacao, quantidade, num_serie)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($linhasValidas as $linha) {
            $stmtLinha->execute([
                $envioId,
                $linha["categoria"],
                $linha["produto"],
                $linha["quantidade"],
                $linha["num_serie"],
            ]);
        }

        $pdo->commit();
        $_SESSION["mensagem_sucesso"] = "Envio criado com sucesso.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION["mensagem_erro"] =
            "Erro ao criar o envio: " . $e->getMessage();
        $_SESSION["form_envio"] = $_POST;
    }

    header("Location: app.php?page=envios");
    exit();
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
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout (texto extraído)
        2 => ["pipe", "w"], // stderr (erros do pdftotext)
    ];

    $process = proc_open($cmd, $descSpec, $pipes);

    if (!is_resource($process)) {
        throw new Exception(
            "Não foi possível iniciar o pdftotext. Verifica o caminho.",
        );
    }

    fclose($pipes[0]);
    $texto = stream_get_contents($pipes[1]);
    $erros = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $codigo = proc_close($process);

    if ($codigo !== 0 || trim($texto) === "") {
        throw new Exception(
            "Erro ao extrair texto do PDF" .
                ($erros !== ""
                    ? ": " . trim($erros)
                    : ". Verifica se o ficheiro não está corrompido."),
        );
    }

    return $texto;
}

// ============================================================
// Analisa o texto extraído e devolve os dados estruturados
// para preencher o formulário de envio
// ============================================================
function extrairDadosGuiaTransporteNova(
    string $texto,
    PDO $pdo,
    array $parceirosInventario,
    array $catalogoProdutos = [],
): array {
    // ── Auxiliar: normaliza texto para comparações (remove acentos, minúsculas)
    $norm = static function (string $s): string {
        $mapa = [
            "á" => "a",
            "à" => "a",
            "ã" => "a",
            "â" => "a",
            "ä" => "a",
            "é" => "e",
            "è" => "e",
            "ê" => "e",
            "ë" => "e",
            "í" => "i",
            "ì" => "i",
            "î" => "i",
            "ï" => "i",
            "ó" => "o",
            "ò" => "o",
            "õ" => "o",
            "ô" => "o",
            "ö" => "o",
            "ú" => "u",
            "ù" => "u",
            "û" => "u",
            "ü" => "u",
            "ç" => "c",
            "ñ" => "n",
            "Á" => "a",
            "À" => "a",
            "Ã" => "a",
            "Â" => "a",
            "Ä" => "a",
            "É" => "e",
            "È" => "e",
            "Ê" => "e",
            "Ë" => "e",
            "Í" => "i",
            "Ì" => "i",
            "Î" => "i",
            "Ï" => "i",
            "Ó" => "o",
            "Ò" => "o",
            "Õ" => "o",
            "Ô" => "o",
            "Ö" => "o",
            "Ú" => "u",
            "Ù" => "u",
            "Û" => "u",
            "Ü" => "u",
            "Ç" => "c",
            "Ñ" => "n",
        ];
        // mb_strtolower garante suporte correto a UTF-8. Colapsa também espaços
        // múltiplos (comuns no texto extraído de PDFs com pdftotext -layout, onde
        // colunas alinhadas geram vários espaços seguidos) para um único espaço,
        // evitando falhas de correspondência por diferenças de espaçamento.
        $normalizado = mb_strtolower(strtr($s, $mapa), "UTF-8");
        return trim(preg_replace("/\s+/u", " ", $normalizado));
    };

    // O PDF tem 3 vias (Original, Duplicado, Triplicado) separadas por \f
    // Só a primeira página é necessária
    $paginas = preg_split('/\f/', $texto);
    $pagina = $paginas[0] ?? $texto;
    $linhas = array_values(
        array_filter(
            array_map("trim", explode("\n", $pagina)),
            static fn($l) => $l !== "",
        ),
    );

    // ── 1. Tipo de documento ──────────────────────────────────────────────────
    // Pesquisa linha-a-linha para evitar falsos positivos causados por "fornec"
    // ou "clien" noutras partes da página (rodapé, morada, texto legal).
    // Fallback: página inteira com âncora de linha (/m, sem dotall).
    $documento = "";
    $paginaNorm = $norm($pagina);

    // PDF usa abreviação "said cli" (não "cliente") e "said fornec" (não "fornecedor")
    foreach ($linhas as $linhaTmp) {
        $lNorm = $norm($linhaTmp);
        if (
            preg_match('/g\.?\s*transp[^\n]*fornec/', $lNorm) ||
            preg_match('/guia[^\n]*transp[^\n]*fornec/', $lNorm)
        ) {
            $documento = "G. Transp Fornec";
            break;
        }
        if (
            preg_match('/g\.?\s*transp[^\n]*\bcli\b/', $lNorm) ||
            preg_match('/guia[^\n]*transp[^\n]*\bcli\b/', $lNorm)
        ) {
            $documento = "G.Transp Cliente";
            break;
        }
    }
    if ($documento === "") {
        if (preg_match('/g\.?\s*transp[^\n]*fornec/m', $paginaNorm)) {
            $documento = "G. Transp Fornec";
        } elseif (preg_match('/g\.?\s*transp[^\n]*\bcli\b/m', $paginaNorm)) {
            $documento = "G.Transp Cliente";
        }
    }

    // ── 2. Nº Documento e Data ────────────────────────────────────────────────
    // Método primário: linha ATCUD — ex: "ATCUD:J6NJKWCK-123"
    // O número do documento é sempre o último segmento após o último "-"
    $numDocumento = "";
    $dataDocumento = "";

    foreach ($linhas as $linha) {
        if (
            $numDocumento === "" &&
            preg_match("/ATCUD:[A-Z0-9]+-(\d+)/i", $linha, $m)
        ) {
            $numDocumento = $m[1];
        }
        if (
            $dataDocumento === "" &&
            preg_match("/(\d{4}-\d{2}-\d{2})/", $linha, $m)
        ) {
            $dataDocumento = $m[1];
        }
        if ($numDocumento !== "" && $dataDocumento !== "") {
            break;
        }
    }

    // ── 3. Parceiro ───────────────────────────────────────────────────────────
    // Regra 1: Guia de Cliente → parceiro é sempre "Field NewVision"
    // Regra 2: Guia de Fornecedor → correspondência automática com os parceiros
    //          registados na página Inventário ($parceirosInventario)
    if ($documento === "G.Transp Cliente") {
        $parceiro = "Field NewVision";
    } else {
        $parceiro = "";

        // 1.º — candidato pela linha a seguir a "Exmo(s) Senhor(es)" (destinatário da guia)
        $candidato = "";
        foreach ($linhas as $i => $linha) {
            if (
                stripos($linha, "Exmo") !== false &&
                stripos($linha, "Senhor") !== false
            ) {
                $candidato = trim($linhas[$i + 1] ?? "");
                break;
            }
        }

        // Normaliza ainda mais agressivamente para comparar nomes de parceiros:
        // remove espaços, vírgulas, pontos e sufixos jurídicos comuns (S.A., Lda, Unipessoal, etc.).
        // Isto evita criar parceiros "duplicados" só por diferenças de formatação
        // (ex: "MCComputadores, S.A." vs "MC Computadores").
        $normParceiro = static function (string $s) use ($norm): string {
            $s = $norm($s);
            $s = preg_replace(
                "/\b(s\.?a\.?|lda\.?|unip(essoal)?\.?|limitada)\b/u",
                "",
                $s,
            );
            $s = preg_replace("/[^a-z0-9]/u", "", $s);
            return $s;
        };

        // Tenta casar o candidato com um parceiro registado no inventário
        if ($candidato !== "") {
            $candNorm = $normParceiro($candidato);
            foreach ($parceirosInventario as $p) {
                $pNorm = $normParceiro($p);
                if (strlen($pNorm) < 4) {
                    continue;
                }
                if (
                    $candNorm === $pNorm ||
                    str_contains($candNorm, $pNorm) ||
                    str_contains($pNorm, $candNorm)
                ) {
                    $parceiro = $p;
                    break;
                }
            }
            // Sem correspondência exata: usa o nome lido tal como aparece na guia
            if ($parceiro === "") {
                $parceiro = $candidato;
            }
        }

        // 2.º — fallback: procura um parceiro do inventário em qualquer linha
        //         (apenas correspondência direta: nome do parceiro contido na linha)
        if ($parceiro === "") {
            foreach ($linhas as $linha) {
                $linhaNorm = $normParceiro($linha);
                foreach ($parceirosInventario as $p) {
                    $pNorm = $normParceiro($p);
                    if (strlen($pNorm) < 4) {
                        continue;
                    }
                    if (str_contains($linhaNorm, $pNorm)) {
                        $parceiro = $p;
                        break 2;
                    }
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
    $mapearProduto = static function (string $desPdf) use (
        $catalogoDb,
        $norm,
    ): array {
        $desNorm = $norm($desPdf);
        $melhor = ["categoria" => "", "produto" => $desPdf];
        $score = 0;

        foreach ($catalogoDb as $categoria => $produtos) {
            foreach ($produtos as $produto) {
                $pNorm = $norm($produto);
                if ($pNorm === "") {
                    continue;
                }

                if ($desNorm === $pNorm) {
                    return ["categoria" => $categoria, "produto" => $produto];
                }

                // Designação contém o nome do produto (ex: "CABECOTE PROXIMA CGD" contém "proxima cgd")
                if (str_contains($desNorm, $pNorm) && strlen($pNorm) > $score) {
                    $score = strlen($pNorm);
                    $melhor = [
                        "categoria" => $categoria,
                        "produto" => $produto,
                    ];
                }
                // Nome do produto contém a designação (ex: "Cabeçote Proxima CGD" contém "proxima")
                elseif (
                    $score === 0 &&
                    str_contains($pNorm, $desNorm) &&
                    strlen($desNorm) >= 4
                ) {
                    $score = strlen($desNorm);
                    $melhor = [
                        "categoria" => $categoria,
                        "produto" => $produto,
                    ];
                }
            }
        }

        // PRIORIDADE 3 (fallback adicional): correspondência por palavra/código em comum.
        // Cobre casos em que a designação do PDF usa um prefixo diferente do catálogo
        // (ex.: PDF "PC KP1-AB5" vs catálogo "Insys KP1-AB5" — não há contenção total,
        // mas partilham o token "kp1-ab5"). Só corre se as duas tentativas anteriores
        // não encontraram nada, para não alterar o comportamento já validado.
        if ($score === 0) {
            $desTokens = array_values(
                array_filter(
                    preg_split("/[^a-z0-9]+/u", $desNorm),
                    fn($t) => strlen($t) >= 3,
                ),
            );
            $melhorTok = 0;

            foreach ($catalogoDb as $categoria => $produtos) {
                foreach ($produtos as $produto) {
                    $pNorm = $norm($produto);
                    if ($pNorm === "") {
                        continue;
                    }
                    $pTokens = array_values(
                        array_filter(
                            preg_split("/[^a-z0-9]+/u", $pNorm),
                            fn($t) => strlen($t) >= 3,
                        ),
                    );

                    foreach ($pTokens as $pTok) {
                        if (!in_array($pTok, $desTokens, true)) {
                            continue;
                        }
                        // Prioriza o token partilhado mais longo (mais específico, ex: "kp1ab5" em vez de "pc")
                        if (strlen($pTok) > $melhorTok) {
                            $melhorTok = strlen($pTok);
                            $melhor = [
                                "categoria" => $categoria,
                                "produto" => $produto,
                            ];
                        }
                    }
                }
            }
        }

        return $melhor;
    };

    // ── 5. Linhas de artigos ──────────────────────────────────────────────────
    // As guias da empresa usam VÁRIOS formatos para a coluna Designação/Nº Série,
    // por exemplo (confirmado em PDFs reais):
    //   "BOX D039 / ISD039X23A50415              1,00"                (1 barra, SN limpo)
    //   "MONITOR SELENIKO TOUCH/C2021050830049/  1,00"                (barra a fechar, sem nada depois)
    //   "PC GIADA F108D/K1647P700638/PAT-100055/AUCHAN COIMBRA  1,00" (SN + anotações extra após mais barras)
    //   "FONTE PRATEADA/REGULADOR DE PC/PAT-100055/AUCHAN       1,00" (sem SN real, só anotações)
    //   "Cabeçote Proxima INLPXM011262 /PAT-000101728/Unilabs Aveiro 1,00" (SN colado à designação, sem barra antes)
    //   "Vídeo Extender VGA Remoto + Transformador              1,00" (sem barra nenhuma, sem SN)
    // A extração faz-se em 2 passos: (1) isola o texto antes da quantidade, sem exigir
    // barra nenhuma; (2) tenta encontrar o SN dentro desse texto através de heurísticas,
    // ignorando anotações como "PAT-XXXXX" ou nomes de loja/local.
    $linhaCategoria = [];
    $linhaProduto = [];
    $linhaQuantidade = [];
    $linhaNumSerie = [];

    // Restringe a pesquisa à zona da tabela de artigos (entre o cabeçalho
    // "Artigo / Designação ... Nº Série" e o rodapé "Software PHC"), para não
    // arriscar apanhar números soltos noutras partes da guia (totais, datas, etc.)
    // agora que a regex de quantidade deixou de exigir uma barra "/".
    $inicioTabela = null;
    $fimTabela = null;
    foreach ($linhas as $i => $l) {
        if (
            $inicioTabela === null &&
            stripos($l, "Artigo") !== false &&
            stripos($l, "esigna") !== false
        ) {
            $inicioTabela = $i;
            continue;
        }
        if ($inicioTabela !== null && stripos($l, "Software") !== false) {
            $fimTabela = $i;
            break;
        }
    }
    $linhasItens =
        $inicioTabela !== null
            ? array_slice(
                $linhas,
                $inicioTabela + 1,
                $fimTabela !== null ? $fimTabela - $inicioTabela - 1 : null,
            )
            : $linhas; // fallback: se não encontrar o cabeçalho, mantém o comportamento antigo (varre tudo)

    // Heurística: um token "parece" um número de série se for só letras/dígitos
    // (sem espaços nem hífenes) e tiver pelo menos um dígito — isto distingue
    // SNs reais (ex: "K1647P700638") de palavras comuns ou nomes de loja em
    // maiúsculas (ex: "AUCHAN", "COIMBRA", "PRATEADA"), que não têm dígitos.
    $pareceSn = static function (string $tok): bool {
        return (bool) preg_match('/^[A-Z0-9]{4,}$/i', $tok) &&
            (bool) preg_match("/\d/", $tok);
    };

    foreach ($linhasItens as $linha) {
        // Passo 1: isola o texto do artigo e a quantidade no fim da linha.
        // Já não exige barra "/" — só que a linha termine em número (inteiro ou decimal).
        // Exige um espaçamento mínimo (3+ espaços) antes da quantidade, porque o
        // "pdftotext -layout" preserva as colunas: a Quantidade real está sempre bem
        // afastada à direita. Isto evita apanhar números "colados" ao texto em linhas
        // de anotação que não são artigos (ex: "Vodafone Madeira - PAT 99980", onde
        // "99980" ficaria a só 1 espaço do texto, em vez dos 3+ típicos da coluna Qtd.).
        if (
            !preg_match(
                '/^(?:ASSISTENCIA\s+)?(.+?)\s{3,}([\d]+(?:[,.][\d]+)?)\s*$/i',
                $linha,
                $m,
            )
        ) {
            continue;
        }

        $corpo = trim($m[1]);
        $qtd = (float) str_replace(",", ".", $m[2]);

        // Passo 2: dentro do corpo, separa por "/" e tenta identificar o SN.
        $partes = array_values(
            array_filter(
                array_map("trim", explode("/", $corpo)),
                static fn($p) => $p !== "",
            ),
        );
        $designacao = $partes[0] ?? $corpo;
        $sn = "";

        if (isset($partes[1]) && $pareceSn($partes[1])) {
            // Caso "designação / SN" — o 2º segmento é o próprio SN.
            $sn = strtoupper($partes[1]);
        } elseif (
            preg_match('/^(.*\S)\s+([A-Z0-9]{8,})$/u', $designacao, $mEmb) &&
            $pareceSn($mEmb[2])
        ) {
            // Caso "designação SN" sem barra antes do SN (ex: "Cabeçote Proxima INLPXM011262")
            // — o SN está colado ao fim da designação, separado só por espaço.
            $sn = strtoupper($mEmb[2]);
            $designacao = trim($mEmb[1]);
        } elseif (isset($partes[1])) {
            // 2º segmento existe mas não parece um SN (ex: "REGULADOR DE PC") —
            // é descrição adicional, não um código de série; mantém-se na designação.
            $designacao = trim($designacao . " " . $partes[1]);
        }
        // Quaisquer segmentos a partir do 3º ("PAT-XXXXX", nome de loja/local) são
        // sempre ignorados — são anotações internas da guia, não dados da peça.

        // Limpeza: remove um marcador "SN:" / "Nº Série:" que tenha ficado colado
        // ao fim da designação depois de extrair o SN embutido (ex: "Impressora Prima 12 SN:").
        $designacao = preg_replace(
            '/\b(SN|N[ºo]\.?\s*S[ée]rie)\s*:?\s*$/iu',
            "",
            $designacao,
        );
        $designacao = trim($designacao, " :\t-");

        // PRIORIDADE 1: procurar o SN na tabela pecas para obter categoria e produto exatos
        $categoria = "";
        $produto = "";
        if ($sn !== "") {
            $stmtSn = $pdo->prepare(
                "SELECT categoria, produto FROM pecas WHERE UPPER(TRIM(sn)) = ? LIMIT 1",
            );
            $stmtSn->execute([$sn]);
            $pecaRow = $stmtSn->fetch();
            if ($pecaRow && trim((string) $pecaRow["categoria"]) !== "") {
                $categoria = $pecaRow["categoria"];
                $produto = $pecaRow["produto"];
            }
        }

        // PRIORIDADE 2: fallback por designação (categoria em falta OU produto em falta)
        if ($categoria === "" || $produto === "") {
            $mapeamento = $mapearProduto($designacao);
            if ($categoria === "") {
                $categoria = $mapeamento["categoria"];
            }
            if ($produto === "") {
                $produto = $mapeamento["produto"];
            }
        }

        $linhaCategoria[] = $categoria;
        $linhaProduto[] = $produto;
        $linhaQuantidade[] = $qtd;
        $linhaNumSerie[] = $sn;
    }

    return [
        "documento" => $documento,
        "num_documento" => $numDocumento,
        "data_documento" => $dataDocumento,
        "parceiro" => $parceiro,
        "linha_categoria" => $linhaCategoria,
        "linha_produto" => $linhaProduto,
        "linha_quantidade" => $linhaQuantidade,
        "linha_num_serie" => $linhaNumSerie,
    ];
}

// ============================================================
// HANDLER: Guardar rascunho do envio (novo ou atualizar existente)
// ============================================================
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "guardar_rascunho_envio"
) {
    $envioId = (int) ($_POST["envio_id"] ?? 0);
    $documento = trim($_POST["documento"] ?? "");
    $num_documento = trim($_POST["num_documento"] ?? "");
    $data_documento = trim($_POST["data_documento"] ?? "");
    $parceiro = trim($_POST["parceiro"] ?? "");

    $categoriasEnvio = $_POST["linha_categoria"] ?? [];
    $produtosEnvio = $_POST["linha_produto"] ?? [];
    $quantidades = $_POST["linha_quantidade"] ?? [];
    $numSeries = $_POST["linha_num_serie"] ?? [];

    $redirBase =
        "app.php?page=envios" . ($envioId > 0 ? "&ver=" . $envioId : "");

    if (
        $documento === "" ||
        $num_documento === "" ||
        $data_documento === "" ||
        $parceiro === ""
    ) {
        $_SESSION["mensagem_erro"] =
            "Preencher Documento, Nº Documento, Data e Parceiro.";
        header("Location: " . $redirBase);
        exit();
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_documento)) {
        $_SESSION["mensagem_erro"] =
            "A data tem de estar no formato AAAA-MM-DD.";
        header("Location: " . $redirBase);
        exit();
    }

    $linhasValidas = [];
    foreach ($produtosEnvio as $i => $produtoEnvio) {
        $cat = trim($categoriasEnvio[$i] ?? "");
        $prod = trim($produtoEnvio ?? "");
        $qtd = trim($quantidades[$i] ?? "");
        $numSerie = trim($numSeries[$i] ?? "");

        if ($cat === "" && $prod === "" && $qtd === "" && $numSerie === "") {
            continue; // linha vazia — ignorar
        }
        if ($cat === "" || $prod === "" || $qtd === "") {
            $_SESSION["mensagem_erro"] =
                "Cada linha tem de ter Tipo, Nome da Peça e Quantidade.";
            header("Location: " . $redirBase);
            exit();
        }

        $linhasValidas[] = [
            "categoria" => $cat,
            "produto" => $prod,
            "quantidade" => (float) $qtd,
            "num_serie" => $numSerie,
        ];
    }

    if (count($linhasValidas) === 0) {
        $_SESSION["mensagem_erro"] =
            "O rascunho tem de conter pelo menos uma linha válida.";
        header("Location: " . $redirBase);
        exit();
    }

    $pdo->beginTransaction();
    try {
        if ($envioId > 0) {
            // Atualizar rascunho existente — verificar que ainda está em Rascunho
            $stmtCheck = $pdo->prepare(
                "SELECT id, estado FROM envios WHERE id = ?",
            );
            $stmtCheck->execute([$envioId]);
            $envioExistente = $stmtCheck->fetch();

            if (!$envioExistente || $envioExistente["estado"] !== "Rascunho") {
                $pdo->rollBack();
                $_SESSION["mensagem_erro"] =
                    "Rascunho não encontrado ou já confirmado.";
                header("Location: app.php?page=envios");
                exit();
            }

            $pdo->prepare(
                "
                UPDATE envios
                SET documento = ?, num_documento = ?, data_documento = ?, parceiro = ?
                WHERE id = ?
            ",
            )->execute([
                $documento,
                $num_documento,
                $data_documento,
                $parceiro,
                $envioId,
            ]);

            // Apagar linhas antigas e reinserir as novas
            $pdo->prepare(
                "DELETE FROM envios_linhas WHERE envio_id = ?",
            )->execute([$envioId]);
        } else {
            // Criar novo rascunho
            $pdo->prepare(
                "
                INSERT INTO envios (documento, num_documento, data_documento, parceiro, criado_por, created_at, estado)
                VALUES (?, ?, ?, ?, ?, NOW(), 'Rascunho')
            ",
            )->execute([
                $documento,
                $num_documento,
                $data_documento,
                $parceiro,
                $_SESSION["user_nome"] ?? "Sistema",
            ]);
            $envioId = (int) $pdo->lastInsertId();
        }

        $stmtLinha = $pdo->prepare("
            INSERT INTO envios_linhas (envio_id, artigo, designacao, quantidade, num_serie)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($linhasValidas as $linha) {
            $stmtLinha->execute([
                $envioId,
                $linha["categoria"],
                $linha["produto"],
                $linha["quantidade"],
                $linha["num_serie"],
            ]);
        }

        $pdo->commit();
        $_SESSION["mensagem_sucesso"] = "Rascunho guardado com sucesso.";
        header("Location: app.php?page=envios&ver=" . $envioId);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION["mensagem_erro"] =
            "Erro ao guardar o rascunho: " . $e->getMessage();
        header("Location: " . $redirBase);
        exit();
    }
}

// ============================================================
// HANDLER: Confirmar envio final — atualiza inventário
// ============================================================
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "confirmar_envio_final"
) {
    $envioId = (int) ($_POST["envio_id"] ?? 0);

    if ($envioId <= 0) {
        $_SESSION["mensagem_erro"] = "ID do envio inválido.";
        header("Location: app.php?page=envios");
        exit();
    }

    $stmtEnvio = $pdo->prepare("SELECT * FROM envios WHERE id = ?");
    $stmtEnvio->execute([$envioId]);
    $envio = $stmtEnvio->fetch();

    if (!$envio) {
        $_SESSION["mensagem_erro"] = "Envio não encontrado.";
        header("Location: app.php?page=envios");
        exit();
    }

    if ($envio["estado"] !== "Rascunho") {
        $_SESSION["mensagem_erro"] =
            "Este envio já foi confirmado ou cancelado.";
        header("Location: app.php?page=envios&ver=" . $envioId);
        exit();
    }

    $stmtLinhas = $pdo->prepare(
        "SELECT * FROM envios_linhas WHERE envio_id = ? ORDER BY id ASC",
    );
    $stmtLinhas->execute([$envioId]);
    $linhas = $stmtLinhas->fetchAll();

    // Guia Cliente → peça fica com estado 'Cliente'; Guia Fornecedor → 'Parceiro'
    $novoEstadoPeca =
        $envio["documento"] === "G.Transp Cliente" ? "Cliente" : "Parceiro";
    $novoParceiroPeca = $envio["parceiro"];
    $utilizador = $_SESSION["user_nome"] ?? "Sistema";

    $pdo->beginTransaction();
    try {
        $avisos = [];

        foreach ($linhas as $linha) {
            $sn = trim($linha["num_serie"] ?? "");

            if ($sn === "") {
                // Linha sem SN — não é possível identificar a peça no inventário
                $avisos[] =
                    'Linha "' .
                    htmlspecialchars($linha["designacao"] ?? "?") .
                    '" sem Nº Série — não atualizada no inventário.';
                continue;
            }

            $stmtPeca = $pdo->prepare(
                "SELECT id, estado, parceiro FROM pecas WHERE sn = ? LIMIT 1",
            );
            $stmtPeca->execute([$sn]);
            $peca = $stmtPeca->fetch();

            if (!$peca) {
                $avisos[] =
                    'SN "' .
                    htmlspecialchars($sn) .
                    '" não encontrado no inventário — linha ignorada.';
                continue;
            }

            $estadoAntigo = $peca["estado"];
            $parceiroAntigo = $peca["parceiro"];

            $pdo->prepare(
                "UPDATE pecas SET estado = ?, parceiro = ?, estado_desde = NOW() WHERE id = ?",
            )->execute([$novoEstadoPeca, $novoParceiroPeca, $peca["id"]]);

            $stmtHist = $pdo->prepare("
                INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            if ($estadoAntigo !== $novoEstadoPeca) {
                $stmtHist->execute([
                    $peca["id"],
                    "estado",
                    $estadoAntigo,
                    $novoEstadoPeca,
                    $utilizador,
                ]);
            }
            if ($parceiroAntigo !== $novoParceiroPeca) {
                $stmtHist->execute([
                    $peca["id"],
                    "parceiro",
                    $parceiroAntigo,
                    $novoParceiroPeca,
                    $utilizador,
                ]);
            }
            // Registo de rastreabilidade — liga o histórico da peça ao envio
            $stmtHist->execute([
                $peca["id"],
                "envio",
                "",
                "Envio #" . $envioId . " confirmado",
                $utilizador,
            ]);
        }

        // Marcar o envio como Ativa (confirmado)
        $pdo->prepare(
            "UPDATE envios SET estado = 'Ativa' WHERE id = ?",
        )->execute([$envioId]);

        $pdo->commit();

        if (!empty($avisos)) {
            $_SESSION["mensagem_erro"] =
                "Envio confirmado com avisos: " . implode(" | ", $avisos);
        } else {
            $_SESSION["mensagem_sucesso"] =
                "Envio confirmado. Inventário atualizado com sucesso.";
        }

        header("Location: app.php?page=envios");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION["mensagem_erro"] =
            "Erro ao confirmar o envio: " . $e->getMessage();
        header("Location: app.php?page=envios&ver=" . $envioId);
        exit();
    }
}

// ============================================================
// HANDLER: Apagar rascunho de envio
// ============================================================
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "apagar_envio"
) {
    $envioId = (int) ($_POST["envio_id"] ?? 0);

    if ($envioId <= 0) {
        $_SESSION["mensagem_erro"] = "ID do envio inválido.";
        header("Location: app.php?page=envios");
        exit();
    }

    $stmtCheck = $pdo->prepare("SELECT id, estado FROM envios WHERE id = ?");
    $stmtCheck->execute([$envioId]);
    $envio = $stmtCheck->fetch();

    if (!$envio || $envio["estado"] !== "Rascunho") {
        $_SESSION["mensagem_erro"] =
            "Só é possível apagar guias em estado Rascunho.";
        header("Location: app.php?page=envios");
        exit();
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM envios_linhas WHERE envio_id = ?")->execute([
            $envioId,
        ]);
        $pdo->prepare("DELETE FROM envios WHERE id = ?")->execute([$envioId]);
        $pdo->commit();

        $_SESSION["mensagem_sucesso"] = "Guia apagada com sucesso.";
        header("Location: app.php?page=envios");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION["mensagem_erro"] =
            "Erro ao apagar a guia: " . $e->getMessage();
        header("Location: app.php?page=envios&ver=" . $envioId);
        exit();
    }
}

// ══════════════════════════════════════════════

$envios = [];
$envioLinhas = [];
$envioVerId = isset($_GET["ver"])
    ? (int) $_GET["ver"]
    : (isset($_GET["draft"])
        ? (int) $_GET["draft"]
        : 0);
$envioAtual = null;
$parceirosInventario = [];
$categoriasInventarioReal = [];
$catalogoInventarioReal = [];

if ($page === "envios") {
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

    $stmtParceiros = $pdo->query(
        "SELECT DISTINCT parceiro FROM pecas WHERE parceiro IS NOT NULL AND parceiro <> '' ORDER BY parceiro ASC",
    );
    $parceirosInventario = $stmtParceiros->fetchAll(PDO::FETCH_COLUMN);

    $stmtCategorias = $pdo->query(
        "SELECT DISTINCT categoria FROM pecas WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria ASC",
    );
    $categoriasInventarioReal = $stmtCategorias->fetchAll(PDO::FETCH_COLUMN);

    $stmtCatalogo = $pdo->query(
        "SELECT categoria, produto FROM pecas WHERE categoria IS NOT NULL AND categoria <> '' AND produto IS NOT NULL AND produto <> '' ORDER BY categoria ASC, produto ASC",
    );
    $catalogoRows = $stmtCatalogo->fetchAll();
    foreach ($catalogoRows as $row) {
        if (!isset($catalogoInventarioReal[$row["categoria"]])) {
            $catalogoInventarioReal[$row["categoria"]] = [];
        }
        if (
            !in_array(
                $row["produto"],
                $catalogoInventarioReal[$row["categoria"]],
            )
        ) {
            $catalogoInventarioReal[$row["categoria"]][] = $row["produto"];
        }
    }
}
?>

<?php
// Ex3 — layout sem tabs: stats no topo, Importar + Estado lado a lado,
// estatísticas e (sempre visíveis) os 2 envios mais recentes.
$envStats = [
    "total" => count($envios),
    "Rascunho" => 0,
    "Ativa" => 0,
    "Concluida" => 0,
    "Outros" => 0,
];
foreach ($envios as $eStat) {
    $st = $eStat["estado"] ?? "";
    if (isset($envStats[$st])) {
        $envStats[$st]++;
    } else {
        $envStats["Outros"]++;
    }
}
// Os 2 envios mais recentes (a lista $envios já vem ORDER BY created_at DESC).
// Ex: botão "Ver todos" alterna para mostrar a lista completa em vez de só os 2 recentes.
$verTodosEnvios = isset($_GET["lista_envios"]) && $_GET["lista_envios"] === "1";
$enviosRecentes = $verTodosEnvios ? $envios : array_slice($envios, 0, 2);
?>
<style>
.env-estado{ display:inline-flex; align-items:center; gap:7px; padding:3px 11px; border-radius:999px; font-size:11.5px; font-weight:600; background:var(--bg); color:var(--c); }
.env-estado .dot{ width:7px; height:7px; border-radius:50%; background:currentColor; }
.env-stats-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; }
.env-stat{ background:#f8fafc; border:1px solid #e5e9ef; border-radius:12px; padding:16px 18px; }
.env-stat .n{ font-size:30px; font-weight:700; line-height:1; }
.env-stat .l{ font-size:13px; color:#6b7280; margin-top:6px; }
/* Ex3: faixa de stats compacta no topo */
.env-faixa{ display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:20px; }
.env-faixa .env-faixa-card{ background:#fff; border:1px solid #e5e9ef; border-radius:12px; padding:14px 16px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.env-faixa .efn{ font-size:26px; font-weight:700; line-height:1; }
.env-faixa .efl{ font-size:12.5px; color:#6b7280; margin-top:6px; }
body.dark-mode .env-faixa-card{ background:#1e2533; border-color:#374151; }
body.dark-mode .env-stat{ background:#161c27; border-color:#374151; }
@media (max-width:768px){ .env-faixa{ grid-template-columns:repeat(2,1fr); } }
</style>

<!-- ===== FAIXA DE STATS (Ex3) ===== -->
<div class="env-faixa">
  <div class="env-faixa-card"><div class="efn"><?= $envStats["total"] ?></div><div class="efl">Total</div></div>
  <div class="env-faixa-card"><div class="efn" style="color:#b45309;"><?= $envStats["Rascunho"] ?></div><div class="efl">Rascunhos</div></div>
  <div class="env-faixa-card"><div class="efn" style="color:#15803d;"><?= $envStats["Ativa"] ?></div><div class="efl">Ativas</div></div>
  <div class="env-faixa-card"><div class="efn" style="color:#1d4ed8;"><?= $envStats["Concluida"] ?></div><div class="efl">Concluídas</div></div>
</div>

<!-- ===== IMPORTAR + ESTADO (lado a lado) ===== -->
<div class="env-pane-importar">
<?php if ($envioAtual): ?>
<div style="margin-bottom:14px;">
    <a class="btn btn-grey" href="app.php?page=envios" onclick="nvVoltar(event)"><i class="bi bi-arrow-left"></i> Voltar à lista</a>
</div>
<?php endif; ?>
<!-- == Linha Superior: Leitura Guia (Esquerda) + Formulario (Direita) == -->
<!-- Estilos .upload-pdf-* e .btn-ler-guia centralizados em app.php (reutilizados também em Contas/Relatórios) -->
<div class="envios-import-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-bottom:20px;">
   <div class="panel" style="height:100%;">
      <h4 style="margin-bottom:16px;"><i class="bi bi-file-earmark-pdf" style="margin-right:6px; color:#c9a14a;"></i><?= $envioAtual
          ? "Documento Original"
          : "Leitura de Guia de Transporte" ?></h4>
      <?php
      $envDocWeb = "";
      $envDocFs = "";
      if ($envioAtual) {
          $envDocWeb = $envioAtual["ficheiro_path"] ?? "";
          $envDocFs = $envDocWeb
              ? dirname(__DIR__, 2) . "/" . ltrim($envDocWeb, "/")
              : "";
          if ($envDocWeb && !is_file($envDocFs)) {
              $altEnvFs = __DIR__ . "/" . ltrim($envDocWeb, "/");
              if (is_file($altEnvFs)) {
                  $envDocFs = $altEnvFs;
              }
          }
      }
      ?>
      <?php if ($envioAtual && $envDocWeb && is_file($envDocFs)): ?>
          <iframe src="<?= htmlspecialchars(
              $envDocWeb,
          ) ?>#toolbar=0&navpanes=0&scrollbar=0" title="Guia original" style="width:100%; height:620px; border:1px solid #e5e9ef; border-radius:10px; background:#f8fafc;"></iframe>
          <div style="margin-top:10px;">
              <a class="btn btn-grey" href="<?= htmlspecialchars(
                  $envDocWeb,
              ) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir em nova janela</a>
          </div>
      <?php else: ?>
      <form method="post" enctype="multipart/form-data" autocomplete="off" action="app.php?page=envios">
          <input type="hidden" name="form_type" value="importar_guia_envio">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(
              $csrfToken,
          ) ?>">
          <div style="margin-bottom:0;">
              <label for="guia_pdf_input" class="upload-pdf-box" id="uploadPdfBox">
                  <span class="upload-pdf-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span>
                  <span class="upload-pdf-text" id="uploadPdfText">
                      <strong>Clica para escolher um ficheiro</strong><br>ou arrasta o PDF para aqui
                  </span>
                  <span class="upload-pdf-filename" id="uploadPdfFilename" style="display:none;"></span>
              </label>
              <input type="file" id="guia_pdf_input" name="guia_pdf" accept=".pdf,application/pdf" required class="upload-pdf-input">
          </div>
          <button type="submit" class="btn-ler-guia"><i class="bi bi-search"></i> Ler Guia</button>
      </form>
      <?php if ($envioAtual && !$envDocWeb): ?>
          <p class="small-note" style="margin-top:12px;">Documento original não guardado para este envio (importação antiga).</p>
      <?php endif; ?>
      <?php endif; ?>
</div>

<script>
(function () {
    const input = document.getElementById('guia_pdf_input');
    const box = document.getElementById('uploadPdfBox');
    const texto = document.getElementById('uploadPdfText');
    const nomeFicheiro = document.getElementById('uploadPdfFilename');
    if (!input || !box || !texto || !nomeFicheiro) return;

    function mostrarFicheiro(file) {
        if (!file) {
            texto.style.display = '';
            nomeFicheiro.style.display = 'none';
            nomeFicheiro.innerHTML = '';
            return;
        }
        texto.style.display = 'none';
        nomeFicheiro.style.display = 'flex';
        nomeFicheiro.innerHTML = '<i class="bi bi-file-earmark-pdf-fill"></i><span></span><i class="bi bi-x-circle upload-pdf-clear" title="Remover ficheiro"></i>';
        nomeFicheiro.querySelector('span').textContent = file.name;
    }

    input.addEventListener('change', function () {
        mostrarFicheiro(input.files && input.files[0] ? input.files[0] : null);
    });

    nomeFicheiro.addEventListener('click', function (e) {
        if (e.target.classList.contains('upload-pdf-clear')) {
            e.preventDefault();
            e.stopPropagation();
            input.value = '';
            mostrarFicheiro(null);
        }
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
        box.addEventListener(evt, function (e) {
            e.preventDefault();
            box.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        box.addEventListener(evt, function (e) {
            e.preventDefault();
            box.classList.remove('is-dragover');
        });
    });
    box.addEventListener('drop', function (e) {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            input.files = e.dataTransfer.files;
            mostrarFicheiro(e.dataTransfer.files[0]);
        }
    });
})();
</script>

    <!-- PAINEL DIREITO: Formulário / Rascunho -->
    <div class="panel" style="height:100%;">
        <h4 style="margin-bottom:16px;">
            <i class="bi bi-send" style="margin-right:6px; color:#c9a14a;"></i>
            <?= $envioAtual ? "Rascunho / Validação do Envio" : "Novo Envio" ?>
            <?php if ($envioAtual): ?>
                <span style="font-size:12px; font-weight:500; color:#6b7280; margin-left:10px;">
                Estado: <strong style="color:<?= $envioAtual["estado"] ===
                "Rascunho"
                    ? "#b45309"
                    : "#16a34a" ?>">
                    <?= htmlspecialchars($envioAtual["estado"]) ?>
                </strong>
            </span>
            <?php endif; ?>
        </h4>

        <?php if ($envioAtual): ?>
            <?php
            $docAtual = strtolower(trim($envioAtual["documento"] ?? ""));
            $isCliente =
                $docAtual === "g.transp cliente" ||
                $docAtual === "g. transp cliente";
            $isFornecedor = $docAtual === "g. transp fornec";
            $envioSoLeitura = ($envioAtual["estado"] ?? "") !== "Rascunho";
            $ro = $envioSoLeitura ? "readonly" : "";
            $dis = $envioSoLeitura ? "disabled" : "";
            ?>

            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:18px; font-size:13px; color:#15803d;">
                <?= $envioSoLeitura
                    ? "Detalhe do envio registado."
                    : "Guia lida com sucesso. Necessário rever os dados!" ?>
            </div>

            <form method="post" autocomplete="off">
                <input type="hidden" name="form_type" value="guardar_rascunho_envio">
                <input type="hidden" name="envio_id" value="<?= (int) $envioAtual[
                    "id"
                ] ?>">

                <div class="form-grid">
                    <div>
                        <label>Documento</label>
                        <select name="documento" id="documento_envio" required <?= $dis ?>>
                            <option value="">-- Selecione --</option>
                            <option value="G. Transp Fornec" <?= $isFornecedor
                                ? "selected"
                                : "" ?>>G. Transp Fornec</option>
                            <option value="G.Transp Cliente" <?= $isCliente
                                ? "selected"
                                : "" ?>>G. Transp Cliente</option>
                        </select>
                    </div>
                    <div>
                        <label>Nº Documento</label>
                        <input type="text" name="num_documento" value="<?= htmlspecialchars(
                            $envioAtual["num_documento"] ?? "",
                        ) ?>" required <?= $ro ?>>
                    </div>
                    <div>
                        <label>Data</label>
                        <input type="date" name="data_documento" value="<?= htmlspecialchars(
                            $envioAtual["data_documento"] ?? "",
                        ) ?>" required <?= $ro ?>>
                    </div>
                    <div>
                        <label>Parceiro</label>
                        <?php if ($isCliente): ?>
                            <select name="parceiro" required <?= $dis ?>>
                                <option value="Field NewVision" selected>Field NewVision</option>
                            </select>
                            <span class="small-note">Guia Cliente -> SEMPRE Field NewVision!</span>
                        <?php else: ?>
                            <?php
                            $parceiroAtual = $envioAtual["parceiro"] ?? "";
                            $parceiroNaLista = in_array(
                                $parceiroAtual,
                                $parceirosInventario,
                                true,
                            );
                            ?>
                            <select name="parceiro" id="parceiro_envio" required <?= $dis ?>>
                                <option value="">-- Selecione --</option>
                                <?php if (
                                    $parceiroAtual !== "" &&
                                    !$parceiroNaLista
                                ): ?>
                                    <option value="<?= htmlspecialchars(
                                        $parceiroAtual,
                                    ) ?>" selected>
                                        <?= htmlspecialchars($parceiroAtual) ?>
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($parceirosInventario as $p): ?>
                                    <option value="<?= htmlspecialchars(
                                        $p,
                                    ) ?>" <?= $parceiroAtual === $p
    ? "selected"
    : "" ?>>
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
                            <label><select name="linha_categoria[]" class="linha-categoria" required <?= $dis ?>>
                                    <option value="">-- Tipo --</option>
                                    <?php foreach (
                                        $categoriasInventarioReal
                                        as $cat
                                    ): ?>
                                        <option value="<?= htmlspecialchars(
                                            $cat,
                                        ) ?>"><?= htmlspecialchars(
    $cat,
) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                                <label><select name="linha_produto[]" class="linha-produto" data-selected="" required <?= $dis ?>>
                                    <option value="">-- Nome da Peça --</option>
                                </select></label>
                                <label><input type="number" step="1" min="1" name="linha_quantidade[]" value="1" required <?= $ro ?>></label>
                                <label><input type="text" name="linha_num_serie[]" class="linha-num-serie" placeholder="Nº Série" <?= $ro ?>></label>
                            <div class="sn-avisos"></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($envioLinhas as $i => $linha): ?>
                            <div class="linha-envio-grid" data-linha-index="<?= (int) $i ?>">
                                <label><select name="linha_categoria[]" class="linha-categoria" required <?= $dis ?>>
                                        <option value="">-- Tipo --</option>
                                        <?php foreach (
                                            $categoriasInventarioReal
                                            as $cat
                                        ): ?>
                                            <option value="<?= htmlspecialchars(
                                                $cat,
                                            ) ?>" <?= ($linha["artigo"] ??
    "") ===
$cat
    ? "selected"
    : "" ?>>
                                                <?= htmlspecialchars($cat) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php
                                        $catLinhaAtual = $linha["artigo"] ?? "";
                                        if (
                                            $catLinhaAtual !== "" &&
                                            !in_array(
                                                $catLinhaAtual,
                                                $categoriasInventarioReal,
                                                true,
                                            )
                                        ): ?>
                                            <option value="<?= htmlspecialchars(
                                                $catLinhaAtual,
                                            ) ?>" selected>
                                                <?= htmlspecialchars(
                                                    $catLinhaAtual,
                                                ) ?> (não reconhecido — confirmar)
                                            </option>
                                        <?php endif; ?>
                                    </select></label>
                                <label><select name="linha_produto[]" class="linha-produto" data-selected="<?= htmlspecialchars(
                                    $linha["designacao"] ?? "",
                                ) ?>" required <?= $dis ?>>
                                        <option value="">-- Nome da Peça --</option>
                                    </select></label>
                                <label><input type="number" step="1" min="1" name="linha_quantidade[]" value="<?= htmlspecialchars(
                                    $linha["quantidade"] ?? 1,
                                ) ?>" required <?= $ro ?>></label>
                                <label><input type="text" name="linha_num_serie[]" class="linha-num-serie" value="<?= htmlspecialchars(
                                    $linha["num_serie"] ?? "",
                                ) ?>" placeholder="Nº Série" <?= $ro ?>></label>
                                <div class="sn-avisos">
                                    <?php if (!empty($linha["observacoes"])): ?>
                                        <div class="small-note" style="color:#b26a00;"><?= htmlspecialchars(
                                            $linha["observacoes"],
                                        ) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <?php if (!$envioSoLeitura): ?>
                    <button type="button" class="btn btn-grey" id="adicionarLinhaEnvio">+ Linha</button>
                    <button type="submit" class="btn btn-blue">Guardar Rascunho</button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!$envioSoLeitura): ?>
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid #e5e7eb; display:flex; gap:10px; flex-wrap:wrap;">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="form_type" value="confirmar_envio_final">
                    <input type="hidden" name="envio_id" value="<?= (int) $envioAtual[
                        "id"
                    ] ?>">
                    <button type="submit" class="btn btn-green">✓ Confirmar e Guardar Envio</button>
                </form>
                <?php if (($envioAtual["estado"] ?? "") === "Rascunho"): ?>
                    <form method="post" style="margin:0;" onsubmit="return nvConfirmar(this, 'Apagar esta Guia? Esta ação é irreversível.');">
                        <input type="hidden" name="form_type" value="apagar_envio">
                        <input type="hidden" name="envio_id" value="<?= (int) $envioAtual[
                            "id"
                        ] ?>">
                        <button type="submit" class="btn btn-red">Apagar Guia</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div style="text-align:center; padding:34px 20px; color:#6b7280;">
                <div style="width:56px; height:56px; margin:0 auto 14px; border-radius:50%; background:#fbf1da; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-send" style="font-size:24px; color:#c9a14a;"></i>
                </div>
                <p style="font-size:15px; font-weight:600; color:#374151; margin-bottom:6px;">Nenhum rascunho aberto</p>
                <p style="font-size:13px; margin:0;">Faz a leitura de uma Guia para começar.</p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- ===== fim IMPORTAR + ESTADO ===== -->

<!-- ===== LISTA DE ENVIOS — 2 mais recentes / todos (Ex3) ===== -->
<div class="panel">
    <div class="panel-header-row" style="margin-bottom:18px;">
        <div class="panel-header-left">
            <h4 style="margin:0;"><i class="bi bi-list-ul" style="margin-right:6px; color:#c9a14a;"></i><?= $verTodosEnvios
                ? "Todos os envios"
                : "Envios recentes" ?></h4>
            <span class="panel-count-badge"><?= count($enviosRecentes) ?></span>
        </div>
        <div class="panel-header-actions">
            <?php if ($verTodosEnvios): ?>
                <a class="btn btn-grey" href="app.php?page=envios"><i class="bi bi-arrow-left"></i> Ver recentes</a>
            <?php else: ?>
                <a class="btn btn-teal" href="app.php?page=envios&lista_envios=1"><i class="bi bi-list-ul"></i> Ver todos</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="mv-table-wrap" style="overflow-x:auto;">
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
                <th class="actions">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($enviosRecentes)): ?>
                <tr><td colspan="8" class="envios-vazio">Nenhum envio registado.</td></tr>
            <?php else: ?>
                <?php foreach ($enviosRecentes as $e): ?>
                    <tr>
                        <td><?= (int) $e["id"] ?></td>
                        <td><?= htmlspecialchars($e["documento"]) ?></td>
                        <td><?= htmlspecialchars($e["num_documento"]) ?></td>
                        <td><?= htmlspecialchars(
                            $e["data_documento"]
                                ? date("d/m/Y", strtotime($e["data_documento"]))
                                : "—",
                        ) ?></td>
                        <td><?= htmlspecialchars($e["parceiro"]) ?></td>
                        <td>
                            <?php
                            $eMap = [
                                "Rascunho" => ["#92400e", "#fef3c7", "Rascunho"],
                                "Ativa" => ["#15803d", "#dcfce7", "Ativa"],
                                "Concluida" => ["#1d4ed8", "#dbeafe", "Concluída"],
                            ];
                            $eb = $eMap[$e["estado"]] ?? ["#374151", "#f3f4f6", $e["estado"]];
                            ?>
                            <span class="env-estado" style="--c:<?= $eb[0] ?>;--bg:<?= $eb[1] ?>;"><span class="dot"></span><?= htmlspecialchars($eb[2]) ?></span>
                        </td>
                        <td><?= htmlspecialchars($e["criado_por"]) ?></td>
                        <td class="actions">
                            <?php if (($e["estado"] ?? "") === "Rascunho"): ?>
                                <a class="btn btn-yellow" href="app.php?page=envios&draft=<?= (int) $e["id"] ?>">Abrir Rascunho</a>
                            <?php else: ?>
                                <a class="btn btn-grey" href="app.php?page=envios&ver=<?= (int) $e["id"] ?>">Ver</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            </table>
        </div><!-- /.mv-table-wrap -->

<!-- ── Envios · Cards mobile (≤640px) ── -->
<div class="mv-cards">
<?php if (empty($enviosRecentes)): ?>
    <div class="mv-cards-empty"><i class="bi bi-inbox"></i>Nenhum envio registado.</div>
<?php else: ?>
    <?php foreach ($enviosRecentes as $e):
        $eMap = [
            "Rascunho" => ["#92400e", "#fef3c7", "Rascunho"],
            "Ativa" => ["#15803d", "#dcfce7", "Ativa"],
            "Concluida" => ["#1d4ed8", "#dbeafe", "Concluída"],
        ];
        $eb = $eMap[$e["estado"]] ?? ["#374151", "#f3f4f6", $e["estado"]];
        ?>
    <div class="mv-card">
        <div class="mv-card-header">
            <div>
                <div class="mv-card-title"><?= htmlspecialchars($e["num_documento"] ?: "—") ?></div>
                <div class="mv-card-sub mv-card-sub-text"><?= htmlspecialchars($e["parceiro"]) ?></div>
            </div>
            <span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;flex-shrink:0;background:<?= $eb[1] ?>;color:<?= $eb[0] ?>;"><?= htmlspecialchars($eb[2]) ?></span>
        </div>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Documento</span>
            <span class="mv-card-row-val"><?= htmlspecialchars($e["documento"]) ?></span>
        </div>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Data</span>
            <span class="mv-card-row-val"><?= htmlspecialchars($e["data_documento"] ? date("d/m/Y", strtotime($e["data_documento"])) : "—") ?></span>
        </div>
        <div class="mv-card-footer">
            <?php if (($e["estado"] ?? "") === "Rascunho"): ?>
                <a class="btn btn-yellow" href="app.php?page=envios&draft=<?= (int) $e["id"] ?>"><i class="bi bi-pencil"></i> Abrir</a>
            <?php else: ?>
                <a class="btn btn-grey" href="app.php?page=envios&ver=<?= (int) $e["id"] ?>"><i class="bi bi-eye"></i> Ver</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

</div><!-- /.panel envios recentes -->

