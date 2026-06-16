<?php

require_once __DIR__ . '/bootstrap.php';

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

$tesseract = '"C:\\Program Files\\Tesseract-OCR\\tesseract.exe"';
$pdftoppm  = '"C:\\poppler\\poppler-26.02.0\\Library\\bin\\pdftoppm.exe"';
$pdftotext = '"C:\\poppler\\poppler-26.02.0\\Library\\bin\\pdftotext.exe"';

// ── Ligação à Base de Dados ───────────────────────────────────
require_once __DIR__ . '/config.php';
try {
    $pdoEnvios = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Exception $e) {
    die('Erro de ligação à BD: ' . htmlspecialchars($e->getMessage()));
}

// Catálogo produtos (do mais longo para melhor match)
$catalogoDb = $pdoEnvios->query(
    "SELECT p.nome AS produto, c.nome AS categoria
       FROM produtos p JOIN categorias c ON c.id = p.categoria_id
      ORDER BY LENGTH(p.nome) DESC"
)->fetchAll();

// Lista de parceiros para matching
$parceirosDb = $pdoEnvios->query(
    "SELECT empresa FROM parceiros ORDER BY empresa ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// Prefixos de SN (ordenados do mais longo para o mais curto — maior especificidade primeiro)
$prefixosDb = [];
try {
    $prefixosDb = $pdoEnvios->query(
        "SELECT prefixo, categoria, produto FROM produto_sn_prefixos ORDER BY LENGTH(prefixo) DESC"
    )->fetchAll();
} catch (Exception $e) {
    // Tabela ainda não existe — corre primeiro o nvcloud_catalogo.sql
}

$erro = '';
$sucesso = '';
$paginasGeradas = [];
$itensExtraidos = [];
$debugLinhas = [];
$debugModo = '';
$dadosGuia = [
    'documento'          => '',
    'numero_documento'   => '',
    'data_documento'     => '',
    'destinatario_nome'  => '',
    'destinatario_local' => '',
    'fornecedor_numero'  => '',
    'contribuinte'       => '',
    'parceiro'           => '',
];

function limparTexto($texto) {
    $texto = trim((string)$texto);
    $texto = str_replace(["\xc2\xa0", "\t"], ' ', $texto);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    return trim($texto);
}

function normalizarTextoPdf($texto) {
    $texto = str_replace(["\r\n", "\r"], "\n", (string)$texto);
    $texto = str_replace("\t", ' ', $texto);
    $texto = preg_replace('/[ ]{2,}/u', ' ', $texto);
    return trim($texto);
}

/**
 * Identifica produto e categoria pelo prefixo do Nº de Série.
 * Os prefixos já vêm ordenados do mais longo para o mais curto,
 * garantindo que o match mais específico ganha.
 * Devolve ['categoria'=>string, 'produto'=>string, 'matched'=>bool].
 */
function matchProdutoPorSN(string $sn, array $prefixos): array {
    $resultado = ['categoria' => '', 'produto' => '', 'matched' => false];
    if ($sn === '' || empty($prefixos)) return $resultado;
    $snUpper = strtoupper(trim($sn));
    foreach ($prefixos as $p) {
        $pref = strtoupper(trim($p['prefixo']));
        if ($pref !== '' && strpos($snUpper, $pref) === 0) {
            return [
                'categoria' => $p['categoria'] ?? '',
                'produto'   => $p['produto']   ?? '',
                'matched'   => true,
            ];
        }
    }
    return $resultado;
}

/**
 * Normaliza texto para comparação: minúsculas, sem acentos.
 */
function normalizarParaMatch(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $from = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï',
             'ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ',
             'Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï',
             'Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];
    $to   = ['a','a','a','a','a','e','e','e','e','i','i','i','i',
             'o','o','o','o','o','u','u','u','u','c','n',
             'a','a','a','a','a','e','e','e','e','i','i','i','i',
             'o','o','o','o','o','u','u','u','u','c','n'];
    return str_replace($from, $to, $s);
}

/**
 * Dada a designação da guia, encontra o produto e categoria
 * correspondentes na base de dados.
 */
function matchProduto(string $designacao, array $catalogo): array {
    $resultado = ['produto' => $designacao, 'categoria' => '', 'score' => 0];
    if (empty($catalogo) || trim($designacao) === '') return $resultado;
    $destNorm = normalizarParaMatch($designacao);
    foreach ($catalogo as $item) {
        $prodNorm = normalizarParaMatch($item['produto']);
        if ($prodNorm === '') continue;
        if (strpos($destNorm, $prodNorm) !== false) {
            $score = mb_strlen($prodNorm, 'UTF-8');
            if ($score > $resultado['score']) {
                $resultado = [
                    'produto'   => $item['produto'],
                    'categoria' => $item['categoria'],
                    'score'     => $score,
                ];
            }
        }
    }
    return $resultado;
}

/**
 * Faz match do nome do destinatário contra os parceiros da BD.
 */
function matchParceiro(string $nome, array $parceiros): string {
    if ($nome === '' || empty($parceiros)) return $nome;
    $nomeNorm = normalizarParaMatch($nome);
    foreach ($parceiros as $p) {
        if (normalizarParaMatch($p) === $nomeNorm) return $p;
    }
    $melhor = ''; $melhorScore = 0;
    foreach ($parceiros as $p) {
        $pNorm   = normalizarParaMatch($p);
        $palavras = preg_split('/[^a-z0-9]+/', $pNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $score = 0;
        foreach ($palavras as $palavra) {
            if (strlen($palavra) >= 4 && strpos($nomeNorm, $palavra) !== false)
                $score += strlen($palavra);
        }
        if ($score > $melhorScore) { $melhorScore = $score; $melhor = $p; }
    }
    return ($melhorScore >= 6) ? $melhor : $nome;
}

function linhaIgnorar($linha) {
    $linhaLower = mb_strtolower(limparTexto($linha), 'UTF-8');
    $bloqueios = [
        'software phc', 'processado por programa certificado', 'documento não serve', 'documento nao serve',
        'página', 'pagina', 'página 1 de 1', 'pagina 1 de 1', 'atcud:', 'guia de transporte',
        'local de carga', 'designação ata', 'designacao ata', 'nº série', 'n.o série', 'n serie',
        'v/ nº contribuinte', 'v/ encomenda', 'nº documento', 'data documento', 'via do documento',
        'total do documento', 'total ilíquido', 'total do desconto', 'base de incidência', 'total de i.v.a.',
        'matrícula', 'data de carga', 'data de descarga', 'cod. identificação at',
        'original', 'duplicado', 'triplicado',
        'nfornecedor', 'v/ncontribuinte', 'vencome', 'designagao', 'designação',
        'qtd', 'nserie', 'nsérie', 'serie', 'série',
    ];
    foreach ($bloqueios as $bloqueio) {
        if (strpos($linhaLower, $bloqueio) !== false) return true;
    }
    return false;
}

function textoBrutoDoPdf($pdfDestino, $pdftotext) {
    $tmp = tempnam(sys_get_temp_dir(), 'pdftxt_');
    if ($tmp === false) return '';
    @unlink($tmp);
    $cmd = $pdftotext . ' -layout ' . escapeshellarg($pdfDestino) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    shell_exec($cmd);
    $txtFile = $tmp . '.txt';
    if (!is_file($txtFile)) return '';
    $texto = file_get_contents($txtFile);
    @unlink($txtFile);
    return normalizarTextoPdf($texto);
}

function extrairCabecalhoGuia($texto) {
    $dados = [
        'documento'          => '',
        'numero_documento'   => '',
        'data_documento'     => '',
        'destinatario_nome'  => '',
        'destinatario_local' => '',
        'fornecedor_numero'  => '',
        'contribuinte'       => '',
        'parceiro'           => '',
    ];

    // ── Tipo de documento ────────────────────────────────
    if (preg_match('/G\.\s*Transp\s*\(said\s*fornec\)/iu', $texto)) {
        $dados['documento'] = 'G. Transp (said fornec)';
    } elseif (preg_match('/G\.\s*Transp\s*\(said\s*cli\b/iu', $texto)) {
        $dados['documento'] = 'G. Transp (said cli)';
    } elseif (preg_match('/Guia\s+de\s+transporte/iu', $texto)) {
        $dados['documento'] = 'Guia de Transporte';
    }

    // ── Número e data do documento ────────────────────────
    if (preg_match('/G\.\s*Transp\s*\(said\s*(?:fornec|cli\b)[^)]*\)\s+(\d{1,6})\s+(\d{4}-\d{2}-\d{2})/iu', $texto, $m)) {
        $dados['numero_documento'] = $m[1];
        $dados['data_documento']   = $m[2];
    } else {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $texto, $m)) {
            $dados['data_documento'] = $m[1];
        }
        if (preg_match('/N[\xba\xb0o\.?]\s*Documento[^0-9]*(\d{1,6})\b/isu', $texto, $m)) {
            $dados['numero_documento'] = $m[1];
        }
        // Fallback ATCUD: número segue o ATCUD após o "-"
        if ($dados['numero_documento'] === '' &&
            preg_match('/ATCUD:[A-Z0-9]+-([0-9]+)/iu', $texto, $m)) {
            $dados['numero_documento'] = $m[1];
        }
    }

    // ── Nome do destinatário ──────────────────────────────
    // Padrão 1: linha após "Exmo(s) Senhor(es)" com sufixo de empresa
    if (preg_match(
        '/Exmo\(s\)\s+Senhor\(es\).*?\n+\s*([^\n]{8,}(?:LDA|S\.?\s*A\.?|SRL|UNIPESSOAL|COOPERATIVA)[^\n]*)/isu',
        $texto, $m
    )) {
        $cand = limparTexto($m[1]);
        // Rejeitar se começa com referência de documento (GT 2026...)
        if (!preg_match('/^GT\s+\d/i', $cand)) {
            $dados['destinatario_nome'] = $cand;
        }
    }
    // Padrão 2: KONICA-type — empresa numa linha, UNIP./LDA na linha seguinte (com GT)
    if ($dados['destinatario_nome'] === '') {
        if (preg_match(
            '/Exmo\(s\)\s+Senhor\(es\)\s*\n+\s*([^\n]{8,})\n+([^\n]*(?:LDA|S\.?\s*A\.?|UNIPESSOAL|SRL|COOPERATIVA)[^\n]*)/isu',
            $texto, $m
        )) {
            $partA = limparTexto($m[1]);
            $partB = limparTexto($m[2]);
            if (!preg_match('/^GT\s+\d|^Guia de|^ATCUD/i', $partA)) {
                // Remover referência GT da linha do sufixo (ex: "GT 2026BO91/131 UNIP. LDA" → "UNIP. LDA")
                $suffix = preg_replace('/^GT\s+[A-Z0-9\/\.]+\s*/i', '', $partB);
                $suffix = limparTexto($suffix);
                $dados['destinatario_nome'] = $suffix !== '' ? limparTexto($partA . ' ' . $suffix) : $partA;
            }
        }
    }
    // Padrão 3: mesma linha que Exmo(s) (layout com colunas sobrepostas)
    if ($dados['destinatario_nome'] === '' &&
        preg_match('/Exmo\(s\)\s+Senhor\(es\)\s+([^\n]{8,})/iu', $texto, $m)) {
        $cand = limparTexto($m[1]);
        if (!preg_match('/^(?:Documento|N[\xba\xb0o]|Via\s+do)/iu', $cand))
            $dados['destinatario_nome'] = $cand;
    }
    // Padrão 4: qualquer linha com sufixo societário
    if ($dados['destinatario_nome'] === '' &&
        preg_match('/^([A-Z\xc0-\xff\-][^\n]{8,}(?:LDA|S\.?A\.|UNIPESSOAL|COOPERATIVA)[^\n]*)/mu', $texto, $m)) {
        $dados['destinatario_nome'] = limparTexto($m[1]);
    }
    // Limpeza: remover texto de coluna de documento que possa ter ficado colado
    if ($dados['destinatario_nome'] !== '') {
        $dados['destinatario_nome'] = preg_replace(
            '/\s+(?:G\.\s*Transp|N\xba\s*Fornecedor|Documento|ORIGINAL|DUPLICADO|TRIPLICADO).*/iu',
            '', $dados['destinatario_nome']
        );
        $dados['destinatario_nome'] = limparTexto($dados['destinatario_nome']);
    }

    // ── Local de descarga ───────────────────────────────
    if (preg_match('/Local\s+de\s+descarga\s+(.+?)\s+(?:Total\s+il[i\xed]quido|Cod\.\s+Identifica[c\xe7][a\xe3]o)/isu', $texto, $m)) {
        $dados['destinatario_local'] = limparTexto($m[1]);
    } elseif (preg_match('/Local\s+de\s+descarga\s+(.+?)\s+Cod\./isu', $texto, $m)) {
        $dados['destinatario_local'] = limparTexto($m[1]);
    }

    // ── Nº Fornecedor ───────────────────────────────────
    if (preg_match('/N[\xba\xb0o]\s+Fornecedor[^0-9]*(\d{1,10})\b/isu', $texto, $m)) {
        $dados['fornecedor_numero'] = $m[1];
    } elseif (preg_match('/\b2476\b/u', $texto, $m)) {
        $dados['fornecedor_numero'] = $m[0];
    }

    // ── Contribuinte ──────────────────────────────────
    if (preg_match('/V\/\s*N[\xba\xb0o]\s*Contribu[i\xed]nte[^0-9]*(\d{9})\b/isu', $texto, $m)) {
        $dados['contribuinte'] = $m[1];
    } elseif (preg_match('/\b500339023\b/u', $texto, $m)) {
        $dados['contribuinte'] = $m[0];
    }

    return $dados;
}

