<?php
require __DIR__ . '/auth.php';            // exige sessão
if (($_SESSION['user_role'] ?? '') !== 'admin') { http_response_code(403); exit('Acesso negado.'); }
// includes/relatorios_parser.php — deteção de formato + extração

// Caminhos sensíveis ao SO. O bootstrap.php define-os primeiro (têm prioridade);
// estes são apenas fallback caso este ficheiro seja incluído isoladamente.
if (!defined('TESSERACT_BIN'))  define('TESSERACT_BIN', '/usr/bin/tesseract');
if (!defined('TESSERACT_LANG')) define('TESSERACT_LANG', 'por+eng');
if (!defined('PDFTOTEXT_BIN'))  define('PDFTOTEXT_BIN', '/usr/bin/pdftotext');
if (!defined('PDFTOPPM_BIN'))   define('PDFTOPPM_BIN',  '/usr/bin/pdftoppm');



function nvParseRelatorio(string $path, string $nomeOriginal): array
{
	$ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
	if ($ext === 'eml') {
		return nvParseKonicaEml($path);
	}
	// PDF: tentar camada de texto
	$texto = nvPdfParaTexto($path);
	if (mb_strlen(trim($texto)) > 40) {
		if (stripos($texto, 'Ref. Cliente') !== false || stripos($texto, 'Relatório de Intervenção') !== false) {
			return nvParseCronotecnica($texto, $nomeOriginal);
		}
	}
	// PDF sem marcadores da Cronotécnica => Field Service.
	// Os PDFs da Field Service nem sempre são scans sem texto — muitos têm
	// já uma camada de texto legível (o próprio "Worksheet / Folha de Obra"
	// é texto real, não imagem). Quando já temos texto bom, é melhor usá-lo
	// diretamente: o OCR (rasterizar a página + Tesseract) introduz erros
	// que o texto original não tem (ex.: "BCM" lido como "BOM" num teste).
	// Só recorremos ao OCR quando a camada de texto está mesmo vazia/curta.
	return nvParseFieldService($path, $nomeOriginal, $texto);
}

function nvPdfParaTexto(string $path): string
{
	// No Windows o binário é um caminho absoluto verificável; em Linux está no PATH.
	if (PHP_OS_FAMILY === 'Windows' && !is_file(PDFTOTEXT_BIN)) return '';
	$tmp = tempnam(sys_get_temp_dir(), 'nvpdf') . '.txt';
	$cmd = '"' . PDFTOTEXT_BIN . '" -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp);
	@exec($cmd);
	$txt = is_file($tmp) ? (string)file_get_contents($tmp) : '';
	@unlink($tmp);
	return $txt;
}

/** Extrai PAT no formato 00102521/2 ou PAT-00102521/2 e devolve 'PAT-00102521/2'. */
function nvExtrairPat(string $texto): ?string
{
	if (preg_match('/PAT[-\s]?0*?(\d{6,8})[\/_-](\d{1,2})/i', $texto, $m)) {
		return 'PAT-' . str_pad($m[1], 8, '0', STR_PAD_LEFT) . '/' . $m[2];
	}
	if (preg_match('/\b(\d{6,8})\s*\/\s*(\d{1,2})\b/', $texto, $m)) {
		return 'PAT-' . str_pad($m[1], 8, '0', STR_PAD_LEFT) . '/' . $m[2];
	}
	return null;
}

// ---------- FORMATO B: KONICA (.eml) — o mais fiável ----------

/**
 * Descodifica o conteúdo de uma parte MIME de acordo com o seu
 * Content-Transfer-Encoding (base64 / quoted-printable / 7bit-8bit-binary).
 */
function nvDecodificarParteEml(string $corpoBruto, string $encoding): string
{
	$encoding = trim(strtolower($encoding));
	if ($encoding === 'base64') {
		$limpo = preg_replace('/[^A-Za-z0-9+\/=]/', '', $corpoBruto);
		return (string) base64_decode($limpo ?? '');
	}
	if ($encoding === 'quoted-printable') {
		return quoted_printable_decode($corpoBruto);
	}
	return $corpoBruto; // 7bit/8bit/binary/sem encoding -> já é texto direto
}

