<?php
$lines = file('C:/laragon/www/nvcloud/app.php');
foreach ($lines as $i => $l) {
    $t = trim($l);
    // Print section headers with their title lines
    if (strpos($t, '// ===') === 0) {
        // also print next non-=== line
        $next = trim($lines[$i+1] ?? '');
        if (strpos($next, '// ===') === 0 || $next === '') {
            $next = trim($lines[$i+2] ?? '');
        }
        if (strpos($next, '//') === 0) {
            echo ($i+1) . ': ' . $next . PHP_EOL;
        }
    }
    if (strpos($t, '// HAND') === 0 || strpos($t, '// ----') === 0 || strpos($t, '// -----') === 0) {
        echo ($i+1) . ': ' . $t . PHP_EOL;
    }
}