function extrairBlocoItens($texto) {
    if (preg_match('/Artigo\s+Designa[cç][aã]o\s+Qtd\.?\s+N[ºo]\s*S[ée]rie(.+?)(Software\s+PHC|Local\s+de\s+carga|P[aá]gina\s+\d+\s+de\s+\d+)/isu', $texto, $m)) {
        return trim($m[1]);
    }
    return '';
}

function linhasLimpasItens($texto) {
    $bloco = extrairBlocoItens($texto);
    if ($bloco === '') return [];
    $linhas = preg_split('/\n/u', $bloco) ?: [];
    $out = [];
    foreach ($linhas as $linha) {
        $linha = limparTexto($linha);
        if ($linha === '' || linhaIgnorar($linha)) continue;
        $out[] = $linha;
    }
    return $out;
}

function ehPat($token) {
    return (bool)preg_match('/^PAT-\d+$/i', trim($token));
}

function ehSnValido($token) {
    $token = strtoupper(trim($token));
    if ($token === '') return false;
    if (ehPat($token)) return false;
    if (strlen($token) < 7) return false;
    if (preg_match('/\s/', $token)) return false;
    if (!preg_match('/[A-Z]/', $token)) return false;
    if (!preg_match('/\d/', $token)) return false;
    $bloqueiosExatos = ['IMPRESSORA','ASSISTENCIA','PC','BOTAO','BOTÃO','WIFI','BOX','VODAFONE','PORTO','MOS','MÓS','EDP','COMERCIAL','LEIRIA'];
    if (in_array($token, $bloqueiosExatos, true)) return false;
    if (preg_match('/^[A-Z]{2,}\-[A-Z0-9\-]+$/', $token) && !preg_match('/\d{4,}/', $token)) return false;
    return true;
}

