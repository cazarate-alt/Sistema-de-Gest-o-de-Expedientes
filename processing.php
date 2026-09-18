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
requirePermissionOrRedirect($pdo, 'PROCESSING', 'VIEW');

$userId      = (int) $_SESSION['user_id'];
$profileCode = strtoupper($_SESSION['profile_code'] ?? '');
$myProfileId = (int) $_SESSION['profile_id'];

$__allowed = allowedModules($pdo);
$_SESSION['__allowed_modules'] = $__allowed;

function buildProcessingFilter(string $profileCode, int $userId, int $myProfileId): array {
    if ($profileCode === 'ESTU') {
        return [
            'WHERE d.USER_ID = :filter_user_id',
            [':filter_user_id' => $userId]
        ];
    }
    if ($profileCode === 'DOCE') {
        return [
            'WHERE (p.FROM_USER_ID = :filter_user_id OR p.TO_USER_ID = :filter_user_id)',
            [':filter_user_id' => $userId]
        ];
    }
    if ($profileCode === 'SECR') {
        return [
            'WHERE (p.FROM_PROFILE_ID = :filter_profile_id OR p.TO_PROFILE_ID = :filter_profile_id)',
            [':filter_profile_id' => $myProfileId]
        ];
    }
    return ['', []];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if ($action === 'stats') {
        [$where, $params] = buildProcessingFilter($profileCode, $userId, $myProfileId);
        $baseFrom = "FROM `PROCESSING` p
                     INNER JOIN `DOCUMENT` d ON d.DOCUMENT_ID = p.DOCUMENT_ID";

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom $where"); $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom $where " . ($where ? " AND " : " WHERE ") . " p.PROCESSING_ACTION='SUBMIT'"); $st->execute($params);
        $submits = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom $where " . ($where ? " AND " : " WHERE ") . " p.PROCESSING_ACTION='FORWARD'"); $st->execute($params);
        $forwards = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom $where " . ($where ? " AND " : " WHERE ") . " p.PROCESSING_ACTION='RESOLVE'"); $st->execute($params);
        $resolves = (int)$st->fetchColumn();

        echo json_encode(['success'=>true,'data'=>compact('total','submits','forwards','resolves')]);
        exit;
    }

    if ($action === 'delete') {
        requirePermissionApi($pdo, 'PROCESSING', 'DELE');
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
        if (!in_array($profileCode, ['SECR','SUPE'], true)) {
            echo json_encode(['success'=>false,'message'=>'Sem permissão para eliminar tramitações.']); exit;
        }
        $pdo->prepare("DELETE FROM `DOCUMENT_COMMENT` WHERE PROCESSING_ID=:id")->execute([':id'=>$id]);
        $pdo->prepare("DELETE FROM `PROCESSING` WHERE PROCESSING_ID=:id")->execute([':id'=>$id]);
        echo json_encode(['success'=>true,'message'=>'Tramitação eliminada com sucesso!']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']);
    exit;
}

[$where, $params] = buildProcessingFilter($profileCode, $userId, $myProfileId);

$sql = "SELECT p.*, d.DOCUMENT_CODE, d.DOCUMENT_SUBJECT, d.USER_ID AS DOC_OWNER_ID,
               CONCAT(fu.USER_FIRSTNAME,' ',fu.USER_LASTNAME) AS FROM_USER,
               CONCAT(tu.USER_FIRSTNAME,' ',tu.USER_LASTNAME) AS TO_USER,
               fp.PROFILE_NAME AS FROM_PROFILE_NAME,
               tp.PROFILE_NAME AS TO_PROFILE_NAME
        FROM `PROCESSING` p
        INNER JOIN `DOCUMENT` d ON d.DOCUMENT_ID = p.DOCUMENT_ID
        LEFT JOIN `USERS` fu ON fu.USER_ID = p.FROM_USER_ID
        LEFT JOIN `USERS` tu ON tu.USER_ID = p.TO_USER_ID
        LEFT JOIN `PROFILE` fp ON fp.PROFILE_ID = p.FROM_PROFILE_ID
        LEFT JOIN `PROFILE` tp ON tp.PROFILE_ID = p.TO_PROFILE_ID
        $where
        ORDER BY p.PROCESSING_ID DESC
        LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$proc = $st->fetchAll();

$pageTitle  = 'Tramitações';
$activePage = 'processing.php';

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-title mb-0" style="margin:0;">
        <h2><i class="fas fa-exchange-alt" style="color:var(--accent-color);"></i>
            <?= $profileCode === 'ESTU' ? 'Histórico de Tramitações' : 'Tramitações' ?>
        </h2>
        <div class="breadcrumb-custom">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i>
            <span>Tramitações</span>
        </div>
    </div>
    <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=processing','_blank')">
        <i class="fas fa-file-pdf"></i> Exportar PDF
    </button>
</div>

<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-exchange-alt"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(241,196,15,.12);color:#f1c40f;"><i class="fas fa-paper-plane"></i></div><div class="stat-info"><div class="value" id="statSubmits">—</div><div class="label">Submissões</div></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-share"></i></div><div class="stat-info"><div class="value" id="statForwards">—</div><div class="label">Encaminhamentos</div></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="statResolves">—</div><div class="label">Resoluções</div></div></div></div>
</div>

<div class="filter-card">
    <h6><i class="fas fa-filter"></i> Filtros</h6>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Documento, utilizador, nota..."></div>
        <div class="col-md-3"><label class="form-label">Ação</label>
            <select class="form-select" id="filterAction">
                <option value="">Todas</option>
                <option value="SUBMIT">Submissão</option>
                <option value="FORWARD">Encaminhamento</option>
                <option value="RESOLVE">Resolução</option>
                <option value="RETURN">Devolução</option>
                <option value="REJECT">Rejeição</option>
                <option value="CLOSE">Fecho</option>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-outline-secondary w-100" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;"><i class="fas fa-redo me-1"></i> Limpar</button>
        </div>
    </div>
</div>

<div class="main-card">
    <div class="main-card-header">
        <div class="title"><i class="fas fa-list-ul"></i><span>Histórico de Tramitações</span><span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;"><?= count($proc) ?></span></div>
    </div>
    <div class="main-card-body">
        <div class="table-responsive">
            <table class="table table-custom">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th><th style="width:130px;">Documento</th>
                        <th style="width:130px;">Ação</th><th>De</th><th>Para</th>
                        <th class="d-none d-md-table-cell">Nota</th>
                        <th style="width:140px;">Data</th><th style="width:70px;">Ações</th>
                    </tr>
                </thead>
                <tbody id="procTableBody">
                    <?php if (empty($proc)): ?>
                        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhuma tramitação encontrada</h6></div></td></tr>
                    <?php else: foreach ($proc as $p):
                        $actMap = [
                            'SUBMIT'=>['Submissão','warning'],
                            'FORWARD'=>['Encaminhamento','info'],
                            'RESOLVE'=>['Resolução','success'],
                            'RETURN'=>['Devolução','secondary'],
                            'REJECT'=>['Rejeição','danger'],
                            'CLOSE'=>['Fecho','dark']
                        ];
                        [$actLabel, $actColor] = $actMap[$p['PROCESSING_ACTION']] ?? [$p['PROCESSING_ACTION'],'secondary'];
                    ?>
                        <tr id="row-<?= (int)$p['PROCESSING_ID'] ?>">
                            <td><strong>#<?= (int)$p['PROCESSING_ID'] ?></strong></td>
                            <td class="cell-doc"><a href="document_view.php?id=<?= (int)$p['DOCUMENT_ID'] ?>" style="text-decoration:none;"><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.75rem;"><?= htmlspecialchars($p['DOCUMENT_CODE'] ?? '—') ?></code></a></td>
                            <td class="cell-action"><span class="badge bg-<?= $actColor ?>"><?= $actLabel ?></span></td>
                            <td class="cell-from" style="font-size:.85rem;"><?= htmlspecialchars($p['FROM_USER'] ?? '—') ?><br><small class="text-muted"><?= htmlspecialchars($p['FROM_PROFILE_NAME'] ?? '') ?></small></td>
                            <td class="cell-to" style="font-size:.85rem;"><?= htmlspecialchars($p['TO_USER'] ?? '—') ?><br><small class="text-muted"><?= htmlspecialchars($p['TO_PROFILE_NAME'] ?? '') ?></small></td>
                            <td class="cell-note d-none d-md-table-cell" style="font-size:.85rem;color:#7f8c8d;"><?= htmlspecialchars($p['PROCESSING_NOTE'] ?: '—') ?></td>
                            <td class="cell-date" style="font-size:.82rem;color:#7f8c8d;"><?= htmlspecialchars($p['PROCESSING_CREATEDAT'] ?? '—') ?></td>
                            <td class="cell-actions" data-id="<?= (int)$p['PROCESSING_ID'] ?>" data-doc="<?= htmlspecialchars($p['DOCUMENT_CODE'] ?? '') ?>">
                                <div class="action-links">
                                    <button class="btn-icon view" onclick="openViewModal(<?= (int)$p['PROCESSING_ID'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                    <?php if (in_array($profileCode, ['SECR','SUPE'], true)): ?>
                                    <button class="btn-icon delete" onclick="openDeleteModal(<?= (int)$p['PROCESSING_ID'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Detalhes da Tramitação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="viewBody"></div>
            <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <i class="fas fa-exclamation-triangle modal-danger-icon"></i>
                <h5 style="color:var(--primary-color);">Eliminar Tramitação</h5>
                <p class="text-muted mb-0" style="font-size:.9rem;">Eliminar registo <strong id="deleteProcId" style="color:#e74c3c;"></strong>?</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pb-4">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fas fa-trash"></i> Eliminar</button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = 'processing.php';
let viewModal, deleteModal, pendingDeleteId = null;

document.addEventListener('DOMContentLoaded', () => {
    viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    loadStats();
    let t;
    document.getElementById('filterSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilters, 300); });
    document.getElementById('filterAction').addEventListener('change', applyFilters);
    applyFilters();
});

function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

function loadStats(){
    const fd = new FormData(); fd.append('action','stats');
    fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
        if (j.success){
            document.getElementById('statTotal').textContent = j.data.total;
            document.getElementById('statSubmits').textContent = j.data.submits;
            document.getElementById('statForwards').textContent = j.data.forwards;
            document.getElementById('statResolves').textContent = j.data.resolves;
        }
    }).catch(console.error);
}

