<?php

// ============================================================
// BOOTSTRAP — Carregamento automático e inicialização Sentry
// Incluir no topo de TODOS os pontos de entrada PHP
// ============================================================

// ------------------------------------------------------------
// Cookie de sessão seguro (TEM de ser definido antes de qualquer
// session_start(), por isso fica aqui no topo do bootstrap)
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ------------------------------------------------------------
// Headers de segurança HTTP (aplicados em todos os pontos de entrada)
// ------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // CSP ajustada para incluir os CDNs que a app realmente usa
    // (Chart.js, Bootstrap Icons, cropperjs, html5-qrcode, Google Fonts).
    header("Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: blob:; " .
        "connect-src 'self' https://unpkg.com; " .
        "worker-src 'self' blob:;");
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/estados_const.php';
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
    'traces_sample_rate' => (float)(getenv('SENTRY_TRACES_RATE') ?: 0.1),

    // Identifica o ambiente no painel Sentry
    'environment' => getenv('APP_ENV') ?: 'production',

    // Versão da aplicação (opcional — útil para agrupar erros por release)
    // 'release' => '1.0.0',
]);
