<?php

// ============================================================
// BOOTSTRAP — Carregamento automático e inicialização Sentry
// Incluir no topo de TODOS os pontos de entrada PHP
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';

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
