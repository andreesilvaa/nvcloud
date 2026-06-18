<?php
// split_pages.php - extracts page HTML blocks from app.php into includes/pages/*.php
// Run once from CLI: php split_pages.php

$appFile  = __DIR__ . '/app.php';
$pagesDir = __DIR__ . '/includes/pages';

if (!is_dir($pagesDir)) {
    mkdir($pagesDir, 0755, true);
}

$lines = file($appFile);
$total = count($lines);

$toExtract = [
    'dashboard', 'inventario', 'nova_peca', 'historico', 'envios',
    'qrs', 'contas', 'auditoria', 'alertas', 'pats',
    'relatorios', 'categorias', 'estados', 'fabricantes',
    'produtos', 'parceiros', 'nvi',
];

$alreadyExtracted = ['revisao', 'resumo', 'movimentos', 'etiqueta', 'sla'];
$allKnown = array_merge($toExtract, $alreadyExtracted);

$boundaries  = [];
$elseLineIdx = null;

foreach ($lines as $i => $line) {
    $trimmed = trim($line);
    if (preg_match('/^<\?php\s+(?:if|elseif)\s*\(\s*\$page\s*===\s*[\'"](\w+)[\'"]\s*\)\s*:/', $trimmed, $m)) {
        if (in_array($m[1], $allKnown)) {
            $boundaries[$m[1]] = $i;
        }
    }
    if (preg_match('/^<\?php\s+else\s*:/', $trimmed)) {
        $elseLineIdx = $i;
    }
}

echo "Found page boundaries:\n";
foreach ($boundaries as $name => $lineNo) {
    echo "  $name => line " . ($lineNo + 1) . "\n";
}
echo "\n";

if (empty($boundaries)) {
    echo "ERROR: No boundaries found.\n";
    exit(1);
}

asort($boundaries);
$sortedNames = array_keys($boundaries);
$sortedLines = array_values($boundaries);
$lastEnd     = $elseLineIdx ?? $total;

// Extract content for each page
$extractedContent = [];
foreach ($toExtract as $page) {
    if (!isset($boundaries[$page])) { echo "WARNING: no boundary for '$page'\n"; continue; }
    $startLine = $boundaries[$page];
    $pos       = array_search($page, $sortedNames);
    $endLine   = isset($sortedLines[$pos + 1]) ? $sortedLines[$pos + 1] : $lastEnd;
    $extractedContent[$page] = implode('', array_slice($lines, $startLine + 1, $endLine - $startLine - 1));
}

// Write page files
$alreadyWritten = ['qrs', 'nvi'];
foreach ($extractedContent as $page => $content) {
    $outFile = $pagesDir . '/' . $page . '.php';
    if (in_array($page, $alreadyWritten) && file_exists($outFile)) {
        echo "SKIP (already written): $page.php\n";
        continue;
    }
    file_put_contents($outFile, $content);
    echo "WROTE: includes/pages/$page.php (" . strlen($content) . " bytes)\n";
}

echo "\nPatching app.php...\n\n";

// Patch app.php bottom-up
$newLines     = $lines;
$replacements = [];

foreach ($toExtract as $page) {
    if (!isset($boundaries[$page])) continue;
    $startLine = $boundaries[$page];
    $pos       = array_search($page, $sortedNames);
    $endLine   = isset($sortedLines[$pos + 1]) ? $sortedLines[$pos + 1] : $lastEnd;
    $keyword   = (strpos(trim($lines[$startLine]), 'elseif') !== false) ? 'elseif' : 'if';
    $replacements[$startLine] = compact('endLine', 'page', 'keyword');
}

krsort($replacements);

foreach ($replacements as $startLine => $info) {
    $stub = "<?php {$info['keyword']} (\$page === '{$info['page']}'): ?>\n"
          . "  <?php require __DIR__ . '/includes/pages/{$info['page']}.php'; ?>\n";
    array_splice($newLines, $startLine, $info['endLine'] - $startLine, [$stub]);
    echo "PATCHED: {$info['page']} (line " . ($startLine + 1) . " to {$info['endLine']})\n";
}

file_put_contents($appFile, implode('', $newLines));
echo "\napp.php patched!\n";
