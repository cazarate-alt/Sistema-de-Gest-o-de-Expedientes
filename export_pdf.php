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

$tipo = strtolower(trim($_GET['tipo'] ?? ''));
$userId      = (int)($_SESSION['user_id'] ?? 0);
$profileCode = strtoupper($_SESSION['profile_code'] ?? '');
$profileName = $_SESSION['profile_name'] ?? '—';
$myProfileId = (int)($_SESSION['profile_id'] ?? 0);

function statusToInt($value): int {
    if ($value === null) return 0;
    if (is_numeric($value)) return ((int)$value === 1) ? 1 : 0;
    $v = strtoupper(trim((string)$value));
    return in_array($v, ['1','ACTIVE','ATIVO','A','YES','TRUE','Y'], true) ? 1 : 0;
}
function statusLabelPT(string $status): string {
    $map = [
        'DRAFT'=>'Rascunho','SUBMITTED'=>'Submetido','IN_ANALYSIS'=>'Em Análise',
        'FORWARDED'=>'Reencaminhado','IN_RESOLUTION'=>'Em Resolução','RESOLVED'=>'Resolvido',
        'RETURNED'=>'Devolvido','CLOSED'=>'Fechado','REJECTED'=>'Rejeitado','CANCELLED'=>'Cancelado',
    ];
    return $map[$status] ?? $status;
}
function pdfSafeScalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try { $st = $pdo->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return $v === false ? $default : $v; }
    catch (\Throwable $e) { return $default; }
}
function pdfSafeQuery(PDO $pdo, string $sql, array $params = [], $default = []) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
    catch (\Throwable $e) { return $default; }
}
function buildProcessingFilter(string $code, int $uid, int $pid): array {
    if ($code === 'ESTU') return ['WHERE d.USER_ID = :u', [':u'=>$uid]];
    if ($code === 'DOCE') return ['WHERE (p.FROM_USER_ID = :u OR p.TO_USER_ID = :u)', [':u'=>$uid]];
    if ($code === 'SECR') return ['WHERE (p.FROM_PROFILE_ID = :p OR p.TO_PROFILE_ID = :p)', [':p'=>$pid]];
    return ['', []];
}

