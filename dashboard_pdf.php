<?php
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$host='localhost'; $db='GED'; $user='root'; $pass=''; $charset='utf8mb4';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\PDOException $e) { die("Erro na conexão: " . $e->getMessage()); }

require_once 'includes/auth.php';
require_once 'includes/pdf_report.php';
requireLogin();
requirePermissionOrRedirect($pdo, 'DASHBOARD', 'VIEW');

$userId      = (int) $_SESSION['user_id'];
$profileCode = strtoupper($_SESSION['profile_code'] ?? '');
$profileName = $_SESSION['profile_name'] ?? '—';

function safeScalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (\Throwable $e) { return $default; }
}

function safeQuery(PDO $pdo, string $sql, array $params = [], $default = []) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (\Throwable $e) { return $default; }
}

function statusLabelPT(string $status): string {
    $map = [
        'DRAFT'=>'Rascunho','SUBMITTED'=>'Submetido','IN_ANALYSIS'=>'Em Análise',
        'FORWARDED'=>'Reencaminhado','IN_RESOLUTION'=>'Em Resolução','RESOLVED'=>'Resolvido',
        'RETURNED'=>'Devolvido','CLOSED'=>'Fechado','REJECTED'=>'Rejeitado','CANCELLED'=>'Cancelado',
    ];
    return $map[$status] ?? $status;
}

function applyStatusLabels(array $rows, string $column): array {
    foreach ($rows as &$r) {
        if (isset($r[$column])) {
            $r[$column] = statusLabelPT($r[$column]);
        }
    }
    return $rows;
}

$kpis = [];
$tableTitle = 'Dados';
$recentDocs = [];

