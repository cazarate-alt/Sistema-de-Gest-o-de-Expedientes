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
    requirePermissionOrRedirect($pdo, 'DOCUMENTS', 'VIEW');

    $userId      = (int) $_SESSION['user_id'];
    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $myProfileId = (int) $_SESSION['profile_id'];

    $__allowed = allowedModules($pdo);
    $_SESSION['__allowed_modules'] = $__allowed;

    $docId = (int)($_GET['id'] ?? 0);
    if ($docId <= 0) { header('Location: documents.php'); exit; }

    $UPLOAD_DIR = __DIR__ . '/uploads/attachments/';
    $UPLOAD_URL = 'uploads/attachments/';
    $MAX_SIZE   = 10 * 1024 * 1024;
    $ALLOWED = ['pdf','doc','docx','odt','rtf','txt','xls','xlsx','ods','csv','ppt','pptx','odp','jpg','jpeg','png','gif','webp','bmp','svg','zip','rar','7z','tar','gz'];
    if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);

    function formatBytes($b) {
        if ($b <= 0) return '0 B';
        $u = ['B','KB','MB','GB']; $i = floor(log($b)/log(1024));
        return round($b/pow(1024,$i), 2) . ' ' . $u[$i];
    }

    $st = $pdo->prepare(
        "SELECT d.*, t.DOCUMENT_TYPE_NAME,
                CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS OWNER,
                u.USER_EMAIL AS OWNER_EMAIL,
                CONCAT(a.USER_FIRSTNAME,' ',a.USER_LASTNAME) AS ASSIGNED_NAME,
                f.FLOW_NAME, f.FLOW_CODE,
                fs.FLOW_STEP_NAME AS CURRENT_STEP_NAME,
                fs.FLOW_STEP_IS_FINAL AS CURRENT_STEP_IS_FINAL
        FROM `DOCUMENT` d
        LEFT JOIN `DOCUMENT_TYPE` t ON t.DOCUMENT_TYPE_ID = d.DOCUMENT_TYPE_ID
        LEFT JOIN `USERS` u ON u.USER_ID = d.USER_ID
        LEFT JOIN `USERS` a ON a.USER_ID = d.DOCUMENT_ASSIGNED_TO
        LEFT JOIN `FLOW` f ON f.FLOW_ID = d.FLOW_ID
        LEFT JOIN `FLOW_STEP` fs ON fs.FLOW_ID = d.FLOW_ID
                                AND fs.FLOW_STEP_ORDER = d.DOCUMENT_CURRENT_STEP
        WHERE d.DOCUMENT_ID = :id"
    );
    $st->execute([':id'=>$docId]);
    $doc = $st->fetch();

    if (!$doc) { header('Location: documents.php'); exit; }

    $canAccess = false;
    if ($profileCode === 'SUPE') $canAccess = true;
    elseif ($profileCode === 'ESTU' && (int)$doc['USER_ID'] === $userId) $canAccess = true;
    elseif ($profileCode === 'DOCE' && (int)$doc['DOCUMENT_ASSIGNED_TO'] === $userId) $canAccess = true;
    elseif ($profileCode === 'SECR') $canAccess = true;
    elseif ($profileCode === 'DIRE') $canAccess = true;

    if (!$canAccess) { header('Location: documents.php'); exit; }

    $canUpload = in_array($profileCode, ['SECR','SUPE','ESTU'], true) && !in_array($doc['DOCUMENT_STATUS'], ['CLOSED','CANCELLED'], true);
    $canDeleteAtt = in_array($profileCode, ['SECR','SUPE'], true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'upload_attachment') {
            if (!$canUpload) { echo json_encode(['success'=>false,'message'=>'Sem permissão.']); exit; }
            if (empty($_FILES['ficheiro']['name'])) { echo json_encode(['success'=>false,'message'=>'Selecione um ficheiro.']); exit; }

            $desc = trim($_POST['descricao'] ?? '');
            $ext = strtolower(pathinfo($_FILES['ficheiro']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $ALLOWED)) { echo json_encode(['success'=>false,'message'=>'Extensão .'.$ext.' não permitida.']); exit; }
            if ($_FILES['ficheiro']['size'] > $MAX_SIZE) { echo json_encode(['success'=>false,'message'=>'Ficheiro excede 10MB.']); exit; }

            try {
                $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['ficheiro']['name']);
                $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
                $dest = $UPLOAD_DIR . $storedName;
                if (!move_uploaded_file($_FILES['ficheiro']['tmp_name'], $dest)) {
                    echo json_encode(['success'=>false,'message'=>'Erro ao guardar ficheiro.']); exit;
                }
                $storedPath = $UPLOAD_URL . $storedName;

                $pdo->prepare("INSERT INTO `DOCUMENT_ATTACHMENT` (DOCUMENT_ID, ATTACHMENT_DESCRIPTION, ATTACHMENT_FILENAME, ATTACHMENT_ORIGINALNAME, ATTACHMENT_PATH, ATTACHMENT_SIZE, ATTACHMENT_EXTENSION) VALUES (:d,:desc,:fn,:orig,:fp,:sz,:ex)")
                    ->execute([':d'=>$docId,':desc'=>$desc,':fn'=>$storedName,':orig'=>$_FILES['ficheiro']['name'],':fp'=>$storedPath,':sz'=>$_FILES['ficheiro']['size'],':ex'=>$ext]);
                echo json_encode(['success'=>true,'message'=>'Anexo carregado!']);
            } catch (\Throwable $e) { echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]); }
            exit;
        }

        if ($action === 'delete_attachment') {
            if (!$canDeleteAtt) { echo json_encode(['success'=>false,'message'=>'Sem permissão.']); exit; }
            $attId = intval($_POST['att_id'] ?? 0);
            if ($attId <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            $st = $pdo->prepare("SELECT ATTACHMENT_PATH FROM `DOCUMENT_ATTACHMENT` WHERE ATTACHMENT_ID=:id AND DOCUMENT_ID=:d");
            $st->execute([':id'=>$attId, ':d'=>$docId]); $path = $st->fetchColumn();
            if ($path && file_exists(__DIR__.'/'.$path)) @unlink(__DIR__.'/'.$path);
            $pdo->prepare("DELETE FROM `DOCUMENT_ATTACHMENT` WHERE ATTACHMENT_ID=:id AND DOCUMENT_ID=:d")->execute([':id'=>$attId, ':d'=>$docId]);
            echo json_encode(['success'=>true,'message'=>'Anexo eliminado!']);
            exit;
        }

        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
    }

    $canAct = false;
    if ($profileCode === 'SUPE') {
        $canAct = true;
    } else {
        if (!empty($doc['FLOW_ID']) && !empty($doc['DOCUMENT_CURRENT_STEP'])) {
            $stStep = $pdo->prepare("
                SELECT fs.PROFILE_ID
                FROM `FLOW_STEP` fs
                WHERE fs.FLOW_ID = :fid AND fs.FLOW_STEP_ORDER = :ord AND fs.FLOW_STEP_STATUS = 1
                LIMIT 1
            ");
            $stStep->execute([':fid'=>$doc['FLOW_ID'], ':ord'=>$doc['DOCUMENT_CURRENT_STEP']]);
            $stepProfile = (int)$stStep->fetchColumn();
            if ($stepProfile === $myProfileId) $canAct = true;
        }
    }

    $statusClosed = in_array($doc['DOCUMENT_STATUS'], ['CLOSED','CANCELLED'], true);
    $isFinal = in_array($doc['DOCUMENT_STATUS'], ['RESOLVED','CLOSED'], true);

    $history = $pdo->prepare("SELECT h.*, CONCAT(u.USER_FIRSTNAME,' ',u.USER_LASTNAME) AS USER_NAME
                            FROM `DOCUMENT_HISTORY` h
                            LEFT JOIN `USERS` u ON u.USER_ID = h.USER_ID
                            WHERE h.DOCUMENT_ID = :id ORDER BY h.HISTORY_ID DESC");
    $history->execute([':id'=>$docId]);
    $history = $history->fetchAll();

    $processing = $pdo->prepare("SELECT p.*,
                                        CONCAT(fu.USER_FIRSTNAME,' ',fu.USER_LASTNAME) AS FROM_USER,
                                        CONCAT(tu.USER_FIRSTNAME,' ',tu.USER_LASTNAME) AS TO_USER
                                FROM `PROCESSING` p
                                LEFT JOIN `USERS` fu ON fu.USER_ID = p.FROM_USER_ID
                                LEFT JOIN `USERS` tu ON tu.USER_ID = p.TO_USER_ID
                                WHERE p.DOCUMENT_ID = :id ORDER BY p.PROCESSING_ID DESC");
    $processing->execute([':id'=>$docId]);
    $processing = $processing->fetchAll();

    $attachments = $pdo->prepare("SELECT * FROM `DOCUMENT_ATTACHMENT` WHERE DOCUMENT_ID = :id ORDER BY ATTACHMENT_ID DESC");
    $attachments->execute([':id'=>$docId]);
    $attachments = $attachments->fetchAll();

    $badgeStatus = [
        'DRAFT'=>['Rascunho','badge-secondary'], 'SUBMITTED'=>['Submetido','badge-info'],
        'IN_ANALYSIS'=>['Em análise','badge-primary'], 'FORWARDED'=>['Reencaminhado','badge-warning'],
        'IN_RESOLUTION'=>['Em resolução','badge-warning'], 'RESOLVED'=>['Resolvido','badge-success'],
        'RETURNED'=>['Devolvido','badge-info'], 'CLOSED'=>['Fechado','badge-success'],
        'REJECTED'=>['Rejeitado','badge-danger'], 'CANCELLED'=>['Cancelado','badge-secondary'],
    ];
    $st = $doc['DOCUMENT_STATUS'] ?? 'SUBMITTED';
    [$stLabel, $stCls] = $badgeStatus[$st] ?? [$st,'badge-secondary'];

    $priorityBadge = [
        'LOW'=>['Baixa','badge-secondary'],'NORMAL'=>['Normal','badge-info'],
        'HIGH'=>['Alta','badge-warning'],'URGENT'=>['Urgente','badge-danger'],
    ];
    $pr = $doc['DOCUMENT_PRIORITY'] ?? 'NORMAL';
    [$prLabel, $prCls] = $priorityBadge[$pr] ?? [$pr,'badge-secondary'];

    $pageTitle  = 'Detalhes do Documento';
    $activePage = 'documents.php';

    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="page-title">
            <h2><i class="fas fa-file-alt" style="color:var(--accent-color);"></i> Detalhes do Documento</h2>
            <div class="breadcrumb-custom">
                <a href="dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i>
                <a href="documents.php">Documentos</a>
                <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i>
                <span><?= htmlspecialchars($doc['DOCUMENT_CODE']) ?></span>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">

                <div class="main-card mb-4">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-info-circle"></i><span>Informação</span></div>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span class="badge-status <?= $stCls ?>"><?= htmlspecialchars($stLabel) ?></span>
                            <span class="badge-status <?= $prCls ?>"><?= htmlspecialchars($prLabel) ?></span>
                            <?php if ($isFinal): ?>
                                <a href="document_pdf.php?id=<?= (int)$docId ?>" target="_blank" class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);text-decoration:none;">
                                    <i class="fas fa-file-pdf"></i> Imprimir PDF
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="main-card-body" style="padding:22px;">
                        <div class="info-row"><div class="label"><i class="fas fa-barcode"></i> Código:</div><div class="value"><code><?= htmlspecialchars($doc['DOCUMENT_CODE']) ?></code></div></div>
                        <div class="info-row"><div class="label"><i class="fas fa-heading"></i> Assunto:</div><div class="value"><?= htmlspecialchars($doc['DOCUMENT_SUBJECT']) ?></div></div>
                        <div class="info-row"><div class="label"><i class="fas fa-file-signature"></i> Tipo:</div><div class="value"><?= htmlspecialchars($doc['DOCUMENT_TYPE_NAME'] ?? '—') ?></div></div>
                        <div class="info-row"><div class="label"><i class="fas fa-user"></i> Requerente:</div><div class="value"><?= htmlspecialchars($doc['OWNER'] ?? '—') ?></div></div>
                        <?php if ($doc['ASSIGNED_NAME']): ?>
                        <div class="info-row"><div class="label"><i class="fas fa-user-check"></i> Atribuído a:</div><div class="value"><?= htmlspecialchars($doc['ASSIGNED_NAME']) ?></div></div>
                        <?php endif; ?>
                        <div class="info-row"><div class="label"><i class="fas fa-route"></i> Fluxo:</div><div class="value"><?= htmlspecialchars($doc['FLOW_NAME'] ?? '—') ?></div></div>
                        <div class="info-row"><div class="label"><i class="fas fa-shoe-prints"></i> Passo Atual:</div>
                            <div class="value">
                                <?php if ($doc['CURRENT_STEP_NAME']): ?>
                                    <strong><?= htmlspecialchars($doc['CURRENT_STEP_NAME']) ?></strong>
                                    <?php if ($doc['CURRENT_STEP_IS_FINAL']): ?>
                                        <span class="badge-status badge-success" style="margin-left:6px;"><i class="fas fa-flag-checkered"></i> Final</span>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </div>
                        </div>
                        <div class="info-row"><div class="label"><i class="fas fa-calendar-plus"></i> Criado em:</div><div class="value"><?= htmlspecialchars($doc['DOCUMENT_CREATEDAT']) ?></div></div>
                        <?php if (!empty($doc['DOCUMENT_CLOSEDAT'])): ?>
                        <div class="info-row"><div class="label"><i class="fas fa-calendar-check"></i> Fechado em:</div><div class="value"><?= htmlspecialchars($doc['DOCUMENT_CLOSEDAT']) ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($doc['DOCUMENT_BODY'])): ?>
                        <div class="info-row"><div class="label"><i class="fas fa-align-left"></i> Corpo:</div><div class="value" style="white-space:pre-wrap;"><?= nl2br(htmlspecialchars($doc['DOCUMENT_BODY'])) ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canAct && !$statusClosed): ?>
                <div class="main-card mb-4" style="border-left:4px solid var(--accent-color);">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-exchange-alt"></i><span>Ações de Tramitação</span></div>
                        <span class="badge-status badge-info">Passo atual: <?= htmlspecialchars($doc['CURRENT_STEP_NAME'] ?? '—') ?></span>
                    </div>
                    <div class="main-card-body" style="padding:20px;">
                        <p style="font-size:.88rem;color:#7f8c8d;margin-bottom:16px;">
                            <i class="fas fa-info-circle"></i> Escolha a ação a executar. O documento será encaminhado conforme o fluxo definido.
                        </p>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($profileCode === 'SECR' || $profileCode === 'SUPE'): ?>
                                <?php if (in_array($doc['DOCUMENT_STATUS'], ['SUBMITTED','IN_ANALYSIS'], true)): ?>
                                    <button class="btn-action" onclick="processDoc('resolve')"><i class="fas fa-check-circle"></i> Resolver e Entregar</button>
                                <?php endif; ?>
                                <button class="btn-action" onclick="processDoc('forward')" style="background:linear-gradient(135deg,#3498db,#2980b9);"><i class="fas fa-share"></i> Encaminhar</button>
                                <?php if (in_array($doc['DOCUMENT_STATUS'], ['RETURNED','IN_RESOLUTION'], true)): ?>
                                    <button class="btn-action" onclick="processDoc('deliver')" style="background:linear-gradient(135deg,#27ae60,#2ecc71);"><i class="fas fa-hand-holding-heart"></i> Entregar ao Estudante</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($profileCode === 'DOCE' || $profileCode === 'SUPE'): ?>
                                <button class="btn-action" onclick="processDoc('return')" style="background:linear-gradient(135deg,#e67e22,#f39c12);"><i class="fas fa-undo"></i> Devolver à Secretaria</button>
                            <?php endif; ?>
                            <?php if ($profileCode === 'DIRE' || $profileCode === 'SUPE'): ?>
                                <button class="btn-action" onclick="processDoc('forward')" style="background:linear-gradient(135deg,#27ae60,#2ecc71);"><i class="fas fa-check"></i> Aprovar</button>
                                <button class="btn-action" onclick="processDoc('reject')" style="background:linear-gradient(135deg,#c0392b,#e74c3c);"><i class="fas fa-times"></i> Rejeitar</button>
                                <button class="btn-action" onclick="processDoc('return')" style="background:linear-gradient(135deg,#e67e22,#f39c12);"><i class="fas fa-undo"></i> Devolver à Secretaria</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="main-card mb-4">
                    <div class="main-card-header">
                        <div class="title"><i class="fas fa-paperclip"></i><span>Anexos (<?= count($attachments) ?>)</span></div>
                        <?php if ($canUpload): ?>
                        <button class="btn-action" onclick="openUploadModal()"><i class="fas fa-upload"></i> Carregar Anexo</button>
                        <?php endif; ?>
                    </div>
                    <div class="main-card-body">
                        <?php if (empty($attachments)): ?>
                            <div class="empty-state"><i class="fas fa-paperclip"></i><p>Sem anexos.</p></div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-custom">
                                    <thead><tr><th>Ficheiro</th><th>Descrição</th><th style="width:100px;">Tipo</th><th style="width:100px;">Tam.</th><th style="width:150px;">Data</th><th style="width:100px;">Ações</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($attachments as $a): ?>
                                            <tr id="att-<?= (int)$a['ATTACHMENT_ID'] ?>">
                                                <td><?= htmlspecialchars($a['ATTACHMENT_ORIGINALNAME'] ?? $a['ATTACHMENT_FILENAME']) ?></td>
                                                <td style="font-size:.85rem;color:#7f8c8d;"><?= htmlspecialchars($a['ATTACHMENT_DESCRIPTION'] ?: '—') ?></td>
                                                <td><span class="badge bg-secondary"><?= strtoupper($a['ATTACHMENT_EXTENSION'] ?: '?') ?></span></td>
                                                <td><?= formatBytes((int)$a['ATTACHMENT_SIZE']) ?></td>
                                                <td style="font-size:.82rem;color:#7f8c8d;"><?= htmlspecialchars($a['ATTACHMENT_CREATEDAT']) ?></td>
                                                <td>
                                                    <div class="action-links">
                                                        <a class="btn-icon view" href="<?= htmlspecialchars($a['ATTACHMENT_PATH']) ?>" target="_blank" title="Download"><i class="fas fa-download"></i></a>
                                                        <?php if ($canDeleteAtt): ?>
                                                        <button class="btn-icon delete" onclick="confirmDeleteAtt(<?= (int)$a['ATTACHMENT_ID'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <div class="col-lg-4">

                <div class="main-card mb-4">
                    <div class="main-card-header"><div class="title"><i class="fas fa-history"></i><span>Histórico</span></div></div>
                    <div class="main-card-body" style="padding:18px;max-height:400px;overflow-y:auto;">
                        <?php if (empty($history)): ?>
                            <div class="empty-state"><i class="fas fa-inbox"></i><p>Sem registos.</p></div>
                        <?php else: foreach ($history as $h): ?>
                            <div style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
                                <div style="font-size:.78rem;color:#7f8c8d;"><?= htmlspecialchars($h['HISTORY_CREATEDAT']) ?></div>
                                <div style="font-size:.85rem;color:var(--primary-color);">
                                    <strong><?= htmlspecialchars($h['HISTORY_FROM_STATUS'] ?: '—') ?></strong>
                                    <i class="fas fa-arrow-right mx-1" style="font-size:.7rem;"></i>
                                    <strong><?= htmlspecialchars($h['HISTORY_TO_STATUS']) ?></strong>
                                </div>
                                <div style="font-size:.8rem;color:#95a5a6;"><?= htmlspecialchars($h['USER_NAME'] ?? 'sistema') ?></div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="main-card mb-4">
                    <div class="main-card-header"><div class="title"><i class="fas fa-exchange-alt"></i><span>Tramitações</span></div></div>
                    <div class="main-card-body" style="padding:18px;max-height:400px;overflow-y:auto;">
                        <?php if (empty($processing)): ?>
                            <div class="empty-state"><i class="fas fa-inbox"></i><p>Sem tramitações.</p></div>
                        <?php else: foreach ($processing as $p):
                            $actMap = ['SUBMIT'=>['Submissão','badge-warning'],'FORWARD'=>['Encaminhamento','badge-info'],'RESOLVE'=>['Resolução','badge-success'],'RETURN'=>['Devolução','badge-secondary'],'REJECT'=>['Rejeição','badge-danger'],'CLOSE'=>['Fecho','badge-success']];
                            [$actLabel, $actCls] = $actMap[$p['PROCESSING_ACTION']] ?? [$p['PROCESSING_ACTION'],'badge-secondary'];
                        ?>
                            <div style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <span class="badge-status <?= $actCls ?>" style="font-size:.7rem;"><?= htmlspecialchars($actLabel) ?></span>
                                    <span style="font-size:.72rem;color:#7f8c8d;"><?= htmlspecialchars($p['PROCESSING_CREATEDAT']) ?></span>
                                </div>
                                <div style="font-size:.8rem;color:var(--primary-color);">
                                    <strong><?= htmlspecialchars($p['FROM_USER'] ?? '—') ?></strong>
                                    <i class="fas fa-arrow-right mx-1" style="font-size:.7rem;"></i>
                                    <strong><?= htmlspecialchars($p['TO_USER'] ?? '—') ?></strong>
                                </div>
                                <?php if (!empty($p['PROCESSING_NOTE'])): ?>
                                    <div style="font-size:.78rem;color:#95a5a6;margin-top:4px;font-style:italic;">"<?= htmlspecialchars($p['PROCESSING_NOTE']) ?>"</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <div class="modal fade" id="processModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-question-circle"></i> Confirmar Ação</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p id="processMsg" style="color:var(--primary-color);font-weight:600;"></p>
                        <label class="form-label-custom">Nota (opcional)</label>
                        <textarea class="form-control form-control-custom" id="processNote" rows="3" placeholder="Adicione uma observação..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn-action" id="confirmProcessBtn"><i class="fas fa-check"></i> Confirmar</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="uploadModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-upload"></i> Carregar Anexo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="uploadForm" onsubmit="handleUpload(event)" enctype="multipart/form-data" novalidate>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label-custom">Ficheiro <span class="required">*</span></label>
                                <input type="file" class="form-control form-control-custom" id="upFile" required>
                                <small class="text-muted">Máx: 10 MB.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Descrição</label>
                                <textarea class="form-control form-control-custom" id="upDesc" rows="2" maxlength="255"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn-action" id="upSubmitBtn"><i class="fas fa-save"></i> Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteAttModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-exclamation-triangle modal-danger-icon"></i>
                        <h5 style="color:var(--primary-color);">Eliminar Anexo</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Tem a certeza?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmDelAttBtn" onclick="confirmDeleteAttSubmit()"><i class="fas fa-trash"></i> Eliminar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            let pendingAction = null;
            let processModal, uploadModal, deleteAttModal;
            let pendingAttId = null;
            const DOC_ID = <?= (int)$docId ?>;

            document.addEventListener('DOMContentLoaded', () => {
                processModal  = new bootstrap.Modal(document.getElementById('processModal'));
                uploadModal   = new bootstrap.Modal(document.getElementById('uploadModal'));
                deleteAttModal = new bootstrap.Modal(document.getElementById('deleteAttModal'));
            });

            function processDoc(action) {
                pendingAction = action;
                const msgs = {
                    'resolve': 'Marcar este documento como resolvido e entregue ao Estudante?',
                    'forward': 'Encaminhar este documento para o próximo passo do fluxo?',
                    'return':  'Devolver este documento à Secretaria?',
                    'deliver': 'Entregar este documento ao Estudante?',
                    'reject':  'Rejeitar este documento? (será devolvido à Secretaria)'
                };
                document.getElementById('processMsg').textContent = msgs[action] || 'Confirmar?';
                document.getElementById('processNote').value = '';
                processModal.show();
            }

            document.getElementById('confirmProcessBtn').addEventListener('click', function() {
                if (!pendingAction) return;
                const btn = this; const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A processar...';
                const fd = new FormData();
                fd.append('action', pendingAction);
                fd.append('document_id', DOC_ID);
                fd.append('note', document.getElementById('processNote').value.trim());
                fetch('document_process.php', {method:'POST', body:fd})
                    .then(r => r.json())
                    .then(j => {
                        processModal.hide();
                        if (j.success) { showToast(j.message, 'success'); setTimeout(() => location.reload(), 900); }
                        else showToast(j.message || 'Erro.', 'error');
                    })
                    .catch(() => showToast('Erro de comunicação.', 'error'))
                    .finally(() => { btn.disabled = false; btn.innerHTML = orig; pendingAction = null; });
            });

            function openUploadModal(){
                document.getElementById('upFile').value = '';
                document.getElementById('upDesc').value = '';
                uploadModal.show();
            }

            function handleUpload(e){
                e.preventDefault();
                const fileInput = document.getElementById('upFile');
                if (!fileInput.files.length) { showToast('Selecione um ficheiro.', 'error'); return; }

                const btn = document.getElementById('upSubmitBtn');
                const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A carregar...';

                const fd = new FormData();
                fd.append('action', 'upload_attachment');
                fd.append('descricao', document.getElementById('upDesc').value.trim());
                fd.append('ficheiro', fileInput.files[0]);

                fetch('document_view.php?id=' + DOC_ID, {method:'POST', body:fd})
                    .then(r => r.json())
                    .then(j => {
                        if (j.success) { uploadModal.hide(); showToast(j.message, 'success'); setTimeout(() => location.reload(), 800); }
                        else showToast(j.message || 'Erro.', 'error');
                    })
                    .catch(() => showToast('Erro de comunicação.', 'error'))
                    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
            }

            function confirmDeleteAtt(id){
                pendingAttId = id;
                deleteAttModal.show();
            }

            function confirmDeleteAttSubmit(){
                if (!pendingAttId) return;
                const btn = document.getElementById('confirmDelAttBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const fd = new FormData();
                fd.append('action', 'delete_attachment');
                fd.append('att_id', pendingAttId);
                fetch('document_view.php?id=' + DOC_ID, {method:'POST', body:fd})
                    .then(r => r.json())
                    .then(j => {
                        deleteAttModal.hide();
                        if (j.success) { showToast(j.message, 'success'); setTimeout(() => location.reload(), 800); }
                        else showToast(j.message || 'Erro.', 'error');
                    })
                    .catch(() => showToast('Erro de comunicação.', 'error'))
                    .finally(() => { btn.disabled = false; btn.innerHTML = orig; pendingAttId = null; });
            }
        </script>

<?php
    include 'includes/modals.php';
    include 'includes/footer.php';
?>