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
    require_once 'includes/notification_helper.php';
    requireLogin();
    requirePermissionOrRedirect($pdo, 'DOCUMENTS', 'VIEW');

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

    $userId      = (int) $_SESSION['user_id'];
    $userName    = $_SESSION['user_name'] ?? 'Utilizador';
    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $myProfileId = (int) $_SESSION['profile_id'];

    $__allowed = allowedModules($pdo);
    $_SESSION['__allowed_modules'] = $__allowed;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'save') {
            $isEdit = !empty($_POST['id']);
            requirePermissionApi($pdo, 'DOCUMENTS', $isEdit ? 'UPDT' : 'CREA');

            $id       = $isEdit ? intval($_POST['id']) : null;
            $typeId   = (int)($_POST['type_id'] ?? 0);
            $subject  = trim($_POST['subject'] ?? '');
            $body     = trim($_POST['body'] ?? '');
            $priority = $_POST['priority'] ?? 'NORMAL';
            $status   = $_POST['status'] ?? 'SUBMITTED';

            $errors = [];
            if ($typeId <= 0) $errors['type_id'] = 'Selecione o tipo.';
            if ($subject === '') $errors['subject'] = 'O assunto é obrigatório.';
            if (!in_array($priority, ['LOW','NORMAL','HIGH','URGENT'])) $priority = 'NORMAL';

            if ($errors) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }

            try {
                if ($isEdit) {
                    if (!in_array($profileCode, ['SECR','SUPE'], true)) {
                        echo json_encode(['success'=>false,'message'=>'Sem permissão para editar.']); exit;
                    }
                    $pdo->prepare("UPDATE `DOCUMENT` SET DOCUMENT_TYPE_ID=:dt, DOCUMENT_SUBJECT=:s, DOCUMENT_BODY=:b, DOCUMENT_PRIORITY=:p, DOCUMENT_STATUS=:st WHERE DOCUMENT_ID=:id")
                        ->execute([':dt'=>$typeId, ':s'=>$subject, ':b'=>$body, ':p'=>$priority, ':st'=>$status, ':id'=>$id]);
                    echo json_encode(['success'=>true,'message'=>'Documento atualizado com sucesso!']);
                } else {
                    if (!in_array($profileCode, ['SUPE','ESTU'], true)) {
                        echo json_encode(['success'=>false,'message'=>'Sem permissão para criar requerimentos.']); exit;
                    }
                    $year = date('Y');
                    $seq  = (int)$pdo->query("SELECT COUNT(*) FROM `DOCUMENT` WHERE YEAR(DOCUMENT_CREATEDAT)=$year")->fetchColumn() + 1;
                    $code = sprintf("REQ-%s-%04d", $year, $seq);

                    $st = $pdo->prepare("SELECT FLOW_ID FROM `FLOW` WHERE DOCUMENT_TYPE_ID=:dt AND FLOW_STATUS=1 LIMIT 1");
                    $st->execute([':dt'=>$typeId]); $flowId = $st->fetchColumn() ?: null;

                    $st = $pdo->prepare("
                        SELECT u.USER_ID, u.PROFILE_ID
                        FROM `USERS` u
                        INNER JOIN `PROFILE` p ON p.PROFILE_ID = u.PROFILE_ID
                        WHERE p.PROFILE_CODE = 'SECR' AND u.USER_STATUS = 1
                        ORDER BY u.USER_ID ASC LIMIT 1
                    ");
                    $st->execute();
                    $secr = $st->fetch();
                    $secrUserId = $secr ? (int)$secr['USER_ID'] : null;

                    $pdo->prepare("INSERT INTO `DOCUMENT`
                        (DOCUMENT_CODE, DOCUMENT_TYPE_ID, FLOW_ID, USER_ID, DOCUMENT_SUBJECT, DOCUMENT_BODY,
                        DOCUMENT_STATUS, DOCUMENT_PRIORITY, DOCUMENT_CURRENT_STEP, DOCUMENT_ASSIGNED_TO)
                        VALUES (:c, :dt, :fl, :u, :s, :b, 'SUBMITTED', :p, 1, :assigned)")
                        ->execute([
                            ':c'=>$code, ':dt'=>$typeId, ':fl'=>$flowId, ':u'=>$userId,
                            ':s'=>$subject, ':b'=>$body, ':p'=>$priority, ':assigned'=>$secrUserId
                        ]);

                    $newId = (int)$pdo->lastInsertId();

                    if ($secr) {
                        $pdo->prepare("
                            INSERT INTO `PROCESSING`
                                (DOCUMENT_ID, FROM_USER_ID, FROM_PROFILE_ID, TO_USER_ID, TO_PROFILE_ID, PROCESSING_ACTION, PROCESSING_NOTE)
                            VALUES (:d, :fu, :fp, :tu, :tp, 'SUBMIT', :note)
                        ")->execute([
                            ':d'=>$newId, ':fu'=>$userId, ':fp'=>$myProfileId,
                            ':tu'=>$secrUserId, ':tp'=>(int)$secr['PROFILE_ID'],
                            ':note'=>'Submetido pelo Estudante'
                        ]);
                    }

                    notifyProfile($pdo, 'SECR', $newId, 'SUBMIT', 'Novo requerimento: ' . $code, 'O estudante ' . $userName . ' submeteu "' . $subject . '".', 'document_view.php?id=' . $newId);

                    echo json_encode(['success'=>true,'message'=>"Requerimento $code submetido com sucesso!",'id'=>$newId,'code'=>$code]);
                }
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'delete') {
            requirePermissionApi($pdo, 'DOCUMENTS', 'DELE');
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            if (!in_array($profileCode, ['SECR','SUPE'], true)) {
                echo json_encode(['success'=>false,'message'=>'Sem permissão.']); exit;
            }
            $pdo->prepare("DELETE FROM `DOCUMENT` WHERE DOCUMENT_ID=:id")->execute([':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>'Documento eliminado com sucesso!']);
            exit;
        }

        if ($action === 'options') {
            $types = $pdo->query("SELECT DOCUMENT_TYPE_ID, DOCUMENT_TYPE_CODE, DOCUMENT_TYPE_NAME FROM `DOCUMENT_TYPE` WHERE DOCUMENT_TYPE_STATUS=1 ORDER BY DOCUMENT_TYPE_NAME")->fetchAll();
            echo json_encode(['success'=>true,'data'=>compact('types')]);
            exit;
        }

        if ($action === 'history_list') {
            $page = max(1, intval($_POST['page'] ?? 1));
            $perPage = min(100, max(10, intval($_POST['per_page'] ?? 25)));
            $offset = ($page - 1) * $perPage;
            $search = trim($_POST['search'] ?? '');
            $status = trim($_POST['status'] ?? '');

            [$where, $params] = getDocumentFilter($pdo, $userId);
            $where = str_replace('d.', 'doc.', $where);

            $extra = [];
            if ($search !== '') { $extra[] = "(doc.DOCUMENT_CODE LIKE :s OR doc.DOCUMENT_SUBJECT LIKE :s OR h.HISTORY_TO_STATUS LIKE :s)"; $params[':s'] = '%'.$search.'%'; }
            if ($status !== '') { $extra[] = "h.HISTORY_TO_STATUS = :st"; $params[':st'] = $status; }
            if ($extra) {
                $where = $where === '' ? 'WHERE ' . implode(' AND ', $extra) : $where . ' AND ' . implode(' AND ', $extra);
            }

            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `DOCUMENT_HISTORY` h INNER JOIN `DOCUMENT` doc ON doc.DOCUMENT_ID = h.DOCUMENT_ID $where");
                $st->execute($params);
                $total = (int)$st->fetchColumn();

                $sql = "SELECT h.*, doc.DOCUMENT_CODE, doc.DOCUMENT_SUBJECT,
                            CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS USER_NAME
                        FROM `DOCUMENT_HISTORY` h
                        INNER JOIN `DOCUMENT` doc ON doc.DOCUMENT_ID = h.DOCUMENT_ID
                        LEFT JOIN `USERS` u ON u.USER_ID = h.USER_ID
                        $where
                        ORDER BY h.HISTORY_ID DESC
                        LIMIT $perPage OFFSET $offset";
                $st = $pdo->prepare($sql); $st->execute($params);
                $rows = $st->fetchAll();

                echo json_encode(['success'=>true,'data'=>[
                    'rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per_page'=>$perPage,
                    'pages'=>max(1,(int)ceil($total/$perPage))
                ]]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
            }
            exit;
        }

        if ($action === 'history_stats') {
            [$where, $params] = getDocumentFilter($pdo, $userId);
            $where = str_replace('d.', 'doc.', $where);
            $where = $where !== '' ? $where : '';

            $sql = "SELECT COUNT(*) FROM `DOCUMENT_HISTORY` h INNER JOIN `DOCUMENT` doc ON doc.DOCUMENT_ID = h.DOCUMENT_ID $where";
            $st = $pdo->prepare($sql); $st->execute($params);
            $total = (int)$st->fetchColumn();

            $sql = "SELECT COUNT(DISTINCT h.DOCUMENT_ID) FROM `DOCUMENT_HISTORY` h INNER JOIN `DOCUMENT` doc ON doc.DOCUMENT_ID = h.DOCUMENT_ID $where";
            $st = $pdo->prepare($sql); $st->execute($params);
            $docs = (int)$st->fetchColumn();

            $sql = "SELECT COUNT(*) FROM `DOCUMENT_HISTORY` h INNER JOIN `DOCUMENT` doc ON doc.DOCUMENT_ID = h.DOCUMENT_ID $where " . ($where ? " AND " : " WHERE ") . " h.HISTORY_TO_STATUS='RESOLVED'";
            $st = $pdo->prepare($sql); $st->execute($params);
            $toRes = (int)$st->fetchColumn();

            $sql = "SELECT COUNT(*) FROM `DOCUMENT_HISTORY` h INNER JOIN `DOCUMENT` doc ON doc.DOCUMENT_ID = h.DOCUMENT_ID $where " . ($where ? " AND " : " WHERE ") . " h.HISTORY_TO_STATUS='FORWARDED'";
            $st = $pdo->prepare($sql); $st->execute($params);
            $toFwd = (int)$st->fetchColumn();

            echo json_encode(['success'=>true,'data'=>compact('total','docs','toRes','toFwd')]);
            exit;
        }

        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']);
        exit;
    }

    [$where, $params] = getDocumentFilter($pdo, $userId);

    $sql = "SELECT d.*, t.DOCUMENT_TYPE_NAME,
                CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS OWNER,
                CONCAT(a.USER_FIRSTNAME,' ',a.USER_LASTNAME) AS ASSIGNED_NAME,
                fs.FLOW_STEP_NAME AS CURRENT_STEP_NAME,
                fs.FLOW_STEP_IS_FINAL AS CURRENT_STEP_IS_FINAL
            FROM `DOCUMENT` d
            LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
            LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
            LEFT JOIN `USERS` a ON a.USER_ID = d.DOCUMENT_ASSIGNED_TO
            LEFT JOIN `FLOW_STEP` fs ON fs.FLOW_ID = d.FLOW_ID
                                    AND fs.FLOW_STEP_ORDER = d.DOCUMENT_CURRENT_STEP
            $where
            ORDER BY d.DOCUMENT_ID DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $documents = $st->fetchAll();

    $badgeStatus = [
        'DRAFT'=>['Rascunho','badge-secondary'], 'SUBMITTED'=>['Submetido','badge-info'],
        'IN_ANALYSIS'=>['Em análise','badge-primary'], 'FORWARDED'=>['Reencaminhado','badge-warning'],
        'IN_RESOLUTION'=>['Em resolução','badge-warning'], 'RESOLVED'=>['Resolvido','badge-success'],
        'RETURNED'=>['Devolvido','badge-info'], 'CLOSED'=>['Fechado','badge-success'],
        'REJECTED'=>['Rejeitado','badge-danger'], 'CANCELLED'=>['Cancelado','badge-secondary'],
    ];

    $pageTitle  = 'Documentos';
    $activePage = 'documents.php';

    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-file-alt" style="color:var(--accent-color);"></i>
                    <?= $profileCode === 'ESTU' ? 'Meus Pedidos' : ($profileCode === 'DOCE' ? 'Meus Documentos' : 'Documentos') ?>
                </h2>
                <div class="breadcrumb-custom">
                    <a href="dashboard.php">Dashboard</a>
                    <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i>
                    <span>Documentos</span>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=documents','_blank')">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </button>
                <button class="btn-outline-action" onclick="openHistoryModal()">
                    <i class="fas fa-history"></i> Ver Histórico
                </button>
                <?php if (canCreateDocuments()): ?>
                <button class="btn-action" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> Novo Requerimento
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-card">
            <h6><i class="fas fa-filter"></i> Filtros</h6>
            <div class="row g-3">
                <div class="col-md-5"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Código, assunto, requerente..."></div>
                <div class="col-md-3"><label class="form-label">Estado</label>
                    <select class="form-select" id="filterStatus">
                        <option value="">Todos</option>
                        <?php foreach ($badgeStatus as $k=>$v): ?>
                            <option value="<?= $k ?>"><?= $v[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">Prioridade</label>
                    <select class="form-select" id="filterPriority">
                        <option value="">Todas</option>
                        <option value="LOW">Baixa</option><option value="NORMAL">Normal</option>
                        <option value="HIGH">Alta</option><option value="URGENT">Urgente</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-outline-secondary w-100" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;">
                        <i class="fas fa-redo me-1"></i> Limpar
                    </button>
                </div>
            </div>
        </div>

        <div class="main-card">
            <div class="main-card-header">
                <div class="title">
                    <i class="fas fa-list-ul"></i>
                    <span>Lista de Documentos</span>
                    <span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;">0</span>
                </div>
            </div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th style="width:120px;">Código</th><th>Assunto</th><th style="width:130px;">Tipo</th>
                                <?php if ($profileCode !== 'ESTU'): ?><th style="width:150px;">Requerente</th><?php endif; ?>
                                <th style="width:120px;">Estado</th><th style="width:140px;">Passo Atual</th>
                                <th style="width:130px;">Responsável</th><th style="width:80px;">Prior.</th>
                                <th style="width:100px;">Data</th><th style="width:150px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="docsTableBody">
                            <?php if (empty($documents)): ?>
                                <tr><td colspan="<?= $profileCode==='ESTU'?9:10 ?>"><div class="empty-state"><i class="fas fa-inbox"></i><p>Sem documentos para mostrar.</p></div></td></tr>
                            <?php else: foreach ($documents as $d):
                                $st = $d['DOCUMENT_STATUS'] ?? 'SUBMITTED';
                                [$label, $cls] = $badgeStatus[$st] ?? [$st,'badge-secondary'];
                                $pr = $d['DOCUMENT_PRIORITY'] ?? 'NORMAL';
                                $prColors = ['LOW'=>'badge-secondary','NORMAL'=>'badge-info','HIGH'=>'badge-warning','URGENT'=>'badge-danger'];
                                $prLabels = ['LOW'=>'Baixa','NORMAL'=>'Normal','HIGH'=>'Alta','URGENT'=>'Urgente'];
                                $isFinal = in_array($d['DOCUMENT_STATUS'], ['RESOLVED','CLOSED'], true);
                            ?>
                                <tr id="row-<?= (int)$d['DOCUMENT_ID'] ?>">
                                    <td class="cell-code"><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.78rem;"><?= htmlspecialchars($d['DOCUMENT_CODE']) ?></code></td>
                                    <td class="cell-subject"><strong><?= htmlspecialchars($d['DOCUMENT_SUBJECT']) ?></strong></td>
                                    <td class="cell-type" style="font-size:.82rem;color:#7f8c8d;"><?= htmlspecialchars($d['DOCUMENT_TYPE_NAME'] ?? '—') ?></td>
                                    <?php if ($profileCode !== 'ESTU'): ?><td class="cell-owner" style="font-size:.85rem;"><?= htmlspecialchars($d['OWNER'] ?? '—') ?></td><?php endif; ?>
                                    <td class="cell-status"><span class="badge-status <?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
                                    <td class="cell-step" style="font-size:.8rem;color:#7f8c8d;">
                                        <?php if ($d['CURRENT_STEP_NAME']): ?>
                                            <?= htmlspecialchars($d['CURRENT_STEP_NAME']) ?>
                                            <?php if ($d['CURRENT_STEP_IS_FINAL']): ?><i class="fas fa-flag-checkered" style="color:#27ae60;margin-left:4px;" title="Passo final"></i><?php endif; ?>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td class="cell-assigned" style="font-size:.8rem;color:#7f8c8d;"><?= htmlspecialchars($d['ASSIGNED_NAME'] ?? '—') ?></td>
                                    <td class="cell-priority"><span class="badge-status <?= $prColors[$pr] ?>"><?= $prLabels[$pr] ?></span></td>
                                    <td class="cell-date" style="font-size:.82rem;color:#7f8c8d;"><?= htmlspecialchars(date('d/m/Y', strtotime($d['DOCUMENT_CREATEDAT']))) ?></td>
                                    <td class="cell-actions"
                                        data-id="<?= (int)$d['DOCUMENT_ID'] ?>"
                                        data-code="<?= htmlspecialchars($d['DOCUMENT_CODE']) ?>"
                                        data-subject="<?= htmlspecialchars($d['DOCUMENT_SUBJECT']) ?>"
                                        data-body="<?= htmlspecialchars($d['DOCUMENT_BODY'] ?? '') ?>"
                                        data-type-id="<?= (int)$d['DOCUMENT_TYPE_ID'] ?>"
                                        data-priority="<?= htmlspecialchars($d['DOCUMENT_PRIORITY']) ?>"
                                        data-status="<?= htmlspecialchars($d['DOCUMENT_STATUS']) ?>"
                                        data-owner="<?= htmlspecialchars($d['OWNER'] ?? '') ?>"
                                        data-created="<?= htmlspecialchars($d['DOCUMENT_CREATEDAT']) ?>">
                                        <div class="action-links">
                                            <a href="document_view.php?id=<?= (int)$d['DOCUMENT_ID'] ?>" class="btn-icon view" title="Ver"><i class="fas fa-eye"></i></a>
                                            <?php if ($isFinal): ?>
                                            <a href="document_pdf.php?id=<?= (int)$d['DOCUMENT_ID'] ?>" target="_blank" class="btn-icon" title="Imprimir PDF" style="color:#e74c3c;"><i class="fas fa-file-pdf"></i></a>
                                            <?php endif; ?>
                                            <?php if (in_array($profileCode, ['SECR','SUPE'], true)): ?>
                                            <button class="btn-icon edit" onclick="openEditModal(<?= (int)$d['DOCUMENT_ID'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                            <button class="btn-icon delete" onclick="openDeleteModal(<?= (int)$d['DOCUMENT_ID'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
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

        <div class="modal fade" id="formModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="formModalTitle"><i class="fas fa-plus-circle"></i> Novo Requerimento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="docForm" onsubmit="handleFormSubmit(event)" novalidate>
                        <div class="modal-body">
                            <input type="hidden" id="formDocId">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Tipo de Documento <span class="required">*</span></label>
                                    <select class="form-select form-control-custom" id="formType"></select>
                                    <div class="field-error" id="errorType"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Prioridade</label>
                                    <select class="form-select form-control-custom" id="formPriority">
                                        <option value="NORMAL">Normal</option><option value="HIGH">Alta</option>
                                        <option value="URGENT">Urgente</option><option value="LOW">Baixa</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Assunto <span class="required">*</span></label>
                                <input type="text" class="form-control form-control-custom" id="formSubject" maxlength="200">
                                <div class="field-error" id="errorSubject"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Corpo / Descrição</label>
                                <textarea class="form-control form-control-custom" id="formBody" rows="5"></textarea>
                            </div>
                            <div class="mb-3" id="statusFieldContainer" style="display:none;">
                                <label class="form-label-custom">Estado</label>
                                <select class="form-select form-control-custom" id="formStatus">
                                    <?php foreach ($badgeStatus as $k=>$v): ?>
                                        <option value="<?= $k ?>"><?= $v[0] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn-action" id="formSubmitBtn"><i class="fas fa-save"></i> <span id="formSubmitText">Submeter</span></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-exclamation-triangle modal-danger-icon"></i>
                        <h5 style="color:var(--primary-color);">Eliminar Documento</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Eliminar <strong id="deleteDocCode" style="color:#e74c3c;"></strong>?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fas fa-trash"></i> Eliminar</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="historyModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-history"></i> Histórico de Documentos</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:20px;">

                        <div class="row mb-3">
                            <div class="col-md-3 col-6 mb-2"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-history"></i></div><div class="stat-info"><div class="value" id="hstatTotal">—</div><div class="label">Total</div></div></div></div>
                            <div class="col-md-3 col-6 mb-2"><div class="stat-card"><div class="icon" style="background:rgba(155,89,182,.12);color:#9b59b6;"><i class="fas fa-file"></i></div><div class="stat-info"><div class="value" id="hstatDocs">—</div><div class="label">Documentos</div></div></div></div>
                            <div class="col-md-3 col-6 mb-2"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="value" id="hstatRes">—</div><div class="label">→ Resolvido</div></div></div></div>
                            <div class="col-md-3 col-6 mb-2"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-share"></i></div><div class="stat-info"><div class="value" id="hstatFwd">—</div><div class="label">→ Encaminhado</div></div></div></div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-5"><input type="text" class="form-control form-control-custom" id="hFilterSearch" placeholder="Pesquisar código, assunto ou estado..."></div>
                            <div class="col-md-3">
                                <select class="form-select form-control-custom" id="hFilterStatus">
                                    <option value="">Todos os estados</option>
                                    <?php foreach ($badgeStatus as $k=>$v): ?>
                                        <option value="<?= $k ?>"><?= $v[0] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select form-control-custom" id="hFilterPerPage">
                                    <option value="25">25 / pág.</option>
                                    <option value="50">50 / pág.</option>
                                    <option value="100">100 / pág.</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-secondary w-100" onclick="resetHistoryFilters()" style="border-radius:8px;font-size:.85rem;padding:8px 12px;"><i class="fas fa-redo"></i> Limpar</button>
                            </div>
                        </div>

                        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                            <table class="table table-custom" style="margin:0;">
                                <thead><tr>
                                    <th style="width:60px;">ID</th><th style="width:120px;">Documento</th><th>Assunto</th>
                                    <th style="width:120px;">De</th><th style="width:120px;">Para</th>
                                    <th style="width:140px;">Utilizador</th><th style="width:130px;">Data</th>
                                </tr></thead>
                                <tbody id="historyTableBody">
                                    <tr><td colspan="7"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>A carregar...</p></div></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;flex-wrap:wrap;gap:10px;margin-top:10px;">
                            <div style="font-size:.85rem;color:#7f8c8d;" id="hPaginationInfo">—</div>
                            <div style="display:flex;gap:6px;" id="hPaginationControls"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const API_URL = 'documents.php';
            let formModal, deleteModal, historyModal;
            let pendingDeleteId = null;
            let OPTIONS = { types: [] };
            let historyPage = 1;

            document.addEventListener('DOMContentLoaded', () => {
                formModal    = new bootstrap.Modal(document.getElementById('formModal'));
                deleteModal  = new bootstrap.Modal(document.getElementById('deleteModal'));
                historyModal = new bootstrap.Modal(document.getElementById('historyModal'));

                loadOptions();
                let t;
                document.getElementById('filterSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilters, 300); });
                document.getElementById('filterStatus').addEventListener('change', applyFilters);
                document.getElementById('filterPriority').addEventListener('change', applyFilters);
                applyFilters();

                let th;
                document.getElementById('hFilterSearch').addEventListener('input', () => { clearTimeout(th); th = setTimeout(() => loadHistory(1), 350); });
                document.getElementById('hFilterStatus').addEventListener('change', () => loadHistory(1));
                document.getElementById('hFilterPerPage').addEventListener('change', () => loadHistory(1));

                document.getElementById('historyModal').addEventListener('shown.bs.modal', () => {
                    loadHistoryStats();
                    loadHistory(1);
                });
            });

            function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

            function loadOptions(){
                const fd = new FormData(); fd.append('action','options');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (!j.success) return;
                    OPTIONS.types = j.data.types;
                    const sel = document.getElementById('formType');
                    sel.innerHTML = '<option value="">— Selecione —</option>';
                    j.data.types.forEach(t => {
                        const o = document.createElement('option');
                        o.value = t.DOCUMENT_TYPE_ID;
                        o.textContent = t.DOCUMENT_TYPE_NAME;
                        sel.appendChild(o);
                    });
                }).catch(console.error);
            }

            function applyFilters(){
                const search = document.getElementById('filterSearch').value.toLowerCase().trim();
                const status = document.getElementById('filterStatus').value;
                const priority = document.getElementById('filterPriority').value;
                const rows = Array.from(document.querySelectorAll('#docsTableBody tr[id^="row-"]'));
                if (!rows.length) return;
                let visible = 0;
                rows.forEach(row => {
                    const d = row.querySelector('.cell-actions').dataset;
                    const txt = (d.code + ' ' + d.subject + ' ' + (d.owner||'')).toLowerCase();
                    let show = true;
                    if (search && !txt.includes(search)) show = false;
                    if (status && d.status !== status) show = false;
                    if (priority && d.priority !== priority) show = false;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                document.getElementById('listCount').textContent = visible;
            }

            function resetFilters(){
                document.getElementById('filterSearch').value = '';
                document.getElementById('filterStatus').value = '';
                document.getElementById('filterPriority').value = '';
                applyFilters();
            }

            function showFieldError(field, msg){
                const map = { type_id:'errorType', subject:'errorSubject' };
                const id = map[field]; if (!id) return;
                const el = document.getElementById(id); if (!el) return;
                el.querySelector('span').textContent = msg; el.classList.add('show');
            }
            function clearFieldError(field){
                const map = { type_id:'errorType', subject:'errorSubject' };
                const id = map[field]; if (id && document.getElementById(id)) document.getElementById(id).classList.remove('show');
            }
            function clearAllErrors(){ ['type_id','subject'].forEach(clearFieldError); }

            function openCreateModal(){
                clearAllErrors();
                document.getElementById('formDocId').value = '';
                document.getElementById('formType').value = '';
                document.getElementById('formSubject').value = '';
                document.getElementById('formBody').value = '';
                document.getElementById('formPriority').value = 'NORMAL';
                document.getElementById('formModalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Novo Requerimento';
                document.getElementById('formSubmitText').textContent = 'Submeter';
                document.getElementById('statusFieldContainer').style.display = 'none';
                formModal.show();
            }

            function openEditModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                clearAllErrors();
                document.getElementById('formDocId').value = d.id;
                document.getElementById('formType').value = d.typeId;
                document.getElementById('formSubject').value = d.subject;
                document.getElementById('formBody').value = d.body;
                document.getElementById('formPriority').value = d.priority;
                document.getElementById('formStatus').value = d.status;
                document.getElementById('formModalTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Documento';
                document.getElementById('formSubmitText').textContent = 'Atualizar';
                document.getElementById('statusFieldContainer').style.display = 'block';
                formModal.show();
            }

            function handleFormSubmit(e){
                e.preventDefault(); clearAllErrors();
                const id = document.getElementById('formDocId').value;
                const typeId = parseInt(document.getElementById('formType').value) || 0;
                const subject = document.getElementById('formSubject').value.trim();
                const body = document.getElementById('formBody').value.trim();
                const priority = document.getElementById('formPriority').value;
                const status = document.getElementById('formStatus').value;
                let hasError = false;
                if (typeId <= 0) { showFieldError('type_id','Selecione o tipo.'); hasError = true; }
                if (!subject) { showFieldError('subject','O assunto é obrigatório.'); hasError = true; }
                if (hasError) return;

                const btn = document.getElementById('formSubmitBtn');
                const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';

                const fd = new FormData();
                fd.append('action','save');
                if (id) fd.append('id', id);
                fd.append('type_id', typeId);
                fd.append('subject', subject);
                fd.append('body', body);
                fd.append('priority', priority);
                fd.append('status', status);

                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success) {
                        formModal.hide();
                        showToast(j.message, 'success');
                        setTimeout(() => {
                            if (j.id) location.href = 'document_view.php?id=' + j.id;
                            else location.reload();
                        }, 900);
                    }
                    else if (j.errors) Object.entries(j.errors).forEach(([f,m]) => showFieldError(f,m));
                    else showToast(j.message||'Erro.', 'error');
                }).catch(console.error).finally(() => { btn.disabled = false; btn.innerHTML = orig; });
            }

            function openDeleteModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                pendingDeleteId = id;
                document.getElementById('deleteDocCode').textContent = d.code + ' — ' + d.subject;
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
                    if (j.success) { showToast(j.message,'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled = false; btn.innerHTML = orig; pendingDeleteId = null; });
            }

            function openHistoryModal(){ historyModal.show(); }

            function loadHistoryStats(){
                const fd = new FormData(); fd.append('action','history_stats');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){
                        document.getElementById('hstatTotal').textContent = j.data.total;
                        document.getElementById('hstatDocs').textContent  = j.data.docs;
                        document.getElementById('hstatRes').textContent   = j.data.toRes;
                        document.getElementById('hstatFwd').textContent   = j.data.toFwd;
                    }
                }).catch(console.error);
            }

            function loadHistory(page){
                historyPage = page;
                const perPage = parseInt(document.getElementById('hFilterPerPage').value) || 25;
                const tbody = document.getElementById('historyTableBody');
                tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>A carregar...</p></div></td></tr>';

                const fd = new FormData();
                fd.append('action','history_list');
                fd.append('page', page);
                fd.append('per_page', perPage);
                fd.append('search', document.getElementById('hFilterSearch').value.trim());
                fd.append('status', document.getElementById('hFilterStatus').value);

                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (!j.success) { tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><p>${escapeHtml(j.message)}</p></div></td></tr>`; return; }
                    renderHistory(j.data);
                }).catch(err => { console.error(err); tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p>Erro de comunicação.</p></div></td></tr>'; });
            }

            function renderHistory(data){
                const tbody = document.getElementById('historyTableBody');
                const rows = data.rows;
                if (!rows.length){
                    tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhum registo</h6></div></td></tr>';
                    document.getElementById('hPaginationInfo').textContent = 'Sem registos';
                    document.getElementById('hPaginationControls').innerHTML = '';
                    return;
                }
                const colorMap = {
                    'SUBMITTED':'warning','FORWARDED':'info','RESOLVED':'success','REJECTED':'danger',
                    'RETURNED':'secondary','CLOSED':'dark','CANCELLED':'secondary',
                    'IN_ANALYSIS':'primary','IN_RESOLUTION':'warning','DRAFT':'secondary'
                };
                let html = '';
                rows.forEach(h => {
                    const toSt = h.HISTORY_TO_STATUS || '—';
                    const fromSt = h.HISTORY_FROM_STATUS || '—';
                    const toColor = colorMap[toSt] || 'secondary';
                    html += `<tr>
                        <td><strong>#${h.HISTORY_ID}</strong></td>
                        <td><a href="document_view.php?id=${h.DOCUMENT_ID}" style="text-decoration:none;"><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.75rem;">${escapeHtml(h.DOCUMENT_CODE||'—')}</code></a></td>
                        <td style="font-size:.85rem;">${escapeHtml(h.DOCUMENT_SUBJECT||'—')}</td>
                        <td><span class="badge bg-secondary">${escapeHtml(fromSt)}</span></td>
                        <td><span class="badge bg-${toColor}">${escapeHtml(toSt)}</span></td>
                        <td style="font-size:.85rem;">${escapeHtml(h.USER_NAME||'sistema')}</td>
                        <td style="font-size:.82rem;color:#7f8c8d;">${escapeHtml(h.HISTORY_CREATEDAT)}</td>
                    </tr>`;
                });
                tbody.innerHTML = html;

                const from = data.total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
                const to = Math.min(data.page * data.per_page, data.total);
                document.getElementById('hPaginationInfo').innerHTML = `A mostrar <strong>${from}</strong>–<strong>${to}</strong> de <strong>${data.total}</strong> registos`;

                const style = "min-width:36px;height:36px;border:1.5px solid #e0e0e0;background:white;border-radius:8px;color:var(--primary-color);font-size:.85rem;font-weight:500;cursor:pointer;padding:0 10px;";
                let htmlPag = '';
                const prev = data.page > 1 ? data.page - 1 : 1;
                const next = data.page < data.pages ? data.page + 1 : data.pages;
                htmlPag += `<button onclick="loadHistory(${prev})" ${data.page <= 1 ? 'disabled' : ''} style="${style}"><i class="fas fa-chevron-left"></i></button>`;
                let start = Math.max(1, data.page - 3);
                let end = Math.min(data.pages, start + 6);
                if (end - start < 6) start = Math.max(1, end - 6);
                for (let i = start; i <= end; i++) {
                    htmlPag += `<button onclick="loadHistory(${i})" style="${style}${i===data.page?'background:var(--accent-color);color:white;border-color:var(--accent-color);':''}">${i}</button>`;
                }
                htmlPag += `<button onclick="loadHistory(${next})" ${data.page >= data.pages ? 'disabled' : ''} style="${style}"><i class="fas fa-chevron-right"></i></button>`;
                document.getElementById('hPaginationControls').innerHTML = htmlPag;
            }

            function resetHistoryFilters(){
                document.getElementById('hFilterSearch').value = '';
                document.getElementById('hFilterStatus').value = '';
                document.getElementById('hFilterPerPage').value = '25';
                loadHistory(1);
            }
        </script>

<?php
    include 'includes/modals.php';
    include 'includes/footer.php';
?>