switch ($tipo) {

    case 'dashboard':
        requirePermissionOrRedirect($pdo, 'DASHBOARD', 'VIEW');
        $kpis = []; $tableTitle = 'Dados'; $recentDocs = [];

        if ($profileCode === 'ESTU') {
            $kpis['Pedidos Em Andamento']  = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:u AND DOCUMENT_STATUS NOT IN ('CLOSED','REJECTED','CANCELLED')", [':u'=>$userId]);
            $kpis['Prontos para Levantar'] = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:u AND DOCUMENT_STATUS IN ('RESOLVED','CLOSED')", [':u'=>$userId]);
            $tableTitle = 'Meus Pedidos';
            $recentDocs = pdfSafeQuery($pdo, "SELECT d.DOCUMENT_CODE AS `Código`, t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`, DATE_FORMAT(d.DOCUMENT_CREATEDAT,'%d/%m/%Y') AS `Data` FROM `DOCUMENT` d LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID WHERE d.USER_ID = :u ORDER BY d.DOCUMENT_ID DESC LIMIT 50", [':u'=>$userId]);
            foreach ($recentDocs as &$r) { $r['Estado'] = statusLabelPT($r['Estado']); } unset($r);

        } elseif ($profileCode === 'SECR') {
            $kpis['Pendentes na Secretaria'] = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS') OR DOCUMENT_CURRENT_STEP=1");
            $kpis['Despachados Este Mês']    = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` WHERE FROM_USER_ID=:u AND PROCESSING_ACTION IN ('FORWARD','RESOLVE','RETURN','CLOSE') AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
            $tableTitle = 'Requerimentos Pendentes';
            $recentDocs = pdfSafeQuery($pdo, "SELECT d.DOCUMENT_CODE AS `Código`, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`, t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`, DATE_FORMAT(d.DOCUMENT_CREATEDAT,'%d/%m/%Y') AS `Data` FROM `DOCUMENT` d LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID WHERE d.DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS') OR d.DOCUMENT_CURRENT_STEP=1 ORDER BY d.DOCUMENT_ID DESC LIMIT 50");
            foreach ($recentDocs as &$r) { $r['Estado'] = statusLabelPT($r['Estado']); } unset($r);

        } elseif ($profileCode === 'DOCE') {
            $kpis['Atribuídos a Mim']   = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_ASSIGNED_TO=:u AND DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')", [':u'=>$userId]);
            $kpis['Pareceres Este Mês'] = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` WHERE FROM_USER_ID=:u AND PROCESSING_ACTION IN ('RESOLVE','RETURN') AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
            $tableTitle = 'Documentos Atribuídos a Mim';
            $recentDocs = pdfSafeQuery($pdo, "SELECT d.DOCUMENT_CODE AS `Código`, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`, t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`, DATE_FORMAT(d.DOCUMENT_CREATEDAT,'%d/%m/%Y') AS `Data` FROM `DOCUMENT` d LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID WHERE d.DOCUMENT_ASSIGNED_TO=:u AND d.DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED') ORDER BY d.DOCUMENT_ID DESC LIMIT 50", [':u'=>$userId]);
            foreach ($recentDocs as &$r) { $r['Estado'] = statusLabelPT($r['Estado']); } unset($r);

        } elseif ($profileCode === 'DIRE') {
            $kpis['Pendentes']            = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')");
            $kpis['Despachados Este Mês'] = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` WHERE FROM_USER_ID=:u AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
            $tableTitle = 'Todos os Requerimentos Pendentes';
            $recentDocs = pdfSafeQuery($pdo, "SELECT d.DOCUMENT_CODE AS `Código`, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`, t.DOCUMENT_TYPE_NAME AS `Tipo`, d.DOCUMENT_STATUS AS `Estado`, DATE_FORMAT(d.DOCUMENT_CREATEDAT,'%d/%m/%Y') AS `Data` FROM `DOCUMENT` d LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID WHERE d.DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED') ORDER BY d.DOCUMENT_ID DESC LIMIT 50");
            foreach ($recentDocs as &$r) { $r['Estado'] = statusLabelPT($r['Estado']); } unset($r);

        } elseif ($profileCode === 'ADMI') {
            $kpis['Utilizadores'] = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `USERS` WHERE PROFILE_ID NOT IN (SELECT PROFILE_ID FROM PROFILE WHERE PROFILE_CODE='SUPE')");
            $kpis['Perfis']       = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `PROFILE`");
            $kpis['Módulos']      = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `MODULE`");
            $kpis['Cargos']       = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `POSITION`");
            $tableTitle = 'Estatísticas do Sistema';
            $recentDocs = pdfSafeQuery($pdo, "SELECT 'Utilizadores Ativos' AS `Métrica`, CAST(COUNT(*) AS CHAR) AS `Valor` FROM `USERS` WHERE USER_STATUS=1 UNION ALL SELECT 'Perfis Ativos', CAST(COUNT(*) AS CHAR) FROM `PROFILE` WHERE PROFILE_STATUS=1 UNION ALL SELECT 'Cargos Ativos', CAST(COUNT(*) AS CHAR) FROM `POSITION` WHERE POSITION_STATUS=1 UNION ALL SELECT 'Módulos Ativos', CAST(COUNT(*) AS CHAR) FROM `MODULE` WHERE MODULE_STATUS='ACTIVE' UNION ALL SELECT 'Ações Ativas', CAST(COUNT(*) AS CHAR) FROM `ACTION` WHERE ACTION_STATUS=1 UNION ALL SELECT 'Documentos Totais', CAST(COUNT(*) AS CHAR) FROM `DOCUMENT` UNION ALL SELECT 'Registos de Auditoria', CAST(COUNT(*) AS CHAR) FROM `LOG`");

        } elseif ($profileCode === 'SUPE') {
            $kpis['Utilizadores'] = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `USERS`");
            $kpis['Perfis']       = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `PROFILE`");
            $kpis['Módulos']      = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `MODULE`");
            $kpis['Cargos']       = pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `POSITION`");
            $tableTitle = 'Estatísticas Globais';
            $recentDocs = pdfSafeQuery($pdo, "SELECT 'Utilizadores Totais' AS `Métrica`, CAST(COUNT(*) AS CHAR) AS `Valor` FROM `USERS` UNION ALL SELECT 'Perfis Totais', CAST(COUNT(*) AS CHAR) FROM `PROFILE` UNION ALL SELECT 'Cargos Totais', CAST(COUNT(*) AS CHAR) FROM `POSITION` UNION ALL SELECT 'Módulos Totais', CAST(COUNT(*) AS CHAR) FROM `MODULE` UNION ALL SELECT 'Ações Totais', CAST(COUNT(*) AS CHAR) FROM `ACTION` UNION ALL SELECT 'Permissões', CAST(COUNT(*) AS CHAR) FROM `PERMISSION` UNION ALL SELECT 'Documentos', CAST(COUNT(*) AS CHAR) FROM `DOCUMENT` UNION ALL SELECT 'Sessões Ativas', CAST(COUNT(*) AS CHAR) FROM `SESSION` WHERE SESSION_STATUS='ACTIVE' UNION ALL SELECT 'Registos de Auditoria', CAST(COUNT(*) AS CHAR) FROM `LOG`");
        }

        $pdf = ged_pdf_init('Relatorio da Dashboard', 'Perfil: ' . $profileName . ' (' . $profileCode . ') — ' . date('d/m/Y H:i'));
        $pdf->SectionTitle('Indicadores Principais');
        ged_pdf_kpis($pdf, $kpis);

        if (!empty($recentDocs)) {
            $pdf->SectionTitle($tableTitle);
            $headers = array_keys($recentDocs[0]);
            $rows = [];
            foreach ($recentDocs as $d) { $rows[] = array_values($d); }
            $c = count($headers);
            if ($c === 5) $widths = [30, 70, 30, 25, 25];
            elseif ($c === 4) $widths = [35, 60, 35, 30];
            elseif ($c === 3) $widths = [50, 80, 40];
            elseif ($c === 2) $widths = [130, 50];
            else { $widths = []; foreach ($headers as $h) $widths[] = 180 / max(1, $c); }
            ged_pdf_table($pdf, $headers, $rows, $widths);
        }

        $pdf->Output('I', 'dashboard_' . $profileCode . '_' . date('Ymd_His') . '.pdf');
        break;

    case 'documents':
        requirePermissionOrRedirect($pdo, 'DOCUMENTS', 'VIEW');
        [$where, $params] = getDocumentFilter($pdo, $userId);
        $sql = "SELECT d.DOCUMENT_CODE AS `Código`, d.DOCUMENT_SUBJECT AS `Assunto`,
                       t.DOCUMENT_TYPE_NAME AS `Tipo`,
                       CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS `Requerente`,
                       d.DOCUMENT_STATUS AS `Estado`,
                       d.DOCUMENT_PRIORITY AS `Prioridade`,
                       DATE_FORMAT(d.DOCUMENT_CREATEDAT,'%d/%m/%Y') AS `Data`
                FROM `DOCUMENT` d
                LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
                LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
                $where ORDER BY d.DOCUMENT_ID DESC LIMIT 500";
        $st = $pdo->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['Estado'] = statusLabelPT($r['Estado']);
            $prMap = ['LOW'=>'Baixa','NORMAL'=>'Normal','HIGH'=>'Alta','URGENT'=>'Urgente'];
            $r['Prioridade'] = $prMap[$r['Prioridade']] ?? $r['Prioridade'];
        } unset($r);

        $pdf = ged_pdf_init('Lista de Documentos', 'Perfil: ' . $profileName . ' — ' . count($rows) . ' documento(s)');
        $pdf->SectionTitle('Documentos');
        if (empty($rows)) {
            $pdf->SetFont('Arial','I',11); $pdf->SetTextColor(127,140,141);
            $pdf->Cell(0,10,'Sem documentos para mostrar.',0,1,'C');
        } else {
            $data = [];
            if ($profileCode === 'ESTU') {
                foreach ($rows as $r) $data[] = [$r['Código'],$r['Assunto'],$r['Tipo'],$r['Estado'],$r['Data']];
                ged_pdf_table($pdf,['Código','Assunto','Tipo','Estado','Data'],$data,[30,70,35,25,25]);
            } else {
                foreach ($rows as $r) $data[] = [$r['Código'],$r['Assunto'],$r['Requerente'],$r['Tipo'],$r['Estado'],$r['Data']];
                ged_pdf_table($pdf,['Código','Assunto','Requerente','Tipo','Estado','Data'],$data,[25,55,40,30,25,25]);
            }
        }
        $pdf->Output('I', 'documentos_' . date('Ymd_His') . '.pdf');
        break;

    case 'doctypes':
        requirePermissionOrRedirect($pdo, 'DOCTYPES', 'VIEW');
        $rows = $pdo->query("SELECT t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_CODE, t.DOCUMENT_TYPE_NAME, t.DOCUMENT_TYPE_DESCRIPTION, t.DOCUMENT_TYPE_STATUS, (SELECT COUNT(*) FROM `DOCUMENT` d WHERE d.DOCUMENT_TYPE_ID = t.DOCUMENT_TYPE_ID) AS total_docs FROM `DOCUMENT_TYPE` t ORDER BY t.DOCUMENT_TYPE_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Tipos de Documento', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Tipos');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['DOCUMENT_TYPE_ID'],$r['DOCUMENT_TYPE_CODE'],$r['DOCUMENT_TYPE_NAME'],$r['DOCUMENT_TYPE_DESCRIPTION'] ?? '',$r['total_docs'],((int)$r['DOCUMENT_TYPE_STATUS']===1)?'Ativo':'Inativo'];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Descricao','Docs','Estado'],$data,[15,25,45,55,15,25]);
        $pdf->Output('I', 'tipos_' . date('Ymd_His') . '.pdf');
        break;

    case 'flows':
        requirePermissionOrRedirect($pdo, 'FLOWS', 'VIEW');
        $rows = $pdo->query("SELECT f.FLOW_ID, f.FLOW_CODE, f.FLOW_NAME, f.FLOW_STATUS, t.DOCUMENT_TYPE_NAME, (SELECT COUNT(*) FROM `DOCUMENT` d WHERE d.FLOW_ID = f.FLOW_ID) AS total_docs FROM `FLOW` f LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = f.DOCUMENT_TYPE_ID ORDER BY f.FLOW_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Fluxos de Tramitacao', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Fluxos');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['FLOW_ID'],$r['FLOW_CODE'],$r['FLOW_NAME'],$r['DOCUMENT_TYPE_NAME'] ?? '—',$r['total_docs'],((int)$r['FLOW_STATUS']===1)?'Ativo':'Inativo'];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Tipo Doc','Docs','Estado'],$data,[15,30,45,40,15,25]);
        $pdf->Output('I', 'fluxos_' . date('Ymd_His') . '.pdf');
        break;

    case 'flowsteps':
        requirePermissionOrRedirect($pdo, 'FLOWSTEPS', 'VIEW');
        $rows = $pdo->query("SELECT s.FLOW_STEP_ID, s.FLOW_STEP_ORDER, s.FLOW_STEP_NAME, s.FLOW_STEP_ACTION, s.FLOW_STEP_IS_FINAL, s.FLOW_STEP_STATUS, f.FLOW_CODE, p.PROFILE_NAME FROM `FLOW_STEP` s LEFT JOIN `FLOW` f ON f.FLOW_ID = s.FLOW_ID LEFT JOIN `PROFILE` p ON p.PROFILE_ID = s.PROFILE_ID ORDER BY s.FLOW_ID, s.FLOW_STEP_ORDER")->fetchAll();
        $pdf = ged_pdf_init('Passos dos Fluxos', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Passos');
        $actionMap = ['ANALYSE'=>'Analisar','FORWARD'=>'Encaminhar','RESOLVE'=>'Resolver','RETURN'=>'Devolver','APPROVE'=>'Aprovar','REJECT'=>'Rejeitar','DELIVER'=>'Entregar'];
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['FLOW_STEP_ID'],$r['FLOW_CODE'] ?? '—',$r['FLOW_STEP_ORDER'],$r['FLOW_STEP_NAME'],$r['PROFILE_NAME'] ?? '—',$actionMap[$r['FLOW_STEP_ACTION']] ?? $r['FLOW_STEP_ACTION'],((int)$r['FLOW_STEP_IS_FINAL']===1)?'Sim':'Nao',((int)$r['FLOW_STEP_STATUS']===1)?'Ativo':'Inativo'];
        ged_pdf_table($pdf,['ID','Fluxo','Ord','Nome','Perfil','Acao','Final','Estado'],$data,[15,20,12,40,30,25,15,20]);
        $pdf->Output('I', 'passos_' . date('Ymd_His') . '.pdf');
        break;

    case 'processing':
        requirePermissionOrRedirect($pdo, 'PROCESSING', 'VIEW');
        [$where, $params] = buildProcessingFilter($profileCode, $userId, $myProfileId);
        $sql = "SELECT p.PROCESSING_ID, d.DOCUMENT_CODE, p.PROCESSING_ACTION,
                       CONCAT(fu.USER_FIRSTNAME,' ',fu.USER_LASTNAME) AS FROM_USER,
                       CONCAT(tu.USER_FIRSTNAME,' ',tu.USER_LASTNAME) AS TO_USER,
                       fp.PROFILE_NAME AS FROM_PROFILE_NAME,
                       tp.PROFILE_NAME AS TO_PROFILE_NAME,
                       p.PROCESSING_NOTE, p.PROCESSING_CREATEDAT
                FROM `PROCESSING` p
                INNER JOIN `DOCUMENT` d ON d.DOCUMENT_ID = p.DOCUMENT_ID
                LEFT JOIN `USERS` fu ON fu.USER_ID = p.FROM_USER_ID
                LEFT JOIN `USERS` tu ON tu.USER_ID = p.TO_USER_ID
                LEFT JOIN `PROFILE` fp ON fp.PROFILE_ID = p.FROM_PROFILE_ID
                LEFT JOIN `PROFILE` tp ON tp.PROFILE_ID = p.TO_PROFILE_ID
                $where ORDER BY p.PROCESSING_ID DESC LIMIT 500";
        $st = $pdo->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();
        $actionMap = ['SUBMIT'=>'Submissão','FORWARD'=>'Encaminhamento','RESOLVE'=>'Resolução','RETURN'=>'Devolução','REJECT'=>'Rejeição','CLOSE'=>'Fecho'];
        $pdf = ged_pdf_init('Historico de Tramitacoes', 'Perfil: ' . $profileName . ' — ' . count($rows) . ' registo(s)');
        $pdf->SectionTitle('Tramitações');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['PROCESSING_ID'],$r['DOCUMENT_CODE'] ?? '—',$actionMap[$r['PROCESSING_ACTION']] ?? $r['PROCESSING_ACTION'],$r['FROM_USER'] ?? '—',$r['TO_USER'] ?? '—',substr((string)$r['PROCESSING_CREATEDAT'],0,16)];
        ged_pdf_table($pdf,['ID','Documento','Acao','De','Para','Data'],$data,[15,30,30,45,45,35]);
        $pdf->Output('I', 'tramitacoes_' . date('Ymd_His') . '.pdf');
        break;

    case 'actions':
        requirePermissionOrRedirect($pdo, 'ACTIONS', 'VIEW');
        $rows = $pdo->query("SELECT ACTION_ID, ACTION_CODE, ACTION_NAME, ACTION_DESCRIPTION, ACTION_STATUS FROM `ACTION` ORDER BY ACTION_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Gestao de Acoes', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Acoes');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['ACTION_ID'],$r['ACTION_CODE'],$r['ACTION_NAME'],$r['ACTION_DESCRIPTION'] ?? '',((int)$r['ACTION_STATUS']===1)?'Ativa':'Inativa'];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Descricao','Estado'],$data,[15,25,45,65,25]);
        $pdf->Output('I', 'acoes_' . date('Ymd_His') . '.pdf');
        break;

    case 'modules':
        requirePermissionOrRedirect($pdo, 'MODULES', 'VIEW');
        $rows = $pdo->query("SELECT MODULE_ID, MODULE_CODE, MODULE_NAME, MODULE_DESCRIPTION, MODULE_STATUS FROM `MODULE` ORDER BY MODULE_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Gestao de Modulos', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Modulos');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['MODULE_ID'],$r['MODULE_CODE'],$r['MODULE_NAME'],$r['MODULE_DESCRIPTION'] ?? '',statusToInt($r['MODULE_STATUS'])===1?'Ativo':'Inativo'];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Descricao','Estado'],$data,[15,25,45,65,25]);
        $pdf->Output('I', 'modulos_' . date('Ymd_His') . '.pdf');
        break;

    case 'profiles':
        requirePermissionOrRedirect($pdo, 'PROFILES', 'VIEW');
        $rows = $pdo->query("SELECT p.PROFILE_ID, p.PROFILE_CODE, p.PROFILE_NAME, p.PROFILE_IS_SYSTEM, p.PROFILE_STATUS, (SELECT COUNT(*) FROM `PERMISSION` perm WHERE perm.PROFILE_ID=p.PROFILE_ID AND perm.PERMISSION_GRANTED=1) AS total_perms FROM `PROFILE` p ORDER BY p.PROFILE_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Gestao de Perfis', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Perfis');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['PROFILE_ID'],$r['PROFILE_CODE'],$r['PROFILE_NAME'],((int)$r['PROFILE_IS_SYSTEM']===1)?'Sim':'Nao',((int)$r['PROFILE_STATUS']===1)?'Ativo':'Inativo',$r['total_perms'] ?? 0];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Sistema','Estado','Permissoes'],$data,[15,25,50,25,25,30]);
        $pdf->Output('I', 'perfis_' . date('Ymd_His') . '.pdf');
        break;

    case 'positions':
        requirePermissionOrRedirect($pdo, 'POSITIONS', 'VIEW');
        $rows = $pdo->query("SELECT POSITION_ID, POSITION_CODE, POSITION_NAME, POSITION_DESCRIPTION, POSITION_STATUS FROM `POSITION` ORDER BY POSITION_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Gestao de Cargos', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Cargos');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['POSITION_ID'],$r['POSITION_CODE'],$r['POSITION_NAME'],$r['POSITION_DESCRIPTION'] ?? '',((int)$r['POSITION_STATUS']===1)?'Ativo':'Inativo'];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Descricao','Estado'],$data,[15,25,45,65,25]);
        $pdf->Output('I', 'cargos_' . date('Ymd_His') . '.pdf');
        break;

    case 'permissions':
        requirePermissionOrRedirect($pdo, 'PERMISSIONS', 'VIEW');
        $summary = $pdo->query("SELECT p.PROFILE_ID, p.PROFILE_CODE, p.PROFILE_NAME, SUM(CASE WHEN perm.PERMISSION_GRANTED=1 THEN 1 ELSE 0 END) AS total FROM `PROFILE` p LEFT JOIN `PERMISSION` perm ON perm.PROFILE_ID = p.PROFILE_ID WHERE p.PROFILE_STATUS = 1 GROUP BY p.PROFILE_ID, p.PROFILE_CODE, p.PROFILE_NAME ORDER BY p.PROFILE_NAME")->fetchAll();
        $details = $pdo->query("SELECT p.PROFILE_NAME, m.MODULE_CODE, m.MODULE_NAME, a.ACTION_CODE, a.ACTION_NAME FROM `PERMISSION` perm INNER JOIN `PROFILE` p ON p.PROFILE_ID = perm.PROFILE_ID INNER JOIN `MODULE` m ON m.MODULE_ID = perm.MODULE_ID INNER JOIN `ACTION` a ON a.ACTION_ID = perm.ACTION_ID WHERE perm.PERMISSION_GRANTED = 1 AND p.PROFILE_STATUS = 1 ORDER BY p.PROFILE_NAME, m.MODULE_NAME, a.ACTION_NAME")->fetchAll();
        $totalGrants = (int)$pdo->query("SELECT COUNT(*) FROM `PERMISSION` WHERE PERMISSION_GRANTED=1")->fetchColumn();
        $pdf = ged_pdf_init('Permissoes do Sistema', 'Total de permissoes ativas: ' . $totalGrants);
        $pdf->SectionTitle('Resumo por Perfil');
        $sr = []; foreach ($summary as $s) $sr[] = [$s['PROFILE_CODE'],$s['PROFILE_NAME'],$s['total'] ?? 0];
        ged_pdf_table($pdf,['Codigo','Perfil','Permissoes'],$sr,[30,100,30]);
        $pdf->AddPage();
        $pdf->SectionTitle('Detalhe das Permissoes');
        $dr = []; foreach ($details as $d) $dr[] = [$d['PROFILE_NAME'],$d['MODULE_CODE'].' - '.$d['MODULE_NAME'],$d['ACTION_CODE'].' - '.$d['ACTION_NAME']];
        ged_pdf_table($pdf,['Perfil','Modulo','Acao'],$dr,[45,70,45]);
        $pdf->Output('I', 'permissoes_' . date('Ymd_His') . '.pdf');
        break;

    case 'users':
        requirePermissionOrRedirect($pdo, 'USERS', 'VIEW');
        $where = !isSuperAdmin() ? "WHERE p.PROFILE_CODE <> 'SUPE'" : "";
        $rows = $pdo->query("SELECT u.USER_ID, u.USER_CODE, u.USER_FIRSTNAME, u.USER_LASTNAME, u.USER_EMAIL, u.USER_STATUS, p.PROFILE_NAME, pos.POSITION_NAME FROM `USERS` u LEFT JOIN `PROFILE` p ON p.PROFILE_ID = u.PROFILE_ID LEFT JOIN `POSITION` pos ON pos.POSITION_ID = u.POSITION_ID $where ORDER BY u.USER_ID DESC")->fetchAll();
        $pdf = ged_pdf_init('Gestao de Utilizadores', 'Total: ' . count($rows));
        $pdf->SectionTitle('Lista de Utilizadores');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['USER_ID'],$r['USER_CODE'],trim($r['USER_FIRSTNAME'].' '.$r['USER_LASTNAME']),$r['USER_EMAIL'],$r['PROFILE_NAME'] ?? '—',$r['POSITION_NAME'] ?? '—',((int)$r['USER_STATUS']===1)?'Ativo':'Inativo'];
        ged_pdf_table($pdf,['ID','Codigo','Nome','Email','Perfil','Cargo','Estado'],$data,[12,22,40,45,25,25,20]);
        $pdf->Output('I', 'utilizadores_' . date('Ymd_His') . '.pdf');
        break;

    case 'logs':
        requirePermissionOrRedirect($pdo, 'LOGS', 'VIEW');
        $search = trim($_GET['search'] ?? '');
        $module = trim($_GET['module'] ?? '');
        $operation = trim($_GET['operation'] ?? '');
        $severity = trim($_GET['severity'] ?? '');
        $userIdF = trim($_GET['user_id'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $where = []; $params = [];
        if ($module !== '') { $where[] = "LOG_MODULE = :m"; $params[':m'] = $module; }
        if ($operation !== '') { $where[] = "LOG_ACTION = :op"; $params[':op'] = $operation; }
        if ($severity !== '') { $where[] = "LOG_TYPE = :sev"; $params[':sev'] = $severity; }
        if ($userIdF !== '' && ctype_digit($userIdF)) { $where[] = "LOG_USER_ID = :uid"; $params[':uid'] = (int)$userIdF; }
        if ($search !== '') { $where[] = "(LOG_DESCRIPTION LIKE :s OR LOG_USER_NAME LIKE :s OR LOG_MODULE LIKE :s)"; $params[':s'] = '%'.$search.'%'; }
        if ($dateFrom !== '') { $where[] = "LOG_CREATEDAT >= :df"; $params[':df'] = $dateFrom.' 00:00:00'; }
        if ($dateTo !== '') { $where[] = "LOG_CREATEDAT <= :dt"; $params[':dt'] = $dateTo.' 23:59:59'; }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';
        $st = $pdo->prepare("SELECT LOG_ID, LOG_CREATEDAT, LOG_ACTION, LOG_TYPE, LOG_MODULE, LOG_DESCRIPTION, LOG_USER_NAME, LOG_IP FROM `LOG` $whereSql ORDER BY LOG_ID DESC LIMIT 500");
        $st->execute($params); $rows = $st->fetchAll();
        $subtitle = 'Registos: ' . count($rows);
        if ($dateFrom || $dateTo) $subtitle .= ' — Periodo: ' . ($dateFrom ?: '...') . ' a ' . ($dateTo ?: '...');
        $pdf = ged_pdf_init('Auditoria do Sistema', $subtitle);
        $pdf->SectionTitle('Registos de Auditoria');
        $data = [];
        foreach ($rows as $r) $data[] = ['#'.$r['LOG_ID'],substr((string)$r['LOG_CREATEDAT'],0,16),$r['LOG_ACTION'] ?? '',$r['LOG_TYPE'] ?? '',$r['LOG_MODULE'] ?? '',$r['LOG_DESCRIPTION'] ?? '',$r['LOG_USER_NAME'] ?? 'system'];
        ged_pdf_table($pdf,['ID','Data/Hora','Operacao','Tipo','Modulo','Descricao','Utilizador'],$data,[15,30,25,20,25,45,30]);
        $pdf->Output('I', 'auditoria_' . date('Ymd_His') . '.pdf');
        break;

    case 'reports':
        requirePermissionOrRedirect($pdo, 'REPORTS', 'VIEW');
        $dateFrom = $_GET['from'] ?? date('Y-m-01');
        $dateTo = $_GET['to'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');
        $kpis = [
            'Documentos'   => pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom,':t'=>$dateTo]),
            'Resolvidos'   => pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS IN ('RESOLVED','CLOSED') AND DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom,':t'=>$dateTo]),
            'Pendentes'    => pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED') AND DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom,':t'=>$dateTo]),
            'Utilizadores' => pdfSafeScalar($pdo, "SELECT COUNT(*) FROM `USERS` WHERE USER_STATUS=1"),
        ];
        $byType = pdfSafeQuery($pdo, "SELECT t.DOCUMENT_TYPE_NAME AS name, COUNT(d.DOCUMENT_ID) AS total FROM `DOCUMENT_TYPE` t LEFT JOIN `DOCUMENT` d ON d.DOCUMENT_TYPE_ID = t.DOCUMENT_TYPE_ID AND DATE(d.DOCUMENT_CREATEDAT) BETWEEN :f AND :t GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME ORDER BY total DESC", [':f'=>$dateFrom,':t'=>$dateTo]);
        $byStatus = pdfSafeQuery($pdo, "SELECT DOCUMENT_STATUS AS status, COUNT(*) AS total FROM `DOCUMENT` WHERE DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t GROUP BY DOCUMENT_STATUS ORDER BY total DESC", [':f'=>$dateFrom,':t'=>$dateTo]);
        $byProfile = pdfSafeQuery($pdo, "SELECT p.PROFILE_NAME AS name, COUNT(d.DOCUMENT_ID) AS total FROM `PROFILE` p LEFT JOIN `USERS` u ON u.PROFILE_ID = p.PROFILE_ID LEFT JOIN `DOCUMENT` d ON d.USER_ID = u.USER_ID AND DATE(d.DOCUMENT_CREATEDAT) BETWEEN :f AND :t GROUP BY p.PROFILE_ID, p.PROFILE_NAME ORDER BY total DESC", [':f'=>$dateFrom,':t'=>$dateTo]);
        $topUsers = pdfSafeQuery($pdo, "SELECT CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS name, COUNT(d.DOCUMENT_ID) AS total FROM `USERS` u JOIN `DOCUMENT` d ON d.USER_ID = u.USER_ID WHERE DATE(d.DOCUMENT_CREATEDAT) BETWEEN :f AND :t GROUP BY u.USER_ID, name ORDER BY total DESC LIMIT 10", [':f'=>$dateFrom,':t'=>$dateTo]);
        $pdf = ged_pdf_init('Relatorio do Sistema', 'Periodo: ' . $dateFrom . ' a ' . $dateTo);
        $pdf->SectionTitle('Indicadores Principais');
        ged_pdf_kpis($pdf, $kpis);
        $pdf->SectionTitle('Documentos por Tipo');
        ged_pdf_table($pdf,['Tipo','Total'],array_map(fn($r)=>[$r['name'],$r['total']],$byType),[120,40]);
        $pdf->SectionTitle('Documentos por Estado');
        $sr = []; foreach ($byStatus as $s) $sr[] = [statusLabelPT($s['status']),$s['total']];
        ged_pdf_table($pdf,['Estado','Total'],$sr,[120,40]);
        $pdf->SectionTitle('Documentos por Perfil');
        ged_pdf_table($pdf,['Perfil','Total'],array_map(fn($r)=>[$r['name'],$r['total']],$byProfile),[120,40]);
        if (!empty($topUsers)) {
            $pdf->SectionTitle('Top 10 Utilizadores');
            $tr = []; foreach ($topUsers as $i => $u) $tr[] = ['#'.($i+1).' '.$u['name'],$u['total']];
            ged_pdf_table($pdf,['Utilizador','Documentos'],$tr,[130,30]);
        }
        $pdf->Output('I', 'relatorio_' . date('Ymd_His') . '.pdf');
        break;

    default:
        http_response_code(400);
        die('Tipo de exportacao invalido. Use ?tipo=dashboard|documents|doctypes|flows|flowsteps|processing|actions|modules|profiles|positions|permissions|users|logs|reports');
}