function extrairPats($texto) {
    preg_match_all('/PAT-\d+/iu', $texto, $m);
    $pats = array_map('strtoupper', $m[0] ?? []);
    return array_values(array_unique($pats));
}

function limparTipoPeca($texto) {
    $texto = limparTexto($texto);
    $texto = preg_replace('/\bASSISTENCIA\b/iu', '', $texto);
    $texto = preg_replace('/\bIPRESSORA\b/iu', '', $texto);
    $texto = preg_replace('/\s{2,}/u', ' ', $texto);
    $texto = trim($texto);

    $upper = strtoupper($texto);
    if (in_array($upper, ['TAOWIF', 'TAOWIFI'], true)) {
        return 'taoWifi';
    }
    return $texto;
    }

function extrairTipoPecaDaLinha($linha) {
    $linha = limparTexto($linha);
    $linha = preg_replace('/\s+\d+,\d{2}\s*$/u', '', $linha);
    $partes = array_values(array_filter(array_map('trim', explode('/', $linha)), fn($v) => $v !== ''));
    if (empty($partes)) return '';
    return limparTipoPeca($partes[0]);
}

function extrairQuantidadeDaLinha($linha) {
    if (preg_match('/(\d+),\d{2}\s*$/u', limparTexto($linha), $m)) {
        return (int)$m[1];
    }
    return 1;
}

function dividirItensPorLinhas($linhas) {
    $itens = [];
    $buffer = '';

    foreach ($linhas as $linha) {
        $linha = limparTexto($linha);
        if ($linha === '') continue;

        if (preg_match('/^ASSISTENCIA\b/iu', $linha)) {
            if ($buffer !== '') $itens[] = $buffer;
            $buffer = $linha;
            continue;
        }

        if ($buffer !== '') {
            $buffer .= ' ' . $linha;
        }
    }

    if ($buffer !== '') $itens[] = $buffer;
    return $itens;
}

