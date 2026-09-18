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
requirePermissionOrRedirect($pdo, 'FLOWS', 'VIEW');

$profileCode = strtoupper($_SESSION['profile_code'] ?? '');
$__allowed = allowedModules($pdo);
$_SESSION['__allowed_modules'] = $__allowed;

function gerarCodigoFluxo(PDO $pdo, string $nome, ?int $excludeId = null): string {
    $mapa = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C'];
    $nomeLimpo = strtr($nome, $mapa);
    $letras = preg_replace('/[^A-Za-z]/', '', $nomeLimpo);
    if (strlen($letras) < 4) $letras = str_pad($letras, 4, 'X');
    $base = 'FLOW_' . strtoupper(substr($letras, 0, 4));
    $codigo = $base; $sufixo = 1;
    while (true) {
        $sql = "SELECT COUNT(*) FROM `FLOW` WHERE FLOW_CODE = :c";
        $params = [':c'=>$codigo];
        if ($excludeId) { $sql .= " AND FLOW_ID <> :id"; $params[':id']=$excludeId; }
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
        requirePermissionApi($pdo, 'FLOWS', $isEdit ? 'UPDT' : 'CREA');
        $id = $isEdit ? intval($_POST['id']) : null;
        $typeId = (int)($_POST['document_type_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $desc = trim($_POST['descricao'] ?? '');
        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
        $errors = [];
        if ($typeId <= 0) $errors['document_type_id'] = 'Selecione o tipo de documento.';
        if ($nome === '') $errors['nome'] = 'O nome é obrigatório.';
        if (!isset($errors['nome'])) {
            $sql = "SELECT COUNT(*) FROM `FLOW` WHERE FLOW_NAME = :n";
            $params = [':n'=>$nome]; if ($id) { $sql .= " AND FLOW_ID <> :id"; $params[':id']=$id; }
            $st = $pdo->prepare($sql); $st->execute($params);
            if ((int)$st->fetchColumn() > 0) $errors['nome'] = 'Já existe um fluxo com este nome.';
        }
        if ($errors) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }
        try {
            if ($id) {
                $st = $pdo->prepare("SELECT FLOW_NAME, FLOW_CODE FROM `FLOW` WHERE FLOW_ID=:id");
                $st->execute([':id'=>$id]); $old = $st->fetch();
                $code = ($old['FLOW_NAME'] !== $nome) ? gerarCodigoFluxo($pdo, $nome, $id) : $old['FLOW_CODE'];
                $pdo->prepare("UPDATE `FLOW` SET DOCUMENT_TYPE_ID=:dt, FLOW_CODE=:c, FLOW_NAME=:n, FLOW_DESCRIPTION=:d, FLOW_STATUS=:s WHERE FLOW_ID=:id")
                    ->execute([':dt'=>$typeId,':c'=>$code,':n'=>$nome,':d'=>$desc,':s'=>$status,':id'=>$id]);
                echo json_encode(['success'=>true,'message'=>'Fluxo atualizado com sucesso!']);
            } else {
                $code = gerarCodigoFluxo($pdo, $nome, null);
                $pdo->prepare("INSERT INTO `FLOW` (DOCUMENT_TYPE_ID, FLOW_CODE, FLOW_NAME, FLOW_DESCRIPTION, FLOW_STATUS) VALUES (:dt,:c,:n,:d,:s)")
                    ->execute([':dt'=>$typeId,':c'=>$code,':n'=>$nome,':d'=>$desc,':s'=>$status]);
                echo json_encode(['success'=>true,'message'=>'Fluxo criado com sucesso!','id'=>(int)$pdo->lastInsertId()]);
            }
        } catch (\PDOException $e) { echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]); }
        exit;
    }

    if ($action === 'toggle') {
        requirePermissionApi($pdo, 'FLOWS', 'UPDT');
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
        $st = $pdo->prepare("SELECT FLOW_STATUS FROM `FLOW` WHERE FLOW_ID=:id");
        $st->execute([':id'=>$id]); $cur = $st->fetchColumn();
        if ($cur === false) { echo json_encode(['success'=>false,'message'=>'Fluxo não encontrado.']); exit; }
        $new = ((int)$cur === 1) ? 0 : 1;
        $pdo->prepare("UPDATE `FLOW` SET FLOW_STATUS=:s WHERE FLOW_ID=:id")->execute([':s'=>$new,':id'=>$id]);
        echo json_encode(['success'=>true,'message'=>$new?'Fluxo ativado!':'Fluxo desativado!']);
        exit;
    }

    if ($action === 'delete') {
        requirePermissionApi($pdo, 'FLOWS', 'DELE');
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
        $st = $pdo->prepare("SELECT COUNT(*) FROM `DOCUMENT` WHERE FLOW_ID=:id");
        $st->execute([':id'=>$id]);
        if ((int)$st->fetchColumn() > 0) { echo json_encode(['success'=>false,'message'=>'Existem documentos associados.']); exit; }
        $pdo->prepare("DELETE FROM `FLOW` WHERE FLOW_ID=:id")->execute([':id'=>$id]);
        echo json_encode(['success'=>true,'message'=>'Fluxo eliminado com sucesso!']);
        exit;
    }

    if ($action === 'options') {
        $types = $pdo->query("SELECT DOCUMENT_TYPE_ID, DOCUMENT_TYPE_CODE, DOCUMENT_TYPE_NAME FROM `DOCUMENT_TYPE` WHERE DOCUMENT_TYPE_STATUS=1 ORDER BY DOCUMENT_TYPE_NAME")->fetchAll();
        echo json_encode(['success'=>true,'data'=>compact('types')]);
        exit;
    }

    if ($action === 'stats') {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW`")->fetchColumn();
        $active = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW` WHERE FLOW_STATUS=1")->fetchColumn();
        $inactive = (int)$pdo->query("SELECT COUNT(*) FROM `FLOW` WHERE FLOW_STATUS=0")->fetchColumn();
        $inUse = (int)$pdo->query("SELECT COUNT(DISTINCT FLOW_ID) FROM `DOCUMENT` WHERE FLOW_ID IS NOT NULL")->fetchColumn();
        echo json_encode(['success'=>true,'data'=>compact('total','active','inactive','inUse')]);
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
}

