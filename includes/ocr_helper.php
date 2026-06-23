<?php
require_once __DIR__ . '/../vendor/autoload.php';

use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Extrai texto de um PDF.
 * - Se o PDF tiver texto nativo → usa Poppler (rápido)
 * - Se for PDF escaneado/imagem → converte para imagem e usa Tesseract OCR
 */
function extrairTextoPDF(string $pdfPath): string
{

	// Caminhos centralizados (definidos no bootstrap.php; fallback aqui caso este
	// ficheiro seja incluído isoladamente). Em Linux assume binários no PATH.
	if (!defined('PDFTOTEXT_BIN')) {
		define('PDFTOTEXT_BIN', '/usr/bin/pdftotext');
	}
	if (!defined('PDFTOPPM_BIN')) {
		define('PDFTOPPM_BIN', '/usr/bin/pdftoppm');
	}
	if (!defined('TESSERACT_BIN')) {
		define('TESSERACT_BIN', '/usr/bin/tesseract');
	}
	$tesseract = TESSERACT_BIN;
	$tmpBase = sys_get_temp_dir() . '/nvcloud_' . uniqid();

	// ── TENTATIVA 1: Poppler (PDFs com texto embutido) ──────────────────
	$txtFile = $tmpBase . '.txt';
	exec('"' . PDFTOTEXT_BIN . '" "' . $pdfPath . '" "' . $txtFile . '"');
	$texto = file_exists($txtFile) ? trim(file_get_contents($txtFile)) : '';

	// ── TENTATIVA 2: Tesseract OCR (PDFs escaneados) ─────────────────────
	if (strlen($texto) < 20) {

		// Converte a 1ª página do PDF em imagem PNG (300dpi = boa qualidade para OCR)
		$imgPrefix = $tmpBase . '_pag';
		exec('"' . PDFTOPPM_BIN . '" -r 300 -png -l 1 "' . $pdfPath . '" "' . $imgPrefix . '"');

		// pdftoppm gera: prefixo-1.png
		$imgFile = $imgPrefix . '-1.png';

		if (file_exists($imgFile)) {
			$ocr = new TesseractOCR($imgFile);
			$ocr->executable($tesseract);
			$ocr->lang('por', 'eng'); // Português primário + inglês fallback
			$texto = $ocr->run();

			unlink($imgFile); // apaga imagem temporária
		}
	}

	// Limpeza do ficheiro de texto temporário
	if (file_exists($txtFile)) unlink($txtFile);

	return $texto ?: 'Não foi possível extrair texto deste ficheiro.';
}