function expandirLinhaEmItens($linha) {
    $linhaOriginal = limparTexto($linha);
    if ($linhaOriginal === '' || !preg_match('/^ASSISTENCIA\b/iu', $linhaOriginal)) return [];

    $quantidade = extrairQuantidadeDaLinha($linhaOriginal);
    $semQtd = preg_replace('/\s+\d+,\d{2}\s*$/u', '', $linhaOriginal);
    $tipo = extrairTipoPecaDaLinha($semQtd);
    $pats = extrairPats($semQtd);

    $partes = array_values(array_filter(array_map('trim', explode('/', $semQtd)), fn($v) => $v !== ''));
    $sns = [];

    foreach ($partes as $i => $parte) {
        $parteNorm = strtoupper(limparTexto($parte));
        if ($i === 0) continue;
        if (ehPat($parteNorm)) continue;
        if (ehSnValido($parteNorm)) $sns[] = $parteNorm;
    }

    $sns = array_values(array_unique($sns));
    $patsTexto = !empty($pats) ? implode(', ', $pats) : '';
    $resultado = [];

    if (!empty($sns)) {
        foreach ($sns as $sn) {
            $resultado[] = [
                'linha'      => $linhaOriginal,
                'tipo'       => $tipo,
                'quantidade' => $quantidade,
                'sn'         => $sn,
                'pat'        => $patsTexto,
                'nome_peca'  => '',
                'categoria'  => '',
            ];
        }
        return $resultado;
    }

    if ($patsTexto !== '') {
        $resultado[] = [
            'linha'      => $linhaOriginal,
            'tipo'       => $tipo,
            'quantidade' => $quantidade,
            'sn'         => '',
            'pat'        => $patsTexto,
            'nome_peca'  => '',
            'categoria'  => '',
        ];
    }

    return $resultado;
}

function extrairItensTextoPdf($texto) {
    $linhas = linhasLimpasItens($texto);
    $linhasItens = dividirItensPorLinhas($linhas);
    $resultado = [];
    $vistos = [];

    foreach ($linhasItens as $linha) {
        foreach (expandirLinhaEmItens($linha) as $item) {
            $snKey = strtoupper(trim((string)$item['sn']));
            $patKey = strtoupper(trim((string)$item['pat']));
            $tipoKey = strtoupper(trim((string)$item['tipo']));

            if ($snKey !== '') {
                $chave = 'SN|' . $snKey;
            } elseif ($patKey !== '') {
                $chave = 'PAT|' . $patKey;
            } else {
                $chave = 'TIPO|' . $tipoKey . '|QTD|' . (int)$item['quantidade'];
            }

            if (isset($vistos[$chave])) {
                continue;
            }

            $vistos[$chave] = true;
            $resultado[] = $item;
        }
    }

    return $resultado;
}

function cortarZonaTabela($origem, $destino) {
    $img = @imagecreatefrompng($origem);
    if (!$img) return false;
    $largura = imagesx($img);
    $altura = imagesy($img);
    $crop = imagecrop($img, ['x' => (int)($largura * 0.02), 'y' => (int)($altura * 0.12), 'width' => (int)($largura * 0.96), 'height' => (int)($altura * 0.70)]);
    if ($crop === false) { imagedestroy($img); return false; }
    imagepng($crop, $destino);
    imagedestroy($crop);
    imagedestroy($img);
    return true;
}

function preprocessarImagem($origem, $destino) {
    $img = @imagecreatefrompng($origem);
    if (!$img) return false;
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_CONTRAST, -25);
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 60 || $h < 20) { imagedestroy($img); return false; }
    $nw = $w * 3;
    $nh = $h * 3;
    $out = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagepng($out, $destino);
    imagedestroy($img);
    imagedestroy($out);
    return true;
}

function gerarLinhasFixasTabela($origem, $pastaDestino, $prefixo) {
    $img = @imagecreatefrompng($origem);
    if (!$img) return [];
    $largura = imagesx($img);
    $altura = imagesy($img);
    $linhas = [];
    $zonaInicioY = (int)($altura * 0.18);
    $zonaFimY = (int)($altura * 0.86);
    $zonaAltura = $zonaFimY - $zonaInicioY;
    $numeroLinhas = 5;
    $alturaLinha = (int)floor($zonaAltura / $numeroLinhas);
    $margemTop = 16;
    $margemBottom = 16;
    for ($i = 0; $i < $numeroLinhas; $i++) {
        $y = max(0, $zonaInicioY + ($i * $alturaLinha) - $margemTop);
        $h = min($altura - $y, $alturaLinha + $margemTop + $margemBottom);
        if ($h < 40) continue;
        $crop = imagecrop($img, ['x' => 0, 'y' => $y, 'width' => $largura, 'height' => $h]);
        if ($crop === false) continue;
        $ficheiro = $pastaDestino . $prefixo . '_linha_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '.png';
        imagepng($crop, $ficheiro);
        imagedestroy($crop);
        $linhas[] = $ficheiro;
    }
    imagedestroy($img);
    return $linhas;
}

function cortarColunasLinha($origem, $destinoDesc, $destinoQtd) {
    $img = @imagecreatefrompng($origem);
    if (!$img) return false;
    $largura = imagesx($img);
    $altura = imagesy($img);
    $cropDesc = imagecrop($img, ['x' => (int)($largura * 0.22), 'y' => 0, 'width' => (int)($largura * 0.65), 'height' => $altura]);
    $cropQtd = imagecrop($img, ['x' => (int)($largura * 0.86), 'y' => 0, 'width' => (int)($largura * 0.12), 'height' => $altura]);
    if ($cropDesc === false || $cropQtd === false) {
        imagedestroy($img);
        if ($cropDesc) imagedestroy($cropDesc);
        if ($cropQtd) imagedestroy($cropQtd);
        return false;
    }
    imagepng($cropDesc, $destinoDesc);
    imagepng($cropQtd, $destinoQtd);
    imagedestroy($cropDesc);
    imagedestroy($cropQtd);
    imagedestroy($img);
    return true;
}

