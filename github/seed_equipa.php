<?php
/**
 * Seed da equipa NewVision.
 * Corre uma vez, depois apaga este ficheiro.
 * Requer que a coluna `area` já exista na tabela `utilizadores`:
 *   ALTER TABLE utilizadores ADD COLUMN area VARCHAR(50) NULL AFTER role;
 */
require __DIR__ . '/../includes/db.php';
/** @var PDO $pdo */

$tempPass = 'NV12345';
$hash = password_hash($tempPass, PASSWORD_DEFAULT);

$equipa = [
    // [nome, email, area, role]
    ['Jorge Bouças',       'jorge.boucas@newvision.pt',       'Laboratorio', 'user'],
    ['Artur Trindade',     'artur.trindade@newvision.pt',     'Laboratorio', 'user'],
    ['Tiago Batista',      'tiago.batista@newvision.pt',      'Escritorio',  'user'],
    ['Fernando Fernandes', 'fernando.fernandes@newvision.pt', 'Escritorio',  'user'],
    ['António Pedroso',    'antonio.pedroso@newvision.pt',    'Escritorio',  'user'],
    ['Carlos Gonçalves',   'carlos.goncalves@newvision.pt',   'Escritorio',  'user'],
    ['João Souza',         'joao.souza@newvision.pt',         'TI',          'admin'],  // IT / responsável de desenvolvimento
];

$st = $pdo->prepare("
    INSERT INTO utilizadores (nome, email, password, fotografia, role, area, must_change_password, created_at)
    VALUES (?, ?, ?, '', ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE area = VALUES(area), role = VALUES(role), password = VALUES(password), must_change_password = 1
");

foreach ($equipa as [$nome, $email, $area, $role]) {
    $st->execute([$nome, $email, $hash, $role, $area]);
    echo "OK: $nome ($area / $role)<br>\n";
}

echo '<br><strong>Concluído.</strong> Password temporária: <code>' . htmlspecialchars($tempPass) . '</code><br>';
echo '<em>Apaga este ficheiro após execução.</em>';
