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
requirePermissionOrRedirect($pdo, 'REPORTS', 'VIEW');

$profileCode = strtoupper($_SESSION['profile_code'] ?? '');
$__allowed = allowedModules($pdo);
$_SESSION['__allowed_modules'] = $__allowed;

function safeScalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try { $st = $pdo->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return $v === false ? $default : $v; }
    catch (\Throwable $e) { return $default; }
}
function safeQuery(PDO $pdo, string $sql, array $params = [], $default = []) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
    catch (\Throwable $e) { return $default; }
}

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

$kpis = [
    'docs_total'   => safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom, ':t'=>$dateTo]),
    'docs_resolved'=> safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS IN ('RESOLVED','CLOSED') AND DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom, ':t'=>$dateTo]),
    'docs_pending' => safeScalar($pdo, "SELECT COUNT(*) FROM `DOCUMENT` WHERE DOCUMENT_STATUS NOT IN ('RESOLVED','CLOSED','REJECTED','CANCELLED') AND DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom, ':t'=>$dateTo]),
    'users_total'  => safeScalar($pdo, "SELECT COUNT(*) FROM `USERS` WHERE USER_STATUS=1"),
    'logs_total'   => safeScalar($pdo, "SELECT COUNT(*) FROM `LOG` WHERE DATE(LOG_CREATEDAT) BETWEEN :f AND :t", [':f'=>$dateFrom, ':t'=>$dateTo]),
];

