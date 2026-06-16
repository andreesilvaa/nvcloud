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

	$popplerDir = 'C:/poppler/poppler-26.02.0/Library/bin';
	$tesseract = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
	$tmpBase = sys_get_temp_dir() . '/nvcloud_' . uniqid();

	// ── TENTATIVA 1: Poppler (PDFs com texto embutido) ──────────────────
	$txtFile = $tmpBase . '.txt';
	exec("\"$popplerDir/pdftotext.exe\" \"$pdfPath\" \"$txtFile\"");
	$texto = file_exists($txtFile) ? trim(file_get_contents($txtFile)) : '';

	// ── TENTATIVA 2: Tesseract OCR (PDFs escaneados) ─────────────────────
	if (strlen($texto) < 20) {

		// Converte a 1ª página do PDF em imagem PNG (300dpi = boa qualidade para OCR)
		$imgPrefix = $tmpBase . '_pag';
		exec("\"$popplerDir/pdftoppm.exe\" -r 300 -png -l 1 \"$pdfPath\" \"$imgPrefix\"");

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