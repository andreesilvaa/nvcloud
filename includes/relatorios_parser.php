<?php
// includes/relatorios_parser.php — deteção de formato + extração

if (!defined('TESSERACT_BIN'))  define('TESSERACT_BIN', 'C:/Program Files/Tesseract-OCR/tesseract.exe');
if (!defined('TESSERACT_LANG')) define('TESSERACT_LANG', 'por+eng');
if (!defined('PDFTOTEXT_BIN'))  define('PDFTOTEXT_BIN', 'C:/poppler/poppler-26.02.0/Library/bin/pdftotext.exe');



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
	// PDF sem texto => Field Service (scan) => OCR
	return nvParseFieldService($path, $nomeOriginal);
}

function nvPdfParaTexto(string $path): string
{
	if (!is_file(PDFTOTEXT_BIN)) return '';
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
function nvParseKonicaEml(string $path): array
{
	$raw = (string)file_get_contents($path);

	// Assunto (cliente + estado). Decodifica =?utf-8?...?= se necessário.
	$assunto = '';
	if (preg_match('/^Subject:\s*(.+)$/mi', $raw, $m)) {
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

	// Corpo: tirar HTML para texto simples
	$corpo = $raw;
	if (preg_match('/Content-Type:\s*text\/html.*?\r?\n\r?\n(.*)$/is', $raw, $m)) {
		$corpo = $m[1];
	}
	$corpo = preg_replace('/<style.*?<\/style>/is', ' ', $corpo);
	$corpo = preg_replace('/<[^>]+>/', ' ', $corpo);
	$corpo = html_entity_decode($corpo, ENT_QUOTES, 'UTF-8');
	$corpo = preg_replace('/\s+/', ' ', $corpo);

	$pat = nvExtrairPat($corpo) ?: nvExtrairPat($assunto);

	// Resolução (texto da solução proposta)
	$resol = '';
	if (preg_match('/solução proposta[^:]*:\s*(.+?)\s*\|\s*PAT/iu', $corpo, $m)) {
		$resol = trim($m[1]);
	}

	// Datas
	$dataInt = null;
	if (preg_match('/DataHora_Fim:\s*(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}:\d{2})/', $corpo, $m)) {
		$dataInt = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:00";
	}

	// Pares SN_Recolha / SN_Novo. Cada bloco pode ter "leitor: X pinpad: Y".
	$pecas = [];
	if (preg_match_all('/SN_(Recolha|Novo):\s*(.+?)(?=\s*\||$)/iu', $corpo, $blocos, PREG_SET_ORDER)) {
		foreach ($blocos as $b) {
			$papel = (strtolower($b[1]) === 'recolha') ? 'recolha' : 'novo';
			$txt   = $b[2];
			$equip = null;
			if (preg_match('/\(kio:(\d+)\)/i', $txt, $mk)) $equip = 'kio:' . $mk[1];
			// pares componente: sn
			if (preg_match_all('/([A-Za-zçãéíõ]+)\s*:\s*([A-Za-z0-9]{6,})/u', $txt, $comp, PREG_SET_ORDER)) {
				foreach ($comp as $c) {
					$pecas[] = [
						'papel' => $papel,
						'componente' => strtolower(trim($c[1])),
						'sn' => trim($c[2]),
						'equip_ref' => $equip,
					];
				}
			}
		}
	}

	return [
		'fonte' => 'konica',
		'pat_numero' => $pat,
		'ref_documento' => $ref,
		'cliente' => $cliente,
		'resolucao' => $resol ?: $corpo,
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
	// SNs retirado/colocado (muitas vezes vazios — toleramos)
	if (preg_match('/Série\s*Retirado:\s*([A-Za-z0-9]{4,})/i', $texto, $m)) {
		$pecas[] = ['papel'=>'recolha','componente'=>null,'sn'=>trim($m[1]),'equip_ref'=>null];
	}
	if (preg_match('/Série\s*Colocado:\s*([A-Za-z0-9]{4,})/i', $texto, $m)) {
		$pecas[] = ['papel'=>'novo','componente'=>null,'sn'=>trim($m[1]),'equip_ref'=>null];
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
function nvParseFieldService(string $path, string $nome): array
{
	$texto = nvOcrPdf($path);
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
	if (!is_file(TESSERACT_BIN) || !is_file(PDFTOTEXT_BIN)) return '';
	// Converter 1.ª página em imagem com pdftoppm (vem com o Poppler)
	$ppm = dirname(PDFTOTEXT_BIN) . '/pdftoppm.exe';
	if (!is_file($ppm)) return '';
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