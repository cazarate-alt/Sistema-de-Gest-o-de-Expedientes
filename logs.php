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
    requirePermissionOrRedirect($pdo, 'LOGS', 'VIEW');

    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $__allowed = allowedModules($pdo);
    $_SESSION['__allowed_modules'] = $__allowed;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'list') {
            $page = max(1, intval($_POST['page'] ?? 1));
            $perPage = min(100, max(5, intval($_POST['per_page'] ?? 25)));
            $offset = ($page - 1) * $perPage;
            $module = trim($_POST['module'] ?? '');
            $operation = trim($_POST['operation'] ?? '');
            $severity = trim($_POST['severity'] ?? '');
            $userId = trim($_POST['user_id'] ?? '');
            $search = trim($_POST['search'] ?? '');
            $dateFrom = trim($_POST['date_from'] ?? '');
            $dateTo = trim($_POST['date_to'] ?? '');

            $where = []; $params = [];
            if ($module !== '') { $where[] = "LOG_MODULE = :m"; $params[':m'] = $module; }
            if ($operation !== '') { $where[] = "LOG_ACTION = :op"; $params[':op'] = $operation; }
            if ($severity !== '') { $where[] = "LOG_TYPE = :sev"; $params[':sev'] = $severity; }
            if ($userId !== '' && ctype_digit($userId)) { $where[] = "LOG_USER_ID = :uid"; $params[':uid'] = (int)$userId; }
            if ($search !== '') { $where[] = "(LOG_DESCRIPTION LIKE :s OR LOG_USER_NAME LIKE :s OR LOG_MODULE LIKE :s)"; $params[':s'] = '%'.$search.'%'; }
            if ($dateFrom !== '') { $where[] = "LOG_CREATEDAT >= :df"; $params[':df'] = $dateFrom.' 00:00:00'; }
            if ($dateTo !== '') { $where[] = "LOG_CREATEDAT <= :dt"; $params[':dt'] = $dateTo.' 23:59:59'; }
            $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `LOG` $whereSql"); $st->execute($params);
                $total = (int)$st->fetchColumn();

                $sql = "SELECT LOG_ID, LOG_MODULE, LOG_ACTION, LOG_TYPE, LOG_DESCRIPTION,
                            LOG_USER_ID, LOG_USER_NAME, LOG_IP, LOG_CREATEDAT
                        FROM `LOG` $whereSql ORDER BY LOG_ID DESC LIMIT $perPage OFFSET $offset";
                $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll();

                echo json_encode(['success'=>true,'data'=>[
                    'rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per_page'=>$perPage,
                    'pages'=>max(1,(int)ceil($total/$perPage))
                ]]);
            } catch (\PDOException $e) { echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]); }
            exit;
        }

        if ($action === 'detail') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            $st = $pdo->prepare("SELECT * FROM `LOG` WHERE LOG_ID = :id");
            $st->execute([':id'=>$id]); $row = $st->fetch();
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Log não encontrado.']); exit; }
            echo json_encode(['success'=>true,'data'=>$row]);
            exit;
        }

        if ($action === 'stats') {
            $total  = (int)$pdo->query("SELECT COUNT(*) FROM `LOG`")->fetchColumn();
            $today  = (int)$pdo->query("SELECT COUNT(*) FROM `LOG` WHERE DATE(LOG_CREATEDAT)=CURDATE()")->fetchColumn();
            $week   = (int)$pdo->query("SELECT COUNT(*) FROM `LOG` WHERE LOG_CREATEDAT >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
            $errors = (int)$pdo->query("SELECT COUNT(*) FROM `LOG` WHERE LOG_TYPE IN ('ERROR','SECURITY')")->fetchColumn();
            echo json_encode(['success'=>true,'data'=>compact('total','today','week','errors')]);
            exit;
        }

        if ($action === 'filter_options') {
            $modules = $pdo->query("SELECT DISTINCT LOG_MODULE FROM `LOG` WHERE LOG_MODULE IS NOT NULL ORDER BY LOG_MODULE")->fetchAll(PDO::FETCH_COLUMN);
            $users   = $pdo->query("SELECT DISTINCT LOG_USER_ID, LOG_USER_NAME FROM `LOG` WHERE LOG_USER_ID IS NOT NULL ORDER BY LOG_USER_NAME")->fetchAll();
            echo json_encode(['success'=>true,'data'=>compact('modules','users')]);
            exit;
        }
        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
    }

    $pageTitle = 'Auditoria';
    $activePage = 'logs.php';
    include 'includes/header.php';
    include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="page-title mb-0" style="margin:0;">
            <h2><i class="fas fa-history" style="color:var(--accent-color);"></i> Auditoria do Sistema</h2>
            <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Auditoria</span></div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="exportPdf()">
                <i class="fas fa-file-pdf"></i> Exportar PDF
            </button>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-database"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
        <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-calendar-day"></i></div><div class="stat-info"><div class="value" id="statToday">—</div><div class="label">Hoje</div></div></div></div>
        <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-calendar-week"></i></div><div class="stat-info"><div class="value" id="statWeek">—</div><div class="label">Últimos 7 Dias</div></div></div></div>
        <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(231,76,60,.12);color:#e74c3c;"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-info"><div class="value" id="statErrors">—</div><div class="label">Erros/Segurança</div></div></div></div>
    </div>

    <div class="filter-card">
        <h6><i class="fas fa-filter"></i> Filtros</h6>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Descrição, utilizador, módulo..."></div>
            <div class="col-md-2"><label class="form-label">Módulo</label><select class="form-select" id="filterModule"><option value="">Todos</option></select></div>
            <div class="col-md-2"><label class="form-label">Operação</label>
                <select class="form-select" id="filterOperation"><option value="">Todas</option>
                    <option value="INSERT">INSERT</option><option value="UPDATE">UPDATE</option><option value="DELETE">DELETE</option>
                    <option value="LOGIN">LOGIN</option><option value="LOGOUT">LOGOUT</option>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Severidade</label>
                <select class="form-select" id="filterSeverity"><option value="">Todas</option>
                    <option value="INFO">INFO</option><option value="WARNING">WARNING</option>
                    <option value="ERROR">ERROR</option><option value="SECURITY">SECURITY</option>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Utilizador</label><select class="form-select" id="filterUser"><option value="">Todos</option></select></div>
            <div class="col-md-3"><label class="form-label">Data Início</label><input type="date" class="form-control" id="filterDateFrom"></div>
            <div class="col-md-3"><label class="form-label">Data Fim</label><input type="date" class="form-control" id="filterDateTo"></div>
            <div class="col-md-3"><label class="form-label">Registos/Página</label>
                <select class="form-select" id="filterPerPage"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button class="btn btn-outline-secondary flex-fill" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;"><i class="fas fa-redo me-1"></i> Limpar</button>
                <button class="btn-action flex-fill" onclick="loadLogs(1)"><i class="fas fa-search"></i> Filtrar</button>
            </div>
        </div>
    </div>

    <div class="main-card">
        <div class="main-card-header">
            <div class="title"><i class="fas fa-list-ul"></i><span>Registos de Auditoria</span><span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;">0</span></div>
            <button class="btn-outline-action" onclick="loadLogs(currentPage)"><i class="fas fa-sync-alt"></i> Atualizar</button>
        </div>
        <div class="main-card-body">
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead><tr>
                        <th style="width:70px;">ID</th><th style="width:160px;">Data/Hora</th><th style="width:130px;">Operação</th>
                        <th style="width:110px;">Tipo</th><th>Módulo</th><th>Descrição</th>
                        <th class="d-none d-md-table-cell" style="width:150px;">Utilizador</th><th style="width:80px;">Detalhes</th>
                    </tr></thead>
                    <tbody id="logsTableBody">
                        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>A carregar...</p></div></td></tr>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-top:1px solid #f0f0f0;background:#fafbfc;flex-wrap:wrap;gap:12px;">
                <div style="font-size:.85rem;color:#7f8c8d;" id="paginationInfo">—</div>
                <div style="display:flex;gap:6px;align-items:center;" id="paginationControls"></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes do Registo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body" id="detailBody"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> A carregar...</div></div>
                <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
            </div>
        </div>
    </div>

    <script>
        const API_URL = 'logs.php';
        let detailModal;
        let currentPage = 1;

        document.addEventListener('DOMContentLoaded', () => {
            detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
            loadStats();
            loadFilterOptions();
            loadLogs(1);

            let t;
            document.getElementById('filterSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => loadLogs(1), 400); });
            ['filterModule','filterOperation','filterSeverity','filterUser','filterDateFrom','filterDateTo'].forEach(id => {
                document.getElementById(id).addEventListener('change', () => loadLogs(1));
            });
            document.getElementById('filterPerPage').addEventListener('change', () => loadLogs(1));
            document.getElementById('filterSearch').addEventListener('keydown', e => { if (e.key === 'Enter') loadLogs(1); });
        });

        function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
        function formatDateTime(dt){ if (!dt) return '—'; const d = new Date(dt.replace(' ','T')); if (isNaN(d)) return dt; return d.toLocaleDateString('pt-PT',{day:'2-digit',month:'2-digit',year:'numeric'}) + ' ' + d.toLocaleTimeString('pt-PT',{hour:'2-digit',minute:'2-digit'}); }

        function loadStats(){
            const fd = new FormData(); fd.append('action','stats');
            fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                if (j.success) {
                    document.getElementById('statTotal').textContent = j.data.total;
                    document.getElementById('statToday').textContent = j.data.today;
                    document.getElementById('statWeek').textContent = j.data.week;
                    document.getElementById('statErrors').textContent = j.data.errors;
                }
            }).catch(console.error);
        }

        function loadFilterOptions(){
            const fd = new FormData(); fd.append('action','filter_options');
            fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                if (!j.success) return;
                const selM = document.getElementById('filterModule');
                j.data.modules.forEach(t => { const o = document.createElement('option'); o.value = t; o.textContent = t; selM.appendChild(o); });
                const selU = document.getElementById('filterUser');
                j.data.users.forEach(u => { const o = document.createElement('option'); o.value = u.LOG_USER_ID; o.textContent = u.LOG_USER_NAME + ' (#' + u.LOG_USER_ID + ')'; selU.appendChild(o); });
            }).catch(console.error);
        }

        function loadLogs(page){
            currentPage = page;
            const perPage = parseInt(document.getElementById('filterPerPage').value);
            const tbody = document.getElementById('logsTableBody');
            tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>A carregar...</p></div></td></tr>`;

            const fd = new FormData();
            fd.append('action','list'); fd.append('page',page); fd.append('per_page',perPage);
            fd.append('search', document.getElementById('filterSearch').value.trim());
            fd.append('module', document.getElementById('filterModule').value);
            fd.append('operation', document.getElementById('filterOperation').value);
            fd.append('severity', document.getElementById('filterSeverity').value);
            fd.append('user_id', document.getElementById('filterUser').value);
            fd.append('date_from', document.getElementById('filterDateFrom').value);
            fd.append('date_to', document.getElementById('filterDateTo').value);

            fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                if (!j.success) { tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><p>${escapeHtml(j.message)}</p></div></td></tr>`; return; }
                renderLogs(j.data);
            }).catch(err => { console.error(err); tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><p>Erro de comunicação.</p></div></td></tr>`; });
        }

        function renderLogs(data){
            const tbody = document.getElementById('logsTableBody');
            const rows = data.rows;
            if (!rows.length){
                tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhum registo encontrado</h6></div></td></tr>`;
                document.getElementById('listCount').textContent = 0;
                document.getElementById('paginationInfo').textContent = 'Sem registos';
                document.getElementById('paginationControls').innerHTML = '';
                return;
            }
            let html = '';
            rows.forEach(r => {
                const sev = r.LOG_TYPE || 'INFO';
                const badgeCls = sev==='SECURITY'?'badge-system':sev==='ERROR'?'badge-inactive':sev==='WARNING'?'badge-system':'badge-active';
                html += `<tr>
                    <td><strong>#${r.LOG_ID}</strong></td>
                    <td style="font-size:.82rem;color:#7f8c8d;white-space:nowrap;">${formatDateTime(r.LOG_CREATEDAT)}</td>
                    <td><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.75rem;">${escapeHtml(r.LOG_ACTION)}</code></td>
                    <td><span class="badge-status ${badgeCls}" style="font-size:.7rem;">${escapeHtml(sev)}</span></td>
                    <td style="font-weight:600;color:var(--primary-color);font-size:.85rem;">${escapeHtml(r.LOG_MODULE || '—')}</td>
                    <td style="font-size:.85rem;">${escapeHtml(r.LOG_DESCRIPTION || '—')}</td>
                    <td class="d-none d-md-table-cell" style="font-size:.85rem;">${escapeHtml(r.LOG_USER_NAME || 'system')}</td>
                    <td><div class="action-links"><button class="btn-icon view" onclick="openDetailModal(${r.LOG_ID})"><i class="fas fa-eye"></i></button></div></td>
                </tr>`;
            });
            tbody.innerHTML = html;
            document.getElementById('listCount').textContent = data.total;
            renderPagination(data);
        }

        function renderPagination(data){
            const info = document.getElementById('paginationInfo');
            const controls = document.getElementById('paginationControls');
            const from = data.total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
            const to = Math.min(data.page * data.per_page, data.total);
            info.innerHTML = `A mostrar <strong>${from}</strong>–<strong>${to}</strong> de <strong>${data.total}</strong> registos`;

            let html = '';
            const prev = data.page > 1 ? data.page - 1 : 1;
            const next = data.page < data.pages ? data.page + 1 : data.pages;
            const style = "min-width:36px;height:36px;border:1.5px solid #e0e0e0;background:white;border-radius:8px;color:var(--primary-color);font-size:.85rem;font-weight:500;cursor:pointer;padding:0 10px;";
            html += `<button onclick="loadLogs(${prev})" ${data.page <= 1 ? 'disabled' : ''} style="${style}"><i class="fas fa-chevron-left"></i></button>`;
            let start = Math.max(1, data.page - 3);
            let end = Math.min(data.pages, start + 6);
            if (end - start < 6) start = Math.max(1, end - 6);
            for (let i = start; i <= end; i++) {
                html += `<button onclick="loadLogs(${i})" style="${style}${i===data.page?'background:var(--accent-color);color:white;border-color:var(--accent-color);':''}">${i}</button>`;
            }
            html += `<button onclick="loadLogs(${next})" ${data.page >= data.pages ? 'disabled' : ''} style="${style}"><i class="fas fa-chevron-right"></i></button>`;
            controls.innerHTML = html;
        }

        function resetFilters(){
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterModule').value = '';
            document.getElementById('filterOperation').value = '';
            document.getElementById('filterSeverity').value = '';
            document.getElementById('filterUser').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterPerPage').value = '25';
            loadLogs(1);
        }

        function openDetailModal(id){
            const body = document.getElementById('detailBody');
            body.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> A carregar...</div>';
            detailModal.show();
            const fd = new FormData(); fd.append('action','detail'); fd.append('id',id);
            fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                if (!j.success) { body.innerHTML = `<div class="text-center py-4 text-danger">${escapeHtml(j.message)}</div>`; return; }
                renderDetail(j.data);
            }).catch(()=>{ body.innerHTML = '<div class="text-center py-4 text-danger">Erro de comunicação.</div>'; });
        }

        function renderDetail(d){
            document.getElementById('detailBody').innerHTML = `
                <div class="info-row"><div class="label"><i class="fas fa-hashtag"></i> ID:</div><div class="value">#${d.LOG_ID}</div></div>
                <div class="info-row"><div class="label"><i class="fas fa-table"></i> Módulo:</div><div class="value"><code>${escapeHtml(d.LOG_MODULE || '—')}</code></div></div>
                <div class="info-row"><div class="label"><i class="fas fa-cog"></i> Operação:</div><div class="value"><code>${escapeHtml(d.LOG_ACTION)}</code></div></div>
                <div class="info-row"><div class="label"><i class="fas fa-exclamation-triangle"></i> Tipo:</div><div class="value">${escapeHtml(d.LOG_TYPE)}</div></div>
                <div class="info-row"><div class="label"><i class="fas fa-align-left"></i> Descrição:</div><div class="value">${escapeHtml(d.LOG_DESCRIPTION || '—')}</div></div>
                <div class="info-row"><div class="label"><i class="fas fa-user"></i> Utilizador:</div><div class="value">${escapeHtml(d.LOG_USER_NAME || 'system')} ${d.LOG_USER_ID ? '(ID #' + d.LOG_USER_ID + ')' : ''}</div></div>
                <div class="info-row"><div class="label"><i class="fas fa-network-wired"></i> IP:</div><div class="value">${escapeHtml(d.LOG_IP || '—')}</div></div>
                <div class="info-row"><div class="label"><i class="fas fa-desktop"></i> User Agent:</div><div class="value" style="font-size:.78rem;word-break:break-all;">${escapeHtml(d.LOG_USER_AGENT || '—')}</div></div>
                <div class="info-row"><div class="label"><i class="fas fa-calendar-plus"></i> Data/Hora:</div><div class="value">${formatDateTime(d.LOG_CREATEDAT)}</div></div>
            `;
        }

        function exportPdf(){
            const params = new URLSearchParams({
                search: document.getElementById('filterSearch').value.trim(),
                module: document.getElementById('filterModule').value,
                operation: document.getElementById('filterOperation').value,
                severity: document.getElementById('filterSeverity').value,
                user_id: document.getElementById('filterUser').value,
                date_from: document.getElementById('filterDateFrom').value,
                date_to: document.getElementById('filterDateTo').value
            });
            window.open('logs_pdf.php?' + params.toString(), '_blank');
        }
    </script>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>