$fluxos = $pdo->query(
    "SELECT f.*, t.DOCUMENT_TYPE_NAME,
            (SELECT COUNT(*) FROM `DOCUMENT` d WHERE d.FLOW_ID = f.FLOW_ID) AS TOTAL_DOCS
     FROM `FLOW` f
     LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = f.DOCUMENT_TYPE_ID
     ORDER BY f.FLOW_ID DESC"
)->fetchAll();

$pageTitle = 'Fluxos de Tramitação';
$activePage = 'flows.php';
include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-title mb-0" style="margin:0;">
        <h2><i class="fas fa-project-diagram" style="color:var(--accent-color);"></i> Fluxos de Tramitação</h2>
        <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Fluxos</span></div>
    </div>
    <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=flows','_blank')">
        <i class="fas fa-file-pdf"></i> Exportar PDF
    </button>
</div>

<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-project-diagram"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="statActive">—</div><div class="label">Ativos</div></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(231,76,60,.12);color:#e74c3c;"><i class="fas fa-times-circle"></i></div><div class="stat-info"><div class="value" id="statInactive">—</div><div class="label">Inativos</div></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-file"></i></div><div class="stat-info"><div class="value" id="statInUse">—</div><div class="label">Em Uso</div></div></div></div>
</div>

<div class="filter-card">
    <h6><i class="fas fa-filter"></i> Filtros</h6>
    <div class="row g-3">
        <div class="col-md-5"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Código, nome, tipo..."></div>
        <div class="col-md-3"><label class="form-label">Estado</label><select class="form-select" id="filterStatus"><option value="">Todos</option><option value="1">Ativo</option><option value="0">Inativo</option></select></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;"><i class="fas fa-redo me-1"></i> Limpar</button></div>
    </div>
</div>