/**
 * Extrai e descodifica o corpo de um .eml em texto simples.
 * As mensagens (ex.: Outlook/Konica) vêm muitas vezes com o corpo em
 * Content-Transfer-Encoding: base64 — aplicar regex ao ficheiro bruto
 * (sem descodificar) nunca encontra nada, porque o texto procurado está
 * codificado. Procuramos a parte text/plain (mais simples) e, se não
 * existir, a parte text/html (com remoção de tags depois).
 */
function nvExtrairCorpoEml(string $raw): string
{
	$partes = [];
	if (preg_match_all(
		'/Content-Type:\s*(text\/plain|text\/html)[^\r\n]*\r?\n(?:[^\r\n]+\r?\n)*?Content-Transfer-Encoding:\s*([^\r\n]+)\r?\n(?:[^\r\n]+\r?\n)*\r?\n(.*?)(?=\r?\n--|\z)/is',
		$raw, $mm, PREG_SET_ORDER
	)) {
		foreach ($mm as $m) {
			$tipo = strtolower($m[1]);
			if (isset($partes[$tipo])) continue; // mantém só a 1ª ocorrência de cada tipo
			$partes[$tipo] = nvDecodificarParteEml($m[3], $m[2]);
		}
	}

	if (isset($partes['text/plain']) && trim($partes['text/plain']) !== '') {
		return $partes['text/plain'];
	}
	if (isset($partes['text/html'])) {
		$html = $partes['text/html'];
		$html = preg_replace('/<style.*?<\/style>/is', ' ', $html);
		$html = preg_replace('/<[^>]+>/', ' ', $html);
		$html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
		return $html;
	}
	return $raw; // fallback: nenhuma parte MIME reconhecida — comportamento antigo
}

