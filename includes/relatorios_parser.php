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
	// A abordagem antiga assumia que dentro de cada parte MIME o cabeçalho
	// "Content-Type" vinha SEMPRE antes do "Content-Transfer-Encoding". Os
	// e-mails da Konica (gerados pelo Outlook/sistema deles) trazem-nos pela
	// ORDEM INVERSA (CTE primeiro, CT depois). Com a regex antiga nenhuma
	// parte casava, a função devolvia o $raw inteiro (e-mail por
	// descodificar) e, como o corpo vinha em quoted-printable, qualquer
	// texto com acentos — ex.: "solução proposta" — aparecia como
	// "solu=C3=A7=C3=A3o" e nunca era encontrado. Resultado: a resolução
	// ficava com o e-mail inteiro (60000 carateres) e os acentos partidos.
	//
	// Agora dividimos o e-mail pelas boundaries (suporta multipart aninhado)
	// e, em cada parte, lemos os cabeçalhos por QUALQUER ordem.
	$partes = [];
	$boundaries = [];
	if (preg_match_all('/boundary="?([^"\r\n;]+)"?/i', $raw, $bm)) {
		foreach ($bm[1] as $b) $boundaries[$b] = true;
	}
	if ($boundaries) {
		$delim = implode('|', array_map(static fn($b) => preg_quote($b, '/'), array_keys($boundaries)));
		$segmentos = preg_split('/--(?:' . $delim . ')(?:--)?[ \t]*\r?\n/', $raw);
		foreach ($segmentos as $seg) {
			// Cada segmento = cabeçalhos + linha em branco + corpo.
			if (!preg_match('/^(.*?\r?\n)\r?\n(.*)$/s', $seg, $sp)) continue;
			$head = $sp[1]; $body = $sp[2];
			if (!preg_match('/Content-Type:\s*(text\/plain|text\/html)/i', $head, $ct)) continue;
			$tipo = strtolower($ct[1]);
			if (isset($partes[$tipo])) continue; // só a 1ª ocorrência de cada tipo
			$enc = '';
			if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n;]+)/i', $head, $ce)) {
				$enc = trim($ce[1]);
			}
			$partes[$tipo] = nvDecodificarParteEml($body, $enc);
		}
	}

	// E-mail não-multipart: descodifica o corpo único conforme o CTE do topo.
	if (!$partes && preg_match('/\r?\n\r?\n(.*)$/s', $raw, $mb)) {
		$enc = '';
		if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n;]+)/i', $raw, $ce)) {
			$enc = trim($ce[1]);
		}
		return nvDecodificarParteEml($mb[1], $enc);
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
	// Texto da solução proposta. No corpo Konica vem assim:
	//   "A solução proposta para o seu Pedido: <TEXTO> | DataHora_Inicio: ..."
	// ou seja, o TEXTO termina no primeiro "|" (separador dos campos
	// DataHora_*/SN_*) e NÃO num "| PAT" — a versão antiga exigia "| PAT"
	// logo a seguir, coisa que estes e-mails não têm, por isso a captura
	// falhava sempre e a resolução acabava por ser o corpo inteiro.
	// Aceitamos acentos partidos (solu[çc]ão) por precaução e paramos no
	// primeiro "|", em "DataHora" ou no fim.
	$resol = '';
	if (preg_match('/solu[çc][ãa]o\s+proposta[^:]*:\s*(.+?)\s*(?:\||DataHora|SN_|$)/iu', $corpo, $m)) {
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
	// O cabeçalho do cliente tem normalmente DUAS linhas: a entidade jurídica
	// "mãe" (ex.: "EDP Comercial - Comercialização de Energia, S.A.") e, logo
	// a seguir, o local/site específico onde a intervenção aconteceu (ex.:
	// "EDP Comercial - Ovar Agente Exclusivo"), imediatamente antes da morada
	// (linha que começa por "Rua", "Av.", etc.). Queremos o site específico,
	// não a entidade mãe — por isso apanhamos a linha imediatamente anterior
	// à morada. Se não houver morada reconhecível, caímos no comportamento
	// antigo (linha antes de "Para qualquer questão").
	if (preg_match('/^\s*(.+?)\s*\r?\n\s*(?:Rua|Av(?:enida)?\.?|Pra[çc]a|Estrada|Largo|Travessa|Alameda)\s/miu', $texto, $m)) {
		$cliente = trim($m[1]);
	} elseif (preg_match('/^\s*(.+?)\s*Para qualquer quest/mi', $texto, $m)) {
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

	// Layout alternativo (visto nalguns relatórios Cronotécnica): os campos
	// "Nº de Série Retirado/Colocado" ficam vazios e o SN real vem embutido
	// dentro dos campos "PN do Player que saiu/entrou", no formato
	// "SN:XXXXX" ou "S/N:XXXXX". Só tentamos isto quando o padrão principal
	// acima não encontrou nada, para não duplicar peças nos relatórios que
	// já funcionam corretamente com o formato clássico.
	if (!$pecas) {
		if (preg_match('/PN do Player que saiu:\s*S\/?N\s*:?\s*([A-Za-z0-9]{4,})/iu', $texto, $mSaiu)) {
			$pecas[] = ['papel'=>'recolha','componente'=>null,'sn'=>trim($mSaiu[1]),'equip_ref'=>null];
		}
		if (preg_match('/PN do Player que entrou:\s*S\/?N\s*:?\s*([A-Za-z0-9]{4,})/iu', $texto, $mEntrou)) {
			$pecas[] = ['papel'=>'novo','componente'=>null,'sn'=>trim($mEntrou[1]),'equip_ref'=>null];
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
		// Limpeza do OCR: tira pontuação/traços iniciais ("— Loomis...") e
		// corta no salto de coluna (2+ espaços) que o scan deixa entre o
		// valor e o rótulo seguinte. Se o que sobra for ele próprio um
		// rótulo do formulário (ex.: "Location / Local:") descarta-se —
		// nesses casos o campo Entidade estava vazio e a regex apanhou a
		// etiqueta a seguir.
		$cliente = preg_replace('/^[\s\-–—:.\[\]]+/u', '', $cliente);
		$cliente = preg_split('/\s{2,}/u', $cliente)[0];
		$cliente = trim($cliente);
		if ($cliente !== '' && (str_contains(strtolower($cliente), 'local') || str_ends_with($cliente, ':'))) {
			$cliente = '';
		}
	}

	// Field Service é manuscrito => peças/SNs NÃO são fiáveis para aplicar
	// automaticamente. Mesmo assim, sugerimos candidatos a SN (tokens
	// alfanuméricos com 6+ caracteres e pelo menos um dígito) para dar ao
	// revisor um ponto de partida. Ficam marcados como 'novo' por defeito e
	// 'forcar_revisao' continua true (nada é aplicado sem confirmação humana).
	$pecasSugeridas = [];
	if (preg_match_all('/\b(?=[A-Z0-9]*\d)[A-Z0-9]{6,}\b/i', $texto, $mSn)) {
		$vistos = [];
		foreach ($mSn[0] as $cand) {
			$cand = strtoupper($cand);
			if (isset($vistos[$cand])) continue;
			$vistos[$cand] = true;
			$pecasSugeridas[] = [
				'papel' => 'novo', 'componente' => null,
				'sn' => $cand, 'equip_ref' => null, 'sugerido' => true,
			];
			if (count($pecasSugeridas) >= 8) break; // não inundar o ecrã de revisão
		}
	}

	return [
		'fonte' => 'field_service',
		'pat_numero' => $pat,
		'ref_documento' => null,
		'cliente' => $cliente,
		'resolucao' => trim(preg_replace('/\s+/', ' ', mb_substr($texto, 0, 1000))),
		'data_intervencao' => null,
		'pecas' => $pecasSugeridas,   // sugestões; revisão manual continua obrigatória
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