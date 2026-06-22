<?php

require_once __DIR__ . '/bootstrap.php';

session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SESSION['user_id'])) 
    {
    header('Location: app.php?page=dashboard');
    exit;
    }

require_once __DIR__ . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
$erro = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $erro = 'Preenche o email e a palavra-passe.';
    } else {
        $stmt = $pdo->prepare("SELECT id, nome, email, password, fotografia, role, area FROM utilizadores WHERE email = ?");
        $stmt->execute([$email]);
        $userData = $stmt->fetch();

        if ($userData && password_verify($password, $userData['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $userData['id'];
            $_SESSION['user_nome'] = $userData['nome'];
            $_SESSION['user_email'] = $userData['email'];
            $_SESSION['user_fotografia'] = $userData['fotografia'] ?? '';
            $_SESSION['user_role'] = $userData['role'] ?? 'user';
            $_SESSION['user_area'] = $userData['area'] ?? '';
            $_SESSION['LAST_ACTIVITY'] = time();

            session_write_close();

            header('Location: app.php?page=dashboard');
            exit;
        } else {
            $erro = 'Utilizador ou palavra-passe incorretos.';
        }
    }
}



?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Stockvision</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
          margin: 0;
          font-family: Arial, sans-serif;
          background: #2f3540;
          height: 100vh;  
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 0;      
        }

        .login-wrapper {
          width: 100vw;    
          height: 100vh;   
          background: #ffffff;
          border-radius: 0; 
          overflow: hidden;
          display: flex;
          box-shadow: none; 
        }
        

        .login-left {
          width: 42%;
          background: #ffffff;
          padding: 60px 40px 60px; 
          display: flex;
          flex-direction: column;
          justify-content: center; 
          min-height: 100vh;
        }

        .login-left-top {
            width: 100%;
        }

        .logo {
            max-width: 280px;
            margin-bottom: 34px;
        }

        .logo img {
            width: 100%;
            height: auto;
            display: block;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
            color: #26313f;
        }

        .subtitle {
            margin: 0 0 28px;
            color: #6a7179;
            font-size: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 15px;
            color: #444;
        }

        input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #d6dbe1;
            border-radius: 10px;
            font-size: 15px;
            margin-bottom: 18px;
            outline: none;
            transition: 0.2s;
        }

        input:focus {
            border-color: #4a89c7;
            box-shadow: 0 0 0 3px rgba(74, 137, 199, 0.12);
        }

        button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #3f7fba;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
        }

        button:hover {
            background: #346c9f;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .footer-note {
            text-align: center;
            color: #c8c8c8;
            font-size: 13px;
            margin-top: 24px;
        }

        .login-right {
          width: 58%;
          position: relative;
          background: url('assets/bg-login.jpg') center center / cover no-repeat;
          display: flex;
          align-items: flex-end;
          padding: 34px 30px;
        }

        .login-right::before {
          content: "";
          position: absolute;
          inset: 0;
          background: linear-gradient(to top, rgba(0,0,0,0.42), rgba(0,0,0,0.08));
        }

        .brand-top {
          position: absolute;
          top: 22px;
          right: 24px;
          z-index: 2;
        }

        .brand-top img{
          width: 120px;
          height: auto;
          display:block;
        }

        .right-content {
            position: relative;
            z-index: 2;
            color: #fff;
            max-width: 640px;
        }

        .right-content h2 {
            margin: 0 0 12px;
            font-size: 28px;
            line-height: 1.15;
        }

        .right-content p {
            margin: 0;
            font-size: 16px;
            line-height: 1.5;
        }

        @media (max-width: 980px) {
            .login-wrapper {
                flex-direction: column;
                min-height: auto;
            }

            .login-left,
            .login-right {
                width: 100%;
            }

            .login-right {
                min-height: 320px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-left">
            <div class="login-left-top">
                <div class="logo">
                    <img src="assets/stock.vision.png" alt="Stockvision">
                </div>

                <h1>Bem-vindo</h1>
                <p class="subtitle">Inicie sessão na sua conta</p>

                <?php if ($erro): ?>
                    <div class="erro"><?= htmlspecialchars($erro) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required>

                    <label for="password">Palavra-passe</label>
                    <input type="password" id="password" name="password" required>

                    <button type="submit">Entrar</button>
                </form>
            </div>

            <div class="footer-note">© 2025 Newvision Technology Centre</div>
        </div>

        <div class="login-right">
            <div class="brand-top">
              <img src="assets/newvision-logo.png" alt="Newvision">
                </div>

            <div class="right-content">
                <h2>Gestão Inteligente de Stocks</h2>
                <p>Simplifica o Planeamento de Inventário, Ordens de Serviço e PAT's.</p>
            </div>
        </div>
    </div>
</body>
</html>