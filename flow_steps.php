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
    requirePermissionOrRedirect($pdo, 'FLOWSTEPS', 'VIEW');

    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $__allowed = allowedModules($pdo);
    $_SESSION['__allowed_modules'] = $__allowed;

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'save') {
            $isEdit = !empty($_POST['id']);
            requirePermissionApi($pdo, 'FLOWSTEPS', $isEdit ? 'UPDT' : 'CREA');
            $id       = $isEdit ? intval($_POST['id']) : null;
            $flowId   = (int)($_POST['flow_id'] ?? 0);
            $profId   = (int)($_POST['profile_id'] ?? 0);
            $order    = (int)($_POST['order'] ?? 1);
            $name     = trim($_POST['nome'] ?? '');
            $stepAct  = $_POST['step_action'] ?? 'ANALYSE';
            $isFinal  = isset($_POST['is_final']) ? 1 : 0;
            $status   = isset($_POST['status']) ? intval($_POST['status']) : 1;

            $errors = [];
            if ($flowId <= 0) $errors['flow_id'] = 'Selecione o fluxo.';
            if ($profId <= 0) $errors['profile_id'] = 'Selecione o perfil.';
            if ($order <= 0) $errors['order'] = 'A ordem deve ser positiva.';
            if ($name === '') $errors['nome'] = 'O nome é obrigatório.';

            if (!isset($errors['order']) && !isset($errors['flow_id'])) {
                $sql = "SELECT COUNT(*) FROM `FLOW_STEP` WHERE FLOW_ID=:f AND FLOW_STEP_ORDER=:o";
                $p = [':f'=>$flowId, ':o'=>$order];
                if ($id) { $sql .= " AND FLOW_STEP_ID <> :id"; $p[':id']=$id; }
                $st = $pdo->prepare($sql); $st->execute($p);
                if ((int)$st->fetchColumn() > 0) $errors['order'] = 'Já existe um passo com esta ordem neste fluxo.';
            }
            if ($errors) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }

            try {
                if ($id) {
                    $pdo->prepare("UPDATE `FLOW_STEP` SET FLOW_ID=:f, PROFILE_ID=:p, FLOW_STEP_ORDER=:o, FLOW_STEP_NAME=:n, FLOW_STEP_ACTION=:a, FLOW_STEP_IS_FINAL=:fi, FLOW_STEP_STATUS=:s WHERE FLOW_STEP_ID=:id")
                        ->execute([':f'=>$flowId,':p'=>$profId,':o'=>$order,':n'=>$name,':a'=>$stepAct,':fi'=>$isFinal,':s'=>$status,':id'=>$id]);
                    echo json_encode(['success'=>true,'message'=>'Passo atualizado com sucesso!']);
                } else {
                    $pdo->prepare("INSERT INTO `FLOW_STEP` (FLOW_ID, PROFILE_ID, FLOW_STEP_ORDER, FLOW_STEP_NAME, FLOW_STEP_ACTION, FLOW_STEP_IS_FINAL, FLOW_STEP_STATUS) VALUES (:f,:p,:o,:n,:a,:fi,:s)")
                        ->execute([':f'=>$flowId,':p'=>$profId,':o'=>$order,':n'=>$name,':a'=>$stepAct,':fi'=>$isFinal,':s'=>$status]);
                    echo json_encode(['success'=>true,'message'=>'Passo criado com sucesso!','id'=>(int)$pdo->lastInsertId()]);
                }
            } catch (\PDOException $e) { echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]); }
            exit;
        }

        if ($action === 'toggle') {
            requirePermissionApi($pdo, 'FLOWSTEPS', 'UPDT');
            $id = intval($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT FLOW_STEP_STATUS FROM `FLOW_STEP` WHERE FLOW_STEP_ID=:id");
            $st->execute([':id'=>$id]); $cur = $st->fetchColumn();
            if ($cur === false) { echo json_encode(['success'=>false,'message'=>'Passo não encontrado.']); exit; }
            $new = ((int)$cur === 1) ? 0 : 1;
            $pdo->prepare("UPDATE `FLOW_STEP` SET FLOW_STEP_STATUS=:s WHERE FLOW_STEP_ID=:id")->execute([':s'=>$new,':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>$new?'Passo ativado!':'Passo desativado!']);
            exit;
        }

        if ($action === 'delete') {
            requirePermissionApi($pdo, 'FLOWSTEPS', 'DELE');
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM `FLOW_STEP` WHERE FLOW_STEP_ID=:id")->execute([':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>'Passo eliminado com sucesso!']);
            exit;
        }

        if ($action === 'options') {
            $flows = $pdo->query("SELECT FLOW_ID, FLOW_CODE, FLOW_NAME FROM `FLOW` WHERE FLOW_STATUS=1 ORDER BY FLOW_NAME")->fetchAll();
            $profiles = $pdo->query("SELECT PROFILE_ID, PROFILE_CODE, PROFILE_NAME FROM `PROFILE` WHERE PROFILE_STATUS=1 ORDER BY PROFILE_NAME")->fetchAll();
            echo json_encode(['success'=>true,'data'=>compact('flows','profiles')]);
            exit;
        }

        if ($action === 'stats') {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW_STEP`")->fetchColumn();
            $active = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW_STEP` WHERE FLOW_STEP_STATUS=1")->fetchColumn();
            $inactive = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW_STEP` WHERE FLOW_STEP_STATUS=0")->fetchColumn();
            $finals = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW_STEP` WHERE FLOW_STEP_IS_FINAL=1")->fetchColumn();
            echo json_encode(['success'=>true,'data'=>compact('total','active','inactive','finals')]);
            exit;
        }
        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
    }

    $steps = $pdo->query(
        "SELECT s.*, f.FLOW_CODE, f.FLOW_NAME, p.PROFILE_CODE, p.PROFILE_NAME
        FROM `FLOW_STEP` s
        LEFT JOIN `FLOW` f ON f.FLOW_ID = s.FLOW_ID
        LEFT JOIN `PROFILE` p ON p.PROFILE_ID = s.PROFILE_ID
        ORDER BY s.FLOW_ID, s.FLOW_STEP_ORDER"
    )->fetchAll();

    $pageTitle = 'Passos dos Fluxos';
    $activePage = 'flow_steps.php';
    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-stream" style="color:var(--accent-color);"></i> Passos dos Fluxos</h2>
                <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Passos</span></div>
            </div>
            <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=flowsteps','_blank')">
                <i class="fas fa-file-pdf"></i> Exportar PDF
            </button>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-stream"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="statActive">—</div><div class="label">Ativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(231,76,60,.12);color:#e74c3c;"><i class="fas fa-times-circle"></i></div><div class="stat-info"><div class="value" id="statInactive">—</div><div class="label">Inativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-flag-checkered"></i></div><div class="stat-info"><div class="value" id="statFinals">—</div><div class="label">Finais</div></div></div></div>
        </div>

        <div class="filter-card">
            <h6><i class="fas fa-filter"></i> Filtros</h6>
            <div class="row g-3">
                <div class="col-md-5"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Fluxo, perfil, nome..."></div>
                <div class="col-md-3"><label class="form-label">Estado</label><select class="form-select" id="filterStatus"><option value="">Todos</option><option value="1">Ativo</option><option value="0">Inativo</option></select></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;"><i class="fas fa-redo me-1"></i> Limpar</button></div>
            </div>
        </div>

        <div class="main-card">
            <div class="main-card-header">
                <div class="title"><i class="fas fa-list-ul"></i><span>Lista de Passos</span><span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;">0</span></div>
                <button class="btn-action" onclick="openCreateModal()"><i class="fas fa-plus"></i> Novo Passo</button>
            </div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead><tr>
                            <th style="width:60px;">ID</th><th style="width:140px;">Fluxo</th><th style="width:70px;">Ordem</th>
                            <th>Nome</th><th style="width:140px;">Perfil</th><th style="width:120px;">Ação</th>
                            <th style="width:80px;">Final</th><th style="width:100px;">Estado</th><th style="width:180px;">Ações</th>
                        </tr></thead>
                        <tbody id="stepsTableBody">
                            <?php if (empty($steps)): ?>
                                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhum passo</h6></div></td></tr>
                            <?php else: foreach ($steps as $s):
                                $isActive = (int)$s['FLOW_STEP_STATUS'] === 1;
                                $isFinal = (int)$s['FLOW_STEP_IS_FINAL'] === 1;
                            ?>
                                <tr id="row-<?= (int)$s['FLOW_STEP_ID'] ?>">
                                    <td><strong>#<?= (int)$s['FLOW_STEP_ID'] ?></strong></td>
                                    <td class="cell-flow"><code style="background:#f1f5f9;padding:3px 6px;border-radius:4px;font-size:.75rem;"><?= htmlspecialchars($s['FLOW_CODE'] ?? '—') ?></code><br><small class="text-muted"><?= htmlspecialchars($s['FLOW_NAME'] ?? '') ?></small></td>
                                    <td class="cell-order"><span class="badge bg-secondary"><?= (int)$s['FLOW_STEP_ORDER'] ?></span></td>
                                    <td class="cell-name"><strong><?= htmlspecialchars($s['FLOW_STEP_NAME']) ?></strong></td>
                                    <td class="cell-profile"><span class="badge-status badge-info"><?= htmlspecialchars($s['PROFILE_NAME'] ?? '—') ?></span></td>
                                    <td class="cell-action">
                                        <?php
                                        $actionLabels = [
                                            'ANALYSE'=>'Analisar','FORWARD'=>'Encaminhar','RESOLVE'=>'Resolver',
                                            'RETURN'=>'Devolver','APPROVE'=>'Aprovar','REJECT'=>'Rejeitar','DELIVER'=>'Entregar'
                                        ];
                                        $actLabel = $actionLabels[$s['FLOW_STEP_ACTION']] ?? $s['FLOW_STEP_ACTION'];
                                        ?>
                                        <code style="background:#fff5e1;color:#b8860b;padding:3px 6px;border-radius:4px;font-size:.72rem;"><?= htmlspecialchars($actLabel) ?></code>
                                    </td>
                                    <td class="cell-final"><?= $isFinal ? '<i class="fas fa-check-circle" style="color:#2ecc71;"></i>' : '<i class="fas fa-minus-circle" style="color:#cbd5e0;"></i>' ?></td>
                                    <td class="cell-status">
                                        <?php if ($isActive): ?><span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>
                                        <?php else: ?><span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span><?php endif; ?>
                                    </td>
                                    <td class="cell-actions"
                                        data-id="<?= (int)$s['FLOW_STEP_ID'] ?>"
                                        data-flow-id="<?= (int)$s['FLOW_ID'] ?>"
                                        data-profile-id="<?= (int)$s['PROFILE_ID'] ?>"
                                        data-order="<?= (int)$s['FLOW_STEP_ORDER'] ?>"
                                        data-name="<?= htmlspecialchars($s['FLOW_STEP_NAME']) ?>"
                                        data-step-action="<?= htmlspecialchars($s['FLOW_STEP_ACTION']) ?>"
                                        data-is-final="<?= (int)$s['FLOW_STEP_IS_FINAL'] ?>"
                                        data-status="<?= (int)$s['FLOW_STEP_STATUS'] ?>"
                                        data-flow-name="<?= htmlspecialchars($s['FLOW_NAME'] ?? '') ?>"
                                        data-profile-name="<?= htmlspecialchars($s['PROFILE_NAME'] ?? '') ?>">
                                        <div class="action-links">
                                            <button class="btn-icon view" onclick="openViewModal(<?= (int)$s['FLOW_STEP_ID'] ?>)"><i class="fas fa-eye"></i></button>
                                            <button class="btn-icon toggle" onclick="openStatusModal(<?= (int)$s['FLOW_STEP_ID'] ?>)"><i class="fas fa-<?= $isActive?'toggle-on':'toggle-off' ?>"></i></button>
                                            <button class="btn-icon edit" onclick="openEditModal(<?= (int)$s['FLOW_STEP_ID'] ?>)"><i class="fas fa-edit"></i></button>
                                            <button class="btn-icon delete" onclick="openDeleteModal(<?= (int)$s['FLOW_STEP_ID'] ?>)"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="modal fade" id="formModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title" id="formModalTitle"><i class="fas fa-plus-circle"></i> Novo Passo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <form id="stepForm" onsubmit="handleFormSubmit(event)" novalidate>
                        <div class="modal-body">
                            <input type="hidden" id="formStepId">
                            <div class="mb-3">
                                <label class="form-label-custom">Fluxo <span class="required">*</span></label>
                                <select class="form-select form-control-custom" id="formFlow"></select>
                                <div class="field-error" id="errorFlow"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Perfil <span class="required">*</span></label>
                                    <select class="form-select form-control-custom" id="formProfile"></select>
                                    <div class="field-error" id="errorProfile"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Ordem <span class="required">*</span></label>
                                    <input type="number" class="form-control form-control-custom" id="formOrder" min="1" value="1">
                                    <div class="field-error" id="errorOrder"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Nome do Passo <span class="required">*</span></label>
                                <input type="text" class="form-control form-control-custom" id="formName" maxlength="100">
                                <div class="field-error" id="errorName"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Ação</label>
                                <select class="form-select form-control-custom" id="formStepAction">
                                    <option value="ANALYSE">Analisar</option>
                                    <option value="FORWARD">Encaminhar</option>
                                    <option value="RESOLVE">Resolver</option>
                                    <option value="RETURN">Devolver</option>
                                    <option value="APPROVE">Aprovar</option>
                                    <option value="REJECT">Rejeitar</option>
                                    <option value="DELIVER">Entregar (entrega final)</option>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label-custom">Final?</label>
                                    <div class="form-check form-switch" style="margin-top:8px;">
                                        <input class="form-check-input" type="checkbox" id="formIsFinal">
                                        <label class="form-check-label" for="formIsFinal" style="font-size:.9rem;"><span id="formIsFinalLabel">Não</span></label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label-custom">Estado</label>
                                    <div class="form-check form-switch" style="margin-top:8px;">
                                        <input class="form-check-input" type="checkbox" id="formStatus" checked>
                                        <label class="form-check-label" for="formStatus" style="font-size:.9rem;"><span id="formStatusLabel">Ativo</span></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn-action" id="formSubmitBtn"><i class="fas fa-save"></i> <span id="formSubmitText">Guardar</span></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="viewModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Detalhes do Passo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body" id="viewBody"></div>
                    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="statusModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-toggle-on" style="font-size:4rem;color:#f39c12;margin-bottom:15px;"></i>
                        <h5 style="color:var(--primary-color);" id="statusModalTitle">Alterar Estado</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;" id="statusModalText">Tem a certeza?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-warning" id="confirmStatusBtn" onclick="confirmToggleStatus()"><i class="fas fa-check"></i> Confirmar</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-exclamation-triangle modal-danger-icon"></i>
                        <h5 style="color:var(--primary-color);">Eliminar Passo</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Eliminar <strong id="deleteStepName" style="color:#e74c3c;"></strong>?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fas fa-trash"></i> Eliminar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const API_URL = 'flow_steps.php';
            let formModal, viewModal, statusModal, deleteModal;
            let pendingStatusId = null, pendingDeleteId = null;
            let OPTIONS = { flows: [], profiles: [] };

            const ACTION_LABELS = {
                'ANALYSE':'Analisar','FORWARD':'Encaminhar','RESOLVE':'Resolver',
                'RETURN':'Devolver','APPROVE':'Aprovar','REJECT':'Rejeitar','DELIVER':'Entregar'
            };

            document.addEventListener('DOMContentLoaded', () => {
                formModal   = new bootstrap.Modal(document.getElementById('formModal'));
                viewModal   = new bootstrap.Modal(document.getElementById('viewModal'));
                statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
                deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                loadStats();
                loadOptions();
                let t;
                document.getElementById('filterSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilters, 300); });
                document.getElementById('filterStatus').addEventListener('change', applyFilters);
                document.getElementById('formStatus').addEventListener('change', function() { document.getElementById('formStatusLabel').textContent = this.checked ? 'Ativo' : 'Inativo'; });
                document.getElementById('formIsFinal').addEventListener('change', function() { document.getElementById('formIsFinalLabel').textContent = this.checked ? 'Sim' : 'Não'; });
                applyFilters();
            });

            function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

            function showFieldError(field, msg){
                const map = { flow_id:'errorFlow', profile_id:'errorProfile', order:'errorOrder', nome:'errorName' };
                const id = map[field]; if (!id) return; const el = document.getElementById(id);
                if (el) { el.querySelector('span').textContent = msg; el.classList.add('show'); }
            }
            function clearFieldError(field){ const map = { flow_id:'errorFlow', profile_id:'errorProfile', order:'errorOrder', nome:'errorName' }; const id = map[field]; if (id && document.getElementById(id)) document.getElementById(id).classList.remove('show'); }
            function clearAllErrors(){ ['flow_id','profile_id','order','nome'].forEach(clearFieldError); }

            function loadOptions(){
                const fd = new FormData(); fd.append('action','options');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (!j.success) return;
                    OPTIONS.flows = j.data.flows; OPTIONS.profiles = j.data.profiles;
                    const sf = document.getElementById('formFlow'); sf.innerHTML = '<option value="">— Selecione —</option>';
                    j.data.flows.forEach(f => { const o = document.createElement('option'); o.value = f.FLOW_ID; o.textContent = f.FLOW_NAME + ' (' + f.FLOW_CODE + ')'; sf.appendChild(o); });
                    const sp = document.getElementById('formProfile'); sp.innerHTML = '<option value="">— Selecione —</option>';
                    j.data.profiles.forEach(p => { const o = document.createElement('option'); o.value = p.PROFILE_ID; o.textContent = p.PROFILE_NAME; sp.appendChild(o); });
                }).catch(console.error);
            }

            function loadStats(){
                const fd = new FormData(); fd.append('action','stats');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ document.getElementById('statTotal').textContent=j.data.total; document.getElementById('statActive').textContent=j.data.active; document.getElementById('statInactive').textContent=j.data.inactive; document.getElementById('statFinals').textContent=j.data.finals; }
                }).catch(console.error);
            }

            function applyFilters(){
                const search = document.getElementById('filterSearch').value.toLowerCase().trim();
                const status = document.getElementById('filterStatus').value;
                const rows = Array.from(document.querySelectorAll('#stepsTableBody tr[id^="row-"]'));
                if (!rows.length) return;
                let visible = 0;
                rows.forEach(row => {
                    const txt = row.innerText.toLowerCase();
                    const d = row.querySelector('.cell-actions').dataset;
                    let show = true;
                    if (search && !txt.includes(search)) show = false;
                    if (status !== '' && String(d.status) !== status) show = false;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                document.getElementById('listCount').textContent = visible;
            }
            function resetFilters(){ document.getElementById('filterSearch').value=''; document.getElementById('filterStatus').value=''; applyFilters(); }

            function openViewModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                const actionLabel = ACTION_LABELS[d.stepAction] || d.stepAction;
                const st = d.status==='1' ? '<span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>' : '<span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span>';
                const isFinal = d.isFinal==='1' ? '<span class="badge-status badge-success"><i class="fas fa-flag-checkered"></i> Sim</span>' : '<span class="badge-status badge-secondary">Não</span>';

                document.getElementById('viewBody').innerHTML = `
                    <div class="info-row"><div class="label">ID:</div><div class="value">#${d.id}</div></div>
                    <div class="info-row"><div class="label">Fluxo:</div><div class="value">${escapeHtml(d.flowName)}</div></div>
                    <div class="info-row"><div class="label">Ordem:</div><div class="value"><span class="badge bg-secondary">${d.order}</span></div></div>
                    <div class="info-row"><div class="label">Nome:</div><div class="value">${escapeHtml(d.name)}</div></div>
                    <div class="info-row"><div class="label">Perfil:</div><div class="value">${escapeHtml(d.profileName)}</div></div>
                    <div class="info-row"><div class="label">Ação:</div><div class="value"><code>${escapeHtml(actionLabel)}</code></div></div>
                    <div class="info-row"><div class="label">Final?</div><div class="value">${isFinal}</div></div>
                    <div class="info-row"><div class="label">Estado:</div><div class="value">${st}</div></div>
                `;
                viewModal.show();
            }
            function openCreateModal(){
                clearAllErrors();
                document.getElementById('formStepId').value='';
                document.getElementById('formFlow').value='';
                document.getElementById('formProfile').value='';
                document.getElementById('formOrder').value='1';
                document.getElementById('formName').value='';
                document.getElementById('formStepAction').value='ANALYSE';
                document.getElementById('formIsFinal').checked=false; document.getElementById('formIsFinalLabel').textContent='Não';
                document.getElementById('formStatus').checked=true; document.getElementById('formStatusLabel').textContent='Ativo';
                document.getElementById('formModalTitle').innerHTML='<i class="fas fa-plus-circle"></i> Novo Passo';
                document.getElementById('formSubmitText').textContent='Guardar';
                formModal.show();
            }
            function openEditModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                clearAllErrors();
                document.getElementById('formStepId').value=d.id;
                document.getElementById('formFlow').value=d.flowId;
                document.getElementById('formProfile').value=d.profileId;
                document.getElementById('formOrder').value=d.order;
                document.getElementById('formName').value=d.name;
                document.getElementById('formStepAction').value=d.stepAction;
                document.getElementById('formIsFinal').checked = d.isFinal==='1'; document.getElementById('formIsFinalLabel').textContent = d.isFinal==='1'?'Sim':'Não';
                document.getElementById('formStatus').checked = d.status==='1'; document.getElementById('formStatusLabel').textContent = d.status==='1'?'Ativo':'Inativo';
                document.getElementById('formModalTitle').innerHTML='<i class="fas fa-edit"></i> Editar Passo';
                document.getElementById('formSubmitText').textContent='Atualizar';
                formModal.show();
            }
            function handleFormSubmit(e){
                e.preventDefault(); clearAllErrors();
                const id = document.getElementById('formStepId').value;
                const flowId = parseInt(document.getElementById('formFlow').value) || 0;
                const profId = parseInt(document.getElementById('formProfile').value) || 0;
                const order = parseInt(document.getElementById('formOrder').value) || 0;
                const nome = document.getElementById('formName').value.trim();
                const stepAct = document.getElementById('formStepAction').value;
                const isFinal = document.getElementById('formIsFinal').checked ? 1 : 0;
                const status = document.getElementById('formStatus').checked ? 1 : 0;
                let hasError = false;
                if (flowId <= 0) { showFieldError('flow_id','Selecione o fluxo.'); hasError = true; }
                if (profId <= 0) { showFieldError('profile_id','Selecione o perfil.'); hasError = true; }
                if (order <= 0) { showFieldError('order','Ordem inválida.'); hasError = true; }
                if (!nome) { showFieldError('nome','Nome obrigatório.'); hasError = true; }
                if (hasError) return;
                const btn = document.getElementById('formSubmitBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';
                const fd = new FormData(); fd.append('action','save');
                if (id) fd.append('id', id);
                fd.append('flow_id', flowId); fd.append('profile_id', profId);
                fd.append('order', order); fd.append('nome', nome);
                fd.append('step_action', stepAct); fd.append('is_final', isFinal); fd.append('status', status);
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ formModal.hide(); showToast(j.message,'success'); setTimeout(()=>location.reload(),800); }
                    else if (j.errors) Object.entries(j.errors).forEach(([f,m]) => showFieldError(f,m));
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; });
            }
            function openStatusModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                const isActive = d.status==='1';
                pendingStatusId = id;
                document.getElementById('statusModalTitle').textContent = isActive?'Desativar Passo':'Ativar Passo';
                document.getElementById('statusModalText').innerHTML = `Confirma <strong>${isActive?'desativar':'ativar'}</strong> o passo <strong>${escapeHtml(d.name)}</strong>?`;
                statusModal.show();
            }
            function confirmToggleStatus(){
                if (!pendingStatusId) return;
                const btn = document.getElementById('confirmStatusBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const fd = new FormData(); fd.append('action','toggle'); fd.append('id', pendingStatusId);
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    statusModal.hide();
                    if (j.success){ showToast(j.message,'success'); setTimeout(()=>location.reload(),800); }
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; pendingStatusId=null; });
            }
            function openDeleteModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                pendingDeleteId = id;
                document.getElementById('deleteStepName').textContent = row.querySelector('.cell-actions').dataset.name;
                deleteModal.show();
            }
            function confirmDelete(){
                if (!pendingDeleteId) return;
                const btn = document.getElementById('confirmDeleteBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const fd = new FormData(); fd.append('action','delete'); fd.append('id', pendingDeleteId);
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    deleteModal.hide();
                    if (j.success){ showToast(j.message,'success'); setTimeout(()=>location.reload(),800); }
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; pendingDeleteId=null; });
            }
        </script>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>