if ($profileCode === 'ESTU') {
    $kpis['Pedidos Em Andamento']  = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:u AND DOCUMENT_STATUS NOT IN ('CLOSED','REJECTED','CANCELLED')", [':u'=>$userId]);
    $kpis['Prontos para Levantar'] = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:u AND DOCUMENT_STATUS IN ('RESOLVED','CLOSED')", [':u'=>$userId]);
    $tableTitle = 'Meus Pedidos';
    $recentDocs = safeQuery($pdo,
        "SELECT d.DOCUMENT_CODE AS `Código`, t.DOCUMENT_TYPE_NAME AS `Tipo`,
                d.DOCUMENT_STATUS AS `Estado`, DATE_FORMAT(d.DOCUMENT_CREATEDAT, '%d/%m/%Y') AS `Data`
         FROM `DOCUMENT` d
         LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
         WHERE d.USER_ID = :u ORDER BY d.DOCUMENT_ID DESC LIMIT 50", [':u'=>$userId]);
    $recentDocs = applyStatusLabels($recentDocs, 'Estado');

} elseif ($profileCode === 'SECR') {
    $kpis['Pendentes na Secretaria'] = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS') OR DOCUMENT_CURRENT_STEP=1");
    $kpis['Despachados Este Mês']    = safeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` WHERE FROM_USER_ID=:u AND PROCESSING_ACTION IN ('FORWARD','RESOLVE','RETURN','CLOSE') AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
    $tableTitle = 'Requerimentos Pendentes';
    $recentDocs = safeQuery($pdo,
        "SELECT d.DOCUMENT_CODE AS `Código`, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`,
                t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`,
                DATE_FORMAT(d.DOCUMENT_CREATEDAT, '%d/%m/%Y') AS `Data`
         FROM `DOCUMENT` d
         LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
         LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
         WHERE d.DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS') OR d.DOCUMENT_CURRENT_STEP=1
         ORDER BY d.DOCUMENT_ID DESC LIMIT 50");
    $recentDocs = applyStatusLabels($recentDocs, 'Estado');

} elseif ($profileCode === 'DOCE') {
    $kpis['Atribuídos a Mim']   = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_ASSIGNED_TO=:u AND DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')", [':u'=>$userId]);
    $kpis['Pareceres Este Mês'] = safeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` WHERE FROM_USER_ID=:u AND PROCESSING_ACTION IN ('RESOLVE','RETURN') AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
    $tableTitle = 'Documentos Atribuídos a Mim';
    $recentDocs = safeQuery($pdo,
        "SELECT d.DOCUMENT_CODE AS `Código`, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`,
                t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`,
                DATE_FORMAT(d.DOCUMENT_CREATEDAT, '%d/%m/%Y') AS `Data`
         FROM `DOCUMENT` d
         LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
         LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
         WHERE d.DOCUMENT_ASSIGNED_TO=:u AND d.DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')
         ORDER BY d.DOCUMENT_ID DESC LIMIT 50", [':u'=>$userId]);
    $recentDocs = applyStatusLabels($recentDocs, 'Estado');

} elseif ($profileCode === 'DIRE') {
    $kpis['Pendentes']            = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')");
    $kpis['Despachados Este Mês'] = safeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` WHERE FROM_USER_ID=:u AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
    $tableTitle = 'Todos os Requerimentos Pendentes';
    $recentDocs = safeQuery($pdo,
        "SELECT d.DOCUMENT_CODE AS `Código`, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`,
                t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`,
                DATE_FORMAT(d.DOCUMENT_CREATEDAT, '%d/%m/%Y') AS `Data`
         FROM `DOCUMENT` d
         LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
         LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
         WHERE d.DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')
         ORDER BY d.DOCUMENT_ID DESC LIMIT 50");
    $recentDocs = applyStatusLabels($recentDocs, 'Estado');

} elseif ($profileCode === 'ADMI') {
    $kpis['Utilizadores'] = safeScalar($pdo, "SELECT COUNT(*) FROM `USERS` WHERE PROFILE_ID NOT IN (SELECT PROFILE_ID FROM PROFILE WHERE PROFILE_CODE='SUPE')");
    $kpis['Perfis']       = safeScalar($pdo, "SELECT COUNT(*) FROM `PROFILE`");
    $kpis['Módulos']      = safeScalar($pdo, "SELECT COUNT(*) FROM `MODULE`");
    $kpis['Cargos']       = safeScalar($pdo, "SELECT COUNT(*) FROM `POSITION`");
    $tableTitle = 'Estatísticas do Sistema';
    $recentDocs = safeQuery($pdo,
        "SELECT 'Utilizadores Ativos' AS `Métrica`, CAST(COUNT(*) AS CHAR) AS `Valor` FROM `USERS` WHERE USER_STATUS=1
         UNION ALL SELECT 'Perfis Ativos', CAST(COUNT(*) AS CHAR) FROM `PROFILE` WHERE PROFILE_STATUS=1
         UNION ALL SELECT 'Cargos Ativos', CAST(COUNT(*) AS CHAR) FROM `POSITION` WHERE POSITION_STATUS=1
         UNION ALL SELECT 'Módulos Ativos', CAST(COUNT(*) AS CHAR) FROM `MODULE` WHERE MODULE_STATUS='ACTIVE'
         UNION ALL SELECT 'Ações Ativas', CAST(COUNT(*) AS CHAR) FROM `ACTION` WHERE ACTION_STATUS=1
         UNION ALL SELECT 'Documentos Totais', CAST(COUNT(*) AS CHAR) FROM `DOCUMENT`
         UNION ALL SELECT 'Registos de Auditoria', CAST(COUNT(*) AS CHAR) FROM `LOG`");

} elseif ($profileCode === 'SUPE') {
    $kpis['Utilizadores'] = safeScalar($pdo, "SELECT COUNT(*) FROM `USERS`");
    $kpis['Perfis']       = safeScalar($pdo, "SELECT COUNT(*) FROM `PROFILE`");
    $kpis['Módulos']      = safeScalar($pdo, "SELECT COUNT(*) FROM `MODULE`");
    $kpis['Cargos']       = safeScalar($pdo, "SELECT COUNT(*) FROM `POSITION`");
    $tableTitle = 'Estatísticas Globais';
    $recentDocs = safeQuery($pdo,
        "SELECT 'Utilizadores Totais' AS `Métrica`, CAST(COUNT(*) AS CHAR) AS `Valor` FROM `USERS`
         UNION ALL SELECT 'Perfis Totais', CAST(COUNT(*) AS CHAR) FROM `PROFILE`
         UNION ALL SELECT 'Cargos Totais', CAST(COUNT(*) AS CHAR) FROM `POSITION`
         UNION ALL SELECT 'Módulos Totais', CAST(COUNT(*) AS CHAR) FROM `MODULE`
         UNION ALL SELECT 'Ações Totais', CAST(COUNT(*) AS CHAR) FROM `ACTION`
         UNION ALL SELECT 'Permissões', CAST(COUNT(*) AS CHAR) FROM `PERMISSION`
         UNION ALL SELECT 'Documentos', CAST(COUNT(*) AS CHAR) FROM `DOCUMENT`
         UNION ALL SELECT 'Sessões Ativas', CAST(COUNT(*) AS CHAR) FROM `SESSION` WHERE SESSION_STATUS='ACTIVE'
         UNION ALL SELECT 'Registos de Auditoria', CAST(COUNT(*) AS CHAR) FROM `LOG`");
}

$pdf = ged_pdf_init(
    'Relatorio da Dashboard',
    'Perfil: ' . $profileName . ' (' . $profileCode . ') — ' . date('d/m/Y H:i')
);

$pdf->SectionTitle('Indicadores Principais');
ged_pdf_kpis($pdf, $kpis);

if (!empty($recentDocs)) {
    $pdf->SectionTitle($tableTitle);
    $headers = array_keys($recentDocs[0]);
    $rows = [];
    foreach ($recentDocs as $d) { $rows[] = array_values($d); }

    $colCount = count($headers);
    if ($colCount === 5)      $widths = [30, 70, 30, 25, 25];
    elseif ($colCount === 4)  $widths = [35, 60, 35, 30];
    elseif ($colCount === 3)  $widths = [50, 80, 40];
    elseif ($colCount === 2)  $widths = [130, 50];
    else { $widths = []; foreach ($headers as $h) $widths[] = 180 / max(1, $colCount); }

    ged_pdf_table($pdf, $headers, $rows, $widths);
}

$pdf->Output('I', 'dashboard_' . $profileCode . '_' . date('Ymd_His') . '.pdf');