<div class="main-card">
    <div class="main-card-header">
        <div class="title"><i class="fas fa-list-ul"></i><span>Lista de Fluxos</span><span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;">0</span></div>
        <button class="btn-action" onclick="openCreateModal()"><i class="fas fa-plus"></i> Novo Fluxo</button>
    </div>
    <div class="main-card-body">
        <div class="table-responsive">
            <table class="table table-custom">
                <thead><tr>
                    <th style="width:70px;">ID</th><th style="width:140px;">Código</th><th>Nome</th>
                    <th style="width:150px;">Tipo Documento</th><th style="width:80px;">Docs</th>
                    <th style="width:110px;">Estado</th><th style="width:180px;">Ações</th>
                </tr></thead>
                <tbody id="flowsTableBody">
                    <?php if (empty($fluxos)): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhum fluxo</h6></div></td></tr>
                    <?php else: foreach ($fluxos as $f):
                        $isActive = (int)$f['FLOW_STATUS'] === 1;
                    ?>
                        <tr id="row-<?= (int)$f['FLOW_ID'] ?>">
                            <td><strong>#<?= (int)$f['FLOW_ID'] ?></strong></td>
                            <td class="cell-code"><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.8rem;"><?= htmlspecialchars($f['FLOW_CODE']) ?></code></td>
                            <td class="cell-name"><strong><?= htmlspecialchars($f['FLOW_NAME']) ?></strong></td>
                            <td class="cell-type" style="font-size:.85rem;color:#7f8c8d;"><?= htmlspecialchars($f['DOCUMENT_TYPE_NAME'] ?? '—') ?></td>
                            <td><span class="badge bg-info"><?= (int)$f['TOTAL_DOCS'] ?></span></td>
                            <td class="cell-status">
                                <?php if ($isActive): ?><span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>
                                <?php else: ?><span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span><?php endif; ?>
                            </td>
                            <td class="cell-actions"
                                data-id="<?= (int)$f['FLOW_ID'] ?>"
                                data-code="<?= htmlspecialchars($f['FLOW_CODE']) ?>"
                                data-name="<?= htmlspecialchars($f['FLOW_NAME']) ?>"
                                data-desc="<?= htmlspecialchars($f['FLOW_DESCRIPTION'] ?? '') ?>"
                                data-type-id="<?= (int)$f['DOCUMENT_TYPE_ID'] ?>"
                                data-type-name="<?= htmlspecialchars($f['DOCUMENT_TYPE_NAME'] ?? '') ?>"
                                data-status="<?= (int)$f['FLOW_STATUS'] ?>"
                                data-docs="<?= (int)$f['TOTAL_DOCS'] ?>">
                                <div class="action-links">
                                    <button class="btn-icon view" onclick="openViewModal(<?= (int)$f['FLOW_ID'] ?>)"><i class="fas fa-eye"></i></button>
                                    <button class="btn-icon toggle" onclick="openStatusModal(<?= (int)$f['FLOW_ID'] ?>)"><i class="fas fa-<?= $isActive?'toggle-on':'toggle-off' ?>"></i></button>
                                    <button class="btn-icon edit" onclick="openEditModal(<?= (int)$f['FLOW_ID'] ?>)"><i class="fas fa-edit"></i></button>
                                    <button class="btn-icon delete" onclick="openDeleteModal(<?= (int)$f['FLOW_ID'] ?>)" <?= $f['TOTAL_DOCS']>0?'disabled':'' ?>><i class="fas fa-trash"></i></button>
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
            <div class="modal-header"><h5 class="modal-title" id="formModalTitle"><i class="fas fa-plus-circle"></i> Novo Fluxo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form id="flowForm" onsubmit="handleFormSubmit(event)" novalidate>
                <div class="modal-body">
                    <input type="hidden" id="formFlowId">
                    <div class="mb-3">
                        <label class="form-label-custom">Tipo de Documento <span class="required">*</span></label>
                        <select class="form-select form-control-custom" id="formDocType"></select>
                        <div class="field-error" id="errorDocType"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom">Nome <span class="required">*</span></label>
                        <input type="text" class="form-control form-control-custom" id="formName" maxlength="100">
                        <div class="field-error" id="errorName"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom">Descrição</label>
                        <textarea class="form-control form-control-custom" id="formDescription" rows="3" maxlength="255"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-custom">Estado</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="formStatus" checked>
                            <label class="form-check-label" for="formStatus" style="font-size:.9rem;"><span id="formStatusLabel">Ativo</span></label>
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
                <h5 style="color:var(--primary-color);">Eliminar Fluxo</h5>
                <p class="text-muted mb-0" style="font-size:.9rem;">Eliminar <strong id="deleteFlowName" style="color:#e74c3c;"></strong>?</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pb-4">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fas fa-trash"></i> Eliminar</button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = 'flows.php';
let formModal, viewModal, statusModal, deleteModal;
let pendingStatusId = null, pendingDeleteId = null;
let OPTIONS = { types: [] };

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
    applyFilters();
});

function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function showFieldError(field, msg){ const map={ document_type_id:'errorDocType', nome:'errorName' }; const id = map[field]; if (!id) return; const el = document.getElementById(id); if (el) { el.querySelector('span').textContent = msg; el.classList.add('show'); } }
function clearFieldError(field){ const map={ document_type_id:'errorDocType', nome:'errorName' }; const id = map[field]; if (id && document.getElementById(id)) document.getElementById(id).classList.remove('show'); }
function clearAllErrors(){ clearFieldError('document_type_id'); clearFieldError('nome'); }

function loadOptions(){
    const fd = new FormData(); fd.append('action','options');
    fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
        if (!j.success) return;
        OPTIONS.types = j.data.types;
        const sel = document.getElementById('formDocType');
        sel.innerHTML = '<option value="">— Selecione —</option>';
        j.data.types.forEach(t => { const o = document.createElement('option'); o.value = t.DOCUMENT_TYPE_ID; o.textContent = t.DOCUMENT_TYPE_NAME + ' (' + t.DOCUMENT_TYPE_CODE + ')'; sel.appendChild(o); });
    }).catch(console.error);
}

