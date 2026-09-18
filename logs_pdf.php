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
requirePermissionOrRedirect($pdo, 'LOGS', 'VIEW');

$search = trim($_GET['search'] ?? '');
$module = trim($_GET['module'] ?? '');
$operation = trim($_GET['operation'] ?? '');
$severity = trim($_GET['severity'] ?? '');
$userId = trim($_GET['user_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = []; $params = [];
if ($module !== '') { $where[] = "LOG_MODULE = :m"; $params[':m'] = $module; }
if ($operation !== '') { $where[] = "LOG_ACTION = :op"; $params[':op'] = $operation; }
if ($severity !== '') { $where[] = "LOG_TYPE = :sev"; $params[':sev'] = $severity; }
if ($userId !== '' && ctype_digit($userId)) { $where[] = "LOG_USER_ID = :uid"; $params[':uid'] = (int)$userId; }
if ($search !== '') { $where[] = "(LOG_DESCRIPTION LIKE :s OR LOG_USER_NAME LIKE :s OR LOG_MODULE LIKE :s)"; $params[':s'] = '%'.$search.'%'; }
if ($dateFrom !== '') { $where[] = "LOG_CREATEDAT >= :df"; $params[':df'] = $dateFrom.' 00:00:00'; }
if ($dateTo !== '') { $where[] = "LOG_CREATEDAT <= :dt"; $params[':dt'] = $dateTo.' 23:59:59'; }
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$st = $pdo->prepare("
    SELECT LOG_ID, LOG_CREATEDAT, LOG_ACTION, LOG_TYPE, LOG_MODULE, LOG_DESCRIPTION, LOG_USER_NAME, LOG_IP
    FROM `LOG` $whereSql ORDER BY LOG_ID DESC LIMIT 500
");
$st->execute($params);
$rows = $st->fetchAll();

$subtitle = 'Registos: ' . count($rows);
if ($dateFrom || $dateTo) {
    $subtitle .= ' — Período: ' . ($dateFrom ?: '...') . ' a ' . ($dateTo ?: '...');
}

$pdf = ged_pdf_init('Auditoria do Sistema', $subtitle);
$pdf->SectionTitle('Registos de Auditoria');

$headers = ['ID', 'Data/Hora', 'Operação', 'Tipo', 'Módulo', 'Descrição', 'Utilizador'];
$widths  = [15, 30, 25, 20, 25, 45, 30];

$tableRows = [];
foreach ($rows as $r) {
    $tableRows[] = [
        '#' . $r['LOG_ID'],
        substr((string)$r['LOG_CREATEDAT'], 0, 16),
        $r['LOG_ACTION'] ?? '',
        $r['LOG_TYPE'] ?? '',
        $r['LOG_MODULE'] ?? '',
        $r['LOG_DESCRIPTION'] ?? '',
        $r['LOG_USER_NAME'] ?? 'system',
    ];
}

ged_pdf_table($pdf, $headers, $tableRows, $widths);

$pdf->Output('I', 'auditoria_' . date('Ymd_His') . '.pdf');