<?php

// ============================================================
// BOOTSTRAP — Carregamento automático e inicialização Sentry
// Incluir no topo de TODOS os pontos de entrada PHP
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';

// ============================================================
// BINÁRIOS EXTERNOS (Poppler / Tesseract)
// Caminhos centralizados aqui para não estarem hardcoded em vários ficheiros.
// Podem ser sobrepostos no config.php (que é carregado depois) ou por
// variáveis de ambiente. Em Windows aponta para os .exe; em Linux assume
// que estão no PATH (instalar com: apt install poppler-utils tesseract-ocr).
// ============================================================
// Linux/produção: binários do sistema (apt install poppler-utils tesseract-ocr).
// Sobreponíveis por variável de ambiente.
if (!defined('PDFTOTEXT_BIN')) define('PDFTOTEXT_BIN', getenv('PDFTOTEXT_BIN') ?: '/usr/bin/pdftotext');
if (!defined('PDFTOPPM_BIN'))  define('PDFTOPPM_BIN',  getenv('PDFTOPPM_BIN')  ?: '/usr/bin/pdftoppm');
if (!defined('TESSERACT_BIN')) define('TESSERACT_BIN', getenv('TESSERACT_BIN') ?: '/usr/bin/tesseract');
if (!defined('TESSERACT_LANG')) {
    define('TESSERACT_LANG', getenv('TESSERACT_LANG') ?: 'por+eng');
}

\Sentry\init([
    'dsn' => 'https://4fc6ba0fb2beb782ce1b4fdbd15572d0@o4511570013257728.ingest.de.sentry.io/4511570045894736',

    // Captura 100% das transações para performance monitoring.
    // Pode baixar para 0.0 se não quiser performance tracking.
    'traces_sample_rate' => 1.0,

    // Identifica o ambiente no painel Sentry
    'environment' => getenv('APP_ENV') ?: 'production',

    // Versão da aplicação (opcional — útil para agrupar erros por release)
    // 'release' => '1.0.0',
]);
