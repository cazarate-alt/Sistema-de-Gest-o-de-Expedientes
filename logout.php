<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=GED;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $pdo->prepare(
            "INSERT INTO `LOG`
                (LOG_USER_ID, LOG_USER_NAME, LOG_MODULE, LOG_ACTION, LOG_TYPE,
                 LOG_DESCRIPTION, LOG_IP, LOG_USER_AGENT)
             VALUES
                (:uid, :un, 'AUTH', 'LOGOUT', 'SECURITY',
                 'Logout do sistema', :ip, :ua)"
        )->execute([
            ':uid' => $_SESSION['user_id'],
            ':un'  => $_SESSION['user_name'] ?? '',
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

        $pdo->prepare(
            "UPDATE `SESSION`
             SET SESSION_STATUS = 'LOGGED_OUT',
                 SESSION_LOGGEDOUTAT = NOW()
             WHERE SESSION_ID = :sid"
        )->execute([':sid' => session_id()]);

    } catch (\Throwable $e) {
        error_log('Falha ao registar logout: ' . $e->getMessage());
    }
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]);
}
session_destroy();

header('Location: login.php');
exit;