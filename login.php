<?php
    session_start();

    if (!empty($_SESSION['user_id'])) {
        header('Location: dashboard.php');
        exit;
    }

    $host='localhost'; $db='GED'; $user='root'; $pass=''; $charset='utf8mb4';
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (\PDOException $e) { die("Erro na conexão: " . $e->getMessage()); }

    function writeLoginLog(PDO $pdo, ?int $userId, string $userName, string $result, string $desc): void {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO `LOG`
                    (LOG_USER_ID, LOG_USER_NAME, LOG_MODULE, LOG_ACTION, LOG_TYPE,
                    LOG_DESCRIPTION, LOG_IP, LOG_USER_AGENT)
                VALUES (:uid, :uname, 'AUTH', :act, :type, :desc, :ip, :ua)"
            );
            $stmt->execute([
                ':uid'   => $userId,
                ':uname' => $userName,
                ':act'   => 'LOGIN_' . $result,
                ':type'  => $result === 'SUCCESS' ? 'SECURITY' : 'WARNING',
                ':desc'  => $desc,
                ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        } catch (\Throwable $e) { error_log('Falha ao registar log: ' . $e->getMessage()); }
    }

    function registerSession(PDO $pdo, int $userId, string $sessionId): void {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO `SESSION`
                    (SESSION_ID, USER_ID, SESSION_IP, SESSION_USER_AGENT,
                    SESSION_CREATEDAT, SESSION_LASTACTIVITY, SESSION_STATUS)
                VALUES (:sid, :uid, :ip, :ua, NOW(), NOW(), 'ACTIVE')
                ON DUPLICATE KEY UPDATE
                    SESSION_LASTACTIVITY = NOW(),
                    SESSION_STATUS = 'ACTIVE'"
            );
            $stmt->execute([
                ':sid' => $sessionId,
                ':uid' => $userId,
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        } catch (\Throwable $e) { error_log('Falha ao registar sessão: ' . $e->getMessage()); }
    }

    function profileHasDashboardAccess(PDO $pdo, int $profileId): bool {
        if (strtoupper($_SESSION['profile_code'] ?? '') === 'SUPE') {
            return true;
        }
        if ($profileId <= 0) return false;

        try {
            $st = $pdo->prepare("
                SELECT COUNT(*)
                FROM `PERMISSION`
                WHERE PROFILE_ID = :pid
                AND PERMISSION_GRANTED = 1
                AND MODULE_ID = (
                    SELECT MODULE_ID FROM `MODULE`
                    WHERE MODULE_CODE = 'DASHBOARD'
                        AND MODULE_STATUS = 1
                    LIMIT 1
                )
                AND ACTION_ID = (
                    SELECT ACTION_ID FROM `ACTION`
                    WHERE ACTION_CODE = 'VIEW'
                        AND ACTION_STATUS = 1
                    LIMIT 1
                )
            ");
            $st->execute([':pid' => $profileId]);
            return (int)$st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            error_log('profileHasDashboardAccess error: ' . $e->getMessage());
            return false;
        }
    }

    $error = '';

    if (!empty($_GET['error']) && $_GET['error'] === 'no_permission') {
        $error = 'O seu perfil não tem permissões atribuídas. Contacte o administrador.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Preencha o utilizador e a palavra-passe.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "SELECT u.USER_ID, u.USER_CODE, u.USER_FIRSTNAME, u.USER_LASTNAME,
                            u.USER_EMAIL, u.USER_PASSWORD, u.USER_STATUS,
                            u.PROFILE_ID, u.POSITION_ID,
                            p.PROFILE_CODE, p.PROFILE_NAME,
                            pos.POSITION_NAME
                    FROM `USERS` u
                    LEFT JOIN `PROFILE`  p   ON p.PROFILE_ID   = u.PROFILE_ID
                    LEFT JOIN `POSITION` pos ON pos.POSITION_ID = u.POSITION_ID
                    WHERE u.USER_CODE = :u1 OR u.USER_EMAIL = :u2
                    LIMIT 1"
                );
                $stmt->execute([':u1' => $username, ':u2' => $username]);
                $u = $stmt->fetch();

                if (!$u) {
                    $error = 'Utilizador não encontrado.';
                    writeLoginLog($pdo, null, $username, 'FAILED', 'Utilizador inexistente');

                } elseif ((int)$u['USER_STATUS'] !== 1) {
                    $error = 'A sua conta está desativada. Contacte a secretaria.';
                    writeLoginLog($pdo, (int)$u['USER_ID'], $u['USER_CODE'], 'FAILED', 'Conta inativa');

                } elseif (!password_verify($password, $u['USER_PASSWORD'])) {
                    $error = 'Palavra-passe incorreta.';
                    writeLoginLog($pdo, (int)$u['USER_ID'], $u['USER_CODE'], 'FAILED', 'Password inválida');

                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id']       = (int)$u['USER_ID'];
                    $_SESSION['user_code']     = $u['USER_CODE'];
                    $_SESSION['user_name']     = trim($u['USER_FIRSTNAME'] . ' ' . $u['USER_LASTNAME']);
                    $_SESSION['user_email']    = $u['USER_EMAIL'];
                    $_SESSION['profile_id']    = (int)$u['PROFILE_ID'];
                    $_SESSION['profile_code']  = $u['PROFILE_CODE'];
                    $_SESSION['profile_name']  = $u['PROFILE_NAME'];
                    $_SESSION['position_id']   = (int)$u['POSITION_ID'];
                    $_SESSION['position_name'] = $u['POSITION_NAME'];
                    $_SESSION['login_time']    = time();
                    $_SESSION['last_activity'] = time();

                    unset($_SESSION['__allowed_modules']);

                    $profileId = (int)$u['PROFILE_ID'];
                    $hasDashboard = profileHasDashboardAccess($pdo, $profileId);

                    if (!$hasDashboard) {
                        writeLoginLog($pdo, (int)$u['USER_ID'], $u['USER_CODE'], 'FAILED',
                                    'Perfil sem permissão para o Dashboard');
                        $_SESSION = [];
                        session_destroy();
                        $error = 'O seu perfil não tem acesso ao sistema. Contacte o administrador.';
                    } else {
                        $nm = $pdo->quote($_SESSION['user_name']);
                        $pc = $pdo->quote($_SESSION['profile_code']);
                        $ip = $pdo->quote($_SERVER['REMOTE_ADDR'] ?? '');
                        $ua = $pdo->quote(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
                        try {
                            $pdo->exec("
                                SET @app_user_id      = {$_SESSION['user_id']},
                                    @app_user_name    = $nm,
                                    @app_profile_code = $pc,
                                    @app_user_ip      = $ip,
                                    @app_user_agent   = $ua
                            ");
                        } catch (\Throwable $e) {}

                        registerSession($pdo, (int)$u['USER_ID'], session_id());
                        writeLoginLog($pdo, (int)$u['USER_ID'], $u['USER_CODE'], 'SUCCESS', 'Login bem-sucedido');

                        header('Location: dashboard.php');
                        exit;
                    }
                }
            } catch (\Throwable $e) {
                $error = 'Erro interno: ' . $e->getMessage();
                error_log('Login error: ' . $e->getMessage());
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="pt">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login — Sistema de Tramitação</title>
        <link rel="icon" type="image/svg+xml"
            href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%232c3e50'/%3E%3Cpath fill='%233498db' d='M20 12h16l12 12v28a4 4 0 0 1-4 4H20a4 4 0 0 1-4-4V16a4 4 0 0 1 4-4zm14 4v10h10L34 16z'/%3E%3Cpath fill='%23ffffff' d='M22 34h20v3H22zm0 7h20v3H22zm0 7h14v3H22z'/%3E%3C/svg%3E">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/login.css">
    </head>
    <body class="login-page">
        <div class="login-container">
            <div class="login-left">
                <i class="fas fa-file-alt logo-icon"></i>
                <h1>Sistema de Tramitação de Documentos</h1>
                <p>Plataforma integrada para gestão e acompanhamento de requerimentos académicos.</p>
                <ul class="features">
                    <li><i class="fas fa-check-circle"></i> Submissão de pedidos online</li>
                    <li><i class="fas fa-check-circle"></i> Acompanhamento em tempo real</li>
                    <li><i class="fas fa-check-circle"></i> Notificações automáticas</li>
                    <li><i class="fas fa-check-circle"></i> Histórico completo de tramitação</li>
                </ul>
            </div>
            <div class="login-right">
                <h2>Bem-vindo!</h2>
                <p class="subtitle">Faça login para acessar o sistema</p>

                <div class="alert alert-danger alert-custom <?= $error ? 'show' : '' ?>" id="loginError">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <span id="loginErrorText"><?= htmlspecialchars($error ?: 'Credenciais inválidas.') ?></span>
                </div>

                <form id="loginForm" method="POST" action="login.php" autocomplete="on">
                    <div class="form-floating-custom">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" class="form-control" id="username" name="username"
                            placeholder=" " required autocomplete="username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        <label for="username">Código ou Email</label>
                    </div>
                    <div class="form-floating-custom">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" class="form-control" id="password" name="password"
                            placeholder=" " required autocomplete="current-password">
                        <label for="password">Palavra-passe</label>
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                    <div class="form-options">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Lembrar-me</label>
                        </div>
                        <a href="#" onclick="alert('Contacte a secretaria.'); return false;">Esqueceu a palavra-passe?</a>
                    </div>
                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="fas fa-sign-in-alt me-2"></i><span id="btnText">Entrar</span>
                    </button>
                </form>

                <div class="credentials-info">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong>Primeiro acesso?</strong>
                    O código de utilizador é o seu <code>USER_CODE</code> ou o seu email.
                    A palavra-passe inicial é a sua <strong>data de nascimento no formato DDMMAAAA</strong>.
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            function togglePassword() {
                const input = document.getElementById('password');
                const icon  = document.getElementById('eyeIcon');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('fa-eye','fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('fa-eye-slash','fa-eye');
                }
            }
            document.getElementById('loginForm').addEventListener('submit', function () {
                const btn = document.getElementById('loginBtn');
                document.getElementById('btnText').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>A entrar...';
                btn.disabled = true;
            });
            document.getElementById('username').focus();
        </script>
    </body>
</html>