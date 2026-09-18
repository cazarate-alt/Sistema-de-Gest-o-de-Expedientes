<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function($no, $str, $file, $line) {
    echo json_encode(['success'=>false,'message'=>'Erro PHP: '.$str.' em '.basename($file).':'.$line]);
    exit;
});
set_exception_handler(function($e) {
    echo json_encode(['success'=>false,'message'=>'Exceção: '.$e->getMessage()]);
    exit;
});

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Sessão expirada.']);
    exit;
}

$host='localhost'; $db='GED'; $user='root'; $pass=''; $charset='utf8mb4';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'Erro de conexão: '.$e->getMessage()]);
    exit;
}

require_once 'includes/auth.php';
require_once 'includes/notification_helper.php';
requireLogin();

(function() use ($pdo) {
    $id = (int)($_SESSION['user_id'] ?? 0);
    $nm = $pdo->quote($_SESSION['user_name'] ?? '');
    $pc = $pdo->quote($_SESSION['profile_code'] ?? '');
    $ip = $pdo->quote($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = $pdo->quote(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
    try {
        $pdo->exec("SET @app_user_id = $id, @app_user_name = $nm,
                    @app_profile_code = $pc, @app_user_ip = $ip, @app_user_agent = $ua");
    } catch (\Throwable $e) {}
})();

$userId      = (int)$_SESSION['user_id'];
$myProfileId = (int)$_SESSION['profile_id'];
$profileCode = strtoupper($_SESSION['profile_code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Método inválido.']);
    exit;
}

$action = $_POST['action'] ?? '';
$docId  = (int)($_POST['document_id'] ?? 0);
$note   = trim($_POST['note'] ?? '');

if ($docId <= 0) {
    echo json_encode(['success'=>false,'message'=>'Documento inválido.']);
    exit;
}

$st = $pdo->prepare("
    SELECT d.*,
           f.FLOW_CODE,
           (SELECT fs.FLOW_STEP_ID FROM `FLOW_STEP` fs WHERE fs.FLOW_ID = d.FLOW_ID AND fs.FLOW_STEP_ORDER = d.DOCUMENT_CURRENT_STEP LIMIT 1) AS CUR_STEP_ID,
           (SELECT fs.PROFILE_ID FROM `FLOW_STEP` fs WHERE fs.FLOW_ID = d.FLOW_ID AND fs.FLOW_STEP_ORDER = d.DOCUMENT_CURRENT_STEP LIMIT 1) AS CUR_PROFILE_ID,
           (SELECT fs.FLOW_STEP_IS_FINAL FROM `FLOW_STEP` fs WHERE fs.FLOW_ID = d.FLOW_ID AND fs.FLOW_STEP_ORDER = d.DOCUMENT_CURRENT_STEP LIMIT 1) AS CUR_IS_FINAL,
           (SELECT fs.FLOW_STEP_ACTION FROM `FLOW_STEP` fs WHERE fs.FLOW_ID = d.FLOW_ID AND fs.FLOW_STEP_ORDER = d.DOCUMENT_CURRENT_STEP LIMIT 1) AS CUR_ACTION
    FROM `DOCUMENT` d
    LEFT JOIN `FLOW` f ON f.FLOW_ID = d.FLOW_ID
    WHERE d.DOCUMENT_ID = :id
");
$st->execute([':id' => $docId]);
$doc = $st->fetch();

if (!$doc) { echo json_encode(['success'=>false,'message'=>'Documento não encontrado.']); exit; }

if ($profileCode !== 'SUPE' && (int)$doc['CUR_PROFILE_ID'] !== $myProfileId) {
    echo json_encode(['success'=>false,'message'=>'Não tem permissão para agir neste passo. O documento está atribuído a outro perfil.']);
    exit;
}

if (in_array($doc['DOCUMENT_STATUS'], ['CLOSED','CANCELLED'], true)) {
    echo json_encode(['success'=>false,'message'=>'Este documento já está fechado e não pode ser alterado.']);
    exit;
}

$getSecrUser = function() use ($pdo) {
    try { $st = $pdo->query("SELECT USER_ID FROM `USERS` WHERE USER_CODE='ANAMUI' LIMIT 1"); return (int)$st->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
};
$getSecrProfile = function() use ($pdo) {
    try { $st = $pdo->query("SELECT PROFILE_ID FROM `PROFILE` WHERE PROFILE_CODE='SECR' LIMIT 1"); return (int)$st->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
};
$logProc = function($fromU, $fromP, $toU, $toP, $procAction, $n) use ($pdo, $docId) {
    $pdo->prepare("
        INSERT INTO `PROCESSING` (DOCUMENT_ID, FROM_USER_ID, FROM_PROFILE_ID, TO_USER_ID, TO_PROFILE_ID, PROCESSING_ACTION, PROCESSING_NOTE)
        VALUES (:d, :fu, :fp, :tu, :tp, :ac, :n)
    ")->execute([':d'=>$docId, ':fu'=>$fromU, ':fp'=>$fromP, ':tu'=>$toU, ':tp'=>$toP, ':ac'=>$procAction, ':n'=>$n]);
    return (int)$pdo->lastInsertId();
};
$logComment = function($procId, $text, $isPrivate=0) use ($pdo, $userId, $docId) {
    $pdo->prepare("INSERT INTO `DOCUMENT_COMMENT` (DOCUMENT_ID, PROCESSING_ID, USER_ID, COMMENT_TEXT, COMMENT_ISPRIVATE) VALUES (:d,:p,:u,:t,:ip)")
        ->execute([':d'=>$docId, ':p'=>$procId, ':u'=>$userId, ':t'=>$text, ':ip'=>$isPrivate]);
};

$docUrl = 'document_view.php?id=' . $docId;

try {
    $pdo->beginTransaction();

    if ($action === 'resolve') {
        if ($profileCode !== 'SECR' && $profileCode !== 'SUPE') throw new Exception('Apenas a Secretaria pode resolver e entregar documentos.');
        $pdo->prepare("UPDATE `DOCUMENT` SET DOCUMENT_STATUS='RESOLVED', DOCUMENT_ASSIGNED_TO=:uid, DOCUMENT_CLOSEDAT=NOW() WHERE DOCUMENT_ID=:id")
            ->execute([':uid'=>$doc['USER_ID'], ':id'=>$docId]);
        $procId = $logProc($userId, $myProfileId, $doc['USER_ID'], null, 'RESOLVE', $note ?: 'Resolvido e entregue ao Estudante');
        $logComment($procId, $note ?: 'Documento resolvido e entregue pela Secretaria.', 0);
        createNotification($pdo, (int)$doc['USER_ID'], $docId, 'RESOLVE', 'Documento resolvido: ' . $doc['DOCUMENT_CODE'], $note ?: 'O seu documento foi resolvido pela Secretaria.', $docUrl);
        echo json_encode(['success'=>true, 'message'=>'Documento resolvido e entregue ao Estudante.']);
    }

    elseif ($action === 'forward') {
        if (!in_array($profileCode, ['SECR','DOCE','DIRE','SUPE'], true)) throw new Exception('Sem permissão para encaminhar.');
        if (empty($doc['FLOW_ID'])) throw new Exception('Este documento não tem fluxo associado.');

        $nextOrder = (int)$doc['DOCUMENT_CURRENT_STEP'] + 1;
        $st = $pdo->prepare("SELECT fs.PROFILE_ID, fs.FLOW_STEP_IS_FINAL, fs.FLOW_STEP_NAME FROM `FLOW_STEP` fs WHERE fs.FLOW_ID = :fid AND fs.FLOW_STEP_ORDER = :ord AND fs.FLOW_STEP_STATUS = 1 LIMIT 1");
        $st->execute([':fid'=>$doc['FLOW_ID'], ':ord'=>$nextOrder]);
        $next = $st->fetch();
        if (!$next) throw new Exception('Não existe próximo passo ativo neste fluxo. Este é o passo final.');

        $st = $pdo->prepare("SELECT USER_ID FROM `USERS` WHERE PROFILE_ID=:pid AND USER_STATUS=1 ORDER BY USER_ID ASC LIMIT 1");
        $st->execute([':pid'=>$next['PROFILE_ID']]);
        $nextUser = (int)$st->fetchColumn();
        if (!$nextUser) throw new Exception('Não existe utilizador ativo no perfil destino.');

        $pdo->prepare("UPDATE `DOCUMENT` SET DOCUMENT_STATUS='FORWARDED', DOCUMENT_CURRENT_STEP=:step, DOCUMENT_ASSIGNED_TO=:uid WHERE DOCUMENT_ID=:id")
            ->execute([':step'=>$nextOrder, ':uid'=>$nextUser, ':id'=>$docId]);

        $logProc($userId, $myProfileId, $nextUser, $next['PROFILE_ID'], 'FORWARD', $note ?: ('Encaminhado para ' . $next['FLOW_STEP_NAME']));
        createNotification($pdo, $nextUser, $docId, 'FORWARD', 'Novo documento para si: ' . $doc['DOCUMENT_CODE'], 'Foi-lhe encaminhado o documento "' . $doc['DOCUMENT_SUBJECT'] . '".', $docUrl);

        if ((int)$doc['USER_ID'] !== $nextUser && (int)$doc['USER_ID'] !== $userId) {
            createNotification($pdo, (int)$doc['USER_ID'], $docId, 'FORWARD', 'O seu documento avançou: ' . $doc['DOCUMENT_CODE'], 'O documento foi encaminhado para ' . $next['FLOW_STEP_NAME'] . '.', $docUrl);
        }
        echo json_encode(['success'=>true, 'message'=>'Documento encaminhado para ' . $next['FLOW_STEP_NAME'] . '.']);
    }

    elseif ($action === 'return') {
        if (!in_array($profileCode, ['DOCE','DIRE','SUPE'], true)) throw new Exception('Apenas o Docente ou o Director podem devolver à Secretaria.');
        $secUser = $getSecrUser(); $secProfile = $getSecrProfile();
        if (!$secUser || !$secProfile) throw new Exception('Secretaria não encontrada no sistema.');

        $pdo->prepare("UPDATE `DOCUMENT` SET DOCUMENT_STATUS='RETURNED', DOCUMENT_CURRENT_STEP=1, DOCUMENT_ASSIGNED_TO=:uid WHERE DOCUMENT_ID=:id")
            ->execute([':uid'=>$secUser, ':id'=>$docId]);

        $logProc($userId, $myProfileId, $secUser, $secProfile, 'RETURN', $note ?: 'Devolvido à Secretaria pelo ' . $profileCode);
        createNotification($pdo, $secUser, $docId, 'RETURN', 'Documento devolvido: ' . $doc['DOCUMENT_CODE'], 'O documento foi devolvido à Secretaria por ' . $profileCode . '.', $docUrl);
        echo json_encode(['success'=>true, 'message'=>'Documento devolvido à Secretaria.']);
    }

    elseif ($action === 'deliver') {
        if ($profileCode !== 'SECR' && $profileCode !== 'SUPE') throw new Exception('Apenas a Secretaria pode entregar documentos ao Estudante.');
        $pdo->prepare("UPDATE `DOCUMENT` SET DOCUMENT_STATUS='RESOLVED', DOCUMENT_ASSIGNED_TO=:uid, DOCUMENT_CLOSEDAT=NOW() WHERE DOCUMENT_ID=:id")
            ->execute([':uid'=>$doc['USER_ID'], ':id'=>$docId]);
        $procId = $logProc($userId, $myProfileId, $doc['USER_ID'], null, 'CLOSE', $note ?: 'Documento entregue ao Estudante');
        $logComment($procId, $note ?: 'Documento final entregue pela Secretaria.', 0);
        createNotification($pdo, (int)$doc['USER_ID'], $docId, 'CLOSE', 'Documento pronto para levantamento: ' . $doc['DOCUMENT_CODE'], 'O seu documento está disponível. Pode agora imprimir o comprovativo.', $docUrl);
        echo json_encode(['success'=>true, 'message'=>'Documento entregue ao Estudante.']);
    }

    elseif ($action === 'reject') {
        if ($profileCode !== 'DIRE' && $profileCode !== 'SUPE') throw new Exception('Apenas o Director pode rejeitar documentos.');
        $secUser = $getSecrUser(); $secProfile = $getSecrProfile();
        if (!$secUser || !$secProfile) throw new Exception('Secretaria não encontrada no sistema.');

        $pdo->prepare("UPDATE `DOCUMENT` SET DOCUMENT_STATUS='REJECTED', DOCUMENT_ASSIGNED_TO=:uid WHERE DOCUMENT_ID=:id")
            ->execute([':uid'=>$secUser, ':id'=>$docId]);

        $logProc($userId, $myProfileId, $secUser, $secProfile, 'REJECT', $note ?: 'Documento rejeitado pelo Director');
        createNotification($pdo, $secUser, $docId, 'REJECT', 'Documento rejeitado: ' . $doc['DOCUMENT_CODE'], $note ?: 'O Director rejeitou o documento.', $docUrl);
        createNotification($pdo, (int)$doc['USER_ID'], $docId, 'REJECT', 'Documento rejeitado: ' . $doc['DOCUMENT_CODE'], 'O seu documento foi rejeitado.', $docUrl);
        echo json_encode(['success'=>true, 'message'=>'Documento rejeitado e devolvido à Secretaria.']);
    }

    else throw new Exception('Ação desconhecida: ' . $action);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}