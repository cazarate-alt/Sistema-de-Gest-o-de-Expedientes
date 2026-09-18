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
    requirePermissionOrRedirect($pdo, 'PROFILES', 'VIEW');

    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $__allowed = allowedModules($pdo);
    $_SESSION['__allowed_modules'] = $__allowed;

    function gerarCodigoPerfil(PDO $pdo, string $nome, ?int $excludeId = null): string {
        $mapa = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C'];
        $nomeLimpo = strtr($nome, $mapa);
        $letras = preg_replace('/[^A-Za-z]/', '', $nomeLimpo);
        if (strlen($letras) < 4) $letras = str_pad($letras, 4, 'X');
        $base = strtoupper(substr($letras, 0, 4));
        $codigo = $base; $sufixo = 1;
        while (true) {
            $sql = "SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_CODE = :c";
            $params = [':c'=>$codigo];
            if ($excludeId) { $sql .= " AND PROFILE_ID <> :id"; $params[':id']=$excludeId; }
            $st = $pdo->prepare($sql); $st->execute($params);
            if ((int)$st->fetchColumn() === 0) return $codigo;
            $sufixo++; $codigo = $base . $sufixo;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'save') {
            $isEdit = !empty($_POST['id']);
            requirePermissionApi($pdo, 'PROFILES', $isEdit ? 'UPDT' : 'CREA');
            $id = $isEdit ? intval($_POST['id']) : null;
            $nome = trim($_POST['nome'] ?? '');
            $desc = trim($_POST['descricao'] ?? '');
            $isSys = (isset($_POST['is_system']) && intval($_POST['is_system'])===1) ? 1 : 0;
            $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
            $errors = [];
            if ($nome === '') $errors['nome'] = 'O nome é obrigatório.';
            elseif (mb_strlen($nome) < 3) $errors['nome'] = 'Mínimo 3 caracteres.';
            if (!isset($errors['nome'])) {
                $sql = "SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_NAME = :n";
                $params = [':n'=>$nome]; if ($id) { $sql .= " AND PROFILE_ID <> :id"; $params[':id']=$id; }
                $st = $pdo->prepare($sql); $st->execute($params);
                if ((int)$st->fetchColumn() > 0) $errors['nome'] = 'Já existe um perfil com este nome.';
            }
            if ($id && !isset($errors['nome'])) {
                $st = $pdo->prepare("SELECT PROFILE_IS_SYSTEM FROM `PROFILE` WHERE PROFILE_ID=:id");
                $st->execute([':id'=>$id]); $old = $st->fetchColumn();
                if ((int)$old === 1 && $isSys === 0) { echo json_encode(['success'=>false,'message'=>'Não pode remover a flag de sistema.']); exit; }
            }
            if ($errors) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }
            try {
                if ($id) {
                    $st = $pdo->prepare("SELECT PROFILE_NAME, PROFILE_CODE FROM `PROFILE` WHERE PROFILE_ID=:id");
                    $st->execute([':id'=>$id]); $old = $st->fetch();
                    $code = ($old['PROFILE_NAME'] !== $nome) ? gerarCodigoPerfil($pdo, $nome, $id) : $old['PROFILE_CODE'];
                    $pdo->prepare("UPDATE `PROFILE` SET PROFILE_CODE=:c, PROFILE_NAME=:n, PROFILE_DESCRIPTION=:d, PROFILE_IS_SYSTEM=:sys, PROFILE_STATUS=:s WHERE PROFILE_ID=:id")
                        ->execute([':c'=>$code,':n'=>$nome,':d'=>$desc,':sys'=>$isSys,':s'=>$status,':id'=>$id]);
                    echo json_encode(['success'=>true,'message'=>'Perfil atualizado com sucesso!']);
                } else {
                    $code = gerarCodigoPerfil($pdo, $nome, null);
                    $pdo->prepare("INSERT INTO `PROFILE` (PROFILE_CODE, PROFILE_NAME, PROFILE_DESCRIPTION, PROFILE_IS_SYSTEM, PROFILE_STATUS) VALUES (:c,:n,:d,:sys,:s)")
                        ->execute([':c'=>$code,':n'=>$nome,':d'=>$desc,':sys'=>$isSys,':s'=>$status]);
                    echo json_encode(['success'=>true,'message'=>'Perfil criado com sucesso!','id'=>(int)$pdo->lastInsertId()]);
                }
            } catch (\PDOException $e) { echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]); }
            exit;
        }

        if ($action === 'toggle') {
            requirePermissionApi($pdo, 'PROFILES', 'UPDT');
            $id = intval($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT PROFILE_IS_SYSTEM, PROFILE_STATUS FROM `PROFILE` WHERE PROFILE_ID=:id");
            $st->execute([':id'=>$id]); $row = $st->fetch();
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Perfil não encontrado.']); exit; }
            if ((int)$row['PROFILE_IS_SYSTEM'] === 1) { echo json_encode(['success'=>false,'message'=>'Perfil de sistema não pode ser desativado.']); exit; }
            $new = ((int)$row['PROFILE_STATUS'] === 1) ? 0 : 1;
            $pdo->prepare("UPDATE `PROFILE` SET PROFILE_STATUS=:s WHERE PROFILE_ID=:id")->execute([':s'=>$new,':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>$new?'Perfil ativado!':'Perfil desativado!']);
            exit;
        }

        if ($action === 'delete') {
            requirePermissionApi($pdo, 'PROFILES', 'DELE');
            $id = intval($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT PROFILE_IS_SYSTEM FROM `PROFILE` WHERE PROFILE_ID=:id");
            $st->execute([':id'=>$id]); $isSys = $st->fetchColumn();
            if ((int)$isSys === 1) { echo json_encode(['success'=>false,'message'=>'Perfis de sistema não podem ser eliminados.']); exit; }
            $st = $pdo->prepare("SELECT COUNT(*) FROM `USERS` WHERE PROFILE_ID=:id");
            $st->execute([':id'=>$id]);
            if ((int)$st->fetchColumn() > 0) { echo json_encode(['success'=>false,'message'=>'Existem utilizadores associados.']); exit; }
            $pdo->prepare("DELETE FROM `PERMISSION` WHERE PROFILE_ID=:id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM `PROFILE` WHERE PROFILE_ID=:id")->execute([':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>'Perfil eliminado com sucesso!']);
            exit;
        }

        if ($action === 'stats') {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM `PROFILE`")->fetchColumn();
            $active = (int)$pdo->query("SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_STATUS=1")->fetchColumn();
            $inactive = (int)$pdo->query("SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_STATUS=0")->fetchColumn();
            $sys = (int)$pdo->query("SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_IS_SYSTEM=1")->fetchColumn();
            echo json_encode(['success'=>true,'data'=>compact('total','active','inactive','sys')]);
            exit;
        }
        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
    }

    $perfis = $pdo->query("SELECT * FROM `PROFILE` ORDER BY PROFILE_ID DESC")->fetchAll();

    $pageTitle = 'Gestão de Perfis';
    $activePage = 'profiles.php';
    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-user-tag" style="color:var(--accent-color);"></i> Gestão de Perfis</h2>
                <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Perfis</span></div>
            </div>
            <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=profiles','_blank')">
                <i class="fas fa-file-pdf"></i> Exportar PDF
            </button>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-user-tag"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="statActive">—</div><div class="label">Ativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(231,76,60,.12);color:#e74c3c;"><i class="fas fa-times-circle"></i></div><div class="stat-info"><div class="value" id="statInactive">—</div><div class="label">Inativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-lock"></i></div><div class="stat-info"><div class="value" id="statSys">—</div><div class="label">Sistema</div></div></div></div>
        </div>

        <div class="filter-card">
            <h6><i class="fas fa-filter"></i> Filtros</h6>
            <div class="row g-3">
                <div class="col-md-5"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Código, nome..."></div>
                <div class="col-md-3"><label class="form-label">Estado</label><select class="form-select" id="filterStatus"><option value="">Todos</option><option value="1">Ativo</option><option value="0">Inativo</option></select></div>
                <div class="col-md-2"><label class="form-label">Ordenar</label><select class="form-select" id="filterOrderBy"><option value="PROFILE_ID">ID</option><option value="PROFILE_CODE">Código</option><option value="PROFILE_NAME">Nome</option></select></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;"><i class="fas fa-redo me-1"></i> Limpar</button></div>
            </div>
        </div>

        <div class="main-card">
            <div class="main-card-header">
                <div class="title"><i class="fas fa-list-ul"></i><span>Lista de Perfis</span><span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;">0</span></div>
                <button class="btn-action" onclick="openCreateModal()"><i class="fas fa-plus"></i> Novo Perfil</button>
            </div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead><tr>
                            <th style="width:70px;">ID</th><th style="width:120px;">Código</th><th>Nome</th>
                            <th class="d-none d-md-table-cell">Descrição</th><th style="width:140px;">Estado</th><th style="width:180px;">Ações</th>
                        </tr></thead>
                        <tbody id="profilesTableBody">
                            <?php if (empty($perfis)): ?>
                                <tr><td colspan="6"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhum perfil</h6></div></td></tr>
                            <?php else: foreach ($perfis as $p):
                                $isActive = (int)$p['PROFILE_STATUS'] === 1;
                                $isSys = (int)$p['PROFILE_IS_SYSTEM'] === 1;
                            ?>
                                <tr id="row-<?= (int)$p['PROFILE_ID'] ?>">
                                    <td><strong>#<?= (int)$p['PROFILE_ID'] ?></strong></td>
                                    <td class="cell-code"><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.8rem;"><?= htmlspecialchars($p['PROFILE_CODE']) ?></code></td>
                                    <td class="cell-name"><strong><?= htmlspecialchars($p['PROFILE_NAME']) ?></strong></td>
                                    <td class="cell-desc d-none d-md-table-cell" style="color:#7f8c8d;"><?= htmlspecialchars($p['PROFILE_DESCRIPTION'] ?: '—') ?></td>
                                    <td class="cell-status">
                                        <?php if ($isActive): ?><span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>
                                        <?php else: ?><span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span><?php endif; ?>
                                        <?php if ($isSys): ?> <span class="badge-status badge-system"><i class="fas fa-lock"></i> Sistema</span><?php endif; ?>
                                    </td>
                                    <td class="cell-actions"
                                        data-id="<?= (int)$p['PROFILE_ID'] ?>"
                                        data-code="<?= htmlspecialchars($p['PROFILE_CODE']) ?>"
                                        data-name="<?= htmlspecialchars($p['PROFILE_NAME']) ?>"
                                        data-desc="<?= htmlspecialchars($p['PROFILE_DESCRIPTION'] ?? '') ?>"
                                        data-is-system="<?= (int)$p['PROFILE_IS_SYSTEM'] ?>"
                                        data-status="<?= (int)$p['PROFILE_STATUS'] ?>">
                                        <div class="action-links">
                                            <button class="btn-icon view" onclick="openViewModal(<?= (int)$p['PROFILE_ID'] ?>)"><i class="fas fa-eye"></i></button>
                                            <button class="btn-icon toggle" onclick="openStatusModal(<?= (int)$p['PROFILE_ID'] ?>)" <?= $isSys?'disabled':'' ?>><i class="fas fa-<?= $isActive?'toggle-on':'toggle-off' ?>"></i></button>
                                            <button class="btn-icon edit" onclick="openEditModal(<?= (int)$p['PROFILE_ID'] ?>)"><i class="fas fa-edit"></i></button>
                                            <button class="btn-icon delete" onclick="openDeleteModal(<?= (int)$p['PROFILE_ID'] ?>)" <?= $isSys?'disabled':'' ?>><i class="fas fa-trash"></i></button>
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
                    <div class="modal-header"><h5 class="modal-title" id="formModalTitle"><i class="fas fa-plus-circle"></i> Novo Perfil</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <form id="profileForm" onsubmit="handleFormSubmit(event)" novalidate>
                        <div class="modal-body">
                            <input type="hidden" id="formProfileId">
                            <div class="mb-3">
                                <label class="form-label-custom">Nome <span class="required">*</span></label>
                                <input type="text" class="form-control form-control-custom" id="formName" maxlength="100">
                                <div class="field-error" id="errorName"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Descrição</label>
                                <textarea class="form-control form-control-custom" id="formDescription" rows="3" maxlength="255"></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label-custom">Estado</label>
                                    <div class="form-check form-switch" style="margin-top:8px;">
                                        <input class="form-check-input" type="checkbox" id="formStatus" checked>
                                        <label class="form-check-label" for="formStatus" style="font-size:.9rem;"><span id="formStatusLabel">Ativo</span></label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label-custom">Sistema?</label>
                                    <div class="form-check form-switch" style="margin-top:8px;">
                                        <input class="form-check-input" type="checkbox" id="formIsSystem">
                                        <label class="form-check-label" for="formIsSystem" style="font-size:.9rem;"><span id="formIsSystemLabel">Não</span></label>
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
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Detalhes</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
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
                        <h5 style="color:var(--primary-color);">Eliminar Perfil</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Eliminar <strong id="deleteProfileName" style="color:#e74c3c;"></strong>?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fas fa-trash"></i> Eliminar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const API_URL = 'profiles.php';
            let formModal, viewModal, statusModal, deleteModal;
            let pendingStatusId = null, pendingDeleteId = null;

            document.addEventListener('DOMContentLoaded', () => {
                formModal   = new bootstrap.Modal(document.getElementById('formModal'));
                viewModal   = new bootstrap.Modal(document.getElementById('viewModal'));
                statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
                deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                loadStats();
                let t;
                document.getElementById('filterSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilters, 300); });
                document.getElementById('filterStatus').addEventListener('change', applyFilters);
                document.getElementById('filterOrderBy').addEventListener('change', applyFilters);
                document.getElementById('formStatus').addEventListener('change', function() { document.getElementById('formStatusLabel').textContent = this.checked ? 'Ativo' : 'Inativo'; });
                document.getElementById('formIsSystem').addEventListener('change', function() { document.getElementById('formIsSystemLabel').textContent = this.checked ? 'Sim' : 'Não'; });
                applyFilters();
            });

            function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
            function showFieldError(field, msg){ if (field!=='nome') return; const el = document.getElementById('errorName'); if (el) { el.querySelector('span').textContent = msg; el.classList.add('show'); } }
            function clearFieldError(){ document.getElementById('errorName')?.classList.remove('show'); }
            function clearAllErrors(){ clearFieldError(); }

            function loadStats(){
                const fd = new FormData(); fd.append('action','stats');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ document.getElementById('statTotal').textContent=j.data.total; document.getElementById('statActive').textContent=j.data.active; document.getElementById('statInactive').textContent=j.data.inactive; document.getElementById('statSys').textContent=j.data.sys; }
                }).catch(console.error);
            }

            function applyFilters(){
                const search = document.getElementById('filterSearch').value.toLowerCase().trim();
                const status = document.getElementById('filterStatus').value;
                const orderBy = document.getElementById('filterOrderBy').value;
                const tbody = document.getElementById('profilesTableBody');
                const rows = Array.from(tbody.querySelectorAll('tr[id^="row-"]'));
                if (!rows.length) return;
                let visible = 0;
                rows.forEach(row => {
                    const d = row.querySelector('.cell-actions').dataset;
                    const txt = (d.code + ' ' + d.name + ' ' + d.desc).toLowerCase();
                    let show = true;
                    if (search && !txt.includes(search)) show = false;
                    if (status !== '' && String(d.status) !== status) show = false;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                if (orderBy) {
                    rows.sort((a,b) => {
                        const A = a.querySelector('.cell-actions').dataset, B = b.querySelector('.cell-actions').dataset;
                        if (orderBy === 'PROFILE_ID') return parseInt(B.id) - parseInt(A.id);
                        if (orderBy === 'PROFILE_CODE') return A.code.localeCompare(B.code);
                        if (orderBy === 'PROFILE_NAME') return A.name.localeCompare(B.name);
                        return 0;
                    });
                    rows.forEach(r => tbody.appendChild(r));
                }
                document.getElementById('listCount').textContent = visible;
            }
            function resetFilters(){ document.getElementById('filterSearch').value=''; document.getElementById('filterStatus').value=''; document.getElementById('filterOrderBy').value='PROFILE_ID'; applyFilters(); }

            function openViewModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                const st = d.status==='1' ? '<span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>' : '<span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span>';
                const sys = d.isSystem==='1' ? '<span class="badge-status badge-system"><i class="fas fa-lock"></i> Sim</span>' : 'Não';
                document.getElementById('viewBody').innerHTML = `
                    <div class="info-row"><div class="label">ID:</div><div class="value">#${d.id}</div></div>
                    <div class="info-row"><div class="label">Código:</div><div class="value"><code>${escapeHtml(d.code)}</code></div></div>
                    <div class="info-row"><div class="label">Nome:</div><div class="value">${escapeHtml(d.name)}</div></div>
                    <div class="info-row"><div class="label">Descrição:</div><div class="value">${escapeHtml(d.desc||'—')}</div></div>
                    <div class="info-row"><div class="label">Sistema:</div><div class="value">${sys}</div></div>
                    <div class="info-row"><div class="label">Estado:</div><div class="value">${st}</div></div>
                `;
                viewModal.show();
            }
            function openCreateModal(){
                clearAllErrors();
                document.getElementById('formProfileId').value='';
                document.getElementById('formName').value='';
                document.getElementById('formDescription').value='';
                document.getElementById('formStatus').checked=true;
                document.getElementById('formIsSystem').checked=false;
                document.getElementById('formStatusLabel').textContent='Ativo';
                document.getElementById('formIsSystemLabel').textContent='Não';
                document.getElementById('formModalTitle').innerHTML='<i class="fas fa-plus-circle"></i> Novo Perfil';
                document.getElementById('formSubmitText').textContent='Guardar';
                formModal.show();
            }
            function openEditModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                clearAllErrors();
                document.getElementById('formProfileId').value=d.id;
                document.getElementById('formName').value=d.name;
                document.getElementById('formDescription').value=d.desc;
                document.getElementById('formStatus').checked = d.status==='1';
                document.getElementById('formIsSystem').checked = d.isSystem==='1';
                document.getElementById('formStatusLabel').textContent = d.status==='1'?'Ativo':'Inativo';
                document.getElementById('formIsSystemLabel').textContent = d.isSystem==='1'?'Sim':'Não';
                document.getElementById('formModalTitle').innerHTML='<i class="fas fa-edit"></i> Editar Perfil';
                document.getElementById('formSubmitText').textContent='Atualizar';
                formModal.show();
            }
            function handleFormSubmit(e){
                e.preventDefault(); clearAllErrors();
                const id = document.getElementById('formProfileId').value;
                const nome = document.getElementById('formName').value.trim();
                const desc = document.getElementById('formDescription').value.trim();
                const status = document.getElementById('formStatus').checked ? 1 : 0;
                const isSys = document.getElementById('formIsSystem').checked ? 1 : 0;
                let hasError = false;
                if (!nome) { showFieldError('nome','O nome é obrigatório.'); hasError = true; }
                if (hasError) return;
                const btn = document.getElementById('formSubmitBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';
                const fd = new FormData(); fd.append('action','save');
                if (id) fd.append('id', id);
                fd.append('nome', nome); fd.append('descricao', desc);
                fd.append('status', status); fd.append('is_system', isSys);
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ formModal.hide(); showToast(j.message,'success'); setTimeout(()=>location.reload(),800); }
                    else if (j.errors) Object.entries(j.errors).forEach(([f,m]) => showFieldError(f,m));
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; });
            }
            function openStatusModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                if (d.isSystem === '1') { showToast('Perfil de sistema não pode ser desativado.','warning'); return; }
                const isActive = d.status==='1';
                pendingStatusId = id;
                document.getElementById('statusModalTitle').textContent = isActive?'Desativar Perfil':'Ativar Perfil';
                document.getElementById('statusModalText').innerHTML = `Confirma <strong>${isActive?'desativar':'ativar'}</strong> <strong>${escapeHtml(d.name)}</strong>?`;
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
                const d = row.querySelector('.cell-actions').dataset;
                if (d.isSystem === '1') { showToast('Perfil de sistema não pode ser eliminado.','warning'); return; }
                pendingDeleteId = id;
                document.getElementById('deleteProfileName').textContent = d.name;
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