function loadStats(){
    const fd = new FormData(); fd.append('action','stats');
    fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
        if (j.success){ document.getElementById('statTotal').textContent=j.data.total; document.getElementById('statActive').textContent=j.data.active; document.getElementById('statInactive').textContent=j.data.inactive; document.getElementById('statInUse').textContent=j.data.inUse; }
    }).catch(console.error);
}

function applyFilters(){
    const search = document.getElementById('filterSearch').value.toLowerCase().trim();
    const status = document.getElementById('filterStatus').value;
    const rows = Array.from(document.querySelectorAll('#flowsTableBody tr[id^="row-"]'));
    if (!rows.length) return;
    let visible = 0;
    rows.forEach(row => {
        const d = row.querySelector('.cell-actions').dataset;
        const txt = (d.code + ' ' + d.name + ' ' + d.typeName).toLowerCase();
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
    const st = d.status==='1' ? '<span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>' : '<span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span>';
    document.getElementById('viewBody').innerHTML = `
        <div class="info-row"><div class="label">ID:</div><div class="value">#${d.id}</div></div>
        <div class="info-row"><div class="label">Código:</div><div class="value"><code>${escapeHtml(d.code)}</code></div></div>
        <div class="info-row"><div class="label">Nome:</div><div class="value">${escapeHtml(d.name)}</div></div>
        <div class="info-row"><div class="label">Tipo Doc.:</div><div class="value">${escapeHtml(d.typeName)}</div></div>
        <div class="info-row"><div class="label">Descrição:</div><div class="value">${escapeHtml(d.desc||'—')}</div></div>
        <div class="info-row"><div class="label">Docs:</div><div class="value">${d.docs}</div></div>
        <div class="info-row"><div class="label">Estado:</div><div class="value">${st}</div></div>
    `;
    viewModal.show();
}
function openCreateModal(){
    clearAllErrors();
    document.getElementById('formFlowId').value='';
    document.getElementById('formDocType').value='';
    document.getElementById('formName').value='';
    document.getElementById('formDescription').value='';
    document.getElementById('formStatus').checked=true;
    document.getElementById('formStatusLabel').textContent='Ativo';
    document.getElementById('formModalTitle').innerHTML='<i class="fas fa-plus-circle"></i> Novo Fluxo';
    document.getElementById('formSubmitText').textContent='Guardar';
    formModal.show();
}
function openEditModal(id){
    const row = document.getElementById('row-'+id); if (!row) return;
    const d = row.querySelector('.cell-actions').dataset;
    clearAllErrors();
    document.getElementById('formFlowId').value=d.id;
    document.getElementById('formDocType').value=d.typeId;
    document.getElementById('formName').value=d.name;
    document.getElementById('formDescription').value=d.desc;
    document.getElementById('formStatus').checked = d.status==='1';
    document.getElementById('formStatusLabel').textContent = d.status==='1'?'Ativo':'Inativo';
    document.getElementById('formModalTitle').innerHTML='<i class="fas fa-edit"></i> Editar Fluxo';
    document.getElementById('formSubmitText').textContent='Atualizar';
    formModal.show();
}
function handleFormSubmit(e){
    e.preventDefault(); clearAllErrors();
    const id = document.getElementById('formFlowId').value;
    const typeId = parseInt(document.getElementById('formDocType').value) || 0;
    const nome = document.getElementById('formName').value.trim();
    const desc = document.getElementById('formDescription').value.trim();
    const status = document.getElementById('formStatus').checked ? 1 : 0;
    let hasError = false;
    if (typeId <= 0) { showFieldError('document_type_id','Selecione o tipo.'); hasError = true; }
    if (!nome) { showFieldError('nome','O nome é obrigatório.'); hasError = true; }
    if (hasError) return;
    const btn = document.getElementById('formSubmitBtn'); const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';
    const fd = new FormData(); fd.append('action','save');
    if (id) fd.append('id', id);
    fd.append('document_type_id', typeId);
    fd.append('nome', nome); fd.append('descricao', desc); fd.append('status', status);
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
    document.getElementById('statusModalTitle').textContent = isActive?'Desativar Fluxo':'Ativar Fluxo';
    document.getElementById('statusModalText').innerHTML = `Tem a certeza que deseja <strong>${isActive?'desativar':'ativar'}</strong> <strong>${escapeHtml(d.name)}</strong>?`;
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
    pendingDeleteId = id;
    document.getElementById('deleteFlowName').textContent = d.name;
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