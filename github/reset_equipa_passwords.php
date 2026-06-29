<?php
/**
 * Define password "NV12345" e obriga troca no 1.º login para a equipa.
 * Corre uma vez: php github/reset_equipa_passwords.php
 */
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/schema_migrate.php';

$emails = [
    'jorge.boucas@newvision.pt',
    'artur.trindade@newvision.pt',
    'tiago.batista@newvision.pt',
    'fernando.fernandes@newvision.pt',
    'antonio.pedroso@newvision.pt',
    'carlos.goncalves@newvision.pt',
    'joao.souza@newvision.pt',
];

$hash = password_hash('NV12345', PASSWORD_DEFAULT);
$st = $pdo->prepare('UPDATE utilizadores SET password = ?, must_change_password = 1 WHERE email = ?');

foreach ($emails as $email) {
    $st->execute([$hash, $email]);
    echo $st->rowCount() ? "OK: $email\n" : "SKIP (não encontrado): $email\n";
}

echo "\nConcluído. Password temporária: NV12345 (obrigatório alterar no 1.º login).\n";
