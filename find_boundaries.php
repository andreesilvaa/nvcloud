<?php
// Helper: finds exact line numbers of data loading blocks and handlers
$lines = file('C:/laragon/www/nvcloud/app.php');
$total = count($lines);

echo "Total lines: $total\n\n";

// Find all key patterns with line numbers
$patterns = [
    'countQuery function'     => '/^function countQuery/',
    '$totalPecas ='           => '/^\$totalPecas\s*=/',
    'notificacoes = []'       => '/^\$notificacoes\s*=\s*\[\]/',
    '$totalNotif ='           => '/^\$totalNotif\s*=/',
    'estadoData ='            => '/^\$estadoData\s*=/',
    'trendRows ='             => '/^\$trendRows\s*=/',
    'actividadeRecente ='     => '/^\$actividadeRecente\s*=/',
    'pendentesCliente ='      => '/^\$pendentesCliente\s*=/',
    '$filters = ['            => '/^\$filters\s*=\s*\[/',
    '$pecas = $stmt->fetch'   => '/^\$pecas\s*=\s*\$stmt->fetchAll/',
    'historico page guard'    => "/if \(\\\$page === 'historico'/",
    'auditoria page guard'    => "/if \(\\\$page === 'auditoria'/",
    'qrResultado = null'      => '/^\$qrResultado\s*=\s*null/',
    'envios page guard'       => "/if \(\\\$page === 'envios'/",
    'alertas page guard'      => "/if \(\\\$page === 'alertas'/",
    'rever_peca handler'      => "/form_type.*rever_peca/",
    'lote_estado handler'     => "/form_type.*lote_estado/",
    'nvi section start'       => '/function nviSemAcento/',
    'tabelas section start'   => '/TABELAS DE GESTÃO/',
    'contas data load'        => "/if \(\\\$page === 'contas'\)/",
    'pats data load'          => "/if \(\\\$page === 'pats'\)/",
    'HTML starts'             => '/^<!DOCTYPE html>/',
    'categorias tab load'     => "/if \(\\\$page === 'categorias'\)/",
    'relatorios data load'    => "/if \(\\\$page === 'relatorios'\)/",
];

foreach ($patterns as $label => $pattern) {
    foreach ($lines as $i => $line) {
        if (preg_match($pattern, trim($line))) {
            echo str_pad($label, 30) . " => line " . ($i+1) . "\n";
            break;
        }
    }
}
