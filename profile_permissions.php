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

    (function () use ($pdo) {
        $id = (int)($_SESSION['user_id'] ?? 0);
        $nm = $pdo->quote($_SESSION['user_name'] ?? '');
        $ip = $pdo->quote($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = $pdo->quote(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
        try { $pdo->exec("SET @app_user_id = $id, @app_user_name = $nm, @app_user_ip = $ip, @app_user_agent = $ua"); }
        catch (\Throwable $e) {}
    })();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'load_permissions') {
            $profileId = intval($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) { echo json_encode(['success'=>false,'message'=>'Perfil inválido.']); exit; }
            try {
                $st = $pdo->prepare("SELECT PROFILE_ID, PROFILE_CODE, PROFILE_NAME, PROFILE_IS_SYSTEM FROM `PROFILE` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]); $profile = $st->fetch();
                if (!$profile) { echo json_encode(['success'=>false,'message'=>'Perfil não encontrado.']); exit; }

                $modules = $pdo->query("SELECT MODULE_ID, MODULE_CODE, MODULE_NAME FROM `MODULE` WHERE MODULE_STATUS=1 ORDER BY MODULE_NAME")->fetchAll();
                $actions = $pdo->query("SELECT ACTION_ID, ACTION_CODE, ACTION_NAME FROM `ACTION` WHERE ACTION_STATUS=1 ORDER BY ACTION_NAME")->fetchAll();

                $st = $pdo->prepare("SELECT MODULE_ID, ACTION_ID, PERMISSION_GRANTED FROM `PERMISSION` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]); $rows = $st->fetchAll();
                $map = [];
                foreach ($rows as $r) $map[$r['MODULE_ID'].':'.$r['ACTION_ID']] = (int)$r['PERMISSION_GRANTED'];

                echo json_encode(['success'=>true,'data'=>compact('profile','modules','actions','map')]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'save_permissions') {
            $profileId = intval($_POST['profile_id'] ?? 0);
            $payload = $_POST['permissions'] ?? '[]';
            if ($profileId <= 0) { echo json_encode(['success'=>false,'message'=>'Perfil inválido.']); exit; }
            $items = json_decode($payload, true);
            if (!is_array($items)) { echo json_encode(['success'=>false,'message'=>'Formato inválido.']); exit; }
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO `PERMISSION` (PROFILE_ID, MODULE_ID, ACTION_ID, PERMISSION_GRANTED) VALUES (:pid,:mid,:aid,1)");
                $del = $pdo->prepare("DELETE FROM `PERMISSION` WHERE PROFILE_ID=:pid AND MODULE_ID=:mid AND ACTION_ID=:aid");
                $st = $pdo->prepare("SELECT MODULE_ID, ACTION_ID FROM `PERMISSION` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]); $current = $st->fetchAll();
                $currentPairs = [];
                foreach ($current as $c) $currentPairs[$c['MODULE_ID'].':'.$c['ACTION_ID']] = true;

                $sent = []; $added = 0; $removed = 0;
                foreach ($items as $it) {
                    $mid = intval($it['module_id'] ?? 0); $aid = intval($it['action_id'] ?? 0); $g = intval($it['granted'] ?? 0);
                    if ($mid <= 0 || $aid <= 0 || $g !== 1) continue;
                    $key = $mid.':'.$aid;
                    if (isset($sent[$key])) continue;
                    $sent[$key] = ['mid'=>$mid, 'aid'=>$aid];
                    if (!isset($currentPairs[$key])) { $ins->execute([':pid'=>$profileId, ':mid'=>$mid, ':aid'=>$aid]); $added++; }
                }
                foreach ($currentPairs as $key => $_) {
                    if (!isset($sent[$key])) {
                        [$mid, $aid] = explode(':', $key);
                        $del->execute([':pid'=>$profileId, ':mid'=>(int)$mid, ':aid'=>(int)$aid]);
                        $removed++;
                    }
                }
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>"Guardado! (+{$added} adicionadas, -{$removed} removidas, ".count($sent)." ativas)", 'added'=>$added, 'removed'=>$removed, 'total'=>count($sent)]);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'clear_profile') {
            $profileId = intval($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                $st = $pdo->prepare("DELETE FROM `PERMISSION` WHERE PROFILE_ID=:pid");
                $st->execute([':pid'=>$profileId]);
                echo json_encode(['success'=>true,'message'=>"Todas removidas ({$st->rowCount()})."]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'stats') {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM `PERMISSION`")->fetchColumn();
            $grant = (int)$pdo->query("SELECT COUNT(*) FROM `PERMISSION` WHERE PERMISSION_GRANTED=1")->fetchColumn();
            $prof = (int)$pdo->query("SELECT COUNT(*) FROM `PROFILE` WHERE PROFILE_STATUS=1")->fetchColumn();
            $withP = (int)$pdo->query("SELECT COUNT(DISTINCT PROFILE_ID) FROM `PERMISSION` WHERE PERMISSION_GRANTED=1")->fetchColumn();
            echo json_encode(['success'=>true,'data'=>compact('total','grant','prof','withP')]);
            exit;
        }
    }

    $perfis = $pdo->query(
        "SELECT p.PROFILE_ID, p.PROFILE_CODE, p.PROFILE_NAME, p.PROFILE_IS_SYSTEM,
                (SELECT COUNT(*) FROM `PERMISSION` perm WHERE perm.PROFILE_ID=p.PROFILE_ID AND perm.PERMISSION_GRANTED=1) AS total_perms
        FROM `PROFILE` p WHERE p.PROFILE_STATUS=1 ORDER BY p.PROFILE_NAME"
    )->fetchAll();

    $pageTitle  = 'Atribuir Permissões';
    $activePage = 'profile_permissions.php';

    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-user-shield" style="color:var(--accent-color);"></i> Atribuir Permissões ao Perfil</h2>
                <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Atribuir Permissões</span></div>
            </div>
            <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=permissions','_blank')">
                <i class="fas fa-file-pdf"></i> Exportar PDF
            </button>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-shield-alt"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total de Permissões</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="statGrant">—</div><div class="label">Concedidas</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-user-tag"></i></div><div class="stat-info"><div class="value" id="statProf">—</div><div class="label">Perfis Ativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-user-shield"></i></div><div class="stat-info"><div class="value" id="statWith">—</div><div class="label">Perfis com Permissões</div></div></div></div>
        </div>

        <div class="main-card">
            <div class="main-card-header">
                <div class="title"><i class="fas fa-key"></i><span>Perfil → Permissões</span></div>
            </div>
            <div class="main-card-body">
                <div class="row g-0">
                    <div class="col-md-4 col-lg-3">
                        <div style="padding:16px 18px;border-bottom:1px solid #f0f0f0;background:#fafbfc;">
                            <h6 style="color:var(--primary-color);font-weight:600;margin:0;font-size:.9rem;"><i class="fas fa-user-tag" style="color:var(--accent-color);"></i> Selecione um Perfil</h6>
                        </div>
                        <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;background:#fafbfc;">
                            <input type="text" class="form-control form-control-custom" id="profileSearch" placeholder="Pesquisar..." oninput="filterProfiles(this.value)" style="font-size:.85rem;">
                        </div>
                        <div style="max-height:560px;overflow-y:auto;border-right:1px solid #f0f0f0;" id="profileList">
                            <?php foreach ($perfis as $p):
                                $pid = (int)$p['PROFILE_ID']; $cnt = (int)$p['total_perms'];
                                $initial = mb_strtoupper(mb_substr($p['PROFILE_NAME'], 0, 1));
                                $isSys = (int)$p['PROFILE_IS_SYSTEM'] === 1;
                            ?>
                                <div class="profile-item" data-id="<?= $pid ?>"
                                    data-name="<?= htmlspecialchars(mb_strtolower($p['PROFILE_NAME'])) ?>"
                                    data-code="<?= htmlspecialchars(mb_strtolower($p['PROFILE_CODE'])) ?>"
                                    onclick="selectProfile(<?= $pid ?>, this)"
                                    style="padding:12px 16px;border-bottom:1px solid #f0f0f0;cursor:pointer;display:flex;align-items:center;gap:12px;">
                                    <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#3498db,#2980b9);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;"><?= htmlspecialchars($initial) ?></div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:600;color:var(--primary-color);font-size:.88rem;"><?= htmlspecialchars($p['PROFILE_NAME']) ?> <?php if ($isSys): ?><span style="background:#e7dcf0;color:#5b2c8c;font-size:.6rem;padding:1px 5px;border-radius:3px;font-weight:700;"><i class="fas fa-lock"></i> SYS</span><?php endif; ?></div>
                                        <div style="font-size:.72rem;color:#7f8c8d;"><code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:.68rem;"><?= htmlspecialchars($p['PROFILE_CODE']) ?></code> · #<?= $pid ?></div>
                                    </div>
                                    <div class="badge-count" style="background:<?= $cnt===0?'#cbd5e0':'var(--accent-color)' ?>;color:white;font-size:.7rem;padding:3px 8px;border-radius:10px;font-weight:600;min-width:28px;text-align:center;"><?= $cnt ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-8 col-lg-9">
                        <div id="permsArea">
                            <div class="empty-state">
                                <i class="fas fa-hand-point-left"></i>
                                <h6 style="color:var(--primary-color);">Selecione um perfil</h6>
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
                        <p class="text-muted mb-0" style="font-size:.9rem;">Remover <strong>todas</strong> de<br><strong id="clearProfileName" style="color:#e74c3c;"></strong>?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmClearBtn" onclick="confirmClear()"><i class="fas fa-eraser"></i> Limpar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const API_URL = 'profile_permissions.php';
            let clearModal;
            let currentProfileId = null, currentProfileName = '', currentProfileCode = '';
            let isDirty = false;

            document.addEventListener('DOMContentLoaded', () => {
                clearModal = new bootstrap.Modal(document.getElementById('clearModal'));
                loadStats();
                window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
            });

            function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

            function loadStats(){ const fd=new FormData(); fd.append('action','stats'); fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                if (j.success){ document.getElementById('statTotal').textContent=j.data.total; document.getElementById('statGrant').textContent=j.data.grant; document.getElementById('statProf').textContent=j.data.prof; document.getElementById('statWith').textContent=j.data.withP; }
            }).catch(console.error); }

            function filterProfiles(term){ term=term.toLowerCase().trim(); document.querySelectorAll('.profile-item').forEach(item=>{ const n=item.dataset.name||''; const c=item.dataset.code||''; item.style.display=(!term||n.includes(term)||c.includes(term))?'':'none'; }); }

            function selectProfile(id, el){
                if (currentProfileId === id) return;
                if (isDirty && !confirm('Existem alterações não guardadas. Descartar?')) return;
                document.querySelectorAll('.profile-item').forEach(i => { i.style.background=''; i.style.borderLeft=''; });
                el.style.background='#eaf4fc'; el.style.borderLeft='4px solid var(--accent-color)';
                currentProfileId = id;
                currentProfileName = el.querySelector('div[style*="font-weight:600"]').textContent.trim();
                currentProfileCode = el.querySelector('code')?.textContent || '';
                isDirty = false;
                loadPermissions(id);
            }

            function loadPermissions(id){
                const area = document.getElementById('permsArea');
                area.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>A carregar...</p></div>';
                const fd = new FormData(); fd.append('action','load_permissions'); fd.append('profile_id', id);
                fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                    if (!j.success){ area.innerHTML = `<div class="empty-state"><p>${escapeHtml(j.message)}</p></div>`; return; }
                    renderPermissions(j.data);
                }).catch(console.error);
            }

            function renderPermissions(data){
                const { profile, modules, actions, map } = data;
                const area = document.getElementById('permsArea');
                if (!modules.length || !actions.length){ area.innerHTML = '<div class="empty-state"><p>Sem dados suficientes.</p></div>'; return; }

                let activeCount = 0;
                Object.values(map).forEach(v => { if (v === 1) activeCount++; });
                const totalPairs = modules.length * actions.length;
                const initial = (profile.PROFILE_NAME||'?').charAt(0).toUpperCase();

                let html = `<div style="padding:16px 22px;border-bottom:1px solid #f0f0f0;background:#fafbfc;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <div style="width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#3498db,#2980b9);color:white;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;">${escapeHtml(initial)}</div>
                        <div><h5 style="margin:0;color:var(--primary-color);font-weight:700;font-size:1.05rem;">${escapeHtml(profile.PROFILE_NAME)}</h5>
                        <small style="color:#7f8c8d;font-size:.8rem;"><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.72rem;">${escapeHtml(profile.PROFILE_CODE)}</code> · Perfil #${profile.PROFILE_ID}</small></div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn-outline-action" onclick="openClearModal()"><i class="fas fa-eraser"></i> Limpar</button>
                        <button class="btn-outline-action" onclick="selectAll(true)"><i class="fas fa-check-double"></i> Marcar Tudo</button>
                        <button class="btn-outline-action" onclick="selectAll(false)"><i class="fas fa-square"></i> Desmarcar</button>
                        <button class="btn-action" id="btnSave" onclick="savePermissions()"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </div>
                <div style="padding:12px 20px;background:#f8f9fa;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;font-size:.82rem;color:#7f8c8d;">
                    <div style="display:flex;gap:14px;">
                        <div><i class="fas fa-check-circle" style="color:#2ecc71;"></i> <strong id="activeCount" style="color:var(--primary-color);">${activeCount}</strong> concedidas</div>
                        <div><i class="fas fa-circle" style="color:#95a5a6;"></i> <strong id="inactiveCount" style="color:var(--primary-color);">${totalPairs-activeCount}</strong> não concedidas</div>
                    </div>
                    <div><i class="fas fa-info-circle"></i> Marque as permissões (Módulo + Ação) que este perfil terá acesso.</div>
                </div>
                <div class="table-responsive"><table style="margin:0;font-size:.9rem;">
                    <thead><tr style="background:#f8f9fa;">
                        <th style="padding:12px 16px;font-size:.78rem;text-transform:uppercase;color:var(--primary-color);width:70px;">#</th>
                        <th style="padding:12px 16px;font-size:.78rem;text-transform:uppercase;color:var(--primary-color);">Módulo</th>
                        <th style="padding:12px 16px;font-size:.78rem;text-transform:uppercase;color:var(--primary-color);">Ação</th>
                        <th style="padding:12px 16px;font-size:.78rem;text-transform:uppercase;color:var(--primary-color);">Permissão</th>
                    </tr></thead><tbody>`;

                let idx = 0;
                modules.forEach(m => {
                    actions.forEach(a => {
                        idx++;
                        const key = m.MODULE_ID + ':' + a.ACTION_ID;
                        const granted = (map[key] === 1);
                        html += `<tr style="border-bottom:1px solid #f0f0f0;border-left:4px solid ${granted?'#2ecc71':'#e74c3c'};${granted?'':'background:#fdf5f5;'}">
                            <td style="padding:12px 16px;"><strong>${idx}</strong></td>
                            <td style="padding:12px 16px;"><span style="background:#eef7fd;color:#2980b9;padding:5px 12px;border-radius:8px;font-size:.78rem;font-weight:600;"><i class="fas fa-cube"></i> ${escapeHtml(m.MODULE_NAME)} <code style="background:rgba(255,255,255,.7);padding:1px 5px;border-radius:3px;font-size:.68rem;">${escapeHtml(m.MODULE_CODE)}</code></span></td>
                            <td style="padding:12px 16px;"><span style="background:#fff5e1;color:#b8860b;padding:5px 12px;border-radius:8px;font-size:.78rem;font-weight:600;"><i class="fas fa-bolt"></i> ${escapeHtml(a.ACTION_NAME)} <code style="background:rgba(255,255,255,.7);padding:1px 5px;border-radius:3px;font-size:.68rem;">${escapeHtml(a.ACTION_CODE)}</code></span></td>
                            <td style="padding:12px 16px;">
                                <span class="status-pill ${granted?'granted':'revoked'}" data-module="${m.MODULE_ID}" data-action="${a.ACTION_ID}" onclick="togglePermission(this)"
                                    style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:600;cursor:pointer;border:1.5px solid transparent;${granted?'background:#d4edda;color:#155724;border-color:#b1dfbb;':'background:#f8d7da;color:#721c24;border-color:#f1b0b7;'}">
                                    ${granted?'<i class="fas fa-check-circle"></i> Concedida':'<i class="fas fa-times-circle"></i> Não concedida'}
                                </span>
                            </td>
                        </tr>`;
                    });
                });
                html += '</tbody></table></div>';
                area.innerHTML = html;
            }

            function togglePermission(el){
                const granted = el.classList.toggle('granted');
                el.classList.toggle('revoked', !granted);
                el.style.background = granted ? '#d4edda' : '#f8d7da';
                el.style.color = granted ? '#155724' : '#721c24';
                el.style.borderColor = granted ? '#b1dfbb' : '#f1b0b7';
                el.innerHTML = granted ? '<i class="fas fa-check-circle"></i> Concedida' : '<i class="fas fa-times-circle"></i> Não concedida';
                const tr = el.closest('tr');
                tr.style.borderLeftColor = granted ? '#2ecc71' : '#e74c3c';
                tr.style.background = granted ? '' : '#fdf5f5';
                isDirty = true;
                updateCounters();
            }

            function updateCounters(){
                const all = document.querySelectorAll('.status-pill');
                let granted = 0;
                all.forEach(el => { if (el.classList.contains('granted')) granted++; });
                const a = document.getElementById('activeCount'), i = document.getElementById('inactiveCount');
                if (a) a.textContent = granted;
                if (i) i.textContent = all.length - granted;
            }

            function selectAll(value){
                document.querySelectorAll('.status-pill').forEach(el => {
                    const isGranted = el.classList.contains('granted');
                    if ((value && !isGranted) || (!value && isGranted)) togglePermission(el);
                });
                isDirty = true;
            }

            function savePermissions(){
                if (!currentProfileId) return;
                const items = [];
                document.querySelectorAll('.status-pill').forEach(el => {
                    items.push({ module_id: parseInt(el.dataset.module), action_id: parseInt(el.dataset.action), granted: el.classList.contains('granted') ? 1 : 0 });
                });
                const btn = document.getElementById('btnSave'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';
                const fd = new FormData(); fd.append('action','save_permissions'); fd.append('profile_id', currentProfileId); fd.append('permissions', JSON.stringify(items));
                fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ showToast(j.message,'success',5000); isDirty = false; loadStats();
                        const item = document.querySelector(`.profile-item[data-id="${currentProfileId}"] .badge-count`);
                        if (item) { item.textContent = j.total || items.filter(i=>i.granted===1).length; item.style.background = (j.total||0)===0?'#cbd5e0':'var(--accent-color)'; }
                        loadPermissions(currentProfileId);
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
                        loadPermissions(currentProfileId);
                    } else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(()=>{ btn.disabled=false; btn.innerHTML=orig; });
            }
        </script>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>