function nvParseKonicaEml(string $path): array
{
	$raw = (string)file_get_contents($path);

	// Assunto (cliente + estado). Decodifica =?utf-8?...?= se necessário.
	// Cabeçalhos longos vêm "dobrados" pelo Outlook em várias linhas, com as
	// linhas de continuação a começar por um espaço/tab (RFC 2822). Sem juntar
	// essas linhas, o assunto fica cortado a meio (ex.: "...Vodafone Por - foi"
	// sem o "resolvido" que está mesmo na linha seguinte) e o cliente nunca é
	// detetado porque o padrão "foi resolvido" deixa de existir na string.
	$assunto = '';
	if (preg_match('/^Subject:\s*(.+(?:\r?\n[ \t].*)*)/mi', $raw, $m)) {
		$assunto = trim(preg_replace('/\s+/', ' ', $m[1]));
		if (function_exists('iconv_mime_decode')) {
			$assunto = @iconv_mime_decode($assunto, 0, 'UTF-8') ?: $assunto;
		}
	}
	$ref = null;
	if (preg_match('/\bCS(\d{6,})\b/', $assunto, $m)) $ref = 'CS' . $m[1];

	// Cliente do assunto: entre o "PAT-...../n -" e o "- foi resolvido"
	$cliente = '';
	if (preg_match('/PAT[-\s]?\d{6,8}[\/_]\d+\s*[-–]\s*(.+?)\s*[-–]\s*(?:foi resolvido|é necessária)/iu', $assunto, $m)) {
		$cliente = trim($m[1]);
	}

	// Corpo: descodificar a parte MIME certa (base64/quoted-printable) antes
	// de tirar HTML — ver nvExtrairCorpoEml().
	$corpo = nvExtrairCorpoEml($raw);
	$corpo = preg_replace('/<style.*?<\/style>/is', ' ', $corpo);
	$corpo = preg_replace('/<[^>]+>/', ' ', $corpo);
	$corpo = html_entity_decode($corpo, ENT_QUOTES, 'UTF-8');
	// Versão com as quebras de linha/parágrafo ainda preservadas (só o espaço
	// horizontal é normalizado) — usada para limitar onde a extração de
	// SN_Recolha/SN_Novo deve parar (ver mais abaixo). $corpo (totalmente
	// "achatado" numa só linha) continua a ser usado para o resto, onde
	// funciona bem.
	$corpoComLinhas = preg_replace('/[ \t]+/', ' ', trim($corpo));
	$corpo = preg_replace('/\s+/', ' ', $corpo);

	$pat = nvExtrairPat($corpo) ?: nvExtrairPat($assunto);

	// Resolução (texto da solução proposta). O separador antes de "PAT" é
	// normalmente "|", mas já se viu técnicos a escrever um "l" (L minúsculo)
	// por engano no mesmo lugar — aceitamos os dois.
	$resol = '';
	if (preg_match('/solução proposta[^:]*:\s*(.+?)\s*[|l]\s*PAT/iu', $corpo, $m)) {
		$resol = trim($m[1]);
	}

	// Datas
	$dataInt = null;
	if (preg_match('/DataHora_Fim:\s*(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}:\d{2})/', $corpo, $m)) {
		$dataInt = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:00";
	}

	// Pares SN_Recolha / SN_Novo. Cada bloco pode ter "leitor: X pinpad: Y".
	// O valor termina no próximo "|" OU no fim do parágrafo/linha (o que
	// vier primeiro) — usamos $corpoComLinhas (quebras de linha preservadas)
	// para isto. Sem o limite da quebra de linha, quando o SN_Novo é o
	// último campo da frase (não há mais nenhum "|" depois dele), a captura
	// não tinha onde parar e continuava a ler a assinatura/rodapé do email
	// inteiro como se fizesse parte do valor — criando peças falsas a partir
	// de texto como "Account:", "Email:", "Ref:..." (confirmado em teste:
	// apareciam peças "SN NEWVISION", "SN Product", "SN Resolved", etc.).
	// Nota: sem o modificador /u nesta regex em concreto — o padrão só usa
	// ASCII (letras, dígitos, ":", "|"), e se o corpo tiver algum byte que não
	// seja UTF-8 válido (acontece com texto de e-mail mal codificado), o /u
	// faz o preg_match_all falhar SEM AVISO (devolve false, não 0) para a
	// string inteira — o que explicava não aparecer SN nenhum mesmo com o
	// texto correto à frente.
	$pecas = [];
	if (preg_match_all('/SN_(Recolha|Novo):[ \t]*([^\r\n|]{1,80})/i', $corpoComLinhas, $blocos, PREG_SET_ORDER)) {
		foreach ($blocos as $b) {
			$papel = (strtolower($b[1]) === 'recolha') ? 'recolha' : 'novo';
			$txt   = $b[2];
			$equip = null;
			if (preg_match('/\(kio:(\d+)\)/i', $txt, $mk)) $equip = 'kio:' . $mk[1];
			// pares componente: sn (caso "leitor: ABC123 pinpad: XYZ789")
			if (preg_match_all('/([A-Za-zçãéíõ]+)\s*:\s*([A-Za-z0-9]{6,})/u', $txt, $comp, PREG_SET_ORDER)) {
				foreach ($comp as $c) {
					$pecas[] = [
						'papel' => $papel,
						'componente' => strtolower(trim($c[1])),
						'sn' => trim($c[2]),
						'equip_ref' => $equip,
					];
				}
			} elseif (preg_match('/[A-Za-z0-9]{4,}/', $txt, $mSimples)) {
				// Caso simples (o mais comum): o valor é só o SN, sem
				// "componente:" à frente (ex.: "SN_Recolha: INLPXM011712").
				// Sem este "elseif", estas peças nunca eram guardadas —
				// só o formato "leitor: X" é que produzia alguma coisa.
				$pecas[] = [
					'papel' => $papel,
					'componente' => null,
					'sn' => $mSimples[0],
					'equip_ref' => $equip,
				];
			}
		}
	}

	return [
		'fonte' => 'konica',
		'pat_numero' => $pat,
		'ref_documento' => $ref,
		'cliente' => $cliente,
		// Limite defensivo: a coluna `resolucao_texto` é TEXT (65535 carateres).
		// Mesmo com o corpo já descodificado, mantemos um limite de segurança
		// para nunca voltar a rebentar a query de INSERT (erro 1406 já visto).
		'resolucao' => mb_substr($resol ?: $corpo, 0, 60000),
		'data_intervencao' => $dataInt,
		'pecas' => $pecas,
	];
}

