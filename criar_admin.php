<?php
$host = 'localhost';
$db = 'stocks_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    $nome = 'Andre Silva';
    $email = 'andre.silva@newvision.pt';
    $password = '123456';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("DELETE FROM utilizadores WHERE email = ?");
    $stmt->execute([$email]);

    $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, email, password, fotografia, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$nome, $email, $passwordHash, '']);

    echo 'Utilizador criado com sucesso.';
} catch (Exception $e) {
    echo 'Erro: ' . $e->getMessage();
}
?>