<?php
require_once __DIR__ . '/bootstrap.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema_migrate.php';

$erro = '';
$sucesso = '';

$st = $pdo->prepare('SELECT must_change_password FROM utilizadores WHERE id = ?');
$st->execute([(int)$_SESSION['user_id']]);
$mustChange = (int)$st->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova = $_POST['password'] ?? '';
    $conf = $_POST['password_confirm'] ?? '';

    if (strlen($nova) < 8) {
        $erro = 'A nova palavra-passe deve ter pelo menos 8 caracteres.';
    } elseif ($nova !== $conf) {
        $erro = 'As palavras-passe não coincidem.';
    } elseif ($nova === 'NV12345') {
        $erro = 'Escolhe uma palavra-passe diferente da temporária.';
    } else {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE utilizadores SET password = ?, must_change_password = 0 WHERE id = ?')
            ->execute([$hash, (int)$_SESSION['user_id']]);
        $_SESSION['must_change_password'] = 0;
        header('Location: app.php?page=dashboard');
        exit;
    }
}

$obrigatorio = $mustChange === 1;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar palavra-passe — Stockvision</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/svg+xml" href="/icon.svg?v=14">
    <link rel="apple-touch-icon" href="/icon.svg?v=14">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #2f3540; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .box { background: #fff; border-radius: 14px; padding: 36px 32px; max-width: 440px; width: 100%; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        h1 { margin: 0 0 8px; font-size: 24px; color: #1e293b; }
        p { margin: 0 0 22px; color: #64748b; font-size: 14px; line-height: 1.5; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #475569; }
        input { width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 9px; font-size: 14px; margin-bottom: 14px; }
        input:focus { outline: none; border-color: #c9a14a; box-shadow: 0 0 0 3px rgba(201,161,74,.15); }
        button { width: 100%; padding: 13px; border: none; border-radius: 9px; background: #3f7fba; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
        button:hover { background: #346c9f; }
        .erro { background: #fee2e2; color: #991b1b; padding: 12px 14px; border-radius: 9px; margin-bottom: 16px; font-size: 14px; }
        .aviso { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px 14px; border-radius: 9px; margin-bottom: 18px; font-size: 13px; }
    </style>
</head>
<body>
<div class="box">
    <h1>Alterar palavra-passe</h1>
    <?php if ($obrigatorio): ?>
        <div class="aviso"><strong>Primeiro acesso:</strong> por segurança, define uma nova palavra-passe antes de continuar.</div>
        <p>A palavra-passe temporária deixará de funcionar após esta alteração.</p>
    <?php else: ?>
        <p>Define uma nova palavra-passe para a tua conta.</p>
    <?php endif; ?>

    <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <label for="password">Nova palavra-passe</label>
        <input type="password" id="password" name="password" required minlength="8" autofocus>

        <label for="password_confirm">Confirmar palavra-passe</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="8">

        <button type="submit">Guardar e continuar</button>
    </form>
</div>
</body>
</html>