$byType = safeQuery($pdo,
    "SELECT t.DOCUMENT_TYPE_NAME AS name, COUNT(d.DOCUMENT_ID) AS total
     FROM `DOCUMENT_TYPE` t
     LEFT JOIN `DOCUMENT` d ON d.DOCUMENT_TYPE_ID = t.DOCUMENT_TYPE_ID
       AND DATE(d.DOCUMENT_CREATEDAT) BETWEEN :f AND :t
     GROUP BY t.DOCUMENT_TYPE_ID, t.DOCUMENT_TYPE_NAME
     ORDER BY total DESC", [':f'=>$dateFrom, ':t'=>$dateTo]);

$byStatus = safeQuery($pdo,
    "SELECT DOCUMENT_STATUS AS status, COUNT(*) AS total
     FROM `DOCUMENT`
     WHERE DATE(DOCUMENT_CREATEDAT) BETWEEN :f AND :t
     GROUP BY DOCUMENT_STATUS ORDER BY total DESC", [':f'=>$dateFrom, ':t'=>$dateTo]);

$byProfile = safeQuery($pdo,
    "SELECT p.PROFILE_NAME AS name, COUNT(d.DOCUMENT_ID) AS total
     FROM `PROFILE` p
     LEFT JOIN `USERS` u ON u.PROFILE_ID = p.PROFILE_ID
     LEFT JOIN `DOCUMENT` d ON d.USER_ID = u.USER_ID
       AND DATE(d.DOCUMENT_CREATEDAT) BETWEEN :f AND :t
     GROUP BY p.PROFILE_ID, p.PROFILE_NAME
     ORDER BY total DESC", [':f'=>$dateFrom, ':t'=>$dateTo]);

$byWeek = safeQuery($pdo,
    "SELECT YEARWEEK(DOCUMENT_CREATEDAT, 1) AS yw,
            MIN(DATE(DOCUMENT_CREATEDAT)) AS start_date,
            COUNT(*) AS total
     FROM `DOCUMENT`
     WHERE DOCUMENT_CREATEDAT >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
     GROUP BY yw ORDER BY yw ASC");

$topUsers = safeQuery($pdo,
    "SELECT CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS name, COUNT(d.DOCUMENT_ID) AS total
     FROM `USERS` u
     JOIN `DOCUMENT` d ON d.USER_ID = u.USER_ID
     WHERE DATE(d.DOCUMENT_CREATEDAT) BETWEEN :f AND :t
     GROUP BY u.USER_ID, name
     ORDER BY total DESC LIMIT 10", [':f'=>$dateFrom, ':t'=>$dateTo]);

$badgeStatus = [
    'DRAFT'=>['Rascunho','badge-secondary'], 'SUBMITTED'=>['Submetido','badge-info'],
    'IN_ANALYSIS'=>['Em análise','badge-primary'], 'FORWARDED'=>['Reencaminhado','badge-warning'],
    'IN_RESOLUTION'=>['Em resolução','badge-warning'], 'RESOLVED'=>['Resolvido','badge-success'],
    'RETURNED'=>['Devolvido','badge-info'], 'CLOSED'=>['Fechado','badge-success'],
    'REJECTED'=>['Rejeitado','badge-danger'], 'CANCELLED'=>['Cancelado','badge-secondary'],
];

$pageTitle = 'Relatórios';
$activePage = 'reports.php';
include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';

$chartColors = ['#3498db','#2ecc71','#f39c12','#e74c3c','#9b59b6','#1abc9c','#e67e22','#34495e','#16a085','#c0392b'];
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-title mb-0" style="margin:0;">
        <h2><i class="fas fa-chart-bar" style="color:var(--accent-color);"></i> Relatórios do Sistema</h2>
        <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Relatórios</span></div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="exportPdf()">
            <i class="fas fa-file-pdf"></i> Exportar PDF
        </button>
    </div>
</div>

<div class="filter-card mb-4">
    <h6><i class="fas fa-calendar"></i> Período</h6>
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Data Início</label>
            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Data Fim</label>
            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn-action" style="width:100%;"><i class="fas fa-search"></i> Aplicar Filtro</button>
        </div>
    </form>
</div>

<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="stat-card">
            <div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-file-alt"></i></div>
            <div class="stat-info"><div class="value"><?= $kpis['docs_total'] ?></div><div class="label">Documentos no Período</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="stat-card">
            <div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info"><div class="value"><?= $kpis['docs_resolved'] ?></div><div class="label">Resolvidos</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="stat-card">
            <div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info"><div class="value"><?= $kpis['docs_pending'] ?></div><div class="label">Pendentes</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="stat-card">
            <div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-users"></i></div>
            <div class="stat-info"><div class="value"><?= $kpis['users_total'] ?></div><div class="label">Utilizadores Ativos</div></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-6 mb-4">
        <div class="main-card" style="height:100%;">
            <div class="main-card-header"><div class="title"><i class="fas fa-chart-pie"></i><span>Documentos por Tipo</span></div></div>
            <div class="main-card-body" style="padding:20px;">
                <?php if (empty($byType) || array_sum(array_column($byType, 'total')) == 0): ?>
                    <div class="empty-state"><i class="fas fa-chart-pie"></i><p>Sem dados no período.</p></div>
                <?php else: ?>
                    <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
                        <div style="position:relative;width:180px;height:180px;flex-shrink:0;">
                            <?php
                            $total = array_sum(array_column($byType, 'total'));
                            $gradientParts = []; $angle = 0;
                            foreach ($byType as $i => $t) {
                                $pct = ($t['total'] / $total) * 360;
                                $color = $chartColors[$i % count($chartColors)];
                                $gradientParts[] = "$color {$angle}deg " . ($angle + $pct) . "deg";
                                $angle += $pct;
                            }
                            ?>
                            <div style="width:100%;height:100%;border-radius:50%;background:conic-gradient(<?= implode(',', $gradientParts) ?>);"></div>
                            <div style="position:absolute;inset:30px;background:white;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-direction:column;">
                                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-color);"><?= $total ?></div>
                                <div style="font-size:.7rem;color:#7f8c8d;text-transform:uppercase;">Total</div>
                            </div>
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <?php foreach ($byType as $i => $t):
                                $pct = $total > 0 ? round(($t['total']/$total)*100, 1) : 0;
                                $color = $chartColors[$i % count($chartColors)];
                            ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:.85rem;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?= $color ?>;"></span>
                                        <span style="color:var(--primary-color);"><?= htmlspecialchars($t['name']) ?></span>
                                    </div>
                                    <div style="color:#7f8c8d;"><strong><?= $t['total'] ?></strong> (<?= $pct ?>%)</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="main-card" style="height:100%;">
            <div class="main-card-header"><div class="title"><i class="fas fa-chart-bar"></i><span>Documentos por Semana (últimas 8)</span></div></div>
            <div class="main-card-body" style="padding:20px;">
                <?php if (empty($byWeek)): ?>
                    <div class="empty-state"><i class="fas fa-chart-bar"></i><p>Sem dados.</p></div>
                <?php else:
                    $maxWeek = max(array_column($byWeek, 'total')) ?: 1;
                ?>
                    <div style="display:flex;align-items:flex-end;gap:12px;height:200px;padding:0 10px;">
                        <?php foreach ($byWeek as $w):
                            $h = ($w['total'] / $maxWeek) * 150;
                        ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;">
                                <div style="font-size:.75rem;color:#7f8c8d;font-weight:600;"><?= $w['total'] ?></div>
                                <div style="width:100%;height:<?= max($h, 5) ?>px;background:linear-gradient(180deg,#3498db,#2980b9);border-radius:6px 6px 0 0;transition:all .3s;" title="<?= $w['total'] ?> documentos"></div>
                                <div style="font-size:.7rem;color:#7f8c8d;text-align:center;white-space:nowrap;">
                                    <?= date('d/m', strtotime($w['start_date'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-6 mb-4">
        <div class="main-card" style="height:100%;">
            <div class="main-card-header"><div class="title"><i class="fas fa-list-ul"></i><span>Documentos por Estado</span></div></div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead><tr><th>Estado</th><th style="width:100px;text-align:right;">Total</th><th style="width:180px;">Percentagem</th></tr></thead>
                        <tbody>
                            <?php if (empty($byStatus)): ?>
                                <tr><td colspan="3"><div class="empty-state"><p>Sem dados.</p></div></td></tr>
                            <?php else:
                                $totalStatus = array_sum(array_column($byStatus, 'total')) ?: 1;
                                foreach ($byStatus as $s):
                                    $st = $s['status'];
                                    [$label, $cls] = $badgeStatus[$st] ?? [$st, 'badge-secondary'];
                                    $pct = round(($s['total']/$totalStatus)*100, 1);
                            ?>
                                <tr>
                                    <td><span class="badge-status <?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
                                    <td style="text-align:right;font-weight:700;color:var(--primary-color);"><?= $s['total'] ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <div style="flex:1;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;">
                                                <div style="width:<?= $pct ?>%;height:100%;background:var(--accent-color);"></div>
                                            </div>
                                            <span style="font-size:.78rem;color:#7f8c8d;min-width:45px;text-align:right;"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="main-card" style="height:100%;">
            <div class="main-card-header"><div class="title"><i class="fas fa-user-tag"></i><span>Documentos por Perfil</span></div></div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead><tr><th>Perfil</th><th style="width:100px;text-align:right;">Total</th></tr></thead>
                        <tbody>
                            <?php if (empty($byProfile)): ?>
                                <tr><td colspan="2"><div class="empty-state"><p>Sem dados.</p></div></td></tr>
                            <?php else: foreach ($byProfile as $p): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?= htmlspecialchars($p['name']) ?></span></td>
                                    <td style="text-align:right;font-weight:700;color:var(--primary-color);"><?= $p['total'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="main-card mb-4">
    <div class="main-card-header"><div class="title"><i class="fas fa-trophy"></i><span>Top 10 Utilizadores (por Documentos Submetidos)</span></div></div>
    <div class="main-card-body">
        <div class="table-responsive">
            <table class="table table-custom">
                <thead><tr><th style="width:80px;">#</th><th>Utilizador</th><th style="width:150px;text-align:right;">Documentos</th></tr></thead>
                <tbody>
                    <?php if (empty($topUsers)): ?>
                        <tr><td colspan="3"><div class="empty-state"><i class="fas fa-trophy"></i><p>Sem dados no período.</p></div></td></tr>
                    <?php else: foreach ($topUsers as $i => $u): ?>
                        <tr>
                            <td><strong>#<?= $i + 1 ?></strong></td>
                            <td><?= htmlspecialchars($u['name']) ?></td>
                            <td style="text-align:right;font-weight:700;color:var(--primary-color);"><?= $u['total'] ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function exportPdf(){
    const params = new URLSearchParams({
        from: '<?= htmlspecialchars($dateFrom) ?>',
        to: '<?= htmlspecialchars($dateTo) ?>'
    });
    window.open('reports_pdf.php?' + params.toString(), '_blank');
}
</script>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>