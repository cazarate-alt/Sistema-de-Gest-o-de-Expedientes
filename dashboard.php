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
    requireLogin();

    if (!hasPermission($pdo, 'DASHBOARD', 'VIEW')) {
        $allowed = allowedModules($pdo);
        if (!empty($allowed)) {
            foreach ($allowed as $code) {
                if ($code === 'DASHBOARD') continue;
                $route = moduleRoute($code);
                if ($route !== 'dashboard.php' && file_exists(__DIR__ . '/' . $route)) {
                    header('Location: ' . $route);
                    exit;
                }
            }
        }
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?error=no_permission');
        exit;
    }

    $_SESSION['__allowed_modules'] = allowedModules($pdo);

    $userId      = (int) $_SESSION['user_id'];
    $userName    = $_SESSION['user_name']    ?? 'Utilizador';
    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $profileName = $_SESSION['profile_name'] ?? '—';
    $myProfileId = (int) $_SESSION['profile_id'];

    function safeQuery(PDO $pdo, string $sql, array $params = [], $default = []) {
        try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
        catch (\Throwable $e) { return $default; }
    }
    function safeScalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
        try {
            $st = $pdo->prepare($sql); $st->execute($params);
            $v = $st->fetchColumn();
            return $v === false ? $default : $v;
        } catch (\Throwable $e) { return $default; }
    }

    $kpis = [];
    $recentDocs = [];
    $tableTitle = 'Documentos Recentes';
    $tableCols = ['Código', 'Assunto', 'Tipo', 'Estado', 'Data'];
    $showActions = false;

    $chart1 = [];
    $chart2 = [];
    $chart3 = [];
    $chart4 = [];

    $chart1Title = 'Por Estado';
    $chart2Title = 'Por Tipo';
    $chart3Title = 'Evolução';
    $chart4Title = 'Distribuição';
    $chart1Type = 'doughnut';
    $chart2Type = 'bar';
    $chart3Type = 'line';
    $chart4Type = 'pie';

    if ($profileCode === 'ESTU') {
        $kpis['pending']  = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:u AND DOCUMENT_STATUS NOT IN ('CLOSED','REJECTED','CANCELLED')", [':u'=>$userId]);
        $kpis['resolved'] = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:u AND DOCUMENT_STATUS IN ('RESOLVED','CLOSED')", [':u'=>$userId]);
        $tableTitle = 'Meus Pedidos Recentes';
        $tableCols = ['Nº Pedido', 'Tipo', 'Data', 'Estado'];
        $recentDocs = safeQuery($pdo,
            "SELECT d.DOCUMENT_ID, d.DOCUMENT_CODE, d.DOCUMENT_SUBJECT, d.DOCUMENT_STATUS,
                    d.DOCUMENT_CREATEDAT, t.DOCUMENT_TYPE_NAME
            FROM `DOCUMENT` d
            LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            WHERE d.USER_ID = :u ORDER BY d.DOCUMENT_ID DESC LIMIT 5", [':u'=>$userId]);

        $chart1Title = 'Meus Pedidos por Estado';
        $chart1 = safeQuery($pdo,
            "SELECT DOCUMENT_STATUS AS label, COUNT(*) AS total
            FROM `DOCUMENT` WHERE USER_ID=:u GROUP BY DOCUMENT_STATUS", [':u'=>$userId]);

        $chart2Title = 'Meus Pedidos por Tipo';
        $chart2 = safeQuery($pdo,
            "SELECT t.DOCUMENT_TYPE_NAME AS label, COUNT(d.DOCUMENT_ID) AS total
            FROM `DOCUMENT` d
            INNER JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            WHERE d.USER_ID=:u
            GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME", [':u'=>$userId]);

        $chart3Title = 'As Minhas Submissões por Mês';
        $chart3 = safeQuery($pdo,
            "SELECT DATE_FORMAT(DOCUMENT_CREATEDAT, '%Y-%m') AS ym,
                    DATE_FORMAT(DOCUMENT_CREATEDAT, '%b/%y') AS label,
                    COUNT(*) AS total
            FROM `DOCUMENT`
            WHERE USER_ID=:u AND DOCUMENT_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym, label ORDER BY ym ASC", [':u'=>$userId]);

        $chart4Title = 'As Minhas Submissões por Semana';
        $chart4 = safeQuery($pdo,
            "SELECT YEARWEEK(DOCUMENT_CREATEDAT, 1) AS yw,
                    MIN(DATE(DOCUMENT_CREATEDAT)) AS dt, COUNT(*) AS total
            FROM `DOCUMENT`
            WHERE USER_ID=:u AND DOCUMENT_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
            GROUP BY yw ORDER BY yw ASC", [':u'=>$userId]);

    } elseif ($profileCode === 'SECR') {
        $kpis['pending'] = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` 
                                            WHERE DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS')
                                                OR DOCUMENT_CURRENT_STEP = 1");
        $kpis['dispatched'] = safeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` 
                                                WHERE FROM_USER_ID=:u 
                                                AND PROCESSING_ACTION IN ('FORWARD','RESOLVE','RETURN','CLOSE')
                                                AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
        $tableTitle = 'Requerimentos Pendentes na Secretaria';
        $tableCols = ['Nº Processo', 'Requerente', 'Tipo', 'Data Entrada', 'Prioridade', 'Ações'];
        $showActions = true;
        $recentDocs = safeQuery($pdo,
            "SELECT d.DOCUMENT_ID, d.DOCUMENT_CODE, d.DOCUMENT_STATUS, d.DOCUMENT_CREATEDAT, d.DOCUMENT_PRIORITY,
                    t.DOCUMENT_TYPE_NAME, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS OWNER
            FROM `DOCUMENT` d
            LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
            WHERE d.DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS')
                OR d.DOCUMENT_CURRENT_STEP = 1
            ORDER BY d.DOCUMENT_ID DESC LIMIT 5");

        $chart1Title = 'Documentos na Secretaria por Estado';
        $chart1 = safeQuery($pdo,
            "SELECT DOCUMENT_STATUS AS label, COUNT(*) AS total
            FROM `DOCUMENT`
            WHERE DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS','RESOLVED','CLOSED','REJECTED')
                OR DOCUMENT_CURRENT_STEP = 1
            GROUP BY DOCUMENT_STATUS");

        $chart2Title = 'Pendentes por Tipo de Documento';
        $chart2 = safeQuery($pdo,
            "SELECT t.DOCUMENT_TYPE_NAME AS label, COUNT(d.DOCUMENT_ID) AS total
            FROM `DOCUMENT` d
            INNER JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            WHERE d.DOCUMENT_STATUS IN ('SUBMITTED','RETURNED','IN_ANALYSIS') OR d.DOCUMENT_CURRENT_STEP=1
            GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME");

        $chart3Title = 'As Minhas Ações por Mês';
        $chart3 = safeQuery($pdo,
            "SELECT DATE_FORMAT(PROCESSING_CREATEDAT, '%Y-%m') AS ym,
                    DATE_FORMAT(PROCESSING_CREATEDAT, '%b/%y') AS label,
                    COUNT(*) AS total
            FROM `PROCESSING`
            WHERE FROM_USER_ID=:u AND PROCESSING_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym, label ORDER BY ym ASC", [':u'=>$userId]);

        $chart4Title = 'Distribuição das Minhas Ações';
        $chart4 = safeQuery($pdo,
            "SELECT PROCESSING_ACTION AS label, COUNT(*) AS total
            FROM `PROCESSING`
            WHERE FROM_USER_ID=:u
            GROUP BY PROCESSING_ACTION ORDER BY total DESC", [':u'=>$userId]);

    } elseif ($profileCode === 'DOCE') {
        $kpis['pending'] = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` 
                                            WHERE DOCUMENT_ASSIGNED_TO=:u 
                                                AND DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')", [':u'=>$userId]);
        $kpis['dispatched'] = safeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` 
                                                WHERE FROM_USER_ID=:u 
                                                AND PROCESSING_ACTION IN ('RESOLVE','RETURN')
                                                AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
        $tableTitle = 'Documentos Atribuídos a Mim';
        $tableCols = ['Nº Processo', 'Requerente', 'Tipo', 'Data Entrada', 'Prioridade', 'Ações'];
        $showActions = true;
        $recentDocs = safeQuery($pdo,
            "SELECT d.DOCUMENT_ID, d.DOCUMENT_CODE, d.DOCUMENT_STATUS, d.DOCUMENT_CREATEDAT, d.DOCUMENT_PRIORITY,
                    t.DOCUMENT_TYPE_NAME, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS OWNER
            FROM `DOCUMENT` d
            LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
            WHERE d.DOCUMENT_ASSIGNED_TO = :u 
            AND d.DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')
            ORDER BY d.DOCUMENT_ID DESC LIMIT 5", [':u'=>$userId]);

        $chart1Title = 'Os Meus Atribuídos por Estado';
        $chart1 = safeQuery($pdo,
            "SELECT DOCUMENT_STATUS AS label, COUNT(*) AS total
            FROM `DOCUMENT` WHERE DOCUMENT_ASSIGNED_TO=:u
            GROUP BY DOCUMENT_STATUS", [':u'=>$userId]);

        $chart2Title = 'Os Meus Atribuídos por Tipo';
        $chart2 = safeQuery($pdo,
            "SELECT t.DOCUMENT_TYPE_NAME AS label, COUNT(d.DOCUMENT_ID) AS total
            FROM `DOCUMENT` d
            INNER JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            WHERE d.DOCUMENT_ASSIGNED_TO=:u
            GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME", [':u'=>$userId]);

        $chart3Title = 'Pareceres Emitidos por Mês';
        $chart3 = safeQuery($pdo,
            "SELECT DATE_FORMAT(PROCESSING_CREATEDAT, '%Y-%m') AS ym,
                    DATE_FORMAT(PROCESSING_CREATEDAT, '%b/%y') AS label,
                    COUNT(*) AS total
            FROM `PROCESSING`
            WHERE FROM_USER_ID=:u AND PROCESSING_ACTION IN ('RESOLVE','RETURN')
            AND PROCESSING_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym, label ORDER BY ym ASC", [':u'=>$userId]);

        $chart4Title = 'Os Meus Pareceres por Ação';
        $chart4 = safeQuery($pdo,
            "SELECT PROCESSING_ACTION AS label, COUNT(*) AS total
            FROM `PROCESSING`
            WHERE FROM_USER_ID=:u AND PROCESSING_ACTION IN ('RESOLVE','RETURN')
            GROUP BY PROCESSING_ACTION ORDER BY total DESC", [':u'=>$userId]);

    } elseif ($profileCode === 'DIRE') {
        $kpis['pending'] = safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` 
                                            WHERE DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')");
        $kpis['dispatched'] = safeScalar($pdo, "SELECT COUNT(*) FROM `PROCESSING` 
                                                WHERE FROM_USER_ID=:u 
                                                AND MONTH(PROCESSING_CREATEDAT)=MONTH(CURDATE())", [':u'=>$userId]);
        $tableTitle = 'Todos os Requerimentos Pendentes';
        $tableCols = ['Nº Processo', 'Requerente', 'Tipo', 'Data Entrada', 'Prioridade', 'Ações'];
        $showActions = true;
        $recentDocs = safeQuery($pdo,
            "SELECT d.DOCUMENT_ID, d.DOCUMENT_CODE, d.DOCUMENT_STATUS, d.DOCUMENT_CREATEDAT, d.DOCUMENT_PRIORITY,
                    t.DOCUMENT_TYPE_NAME, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS OWNER
            FROM `DOCUMENT` d
            LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
            WHERE d.DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED')
            ORDER BY d.DOCUMENT_ID DESC LIMIT 5");

        $chart1Title = 'Todos os Documentos por Estado';
        $chart1 = safeQuery($pdo,
            "SELECT DOCUMENT_STATUS AS label, COUNT(*) AS total
            FROM `DOCUMENT` GROUP BY DOCUMENT_STATUS ORDER BY total DESC");

        $chart2Title = 'Todos os Documentos por Tipo';
        $chart2 = safeQuery($pdo,
            "SELECT t.DOCUMENT_TYPE_NAME AS label, COUNT(d.DOCUMENT_ID) AS total
            FROM `DOCUMENT` d
            INNER JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME
            ORDER BY total DESC");

        $chart3Title = 'Documentos Criados por Mês';
        $chart3 = safeQuery($pdo,
            "SELECT DATE_FORMAT(DOCUMENT_CREATEDAT, '%Y-%m') AS ym,
                    DATE_FORMAT(DOCUMENT_CREATEDAT, '%b/%y') AS label,
                    COUNT(*) AS total
            FROM `DOCUMENT`
            WHERE DOCUMENT_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym, label ORDER BY ym ASC");

        $chart4Title = 'Top 10 Requerentes';
        $chart4 = safeQuery($pdo,
            "SELECT CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS label, COUNT(d.DOCUMENT_ID) AS total
            FROM `DOCUMENT` d
            INNER JOIN `USERS` u ON u.USER_ID = d.USER_ID
            GROUP BY u.USER_ID, label ORDER BY total DESC LIMIT 10");

    } elseif ($profileCode === 'ADMI') {
        $kpis['users']     = safeScalar($pdo, "SELECT COUNT(*) FROM `USERS` WHERE PROFILE_ID NOT IN (SELECT PROFILE_ID FROM PROFILE WHERE PROFILE_CODE='SUPE')");
        $kpis['profiles']  = safeScalar($pdo, "SELECT COUNT(*) FROM `PROFILE`");
        $kpis['modules']   = safeScalar($pdo, "SELECT COUNT(*) FROM `MODULE`");
        $kpis['positions'] = safeScalar($pdo, "SELECT COUNT(*) FROM `POSITION`");
        $tableTitle = 'Estatísticas do Sistema';
        $tableCols = ['Métrica', 'Valor'];
        $recentDocs = safeQuery($pdo,
            "SELECT 'Utilizadores Ativos' AS METRIC, CAST(COUNT(*) AS CHAR) AS VAL
            FROM `USERS` WHERE USER_STATUS=1
            UNION ALL SELECT 'Utilizadores Inativos', CAST(COUNT(*) AS CHAR) FROM `USERS` WHERE USER_STATUS=0
            UNION ALL SELECT 'Perfis Ativos', CAST(COUNT(*) AS CHAR) FROM `PROFILE` WHERE PROFILE_STATUS=1
            UNION ALL SELECT 'Cargos Ativos', CAST(COUNT(*) AS CHAR) FROM `POSITION` WHERE POSITION_STATUS=1
            UNION ALL SELECT 'Módulos Ativos', CAST(COUNT(*) AS CHAR) FROM `MODULE` WHERE MODULE_STATUS='ACTIVE'
            UNION ALL SELECT 'Ações Ativas', CAST(COUNT(*) AS CHAR) FROM `ACTION` WHERE ACTION_STATUS=1
            UNION ALL SELECT 'Permissões Concedidas', CAST(COUNT(*) AS CHAR) FROM `PERMISSION` WHERE PERMISSION_GRANTED=1
            UNION ALL SELECT 'Documentos Totais', CAST(COUNT(*) AS CHAR) FROM `DOCUMENT`
            UNION ALL SELECT 'Registos de Auditoria', CAST(COUNT(*) AS CHAR) FROM `LOG`");

        $chart1Title = 'Utilizadores Ativos por Perfil';
        $chart1 = safeQuery($pdo,
            "SELECT p.PROFILE_NAME AS label, COUNT(u.USER_ID) AS total
            FROM `PROFILE` p
            LEFT JOIN `USERS` u ON u.PROFILE_ID = p.PROFILE_ID AND u.USER_STATUS=1
            WHERE p.PROFILE_STATUS = 1
            GROUP BY p.PROFILE_ID, p.PROFILE_NAME
            ORDER BY total DESC");

        $chart2Title = 'Documentos por Estado';
        $chart2 = safeQuery($pdo,
            "SELECT DOCUMENT_STATUS AS label, COUNT(*) AS total
            FROM `DOCUMENT` GROUP BY DOCUMENT_STATUS ORDER BY total DESC");

        $chart3Title = 'Documentos por Mês';
        $chart3 = safeQuery($pdo,
            "SELECT DATE_FORMAT(DOCUMENT_CREATEDAT, '%Y-%m') AS ym,
                    DATE_FORMAT(DOCUMENT_CREATEDAT, '%b/%y') AS label,
                    COUNT(*) AS total
            FROM `DOCUMENT`
            WHERE DOCUMENT_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym, label ORDER BY ym ASC");

        $chart4Title = 'Documentos por Tipo';
        $chart4 = safeQuery($pdo,
            "SELECT t.DOCUMENT_TYPE_NAME AS label, COUNT(d.DOCUMENT_ID) AS total
            FROM `DOCUMENT_TYPE` t
            LEFT JOIN `DOCUMENT` d ON d.DOCUMENT_TYPE_ID = t.DOCUMENT_TYPE_ID
            GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME
            ORDER BY total DESC");

    } elseif ($profileCode === 'SUPE') {
        $kpis['users']     = safeScalar($pdo, "SELECT COUNT(*) FROM `USERS`");
        $kpis['profiles']  = safeScalar($pdo, "SELECT COUNT(*) FROM `PROFILE`");
        $kpis['modules']   = safeScalar($pdo, "SELECT COUNT(*) FROM `MODULE`");
        $kpis['positions'] = safeScalar($pdo, "SELECT COUNT(*) FROM `POSITION`");
        $tableTitle = 'Estatísticas Globais';
        $tableCols = ['Métrica', 'Valor'];
        $recentDocs = safeQuery($pdo,
            "SELECT 'Utilizadores Totais' AS METRIC, CAST(COUNT(*) AS CHAR) AS VAL FROM `USERS`
            UNION ALL SELECT 'Perfis Totais', CAST(COUNT(*) AS CHAR) FROM `PROFILE`
            UNION ALL SELECT 'Cargos Totais', CAST(COUNT(*) AS CHAR) FROM `POSITION`
            UNION ALL SELECT 'Módulos Totais', CAST(COUNT(*) AS CHAR) FROM `MODULE`
            UNION ALL SELECT 'Ações Totais', CAST(COUNT(*) AS CHAR) FROM `ACTION`
            UNION ALL SELECT 'Permissões', CAST(COUNT(*) AS CHAR) FROM `PERMISSION`
            UNION ALL SELECT 'Documentos', CAST(COUNT(*) AS CHAR) FROM `DOCUMENT`
            UNION ALL SELECT 'Sessões Ativas', CAST(COUNT(*) AS CHAR) FROM `SESSION` WHERE SESSION_STATUS='ACTIVE'
            UNION ALL SELECT 'Registos de Auditoria', CAST(COUNT(*) AS CHAR) FROM `LOG`");

        $chart1Title = 'Utilizadores por Perfil';
        $chart1 = safeQuery($pdo,
            "SELECT p.PROFILE_NAME AS label, COUNT(u.USER_ID) AS total
            FROM `PROFILE` p
            LEFT JOIN `USERS` u ON u.PROFILE_ID = p.PROFILE_ID
            GROUP BY p.PROFILE_ID, p.PROFILE_NAME
            ORDER BY total DESC");

        $chart2Title = 'Permissões Concedidas por Perfil';
        $chart2 = safeQuery($pdo,
            "SELECT p.PROFILE_NAME AS label, COUNT(perm.PERMISSION_ID) AS total
            FROM `PROFILE` p
            LEFT JOIN `PERMISSION` perm ON perm.PROFILE_ID = p.PROFILE_ID AND perm.PERMISSION_GRANTED=1
            GROUP BY p.PROFILE_ID, p.PROFILE_NAME
            ORDER BY total DESC");

        $chart3Title = 'Auditoria nos Últimos 7 Dias';
        $chart3 = safeQuery($pdo,
            "SELECT DATE(LOG_CREATEDAT) AS dt,
                    DATE_FORMAT(LOG_CREATEDAT, '%d/%m') AS label,
                    COUNT(*) AS total
            FROM `LOG`
            WHERE LOG_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY dt, label ORDER BY dt ASC");

        $chart4Title = 'Auditoria por Módulo (30d)';
        $chart4 = safeQuery($pdo,
            "SELECT COALESCE(LOG_MODULE, 'Sistema') AS label, COUNT(*) AS total
            FROM `LOG`
            WHERE LOG_CREATEDAT >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY LOG_MODULE
            ORDER BY total DESC LIMIT 10");
    }

    $badgeStatus = [
        'DRAFT'=>['Rascunho','badge-secondary'], 'SUBMITTED'=>['Submetido','badge-info'],
        'IN_ANALYSIS'=>['Em análise','badge-primary'], 'FORWARDED'=>['Reencaminhado','badge-warning'],
        'IN_RESOLUTION'=>['Em resolução','badge-warning'], 'RESOLVED'=>['Resolvido','badge-success'],
        'RETURNED'=>['Devolvido','badge-info'], 'CLOSED'=>['Fechado','badge-success'],
        'REJECTED'=>['Rejeitado','badge-danger'], 'CANCELLED'=>['Cancelado','badge-secondary'],
    ];
    $priorityBadge = [
        'LOW'=>['Baixa','badge-secondary'],'NORMAL'=>['Normal','badge-info'],
        'HIGH'=>['Alta','badge-warning'],'URGENT'=>['Urgente','badge-danger'],
    ];

    $statusLabelPT = [
        'DRAFT'=>'Rascunho','SUBMITTED'=>'Submetido','IN_ANALYSIS'=>'Em Análise',
        'FORWARDED'=>'Reencaminhado','IN_RESOLUTION'=>'Em Resolução','RESOLVED'=>'Resolvido',
        'RETURNED'=>'Devolvido','CLOSED'=>'Fechado','REJECTED'=>'Rejeitado','CANCELLED'=>'Cancelado',
    ];
    $actionLabelPT = [
        'SUBMIT'=>'Submissão','FORWARD'=>'Encaminhamento','RESOLVE'=>'Resolução',
        'RETURN'=>'Devolução','REJECT'=>'Rejeição','CLOSE'=>'Fecho',
    ];

    $pageTitle  = 'Dashboard';
    $activePage = 'dashboard.php';

    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-tachometer-alt" style="color:var(--accent-color);"></i> Dashboard</h2>
                <div class="breadcrumb-custom">
                    <a href="dashboard.php">Dashboard</a>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="export_pdf.php?tipo=dashboard" target="_blank" class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);text-decoration:none;">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </a>
            </div>
        </div>

        <div class="row mb-4">
            <?php if ($profileCode === 'ESTU'): ?>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><div class="value"><?= $kpis['pending'] ?? 0 ?></div><div class="label">Pedidos Em Andamento</div></div></div></div>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value"><?= $kpis['resolved'] ?? 0 ?></div><div class="label">Documentos Prontos para Levantar</div></div></div></div>
            <?php elseif ($profileCode === 'SECR'): ?>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><div class="value"><?= $kpis['pending'] ?? 0 ?></div><div class="label">Requerimentos Pendentes na Secretaria</div></div></div></div>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value"><?= $kpis['dispatched'] ?? 0 ?></div><div class="label">Processos Despachados Este Mês</div></div></div></div>
            <?php elseif ($profileCode === 'DOCE'): ?>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><div class="value"><?= $kpis['pending'] ?? 0 ?></div><div class="label">Documentos Atribuídos a Mim</div></div></div></div>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value"><?= $kpis['dispatched'] ?? 0 ?></div><div class="label">Pareceres Emitidos Este Mês</div></div></div></div>
            <?php elseif ($profileCode === 'DIRE'): ?>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><div class="value"><?= $kpis['pending'] ?? 0 ?></div><div class="label">Todos os Requerimentos Pendentes</div></div></div></div>
                <div class="col-md-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value"><?= $kpis['dispatched'] ?? 0 ?></div><div class="label">Processos Despachados Este Mês</div></div></div></div>
            <?php elseif ($profileCode === 'ADMI' || $profileCode === 'SUPE'): ?>
                <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-users"></i></div><div class="stat-info"><div class="value"><?= $kpis['users'] ?? 0 ?></div><div class="label">Utilizadores</div></div></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-user-tag"></i></div><div class="stat-info"><div class="value"><?= $kpis['profiles'] ?? 0 ?></div><div class="label">Perfis</div></div></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-cubes"></i></div><div class="stat-info"><div class="value"><?= $kpis['modules'] ?? 0 ?></div><div class="label">Módulos</div></div></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-user-tie"></i></div><div class="stat-info"><div class="value"><?= $kpis['positions'] ?? 0 ?></div><div class="label">Cargos</div></div></div></div>
            <?php endif; ?>
        </div>

        <?php if ($profileCode === 'ESTU'): ?>
        <div class="text-center mb-4">
            <a href="document_create.php" class="btn-action" style="padding:12px 30px;font-size:1.1rem;">
                <i class="fas fa-plus-circle"></i> Criar Novo Requerimento
            </a>
        </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-lg-6 mb-4">
                <div class="main-card" style="height:100%;">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-chart-pie" style="color:var(--accent-color);"></i>
                            <span><?= htmlspecialchars($chart1Title) ?></span>
                        </div>
                    </div>
                    <div class="main-card-body" style="padding:20px;">
                        <?php if (empty($chart1) || array_sum(array_column($chart1, 'total')) == 0): ?>
                            <div class="empty-state"><i class="fas fa-chart-pie"></i><p>Sem dados.</p></div>
                        <?php else: ?>
                            <div style="position:relative;height:280px;">
                                <canvas id="chart1"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="main-card" style="height:100%;">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-chart-bar" style="color:var(--accent-color);"></i>
                            <span><?= htmlspecialchars($chart2Title) ?></span>
                        </div>
                    </div>
                    <div class="main-card-body" style="padding:20px;">
                        <?php if (empty($chart2) || array_sum(array_column($chart2, 'total')) == 0): ?>
                            <div class="empty-state"><i class="fas fa-chart-bar"></i><p>Sem dados.</p></div>
                        <?php else: ?>
                            <div style="position:relative;height:280px;">
                                <canvas id="chart2"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="main-card" style="height:100%;">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-chart-line" style="color:var(--accent-color);"></i>
                            <span><?= htmlspecialchars($chart3Title) ?></span>
                        </div>
                    </div>
                    <div class="main-card-body" style="padding:20px;">
                        <?php if (empty($chart3) || array_sum(array_column($chart3, 'total')) == 0): ?>
                            <div class="empty-state"><i class="fas fa-chart-line"></i><p>Sem dados.</p></div>
                        <?php else: ?>
                            <div style="position:relative;height:280px;">
                                <canvas id="chart3"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="main-card" style="height:100%;">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-chart-doughnut" style="color:var(--accent-color);"></i>
                            <span><?= htmlspecialchars($chart4Title) ?></span>
                        </div>
                    </div>
                    <div class="main-card-body" style="padding:20px;">
                        <?php if (empty($chart4) || array_sum(array_column($chart4, 'total')) == 0): ?>
                            <div class="empty-state"><i class="fas fa-chart-doughnut"></i><p>Sem dados.</p></div>
                        <?php else: ?>
                            <div style="position:relative;height:280px;">
                                <canvas id="chart4"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($recentDocs)): ?>
        <div class="main-card mb-4">
            <div class="main-card-header">
                <div class="title"><i class="fas fa-list"></i><span><?= htmlspecialchars($tableTitle) ?></span></div>
                <div class="d-flex gap-2">
                    <?php if (!in_array($profileCode, ['SUPE','ADMI'], true)): ?>
                        <a href="documents.php" class="btn-action"><i class="fas fa-eye"></i> Ver Todos</a>
                    <?php endif; ?>
                    <a href="export_pdf.php?tipo=dashboard" target="_blank" class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);text-decoration:none;">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                </div>
            </div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead><tr><?php foreach ($tableCols as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php foreach ($recentDocs as $d):
                                if (in_array($profileCode, ['SUPE','ADMI'], true)):
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($d['METRIC'] ?? '—') ?></strong></td>
                                    <td><span class="badge-status badge-info"><?= htmlspecialchars($d['VAL'] ?? '0') ?></span></td>
                                </tr>
                            <?php
                                continue;
                                endif;

                                $st = $d['DOCUMENT_STATUS'] ?? 'SUBMITTED';
                                [$stLabel, $stCls] = $badgeStatus[$st] ?? [$st,'badge-secondary'];
                                $pr = $d['DOCUMENT_PRIORITY'] ?? 'NORMAL';
                                [$prLabel, $prCls] = $priorityBadge[$pr] ?? [$pr,'badge-secondary'];
                            ?>
                                <tr>
                                    <?php if ($profileCode === 'ESTU'): ?>
                                        <td><a href="document_view.php?id=<?= (int)$d['DOCUMENT_ID'] ?>" style="text-decoration:none;"><code><?= htmlspecialchars($d['DOCUMENT_CODE']) ?></code></a></td>
                                        <td><?= htmlspecialchars($d['DOCUMENT_TYPE_NAME'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($d['DOCUMENT_CREATEDAT']))) ?></td>
                                        <td><span class="badge-status <?= $stCls ?>"><?= htmlspecialchars($stLabel) ?></span></td>
                                    <?php else: ?>
                                        <td><a href="document_view.php?id=<?= (int)$d['DOCUMENT_ID'] ?>" style="text-decoration:none;"><code><?= htmlspecialchars($d['DOCUMENT_CODE']) ?></code></a></td>
                                        <td><?= htmlspecialchars($d['OWNER'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($d['DOCUMENT_TYPE_NAME'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($d['DOCUMENT_CREATEDAT']))) ?></td>
                                        <td><span class="badge-status <?= $prCls ?>"><?= htmlspecialchars($prLabel) ?></span></td>
                                        <?php if ($showActions): ?>
                                        <td>
                                            <div class="action-links">
                                                <a href="document_view.php?id=<?= (int)$d['DOCUMENT_ID'] ?>" class="btn-icon view" title="Ver / Tramitar">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const COLORS = ['#3498db','#2ecc71','#f39c12','#e74c3c','#9b59b6','#1abc9c','#e67e22','#34495e','#16a085','#c0392b'];
                const STATUS_PT = {
                    'DRAFT':'Rascunho','SUBMITTED':'Submetido','IN_ANALYSIS':'Em Análise',
                    'FORWARDED':'Reencaminhado','IN_RESOLUTION':'Em Resolução','RESOLVED':'Resolvido',
                    'RETURNED':'Devolvido','CLOSED':'Fechado','REJECTED':'Rejeitado','CANCELLED':'Cancelado'
                };
                const ACTION_PT = {
                    'SUBMIT':'Submissão','FORWARD':'Encaminhamento','RESOLVE':'Resolução',
                    'RETURN':'Devolução','REJECT':'Rejeição','CLOSE':'Fecho'
                };
                const PROFILE = '<?= $profileCode ?>';
                const USE_STATUS_PT = ['ESTU','SECR','DOCE','DIRE'].includes(PROFILE);
                const USE_ACTION_PT = ['SECR','DOCE'].includes(PROFILE) && <?= json_encode(in_array($chart1Title, ['Documentos na Secretaria por Estado','Os Meus Atribuídos por Estado'], true) ? 'false' : 'true') ?>;

                function translateLabels(labels, kind) {
                    return labels.map(l => {
                        if (kind === 'status') return STATUS_PT[l] || l;
                        if (kind === 'action') return ACTION_PT[l] || l;
                        return l;
                    });
                }

                <?php if (!empty($chart1)): ?>
                (function() {
                    const ctx = document.getElementById('chart1');
                    if (!ctx) return;
                    const rawLabels = <?= json_encode(array_column($chart1, 'label')) ?>;
                    const labels = USE_STATUS_PT ? translateLabels(rawLabels, 'status') : rawLabels;
                    new Chart(ctx, {
                        type: '<?= $chart1Type ?>',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: <?= json_encode(array_map('intval', array_column($chart1, 'total'))) ?>,
                                backgroundColor: COLORS,
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            cutout: '<?= $chart1Type === 'doughnut' ? '60%' : '0' ?>',
                            plugins: { legend: { position: 'right', labels: { font: { size: 11 }, padding: 10 } } }
                        }
                    });
                })();
                <?php endif; ?>

                <?php if (!empty($chart2)): ?>
                (function() {
                    const ctx = document.getElementById('chart2');
                    if (!ctx) return;
                    new Chart(ctx, {
                        type: '<?= $chart2Type ?>',
                        data: {
                            labels: <?= json_encode(array_column($chart2, 'label')) ?>,
                            datasets: [{
                                label: 'Total',
                                data: <?= json_encode(array_map('intval', array_column($chart2, 'total'))) ?>,
                                backgroundColor: COLORS,
                                borderRadius: 6,
                                borderSkipped: false
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                                x: { ticks: { font: { size: 10 } } }
                            }
                        }
                    });
                })();
                <?php endif; ?>

                <?php if (!empty($chart3)): ?>
                (function() {
                    const ctx = document.getElementById('chart3');
                    if (!ctx) return;
                    new Chart(ctx, {
                        type: '<?= $chart3Type ?>',
                        data: {
                            labels: <?= json_encode(array_column($chart3, 'label')) ?>,
                            datasets: [{
                                label: 'Total',
                                data: <?= json_encode(array_map('intval', array_column($chart3, 'total'))) ?>,
                                borderColor: '#3498db',
                                backgroundColor: 'rgba(52,152,219,.15)',
                                fill: true, tension: 0.35,
                                pointRadius: 5, pointBackgroundColor: '#3498db',
                                pointBorderColor: '#fff', pointBorderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }
                        }
                    });
                })();
                <?php endif; ?>

                <?php if (!empty($chart4)): ?>
                (function() {
                    const ctx = document.getElementById('chart4');
                    if (!ctx) return;
                    const rawLabels = <?= json_encode(array_column($chart4, 'label')) ?>;
                    const labels = <?= json_encode(in_array($profileCode, ['SECR','DOCE'], true)) ?>
                        ? translateLabels(rawLabels, 'action')
                        : rawLabels;
                    new Chart(ctx, {
                        type: '<?= $chart4Type ?>',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: <?= json_encode(array_map('intval', array_column($chart4, 'total'))) ?>,
                                backgroundColor: COLORS,
                                borderWidth: 2, borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            cutout: '<?= $chart4Type === 'doughnut' ? '60%' : '0' ?>',
                            plugins: { legend: { position: 'right', labels: { font: { size: 11 }, padding: 10 } } }
                        }
                    });
                })();
                <?php endif; ?>
            });
        </script>

<?php
    include 'includes/modals.php';
    include 'includes/footer.php';
?>