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
    requirePermissionOrRedirect($pdo, 'PERMISSIONS', 'VIEW');

    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $__allowed = allowedModules($pdo);
    $_SESSION['__allowed_modules'] = $__allowed;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'load_matrix') {
            $profileId = intval($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) { echo json_encode(['success'=>false,'message'=>'Perfil inválido.']); exit; }

            try {
                $st = $pdo->prepare("SELECT PROFILE_ID, PROFILE_CODE, PROFILE_NAME FROM `PROFILE` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]);
                $profile = $st->fetch();
                if (!$profile) { echo json_encode(['success'=>false,'message'=>'Perfil não encontrado.']); exit; }

                $modules = $pdo->query("SELECT MODULE_ID, MODULE_CODE, MODULE_NAME FROM `MODULE` WHERE MODULE_STATUS=1 ORDER BY MODULE_NAME")->fetchAll();
                $actions = $pdo->query("SELECT ACTION_ID, ACTION_CODE, ACTION_NAME FROM `ACTION` WHERE ACTION_STATUS=1 ORDER BY ACTION_NAME")->fetchAll();

                $st = $pdo->prepare("SELECT MODULE_ID, ACTION_ID, PERMISSION_GRANTED FROM `PERMISSION` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]);
                $rows = $st->fetchAll();
                $map = [];
                foreach ($rows as $r) $map[$r['MODULE_ID'].':'.$r['ACTION_ID']] = (int)$r['PERMISSION_GRANTED'];

                echo json_encode(['success'=>true,'data'=>compact('profile','modules','actions','map')]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Erro ao carregar: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'save_matrix') {
            requirePermissionApi($pdo, 'PERMISSIONS', 'UPDT');
            $profileId = intval($_POST['profile_id'] ?? 0);
            $payload = $_POST['matrix'] ?? '[]';
            if ($profileId <= 0) { echo json_encode(['success'=>false,'message'=>'Perfil inválido.']); exit; }
            $items = json_decode($payload, true);
            if (!is_array($items)) { echo json_encode(['success'=>false,'message'=>'Formato inválido.']); exit; }

            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO `PERMISSION` (PROFILE_ID, MODULE_ID, ACTION_ID, PERMISSION_GRANTED) VALUES (:pid,:mid,:aid,1) ON DUPLICATE KEY UPDATE PERMISSION_GRANTED=1");
                $del = $pdo->prepare("DELETE FROM `PERMISSION` WHERE PROFILE_ID=:pid AND MODULE_ID=:mid AND ACTION_ID=:aid");
                $active = 0; $seen = [];
                foreach ($items as $it) {
                    $mid = intval($it['module_id'] ?? 0);
                    $aid = intval($it['action_id'] ?? 0);
                    $g   = intval($it['granted'] ?? 0);
                    if ($mid <= 0 || $aid <= 0) continue;
                    $k = $mid.':'.$aid; if (isset($seen[$k])) continue; $seen[$k] = true;
                    if ($g === 1) { $ins->execute([':pid'=>$profileId,':mid'=>$mid,':aid'=>$aid]); $active++; }
                    else { $del->execute([':pid'=>$profileId,':mid'=>$mid,':aid'=>$aid]); }
                }
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>"Permissões guardadas! ({$active} ativas)",'active'=>$active]);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'clear_profile') {
            requirePermissionApi($pdo, 'PERMISSIONS', 'DELE');
            $profileId = intval($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                $st = $pdo->prepare("DELETE FROM `PERMISSION` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]);
                echo json_encode(['success'=>true,'message'=>"Todas as permissões removidas ({$st->rowCount()})."]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'stats') {
            $total    = (int)$pdo->query("SELECT COUNT(*) FROM `PERMISSION`")->fetchColumn();
            $active   = (int)$pdo->query("SELECT COUNT(*) FROM `PERMISSION` WHERE PERMISSION_GRANTED=1")->fetchColumn();
            $profiles = (int)$pdo->query("SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_STATUS=1")->fetchColumn();
            $with     = (int)$pdo->query("SELECT COUNT(DISTINCT PROFILE_ID) FROM `PERMISSION` WHERE PERMISSION_GRANTED=1")->fetchColumn();
            echo json_encode(['success'=>true,'data'=>compact('total','active','profiles','with')]);
            exit;
        }
        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
    }

    $perfis = $pdo->query(
        "SELECT p.PROFILE_ID, p.PROFILE_CODE, p.PROFILE_NAME,
                (SELECT COUNT(*) FROM `PERMISSION` perm WHERE perm.PROFILE_ID=p.PROFILE_ID AND perm.PERMISSION_GRANTED=1) AS total_perms
        FROM `PROFILE` p WHERE p.PROFILE_STATUS = 1 ORDER BY p.PROFILE_NAME"
    )->fetchAll();

    $pageTitle = 'Gestão de Permissões';
    $activePage = 'permissions.php';
    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-shield-alt" style="color:var(--accent-color);"></i> Gestão de Permissões</h2>
                <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Permissões</span></div>
            </div>
            <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=permissions','_blank')">
                <i class="fas fa-file-pdf"></i> Exportar PDF
            </button>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-shield-alt"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="statActive">—</div><div class="label">Concedidas</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-user-tag"></i></div><div class="stat-info"><div class="value" id="statProfiles">—</div><div class="label">Perfis Ativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-user-shield"></i></div><div class="stat-info"><div class="value" id="statWith">—</div><div class="label">Com Permissões</div></div></div></div>
        </div>

        <div class="main-card">
            <div class="main-card-header">
                <div class="title"><i class="fas fa-table"></i><span>Matriz de Permissões</span></div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn-outline-action" id="btnClearProfile" onclick="openClearModal()" disabled><i class="fas fa-eraser"></i> Limpar</button>
                    <button class="btn-outline-action" id="btnSelectAll" onclick="selectAll(true)" disabled><i class="fas fa-check-double"></i> Marcar Tudo</button>
                    <button class="btn-outline-action" id="btnDeselectAll" onclick="selectAll(false)" disabled><i class="fas fa-square"></i> Desmarcar</button>
                    <button class="btn-action" id="btnSave" onclick="saveMatrix()" disabled><i class="fas fa-save"></i> Guardar</button>
                </div>
            </div>
            <div class="main-card-body">
                <div class="row g-0">
                    <div class="col-md-4 col-lg-3">
                        <div style="padding:16px 18px;border-bottom:1px solid #f0f0f0;background:#fafbfc;">
                            <h6 style="color:var(--primary-color);font-weight:600;margin:0;font-size:.9rem;display:flex;align-items:center;gap:8px;">
                                <i class="fas fa-user-tag" style="color:var(--accent-color);"></i> Selecione um Perfil
                            </h6>
                        </div>
                        <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;background:#fafbfc;">
                            <input type="text" class="form-control form-control-custom" id="profileSearch" placeholder="Pesquisar perfil..." oninput="filterProfiles(this.value)" style="font-size:.85rem;">
                        </div>
                        <div style="max-height:520px;overflow-y:auto;border-right:1px solid #f0f0f0;" id="profileList">
                            <?php foreach ($perfis as $p):
                                $pid = (int)$p['PROFILE_ID']; $cnt = (int)$p['total_perms'];
                                $initial = mb_strtoupper(mb_substr($p['PROFILE_NAME'], 0, 1));
                            ?>
                                <div class="profile-item" data-id="<?= $pid ?>"
                                    data-name="<?= htmlspecialchars(mb_strtolower($p['PROFILE_NAME'])) ?>"
                                    data-code="<?= htmlspecialchars(mb_strtolower($p['PROFILE_CODE'])) ?>"
                                    onclick="selectProfile(<?= $pid ?>, this)"
                                    style="padding:12px 16px;border-bottom:1px solid #f0f0f0;cursor:pointer;display:flex;align-items:center;gap:12px;transition:background .2s;">
                                    <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#3498db,#2980b9);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;flex-shrink:0;"><?= htmlspecialchars($initial) ?></div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:600;color:var(--primary-color);font-size:.88rem;"><?= htmlspecialchars($p['PROFILE_NAME']) ?></div>
                                        <div style="font-size:.72rem;color:#7f8c8d;"><code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:.68rem;"><?= htmlspecialchars($p['PROFILE_CODE']) ?></code> · #<?= $pid ?></div>
                                    </div>
                                    <div class="badge-count" style="background:<?= $cnt===0?'#cbd5e0':'var(--accent-color)' ?>;color:white;font-size:.7rem;padding:3px 8px;border-radius:10px;font-weight:600;min-width:28px;text-align:center;"><?= $cnt ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-8 col-lg-9">
                        <div id="matrixArea">
                            <div class="empty-state">
                                <i class="fas fa-hand-point-left"></i>
                                <h6 style="color:var(--primary-color);">Selecione um perfil</h6>
                                <p style="font-size:.85rem;">Escolha um perfil à esquerda para editar as suas permissões.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="clearModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-exclamation-triangle modal-danger-icon"></i>
                        <h5 style="color:var(--primary-color);">Limpar Permissões</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Remover <strong>todas</strong> as permissões de<br><strong id="clearProfileName" style="color:#e74c3c;"></strong>?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmClearBtn" onclick="confirmClear()"><i class="fas fa-eraser"></i> Limpar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const API_URL = 'permissions.php';
            let clearModal;
            let currentProfileId = null, currentProfileName = '', currentProfileCode = '';
            let isDirty = false;

            document.addEventListener('DOMContentLoaded', () => {
                clearModal = new bootstrap.Modal(document.getElementById('clearModal'));
                loadStats();
                window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
            });

            function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

            function loadStats(){
                const fd = new FormData(); fd.append('action','stats');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ document.getElementById('statTotal').textContent=j.data.total; document.getElementById('statActive').textContent=j.data.active; document.getElementById('statProfiles').textContent=j.data.profiles; document.getElementById('statWith').textContent=j.data.with; }
                }).catch(console.error);
            }

            function filterProfiles(term){
                term = term.toLowerCase().trim();
                document.querySelectorAll('.profile-item').forEach(item=>{
                    const n = item.dataset.name || ''; const c = item.dataset.code || '';
                    item.style.display = (!term || n.includes(term) || c.includes(term)) ? '' : 'none';
                });
            }

            function selectProfile(id, el){
                if (currentProfileId === id) return;
                if (isDirty && !confirm('Existem alterações não guardadas. Descartar?')) return;
                document.querySelectorAll('.profile-item').forEach(i => { i.style.background=''; i.style.borderLeft=''; });
                el.style.background = '#eaf4fc';
                el.style.borderLeft = '4px solid var(--accent-color)';
                currentProfileId = id;
                currentProfileName = el.querySelector('div[style*="font-weight:600"]').textContent.trim();
                currentProfileCode = el.querySelector('code')?.textContent || '';
                isDirty = false;
                ['btnSave','btnClearProfile','btnSelectAll','btnDeselectAll'].forEach(i => document.getElementById(i).disabled = false);
                loadMatrix(id);
            }

            function loadMatrix(profileId){
                const area = document.getElementById('matrixArea');
                area.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>A carregar...</p></div>';
                const fd = new FormData(); fd.append('action','load_matrix'); fd.append('profile_id', profileId);
                fetch(API_URL,{method:'POST',body:fd})
                    .then(r => r.text())
                    .then(txt => {
                        let j;
                        try { j = JSON.parse(txt); }
                        catch(e) {
                            console.error('Resposta não-JSON do servidor:', txt);
                            area.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i><p style="color:#e74c3c;">Erro do servidor. Ver consola.</p><pre style="text-align:left;font-size:.7rem;max-height:200px;overflow:auto;background:#f8f9fa;padding:10px;border-radius:6px;margin-top:10px;">${escapeHtml(txt.substring(0,500))}</pre></div>`;
                            return;
                        }
                        if (!j.success){ area.innerHTML = `<div class="empty-state"><p>${escapeHtml(j.message)}</p></div>`; return; }
                        renderMatrix(j.data);
                    })
                    .catch(err => {
                        console.error(err);
                        area.innerHTML = '<div class="empty-state"><p>Erro de comunicação.</p></div>';
                    });
            }

            function renderMatrix(data){
                const { modules, actions, map, profile } = data;
                const area = document.getElementById('matrixArea');
                if (!modules.length || !actions.length){
                    area.innerHTML = '<div class="empty-state"><i class="fas fa-info-circle"></i><p>Sem módulos/ações ativos.</p></div>';
                    return;
                }
                let activeCount = 0;
                Object.values(map).forEach(v => { if (v === 1) activeCount++; });

                let html = `<div style="padding:14px 18px;border-bottom:1px solid #f0f0f0;background:#fafbfc;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                    <div style="font-size:.88rem;color:#7f8c8d;"><i class="fas fa-user-tag" style="color:var(--accent-color);"></i> Perfil: <strong style="color:var(--primary-color);">${escapeHtml(profile.PROFILE_NAME)}</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.72rem;">${escapeHtml(profile.PROFILE_CODE)}</code></div>
                    <div style="display:flex;gap:14px;font-size:.8rem;color:#7f8c8d;">
                        <div><i class="fas fa-check-circle" style="color:#2ecc71;"></i> <strong id="activeCount" style="color:var(--primary-color);">${activeCount}</strong> ativas</div>
                        <div><i class="fas fa-times-circle" style="color:#e74c3c;"></i> <strong id="inactiveCount" style="color:var(--primary-color);">${modules.length*actions.length-activeCount}</strong> inativas</div>
                    </div>
                </div>
                <div style="overflow:auto;max-height:560px;">
                    <table style="width:100%;border-collapse:separate;border-spacing:0;font-size:.88rem;">
                        <thead><tr>
                            <th style="background:#f8f9fa;color:var(--primary-color);font-weight:600;border-bottom:2px solid #e0e0e0;padding:12px 14px;font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:2;">Módulo \\ Ação</th>`;
                actions.forEach(a => {
                    html += `<th style="background:#f8f9fa;color:var(--primary-color);font-weight:600;border-bottom:2px solid #e0e0e0;padding:12px 14px;font-size:.78rem;text-transform:uppercase;text-align:center;position:sticky;top:0;z-index:2;">${escapeHtml(a.ACTION_NAME)}<span style="display:block;font-family:monospace;font-size:.65rem;color:#94a3b8;margin-top:2px;">${escapeHtml(a.ACTION_CODE)}</span></th>`;
                });
                html += '</tr></thead><tbody>';

                modules.forEach(m => {
                    html += `<tr><td style="text-align:left;font-weight:500;color:var(--primary-color);background:#fafbfc;padding:10px 14px;border-bottom:1px solid #f0f0f0;border-right:1px solid #f0f0f0;position:sticky;left:0;z-index:1;"><span style="background:#f1f5f9;color:#475569;font-family:monospace;font-size:.72rem;padding:2px 6px;border-radius:4px;margin-right:8px;">${escapeHtml(m.MODULE_CODE)}</span>${escapeHtml(m.MODULE_NAME)}</td>`;
                    actions.forEach(a => {
                        const key = m.MODULE_ID + ':' + a.ACTION_ID;
                        const checked = map[key] === 1;
                        html += `<td style="text-align:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;">
                            <label class="perm-check ${checked?'checked':''}" data-module="${m.MODULE_ID}" data-action="${a.ACTION_ID}" onclick="toggleCheck(event,this)"
                                style="width:24px;height:24px;border:2px solid ${checked?'var(--accent-color)':'#cbd5e0'};border-radius:6px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;background:${checked?'var(--accent-color)':'white'};transition:all .2s;position:relative;">
                                <input type="checkbox" ${checked?'checked':''} style="display:none;">
                                ${checked?'<i class="fas fa-check" style="color:white;font-size:.8rem;"></i>':''}
                            </label>
                        </td>`;
                    });
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
                area.innerHTML = html;
            }

            function toggleCheck(event, el){
                event.preventDefault();
                const isChecked = el.classList.toggle('checked');
                el.querySelector('input').checked = isChecked;
                el.style.background = isChecked ? 'var(--accent-color)' : 'white';
                el.style.borderColor = isChecked ? 'var(--accent-color)' : '#cbd5e0';
                el.innerHTML = `<input type="checkbox" ${isChecked?'checked':''} style="display:none;">${isChecked?'<i class="fas fa-check" style="color:white;font-size:.8rem;"></i>':''}`;
                isDirty = true;
                updateCounters();
            }

            function updateCounters(){
                const all = document.querySelectorAll('.perm-check');
                let active = 0;
                all.forEach(el => { if (el.classList.contains('checked')) active++; });
                const a = document.getElementById('activeCount'), i = document.getElementById('inactiveCount');
                if (a) a.textContent = active;
                if (i) i.textContent = all.length - active;
            }

            function selectAll(value){
                document.querySelectorAll('.perm-check').forEach(el => {
                    const wasChecked = el.classList.contains('checked');
                    if (value && !wasChecked) { el.classList.add('checked'); el.querySelector('input').checked = true; el.style.background = 'var(--accent-color)'; el.style.borderColor = 'var(--accent-color)'; el.innerHTML = `<input type="checkbox" checked style="display:none;"><i class="fas fa-check" style="color:white;font-size:.8rem;"></i>`; }
                    else if (!value && wasChecked) { el.classList.remove('checked'); el.querySelector('input').checked = false; el.style.background = 'white'; el.style.borderColor = '#cbd5e0'; el.innerHTML = `<input type="checkbox" style="display:none;">`; }
                });
                isDirty = true;
                updateCounters();
            }

            function saveMatrix(){
                if (!currentProfileId) return;
                const items = [];
                document.querySelectorAll('.perm-check').forEach(el => {
                    items.push({ module_id: parseInt(el.dataset.module), action_id: parseInt(el.dataset.action), granted: el.classList.contains('checked') ? 1 : 0 });
                });
                const btn = document.getElementById('btnSave'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';
                const fd = new FormData(); fd.append('action','save_matrix'); fd.append('profile_id', currentProfileId); fd.append('matrix', JSON.stringify(items));
                fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ showToast(j.message,'success'); isDirty = false; loadStats();
                        const item = document.querySelector(`.profile-item[data-id="${currentProfileId}"] .badge-count`);
                        if (item) { item.textContent = j.active; item.style.background = j.active===0?'#cbd5e0':'var(--accent-color)'; }
                        loadMatrix(currentProfileId);
                    } else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(()=>{ btn.disabled=false; btn.innerHTML=orig; });
            }

            function openClearModal(){ if (!currentProfileId) return; document.getElementById('clearProfileName').textContent = currentProfileName; clearModal.show(); }
            function confirmClear(){
                if (!currentProfileId) return;
                const btn = document.getElementById('confirmClearBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const fd = new FormData(); fd.append('action','clear_profile'); fd.append('profile_id', currentProfileId);
                fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                    clearModal.hide();
                    if (j.success){ showToast(j.message,'success'); loadStats(); isDirty = false;
                        const item = document.querySelector(`.profile-item[data-id="${currentProfileId}"] .badge-count`);
                        if (item) { item.textContent = '0'; item.style.background = '#cbd5e0'; }
                        loadMatrix(currentProfileId);
                    } else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(()=>{ btn.disabled=false; btn.innerHTML=orig; });
            }
        </script>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>