function applyFilters(){
    const search = document.getElementById('filterSearch').value.toLowerCase().trim();
    const action = document.getElementById('filterAction').value;
    const rows = Array.from(document.querySelectorAll('#procTableBody tr[id^="row-"]'));
    if (!rows.length) return;
    let visible = 0;
    rows.forEach(row => {
        const txt = row.innerText.toLowerCase();
        const act = row.querySelector('.cell-action')?.innerText.trim() || '';
        let show = true;
        if (search && !txt.includes(search)) show = false;
        if (action) {
            const map = { SUBMIT:'Submissão', FORWARD:'Encaminhamento', RESOLVE:'Resolução', RETURN:'Devolução', REJECT:'Rejeição', CLOSE:'Fecho' };
            if (act !== map[action]) show = false;
        }
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('listCount').textContent = visible;
}

function resetFilters(){
    document.getElementById('filterSearch').value='';
    document.getElementById('filterAction').value='';
    applyFilters();
}

function openViewModal(id){
    const row = document.getElementById('row-'+id); if (!row) return;
    const cells = row.querySelectorAll('td');
    document.getElementById('viewBody').innerHTML = `
        <div class="info-row"><div class="label">ID:</div><div class="value">#${id}</div></div>
        <div class="info-row"><div class="label">Documento:</div><div class="value">${escapeHtml(cells[1].innerText)}</div></div>
        <div class="info-row"><div class="label">Ação:</div><div class="value">${escapeHtml(cells[2].innerText)}</div></div>
        <div class="info-row"><div class="label">De:</div><div class="value">${escapeHtml(cells[3].innerText)}</div></div>
        <div class="info-row"><div class="label">Para:</div><div class="value">${escapeHtml(cells[4].innerText)}</div></div>
        <div class="info-row"><div class="label">Nota:</div><div class="value">${escapeHtml(cells[5].innerText||'—')}</div></div>
        <div class="info-row"><div class="label">Data:</div><div class="value">${escapeHtml(cells[6].innerText)}</div></div>
    `;
    viewModal.show();
}

function openDeleteModal(id){
    pendingDeleteId = id;
    document.getElementById('deleteProcId').textContent = '#' + id;
    deleteModal.show();
}

function confirmDelete(){
    if (!pendingDeleteId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('action','delete');
    fd.append('id', pendingDeleteId);
    fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
        deleteModal.hide();
        if (j.success){ showToast(j.message,'success'); setTimeout(()=>location.reload(),800); }
        else showToast(j.message||'Erro.','error');
    }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; pendingDeleteId=null; });
}
</script>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>