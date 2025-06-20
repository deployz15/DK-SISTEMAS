<?php
session_start();
require_once 'conexao.php'; // Arquivo PDO

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cnpj = trim($_POST['cnpj_loja'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';

    // Busca usuário (senha em texto puro)
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE cnpj_loja = :cnpj_loja AND usuario = :usuario");
    $stmt->execute([':cnpj_loja' => $cnpj, ':usuario' => $usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['senha_hash'] === $senha) { // senha em texto puro
        $_SESSION['usuario_logado'] = true;
        $_SESSION['nome_usuario_logado'] = $user['usuario'];
        $_SESSION['cnpj_loja_logada'] = $user['cnpj_loja'];
        header('Location: dashboard.php');
        exit;
    } else {
        $erro = 'Usuário ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login - Davi Sistemas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Google Fonts para um visual moderno -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700&display=swap" rel="stylesheet">
    <!-- Ícones -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --main-bg: linear-gradient(120deg, #6a11cb 0%, #2575fc 100%);
            --card-bg: rgba(255,255,255,0.95);
            --input-bg: rgba(245,248,255,0.7);
            --accent: #2575fc;
            --accent-dark: #19335a;
            --error: #f44336;
            --shadow: 0 8px 40px rgba(50,80,120,0.18);
        }
        * { box-sizing: border-box; }
        html, body {
            min-height: 100vh;
            margin: 0;
            padding: 0;
            font-family: 'Montserrat', Arial, sans-serif;
            background: var(--main-bg);
            overflow-x: hidden;
        }
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: var(--card-bg);
            border-radius: 22px;
            box-shadow: var(--shadow);
            padding: 54px 40px 38px 40px;
            min-width: 350px;
            max-width: 370px;
            width: 100%;
            position: relative;
            overflow: hidden;
            animation: float-in 1.2s cubic-bezier(.23,1.23,.72,0.75);
        }
        @keyframes float-in {
            0% { opacity: 0; transform: translateY(60px) scale(0.95);}
            60% { opacity: 1; transform: translateY(-8px) scale(1.03);}
            100% { opacity: 1; transform: translateY(0) scale(1);}
        }
        .brand-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .brand-header i {
            font-size: 48px;
            color: var(--accent);
            animation: spin-in 1.5s cubic-bezier(.58,.08,.56,.97);
        }
        @keyframes spin-in {
            from { transform: rotate(-90deg) scale(0.6); opacity: 0;}
            to   { transform: rotate(0deg) scale(1); opacity: 1;}
        }
        .brand-header h2 {
            margin: 8px 0 0 0;
            font-weight: 700;
            color: var(--accent-dark);
            font-size: 2rem;
            letter-spacing: 1px;
        }
        .brand-header span {
            font-size: 1rem;
            color: #999;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        .erro {
            color: var(--error);
            text-align: center;
            margin-bottom: 18px;
            letter-spacing: 0.2px;
            font-weight: 500;
            background: #ffeaea;
            padding: 9px 0;
            border-radius: 7px;
            animation: shake 0.5s;
        }
        @keyframes shake {
            10%, 90% { transform: translateX(-2px); }
            20%, 80% { transform: translateX(4px); }
            30%, 50%, 70% { transform: translateX(-8px);}
            40%, 60% { transform: translateX(8px);}
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 8px;
        }
        .input-group {
            position: relative;
            transition: box-shadow 0.2s;
        }
        .input-group i {
            position: absolute;
            left: 12px;
            top: 12px;
            font-size: 1rem;
            color: #bababa;
            transition: color 0.2s;
        }
        .input-group input {
            width: 100%;
            padding: 12px 14px 12px 38px;
            border: 1px solid #dde7fa;
            border-radius: 9px;
            background: var(--input-bg);
            font-size: 1.05rem;
            color: #222;
            outline: none;
            box-shadow: 0 1px 0 #f3f5fa;
            transition: border 0.2s, box-shadow 0.2s;
        }
        .input-group input:focus {
            border: 1.5px solid var(--accent);
            box-shadow: 0 2px 12px rgba(37,117,252,0.09);
        }
        .input-group input:focus + i,
        .input-group input:not(:placeholder-shown) + i {
            color: var(--accent);
        }
        button[type="submit"] {
            margin-top: 8px;
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 13px 0;
            border-radius: 8px;
            font-size: 1.16rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            cursor: pointer;
            box-shadow: 0 5px 18px rgba(37,117,252,0.13);
            transition: background 0.22s, transform 0.13s;
        }
        button[type="submit"]:hover {
            background: #3e5e8e;
            transform: translateY(-1px) scale(1.03);
        }
        .footer {
            text-align: center;
            margin-top: 26px;
            color: #8d9cb5;
            font-size: 0.97rem;
        }
        @media (max-width: 480px) {
            .login-card {
                padding: 32px 8px 24px 8px;
                min-width: 90vw;
            }
        }
        /* Animação de fundo com bolhas */
        .bubbles-bg {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .bubble {
            position: absolute;
            border-radius: 50%;
            opacity: 0.17;
            background: linear-gradient(135deg, #2575fc 40%, #6a11cb 100%);
            animation: bubble-move 14s infinite;
        }
        .bubble:nth-child(1) { width: 90px; height: 90px; left: 5%; top: 80%; animation-delay: 0s;}
        .bubble:nth-child(2) { width: 40px; height: 40px; left: 70%; top: 60%; animation-delay: 2s;}
        .bubble:nth-child(3) { width: 120px; height: 120px; left: 85%; top: 70%; animation-delay: 4s;}
        .bubble:nth-child(4) { width: 60px; height: 60px; left: 25%; top: 90%; animation-delay: 1.4s;}
        .bubble:nth-child(5) { width: 110px; height: 110px; left: 55%; top: 100%; animation-delay: 0.8s;}
        .bubble:nth-child(6) { width: 32px; height: 32px; left: 82%; top: 82%; animation-delay: 3.2s;}
        @keyframes bubble-move {
            0%   { transform: translateY(0) scale(1);}
            100% { transform: translateY(-120vh) scale(1.18);}
        }
    </style>
</head>
<body>
    <div class="bubbles-bg">
        <div class="bubble"></div>
        <div class="bubble"></div>
        <div class="bubble"></div>
        <div class="bubble"></div>
        <div class="bubble"></div>
        <div class="bubble"></div>
    </div>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="brand-header">
                <i class="fa-solid fa-cube"></i>
                <h2>Davi Sistemas</h2>
                <span>Sistema de Login</span>
            </div>
            <?php if ($erro): ?>
                <div class="erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <div class="input-group">
                    <input type="text" name="cnpj_loja" id="cnpj_loja" required pattern="\d{14}" placeholder=" " autocomplete="off">
                    <i class="fa-solid fa-building"></i>
                    <label for="cnpj_loja" style="display:none;">CNPJ da Loja</label>
                </div>
                <div class="input-group">
                    <input type="text" name="usuario" id="usuario" required placeholder=" " autocomplete="off">
                    <i class="fa-solid fa-user"></i>
                    <label for="usuario" style="display:none;">Usuário</label>
                </div>
                <div class="input-group">
                    <input type="password" name="senha" id="senha" required placeholder=" ">
                    <i class="fa-solid fa-lock"></i>
                    <label for="senha" style="display:none;">Senha</label>
                </div>
                <button type="submit">Entrar</button>
            </form>
            <div class="footer">
                &copy; <?php echo date('Y'); ?> Davi Sistemas.<br>
                <small>Todos os direitos reservados.</small>
            </div>
        </div>
    </div>
    <script>
        // Pequena animação nos campos ao focar
        document.querySelectorAll('.input-group input').forEach(function(input) {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focus');
            });
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focus');
            });
        });
    </script>
</body>
</html>