function gerarDebugLinhasOcr($pagina, $cropDir, $sliceDir, $prepDir, $colDir, $tesseract) {
    $debug = [];
    $cropPagina = $cropDir . basename($pagina, '.png') . '_crop.png';
    if (!cortarZonaTabela($pagina, $cropPagina)) return $debug;
    $linhasFixas = gerarLinhasFixasTabela($cropPagina, $sliceDir, basename($pagina, '.png'));
    foreach ($linhasFixas as $linhaImg) {
        $linhaBase = basename($linhaImg, '.png');
        $imgDesc = $colDir . $linhaBase . '_desc.png';
        $imgQtd = $colDir . $linhaBase . '_qtd.png';
        if (!cortarColunasLinha($linhaImg, $imgDesc, $imgQtd)) {
            $debug[] = ['ficheiro' => basename($linhaImg), 'texto' => 'Falha no corte das colunas.'];
            continue;
        }
        $prepDesc = $prepDir . $linhaBase . '_desc_prep.png';
        $prepQtd = $prepDir . $linhaBase . '_qtd_prep.png';
        if (!preprocessarImagem($imgDesc, $prepDesc) || !preprocessarImagem($imgQtd, $prepQtd)) {
            $debug[] = ['ficheiro' => basename($linhaImg), 'texto' => 'Imagem demasiado pequena para OCR.'];
            continue;
        }
        $cmdDesc = $tesseract . ' ' . escapeshellarg($prepDesc) . ' stdout -l por+eng --psm 6 -c preserve_interword_spaces=1 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-/ 2>&1';
        $cmdQtd = $tesseract . ' ' . escapeshellarg($prepQtd) . ' stdout -l eng --psm 7 -c tessedit_char_whitelist=0123456789, 2>&1';
        $textoDesc = shell_exec($cmdDesc);
        $textoQtd = shell_exec($cmdQtd);
        $debug[] = ['ficheiro' => basename($linhaImg), 'texto' => "DESC:\n" . trim((string)$textoDesc) . "\nQTD:\n" . trim((string)$textoQtd)];
    }
    return $debug;
}