// ---------- FORMATO A: CRONOTÉCNICA (PDF com texto) ----------
function nvParseCronotecnica(string $texto, string $nome): array
{
	$pat = nvExtrairPat($texto);

	$cliente = '';
	// Primeira linha "real" do cabeçalho do cliente (ex.: "Galp Energia - Casa da Musica")
	if (preg_match('/^\s*(.+?)\s*Para qualquer quest/mi', $texto, $m)) {
		$cliente = trim($m[1]);
	}

	$resol = '';
	if (preg_match('/Relatório de Intervenção:\s*(.+?)\s*Aprovação e confirmação/is', $texto, $m)) {
		$resol = trim(preg_replace('/\s+/', ' ', $m[1]));
	}

	$dataInt = null;
	if (preg_match('/Data de Intervenção:\s*(\d{2})\.(\d{2})\.(\d{4})/', $texto, $m)) {
		$dataInt = "{$m[3]}-{$m[2]}-{$m[1]} 00:00:00";
	}

	$pecas = [];
	// SNs retirado/colocado (muitas vezes vazios — toleramos).
	// No PDF, o valor do SN aparece logo depois de "Nº de Série" e só na
	// LINHA SEGUINTE é que vem o rótulo "Retirado:" ou "Colocado:" — não
	// "Retirado: VALOR" como seria de esperar. Por isso procuramos
	// "Nº de Série VALOR" seguido (com quebra de linha) do rótulo certo.
	if (preg_match_all(
		'/N[ºo]\.?\s*de\s*S[ée]rie\s+([A-Za-z0-9]{4,})\s*[\r\n]+\s*(Retirado|Colocado)\s*:/iu',
		$texto, $mAll, PREG_SET_ORDER
	)) {
		foreach ($mAll as $mm) {
			$papel = (strtolower($mm[2]) === 'retirado') ? 'recolha' : 'novo';
			$pecas[] = ['papel'=>$papel,'componente'=>null,'sn'=>trim($mm[1]),'equip_ref'=>null];
		}
	}

	return [
		'fonte' => 'cronotecnica',
		'pat_numero' => $pat,
		'ref_documento' => null,
		'cliente' => $cliente,
		'resolucao' => $resol,
		'data_intervencao' => $dataInt,
		'pecas' => $pecas,
	];
}

// ---------- FORMATO C: FIELD SERVICE (scan -> OCR) ----------
function nvParseFieldService(string $path, string $nome, string $textoExistente = ''): array
{
	// Se já temos uma camada de texto razoável (extraída por pdftotext em
	// nvParseRelatorio), usamo-la em vez de OCR — é mais fiável (sem isto,
	// um PDF com texto perfeitamente legível era sempre re-processado por
	// OCR, que introduz erros: confirmado em teste a ler "BCM" como "BOM").
	// Só recorremos ao OCR quando não há texto nenhum (scan/imagem pura).
	$texto = (mb_strlen(trim($textoExistente)) > 40) ? $textoExistente : nvOcrPdf($path);
	$pat = nvExtrairPat($texto) ?: nvExtrairPat($nome); // PAT também aparece no nome do ficheiro

	$cliente = '';
	if (preg_match('/Entidade:\s*\[?\s*([^\]\n|]+)/i', $texto, $m)) {
		$cliente = trim($m[1]);
	}

	// Field Service é manuscrito => peças/SNs NÃO são fiáveis. Não extraímos peças automaticamente.
	return [
		'fonte' => 'field_service',
		'pat_numero' => $pat,
		'ref_documento' => null,
		'cliente' => $cliente,
		'resolucao' => trim(preg_replace('/\s+/', ' ', mb_substr($texto, 0, 1000))),
		'data_intervencao' => null,
		'pecas' => [],          // sempre vazio: obriga revisão manual
		'forcar_revisao' => true,
	];
}

function nvOcrPdf(string $path): string
{
	if (PHP_OS_FAMILY === 'Windows' && (!is_file(TESSERACT_BIN) || !is_file(PDFTOTEXT_BIN))) return '';
	// Converter 1.ª página em imagem com pdftoppm (vem com o Poppler)
	$ppm = PDFTOPPM_BIN;
	if (PHP_OS_FAMILY === 'Windows' && !is_file($ppm)) return '';
	$base = tempnam(sys_get_temp_dir(), 'nvocr');
	@unlink($base);
	@exec('"' . $ppm . '" -png -r 220 -f 1 -l 1 ' . escapeshellarg($path) . ' ' . escapeshellarg($base));
	$img = $base . '-1.png';
	if (!is_file($img)) { $img = $base . '-01.png'; }
	if (!is_file($img)) return '';
	$out = tempnam(sys_get_temp_dir(), 'nvtxt');
	@exec('"' . TESSERACT_BIN . '" ' . escapeshellarg($img) . ' ' . escapeshellarg($out) . ' -l ' . TESSERACT_LANG);
	$txt = is_file($out . '.txt') ? (string)file_get_contents($out . '.txt') : '';
	@unlink($img); @unlink($out . '.txt');
	return $txt;
}