function extrairDadosLinhaOcr($textoDesc, $textoQtd) {
    $textoDesc = str_replace(["\r\n", "\r"], "\n", (string)$textoDesc);
    $textoQtd = limparTexto($textoQtd);

    if (trim($textoDesc) === '') return [];

    $linhas = preg_split('/\n+/u', $textoDesc) ?: [];
    $resultado = [];
    $vistos = [];

    foreach ($linhas as $linha) {
    $linha = limparTexto($linha);
    if ($linha === '' || linhaIgnorar($linha)) continue;
    if (!str_contains($linha, '/')) continue;
    if (!preg_match('/[A-Z0-9\-]+\/[A-Z0-9\-]+/iu', $linha)) continue;

    $qtd = 1;
    if (preg_match('/\b(\d{1,3})\s*$/u', $linha, $m)) {
        $qtd = (int)$m[1];
        $linha = trim(preg_replace('/\b\d{1,3}\s*$/u', '', $linha));
    } elseif (preg_match('/\b(\d{1,3})\b/u', $textoQtd, $m)) {
        $qtd = (int)$m[1];
    }

    $partes = array_values(array_filter(array_map('trim', explode('/', $linha)), fn($v) => $v !== ''));
    if (count($partes) < 2) continue;

        $tipo = limparTipoPeca($partes[0]);
        $sns = [];
        $pats = [];

        foreach ($partes as $i => $parte) {
            $parte = strtoupper(limparTexto($parte));
            if ($parte === '') continue;
            if ($i === 0) continue;

            if (preg_match('/PAT-\d+/i', $parte, $mPat)) {
                $pats[] = strtoupper($mPat[0]);
                continue;
            }

            if (ehSnValido($parte)) {
                $sns[] = $parte;
                continue;
            }

            preg_match_all('/\b[A-Z0-9]{8,}\b/u', $parte, $mSn);
            foreach ($mSn[0] ?? [] as $cand) {
                $cand = strtoupper(trim($cand));
                if (ehSnValido($cand)) $sns[] = $cand;
            }
        }

        $sns = array_values(array_unique($sns));
        $pats = array_values(array_unique($pats));

        if (empty($sns) && empty($pats)) continue;

        if (!empty($sns)) {
    foreach ($sns as $sn) {
        $snKey = strtoupper(trim((string)$sn));
        $patTexto = !empty($pats) ? implode(', ', $pats) : '';
        $patKey = strtoupper(trim($patTexto));
        $tipoKey = strtoupper(trim((string)$tipo));

        if ($snKey !== '') {
            $chave = 'SN|' . $snKey;
        } elseif ($patKey !== '') {
            $chave = 'PAT|' . $patKey;
        } else {
            $chave = 'TIPO|' . $tipoKey . '|QTD|' . (int)$qtd;
        }

        if (isset($vistos[$chave])) continue;
        $vistos[$chave] = true;

        $resultado[] = [
            'linha' => $linha,
            'tipo' => $tipo,
            'quantidade' => $qtd,
            'sn' => $sn,
            'pat' => $patTexto
        ];
    }
} else {
    $patTexto = !empty($pats) ? implode(', ', $pats) : '';
    $patKey = strtoupper(trim($patTexto));
    $tipoKey = strtoupper(trim((string)$tipo));

    if ($patKey !== '') {
        $chave = 'PAT|' . $patKey;
    } else {
        $chave = 'TIPO|' . $tipoKey . '|QTD|' . (int)$qtd;
    }

    if (!isset($vistos[$chave])) {
        $vistos[$chave] = true;
        $resultado[] = [
            'linha' => $linha,
            'tipo' => $tipo,
            'quantidade' => $qtd,
            'sn' => '',
            'pat' => $patTexto
            ];
        }
      }  
    }

    return $resultado;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmar_envio') {
    $parceiro  = trim($_POST['parceiro']   ?? '');
    $itensJson = $_POST['itens_json']      ?? '[]';
    $itensConf = json_decode($itensJson, true) ?: [];

    $atualizados    = [];
    $naoEncontrados = [];
    $semSN          = [];

    foreach ($itensConf as $itemC) {
        $sn = strtoupper(trim($itemC['sn'] ?? ''));
        if ($sn === '') {
            $semSN[] = $itemC['nome_peca'] ?? ($itemC['tipo'] ?? '?');
            continue;
        }
        $stmtUpd = $pdoEnvios->prepare(
            "UPDATE pecas SET estado = 'Parceiro', parceiro = ? WHERE UPPER(TRIM(sn)) = ? LIMIT 1"
        );
        $stmtUpd->execute([$parceiro, $sn]);
        if ($stmtUpd->rowCount() > 0) {
            $atualizados[] = $sn;
        } else {
            $naoEncontrados[] = $sn;
        }
    }

    $msgParts = [];
    if (!empty($atualizados))
        $msgParts[] = count($atualizados) . ' peça(s) atualizadas para estado <strong>Parceiro</strong> → <strong>' . htmlspecialchars($parceiro) . '</strong>.';
    if (!empty($naoEncontrados))
        $msgParts[] = '<br>⚠ SNs não encontrados no Inventário: <code>' . implode(', ', array_map('htmlspecialchars', $naoEncontrados)) . '</code>';
    if (!empty($semSN))
        $msgParts[] = '<br>ℹ Peças sem SN (não atualizadas): ' . implode(', ', array_map('htmlspecialchars', $semSN));

    if (!empty($atualizados)) {
        $sucesso = implode('', $msgParts);
    } else {
        $erro = implode('', $msgParts) ?: 'Nenhuma peça foi atualizada. Verifica se os SNs existem no Inventário.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['guia_pdf'])) {
    $pdfDir = __DIR__ . '/uploads/pdf/';
    $imgDir = __DIR__ . '/uploads/ocr_pages/';
    $cropDir = __DIR__ . '/uploads/ocr_crops/';
    $sliceDir = __DIR__ . '/uploads/ocr_slices/';
    $prepDir = __DIR__ . '/uploads/ocr_prepared/';
    $colDir = __DIR__ . '/uploads/ocr_columns/';

    foreach ([$pdfDir, $imgDir, $cropDir, $sliceDir, $prepDir, $colDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    if ($_FILES['guia_pdf']['error'] !== UPLOAD_ERR_OK) {
        $erro = 'Erro no upload do PDF. Código: ' . (int)$_FILES['guia_pdf']['error'];
    } else {
        $ext = strtolower(pathinfo($_FILES['guia_pdf']['name'], PATHINFO_EXTENSION));

        if ($ext !== 'pdf') {
            $erro = 'Só são permitidos ficheiros PDF.';
        } else {
            $baseNome = 'guia_' . date('Ymd_His') . '_' . uniqid();
            $pdfDestino = $pdfDir . $baseNome . '.pdf';

            if (!move_uploaded_file($_FILES['guia_pdf']['tmp_name'], $pdfDestino)) {
                $erro = 'Não foi possível guardar o PDF enviado.<br>'
                      . 'tmp_name: ' . htmlspecialchars($_FILES['guia_pdf']['tmp_name']) . '<br>'
                      . 'destino: ' . htmlspecialchars($pdfDestino) . '<br>'
                      . 'erro upload: ' . (int)$_FILES['guia_pdf']['error'] . '<br>'
                      . 'pasta existe: ' . (is_dir($pdfDir) ? 'SIM' : 'NAO') . '<br>'
                      . 'destino pasta escrita: ' . (is_writable($pdfDir) ? 'SIM' : 'NAO');
            } else {
                $debugLinhas[] = [
                    'ficheiro' => 'UPLOAD_DEBUG',
                    'texto' => print_r($_FILES['guia_pdf'], true) . "\nDestino final: " . $pdfDestino
                ];

                $textoPdf = textoBrutoDoPdf($pdfDestino, $pdftotext);

                if ($textoPdf !== '') {
                    $dadosGuia = extrairCabecalhoGuia($textoPdf);
                    $itensTexto = extrairItensTextoPdf($textoPdf);

                    if (!empty($itensTexto)) {
                        $debugModo = 'Texto direto do PDF';
                        $debugLinhas[] = ['ficheiro' => 'PDF_TEXT', 'texto' => $textoPdf];
                        $itensExtraidos = $itensTexto;
                        $sucesso = 'PDF processado com sucesso via texto direto.';
                    }
                }

                if (empty($itensExtraidos)) {
                    $debugModo = 'Fallback OCR';
                    $prefixoSaida = $imgDir . $baseNome;
                    $cmdPdf = $pdftoppm . ' -png ' . escapeshellarg($pdfDestino) . ' ' . escapeshellarg($prefixoSaida) . ' 2>&1';
                    shell_exec($cmdPdf);

                    $paginasGeradas = glob($prefixoSaida . '-*.png') ?: [];
                    sort($paginasGeradas);

                    if (!empty($paginasGeradas)) {
                    $paginasGeradas = [reset($paginasGeradas)];
                    }

                    if (empty($paginasGeradas)) {
                        $erro = 'Não foi possível converter o PDF em imagens.';
                    } else {
                        $todosItens = [];
                        $vistos = [];

                        foreach ($paginasGeradas as $pagina) {
                            foreach (gerarDebugLinhasOcr($pagina, $cropDir, $sliceDir, $prepDir, $colDir, $tesseract) as $dbg) {
                                $debugLinhas[] = $dbg;
                            }

                            $cropPagina = $cropDir . basename($pagina, '.png') . '_crop.png';
                            if (!is_file($cropPagina) && !cortarZonaTabela($pagina, $cropPagina)) {
                                continue;
                            }

                            $linhasFixas = gerarLinhasFixasTabela($cropPagina, $sliceDir, basename($pagina, '.png'));

                            foreach ($linhasFixas as $linhaImg) {
                                $linhaBase = basename($linhaImg, '.png');
                                $imgDesc = $colDir . $linhaBase . '_desc.png';
                                $imgQtd = $colDir . $linhaBase . '_qtd.png';

                                if (!cortarColunasLinha($linhaImg, $imgDesc, $imgQtd)) {
                                    continue;
                                }

                                $prepDesc = $prepDir . $linhaBase . '_desc_prep.png';
                                $prepQtd = $prepDir . $linhaBase . '_qtd_prep.png';

                                if (!preprocessarImagem($imgDesc, $prepDesc) || !preprocessarImagem($imgQtd, $prepQtd)) {
                                    continue;
                                }

                                $cmdDesc = $tesseract . ' ' . escapeshellarg($prepDesc) . ' stdout -l por+eng --psm 6 -c preserve_interword_spaces=1 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-/ 2>&1';
                                $cmdQtd = $tesseract . ' ' . escapeshellarg($prepQtd) . ' stdout -l eng --psm 7 -c tessedit_char_whitelist=0123456789, 2>&1';

                                $textoDesc = trim((string)shell_exec($cmdDesc));
                                $textoQtd = trim((string)shell_exec($cmdQtd));

                                $itensLinha = extrairDadosLinhaOcr($textoDesc, $textoQtd);
                                if (empty($itensLinha)) {
                                    continue;
                                }

                                foreach ($itensLinha as $item) {
                                    $snKey = strtoupper(trim((string)$item['sn']));
                                    $patKey = strtoupper(trim((string)$item['pat']));
                                    $tipoKey = strtoupper(trim((string)$item['tipo']));

                                if ($snKey !== '') {
                                    $chave = 'SN|' . $snKey;
                                } elseif ($patKey !== '') {
                                    $chave = 'PAT|' . $patKey;
                                } else {
                                    $chave = 'TIPO|' . $tipoKey . '|QTD|' . (int)$item['quantidade'];
                                }

                                if (isset($vistos[$chave])) {
                                continue;
                                }
                                    $vistos[$chave] = true;
                                    $todosItens[] = $item;
                                }
                            }
                        }

                        $itensExtraidos = $todosItens;

                        if (empty($itensExtraidos)) {
                            $erro = 'O OCR correu, mas não encontrou linhas válidas de peças.';
                        } else {
                            $sucesso = 'PDF processado com sucesso via OCR.';
                        }
                    }
                }

                // ── Pós-processamento: matching de produto/categoria e parceiro ──
                if (!empty($itensExtraidos)) {
                    foreach ($itensExtraidos as &$itemPP) {
                        $sn           = $itemPP['sn']   ?? '';
                        $tipoExtraido = $itemPP['tipo']  ?? '';

                        // Prioridade 1 — Prefixo do SN (identifica mesmo peças nunca vistas)
                        $matchSN = matchProdutoPorSN($sn, $prefixosDb);
                        if ($matchSN['matched']) {
                            $itemPP['categoria'] = $matchSN['categoria'];
                            if ($matchSN['produto'] !== '') {
                                // Prefixo identifica produto exacto
                                $itemPP['nome_peca'] = $matchSN['produto'];
                            } else {
                                // Prefixo só identifica categoria (ex: todas as Proxima)
                                // usa texto da guia para determinar produto específico
                                $matchTxt = matchProduto($tipoExtraido, $catalogoDb);
                                $itemPP['nome_peca'] = $matchTxt['produto'] ?: $tipoExtraido;
                            }
                        } else {
                            // Prioridade 2 — Texto da designação na guia
                            $matchTxt = matchProduto($tipoExtraido, $catalogoDb);
                            $itemPP['nome_peca'] = $matchTxt['produto'];
                            $itemPP['categoria'] = $matchTxt['categoria'];
                        }
                    }
                    unset($itemPP);
                }

                // Determinar parceiro da guia
                if (stripos($dadosGuia['documento'], 'client') !== false) {
                    $dadosGuia['parceiro'] = 'Field Service';
                } elseif (!empty($dadosGuia['destinatario_nome'])) {
                    $dadosGuia['parceiro'] = matchParceiro($dadosGuia['destinatario_nome'], $parceirosDb);
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Envios - Leitura de Guias</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
body{margin:0;font-family:'Roboto',sans-serif;background:#f5f6fa;color:#222}
.topbar{background:#cba35c;color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.topbar a{color:#fff;text-decoration:none;background:#343a40;padding:10px 14px;border-radius:6px;margin-left:10px;display:inline-block}
.main{max-width:1280px;margin:0 auto;padding:24px}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.06);padding:20px;margin-bottom:20px}
h1,h2,h3{margin-top:0}
label{display:block;font-weight:700;margin-bottom:8px}
input[type=file]{width:100%;padding:12px;border:1px solid #d0d7de;border-radius:8px;background:#fff;box-sizing:border-box}
button{background:#3d82c4;color:#fff;border:none;padding:12px 18px;border-radius:8px;cursor:pointer;margin-top:14px;font-size:15px}
.msg-ok{background:#d1e7dd;color:#0f5132;padding:12px 14px;border-radius:8px;margin-bottom:16px}
.msg-erro{background:#f8d7da;color:#842029;padding:12px 14px;border-radius:8px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.kv{border:1px solid #e5e7eb;border-radius:10px;padding:12px;background:#fafbfc}
.kv b{display:block;margin-bottom:6px}
table{width:100%;border-collapse:collapse}
table th,table td{border:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}
table th{background:#f3f4f6}
.note{color:#666;font-size:14px}
.small{font-size:13px;color:#777}
pre{white-space:pre-wrap;word-break:break-word;background:#f8f9fb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;max-height:420px;overflow:auto}
details summary{cursor:pointer;font-weight:700}
.badge{display:inline-block;background:#eef2ff;color:#3730a3;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
.table-wrap{overflow:auto}
@media (max-width:768px){.grid{grid-template-columns:1fr}.main{padding:16px}}
</style>
</head>
<body>
<div class="topbar">
    <div>Envios - Leitura de Guias</div>
    <div>
        <a href="index.php?page=envios">Voltar</a>
        <a href="logout.php">Sair</a>
    </div>
</div>

<div class="main">
    <div class="card">
        <h1>Ler guia em PDF</h1>
        <p class="small">Modo ativo: <span class="badge"><?= htmlspecialchars($debugModo ?: 'auto') ?></span></p>
        <form method="post" enctype="multipart/form-data">
            <label for="guia_pdf">Seleciona o PDF</label>
            <input type="file" name="guia_pdf" id="guia_pdf" accept="application/pdf,.pdf" required>
            <button type="submit">Ler PDF</button>
        </form>
        <p class="note">Esta versão tenta primeiro extrair texto direto do PDF, que é o método mais simples e fiável para este tipo de guia; só usa OCR se não encontrar itens válidos.</p>
    </div>

    <?php if ($erro): ?>
        <div class="msg-erro"><?= $erro ?></div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
        <div class="msg-ok"><?= $sucesso ?></div>
    <?php endif; ?>

    <?php if (!empty($dadosGuia['destinatario_nome']) || !empty($itensExtraidos)): ?>
        <div class="card">
            <h2>Dados da guia</h2>
            <div class="grid">
                <div class="kv"><b>Tipo de Guia</b><?= htmlspecialchars($dadosGuia['documento'] ?: '-') ?></div>
                <div class="kv"><b>Nº Documento</b><?= htmlspecialchars($dadosGuia['numero_documento'] ?: '-') ?></div>
                <div class="kv"><b>Data</b><?= htmlspecialchars($dadosGuia['data_documento'] ?: '-') ?></div>
                <div class="kv"><b>Destinatário</b><?= htmlspecialchars($dadosGuia['destinatario_nome'] ?: '-') ?></div>
                <div class="kv" style="border:2px solid #c9a14a;background:#fffbf0">
                    <b style="color:#92700f">Parceiro Identificado</b>
                    <?= htmlspecialchars($dadosGuia['parceiro'] ?: '--- não identificado ---') ?>
                </div>
                <?php if ($dadosGuia['contribuinte']): ?>
                <div class="kv"><b>Contribuinte</b><?= htmlspecialchars($dadosGuia['contribuinte']) ?></div>
                <?php endif; ?>
                <?php if ($dadosGuia['fornecedor_numero']): ?>
                <div class="kv"><b>Nº Fornecedor</b><?= htmlspecialchars($dadosGuia['fornecedor_numero']) ?></div>
                <?php endif; ?>
                <?php if ($dadosGuia['destinatario_local']): ?>
                <div class="kv" style="grid-column:1/-1;"><b>Local de Descarga</b><?= htmlspecialchars($dadosGuia['destinatario_local']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($itensExtraidos)): ?>
        <div class="card">
            <h2>Peças Identificadas (<?= count($itensExtraidos) ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Nome da Peça</th>
                            <th style="text-align:center">Qtd.</th>
                            <th>Número de Série</th>
                            <th>PAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itensExtraidos as $item): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($item['categoria'])): ?>
                                        <span style="display:inline-block;background:#eef2ff;color:#3730a3;padding:3px 9px;border-radius:999px;font-size:.82rem;font-weight:700;white-space:nowrap">
                                            <?= htmlspecialchars($item['categoria']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-style:italic">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($item['nome_peca'] ?: ($item['tipo'] ?: '—')) ?></td>
                                <td style="text-align:center"><?= htmlspecialchars((string)$item['quantidade']) ?></td>
                                <td style="font-family:monospace;font-size:.88rem">
                                    <?= $item['sn'] !== '' ? htmlspecialchars($item['sn']) : '<span style="color:#9ca3af">—</span>' ?>
                                </td>
                                <td style="font-size:.82rem;color:#6b7280;font-family:monospace">
                                    <?= $item['pat'] !== '' ? htmlspecialchars($item['pat']) : '<span style="color:#9ca3af">—</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $itensParaJson = array_map(fn($i) => [
                'sn'        => $i['sn'],
                'nome_peca' => $i['nome_peca'] ?: $i['tipo'],
                'categoria' => $i['categoria'],
                'tipo'      => $i['tipo'],
            ], $itensExtraidos);
            $itensJsonStr = htmlspecialchars(json_encode($itensParaJson, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            $parceiroPre  = htmlspecialchars($dadosGuia['parceiro'], ENT_QUOTES, 'UTF-8');
            ?>
            <form method="post" action="envios.php" style="margin-top:20px">
                <input type="hidden" name="action"     value="confirmar_envio">
                <input type="hidden" name="parceiro"   value="<?= $parceiroPre ?>">
                <input type="hidden" name="itens_json" value="<?= $itensJsonStr ?>">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:16px 18px;background:#fffbf0;border:1px solid #f0c070;border-radius:12px">
                    <p style="margin:0;font-size:.92rem;color:#78590a;flex:1">
                        Revê as informações acima. Ao aprovar, as peças com SN serão atualizadas no
                        <strong>Inventário</strong> para estado <strong>Parceiro</strong>
                        <?php if ($dadosGuia['parceiro']): ?> → <strong><?= htmlspecialchars($dadosGuia['parceiro']) ?></strong><?php endif; ?>.
                    </p>
                    <button type="submit" style="background:#16a34a;color:#fff;border:none;padding:11px 24px;border-radius:8px;cursor:pointer;font-size:.95rem;font-weight:700;font-family:inherit;white-space:nowrap">
                        ✓ Aprovar Envio
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!empty($paginasGeradas)): ?>
        <div class="card">
            <h2>Páginas convertidas</h2>
            <p class="small">Foram geradas <?= count($paginasGeradas) ?> página(s) em imagem para OCR.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($debugLinhas)): ?>
        <div class="card">
            <h2>Debug</h2>
            <details>
                <summary>Mostrar/Ocultar texto bruto e OCR</summary>
                <?php foreach ($debugLinhas as $dbg): ?>
                    <div style="margin-top:16px;">
                        <h3 style="margin-bottom:8px;"><?= htmlspecialchars($dbg['ficheiro']) ?></h3>
                        <pre><?= htmlspecialchars($dbg['texto']) ?></pre>
                    </div>
                <?php endforeach; ?>
            </details>
        </div>
    <?php endif; ?>
</div>
</body>
</html>