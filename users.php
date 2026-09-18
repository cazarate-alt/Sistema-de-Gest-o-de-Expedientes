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
    requirePermissionOrRedirect($pdo, 'USERS', 'VIEW');

    $profileCode = strtoupper($_SESSION['profile_code'] ?? '');
    $isSuper     = ($profileCode === 'SUPE');
    $isAdmin     = ($profileCode === 'ADMI');

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

    function gerarCodigoUtilizador(PDO $pdo, string $firstname, string $lastname, ?int $excludeId = null): string {
        $mapa = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C'];
        $fn = preg_replace('/[^A-Za-z]/', '', strtr($firstname, $mapa));
        $ln = preg_replace('/[^A-Za-z]/', '', strtr($lastname, $mapa));
        if (strlen($fn) < 3) $fn = str_pad($fn, 3, 'X');
        if (strlen($ln) < 3) $ln = str_pad($ln, 3, 'X');
        $base = strtoupper(substr($fn, 0, 3) . substr($ln, 0, 3));
        $codigo = $base; $sufixo = 1;
        while (true) {
            $sql = "SELECT COUNT(*) FROM `USERS` WHERE USER_CODE = :c";
            $params = [':c'=>$codigo];
            if ($excludeId) { $sql .= " AND USER_ID <> :id"; $params[':id'] = $excludeId; }
            $st = $pdo->prepare($sql); $st->execute($params);
            if ((int)$st->fetchColumn() === 0) return $codigo;
            $sufixo++;
            $codigo = $base . $sufixo;
            if (strlen($codigo) > 20) $codigo = substr($codigo, 0, 20);
        }
    }

    function gerarPasswordPorData(string $birthdate): string {
        try {
            $d = new DateTime($birthdate);
            return $d->format('dmY');
        } catch (\Throwable $e) { return '00000000'; }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['action'];

        if ($action === 'save') {
            $isEdit = !empty($_POST['id']);
            requirePermissionApi($pdo, 'USERS', $isEdit ? 'UPDT' : 'CREA');

            $id        = $isEdit ? intval($_POST['id']) : null;
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname  = trim($_POST['lastname'] ?? '');
            $gender    = $_POST['gender'] ?? 'M';
            $birthdate = trim($_POST['birthdate'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $positionId= (int)($_POST['position_id'] ?? 0);
            $status    = isset($_POST['status']) ? intval($_POST['status']) : 1;
            $password  = trim($_POST['password'] ?? '');

            $errors = [];
            if ($firstname === '') $errors['firstname'] = 'O primeiro nome é obrigatório.';
            if ($lastname === '')  $errors['lastname']  = 'O apelido é obrigatório.';
            if (!in_array($gender, ['M','F'], true)) $gender = 'M';
            if ($birthdate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
                $errors['birthdate'] = 'Data de nascimento inválida.';
            } else {
                $bd = new DateTime($birthdate); $today = new DateTime('today');
                if ($bd > $today) $errors['birthdate'] = 'Data no futuro.';
                elseif ($bd->diff($today)->y < 10) $errors['birthdate'] = 'Idade mínima 10 anos.';
            }
            if ($email === '') $errors['email'] = 'O email é obrigatório.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email inválido.';
            if ($profileId <= 0)  $errors['profile_id']  = 'Selecione o perfil.';
            if ($positionId <= 0) $errors['position_id'] = 'Selecione o cargo.';

            if (!isset($errors['email'])) {
                $sql = "SELECT COUNT(*) FROM `USERS` WHERE USER_EMAIL = :e";
                $p = [':e'=>$email]; if ($id) { $sql .= " AND USER_ID <> :id"; $p[':id']=$id; }
                $st = $pdo->prepare($sql); $st->execute($p);
                if ((int)$st->fetchColumn() > 0) $errors['email'] = 'Já existe um utilizador com este email.';
            }

            if ($password !== '') {
                if (mb_strlen($password) < 6) $errors['password'] = 'Mínimo 6 caracteres.';
            }

            if ($errors) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }

            try {
                if ($isEdit) {
                    $st = $pdo->prepare("SELECT USER_FIRSTNAME, USER_LASTNAME, USER_CODE, USER_PASSWORD, USER_BIRTHDATE FROM `USERS` WHERE USER_ID=:id");
                    $st->execute([':id'=>$id]); $old = $st->fetch();
                    if (!$old) { echo json_encode(['success'=>false,'message'=>'Utilizador não encontrado.']); exit; }

                    $changeName = ($old['USER_FIRSTNAME'] !== $firstname || $old['USER_LASTNAME'] !== $lastname);
                    $code = $changeName ? gerarCodigoUtilizador($pdo, $firstname, $lastname, $id) : $old['USER_CODE'];

                    $hash = $old['USER_PASSWORD'];
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                    } elseif ($old['USER_BIRTHDATE'] !== $birthdate) {
                        $hash = password_hash(gerarPasswordPorData($birthdate), PASSWORD_DEFAULT);
                    }

                    $pdo->prepare("UPDATE `USERS` SET
                        USER_CODE=:c, USER_FIRSTNAME=:fn, USER_LASTNAME=:ln, USER_GENDER=:g,
                        USER_BIRTHDATE=:bd, USER_EMAIL=:em, USER_PASSWORD=:pw,
                        PROFILE_ID=:pid, POSITION_ID=:posid, USER_STATUS=:s
                        WHERE USER_ID=:id")
                        ->execute([
                            ':c'=>$code, ':fn'=>$firstname, ':ln'=>$lastname, ':g'=>$gender,
                            ':bd'=>$birthdate, ':em'=>$email, ':pw'=>$hash,
                            ':pid'=>$profileId, ':posid'=>$positionId, ':s'=>$status, ':id'=>$id
                        ]);
                    echo json_encode(['success'=>true,'message'=>'Utilizador atualizado com sucesso!']);
                } else {
                    $code = gerarCodigoUtilizador($pdo, $firstname, $lastname, null);
                    $plainPassword = $password !== '' ? $password : gerarPasswordPorData($birthdate);
                    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

                    $pdo->prepare("INSERT INTO `USERS`
                        (USER_CODE, USER_FIRSTNAME, USER_LASTNAME, USER_GENDER, USER_BIRTHDATE,
                         USER_EMAIL, USER_PASSWORD, PROFILE_ID, POSITION_ID, USER_STATUS)
                        VALUES (:c, :fn, :ln, :g, :bd, :em, :pw, :pid, :posid, :s)")
                        ->execute([
                            ':c'=>$code, ':fn'=>$firstname, ':ln'=>$lastname, ':g'=>$gender,
                            ':bd'=>$birthdate, ':em'=>$email, ':pw'=>$hash,
                            ':pid'=>$profileId, ':posid'=>$positionId, ':s'=>$status
                        ]);

                    echo json_encode([
                        'success'=>true,
                        'message'=>"Utilizador criado! Código: $code | Password: $plainPassword",
                        'id'=>(int)$pdo->lastInsertId(),
                        'code'=>$code,
                        'plain_password'=>$plainPassword
                    ]);
                }
            } catch (\PDOException $e) { echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]); }
            exit;
        }

        if ($action === 'toggle') {
            requirePermissionApi($pdo, 'USERS', 'UPDT');
            $id = intval($_POST['id'] ?? 0);
            if ($id === (int)$_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Não pode desativar a sua própria conta.']); exit; }
            $st = $pdo->prepare("SELECT USER_STATUS FROM `USERS` WHERE USER_ID=:id");
            $st->execute([':id'=>$id]); $cur = $st->fetchColumn();
            if ($cur === false) { echo json_encode(['success'=>false,'message'=>'Utilizador não encontrado.']); exit; }
            $new = ((int)$cur === 1) ? 0 : 1;
            $pdo->prepare("UPDATE `USERS` SET USER_STATUS=:s WHERE USER_ID=:id")->execute([':s'=>$new,':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>$new?'Utilizador ativado!':'Utilizador desativado!']);
            exit;
        }

        if ($action === 'reset_password') {
            requirePermissionApi($pdo, 'USERS', 'UPDT');
            $id = intval($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT USER_BIRTHDATE, USER_CODE FROM `USERS` WHERE USER_ID=:id");
            $st->execute([':id'=>$id]); $u = $st->fetch();
            if (!$u) { echo json_encode(['success'=>false,'message'=>'Utilizador não encontrado.']); exit; }
            $plain = gerarPasswordPorData($u['USER_BIRTHDATE']);
            $hash  = password_hash($plain, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE `USERS` SET USER_PASSWORD=:p WHERE USER_ID=:id")->execute([':p'=>$hash, ':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>"Password redefinida! Nova password: $plain", 'plain'=>$plain]);
            exit;
        }

        if ($action === 'delete') {
            requirePermissionApi($pdo, 'USERS', 'DELE');
            $id = intval($_POST['id'] ?? 0);
            if ($id === (int)$_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Não pode eliminar a sua própria conta.']); exit; }

            $st = $pdo->prepare("SELECT p.PROFILE_CODE FROM `USERS` u LEFT JOIN `PROFILE` p ON p.PROFILE_ID=u.PROFILE_ID WHERE u.USER_ID=:id");
            $st->execute([':id'=>$id]); $code = $st->fetchColumn();
            if ($code === 'SUPE') { echo json_encode(['success'=>false,'message'=>'Super Administrador não pode ser eliminado.']); exit; }

            $st = $pdo->prepare("SELECT COUNT(*) FROM `DOCUMENT` WHERE USER_ID=:id");
            $st->execute([':id'=>$id]);
            if ((int)$st->fetchColumn() > 0) { echo json_encode(['success'=>false,'message'=>'Existem documentos associados. Desative em vez de eliminar.']); exit; }

            $pdo->prepare("DELETE FROM `USERS` WHERE USER_ID=:id")->execute([':id'=>$id]);
            echo json_encode(['success'=>true,'message'=>'Utilizador eliminado com sucesso!']);
            exit;
        }

        if ($action === 'options') {
            $profiles  = $pdo->query("SELECT PROFILE_ID, PROFILE_CODE, PROFILE_NAME FROM `PROFILE` WHERE PROFILE_STATUS=1 ORDER BY PROFILE_NAME")->fetchAll();
            $positions = $pdo->query("SELECT POSITION_ID, POSITION_CODE, POSITION_NAME FROM `POSITION` WHERE POSITION_STATUS=1 ORDER BY POSITION_NAME")->fetchAll();
            echo json_encode(['success'=>true,'data'=>compact('profiles','positions')]);
            exit;
        }

        if ($action === 'stats') {
            $where = $isSuper ? '' : "WHERE PROFILE_ID NOT IN (SELECT PROFILE_ID FROM `PROFILE` WHERE PROFILE_CODE='SUPE')";
            $total    = (int)$pdo->query("SELECT COUNT(*) FROM `USERS` $where")->fetchColumn();
            $active   = (int)$pdo->query("SELECT COUNT(*) FROM `USERS` " . ($where ? "$where AND USER_STATUS=1" : "WHERE USER_STATUS=1"))->fetchColumn();
            $inactive = (int)$pdo->query("SELECT COUNT(*) FROM `USERS` " . ($where ? "$where AND USER_STATUS=0" : "WHERE USER_STATUS=0"))->fetchColumn();
            $month    = (int)$pdo->query("SELECT COUNT(*) FROM `USERS` " . ($where ? "$where AND" : "WHERE") . " YEAR(USER_CREATEDAT)=YEAR(CURDATE()) AND MONTH(USER_CREATEDAT)=MONTH(CURDATE())")->fetchColumn();
            echo json_encode(['success'=>true,'data'=>compact('total','active','inactive','month')]);
            exit;
        }
        echo json_encode(['success'=>false,'message'=>'Ação desconhecida.']); exit;
    }

    $where = $isSuper ? '' : "WHERE u.PROFILE_ID NOT IN (SELECT PROFILE_ID FROM `PROFILE` WHERE PROFILE_CODE='SUPE')";
    $utilizadores = $pdo->query("
        SELECT u.*, p.PROFILE_CODE, p.PROFILE_NAME, p.PROFILE_IS_SYSTEM,
               pos.POSITION_CODE, pos.POSITION_NAME
        FROM `USERS` u
        LEFT JOIN `PROFILE`  p   ON p.PROFILE_ID   = u.PROFILE_ID
        LEFT JOIN `POSITION` pos ON pos.POSITION_ID = u.POSITION_ID
        $where
        ORDER BY u.USER_ID DESC
    ")->fetchAll();

    $pageTitle = 'Gestão de Utilizadores';
    $activePage = 'users.php';
    include 'includes/header.php';
    include 'includes/sidebar.php';
    include 'includes/navbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="page-title mb-0" style="margin:0;">
                <h2><i class="fas fa-users-cog" style="color:var(--accent-color);"></i> Gestão de Utilizadores</h2>
                <div class="breadcrumb-custom"><a href="dashboard.php">Dashboard</a> <i class="fas fa-chevron-right mx-1" style="font-size:.7rem;"></i> <span>Utilizadores</span></div>
            </div>
            <button class="btn-action" style="background:linear-gradient(135deg,#c0392b,#e74c3c);" onclick="window.open('export_pdf.php?tipo=users','_blank')">
                <i class="fas fa-file-pdf"></i> Exportar PDF
            </button>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(52,152,219,.12);color:#3498db;"><i class="fas fa-users"></i></div><div class="stat-info"><div class="value" id="statTotal">—</div><div class="label">Total</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(46,204,113,.12);color:#2ecc71;"><i class="fas fa-user-check"></i></div><div class="stat-info"><div class="value" id="statActive">—</div><div class="label">Ativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(231,76,60,.12);color:#e74c3c;"><i class="fas fa-user-times"></i></div><div class="stat-info"><div class="value" id="statInactive">—</div><div class="label">Inativos</div></div></div></div>
            <div class="col-md-3 col-6 mb-3"><div class="stat-card"><div class="icon" style="background:rgba(243,156,18,.12);color:#f39c12;"><i class="fas fa-calendar-plus"></i></div><div class="stat-info"><div class="value" id="statMonth">—</div><div class="label">Este Mês</div></div></div></div>
        </div>

        <div class="filter-card">
            <h6><i class="fas fa-filter"></i> Filtros</h6>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Pesquisar</label><input type="text" class="form-control" id="filterSearch" placeholder="Código, nome, email..."></div>
                <div class="col-md-3"><label class="form-label">Perfil</label><select class="form-select" id="filterProfile"><option value="">Todos</option></select></div>
                <div class="col-md-3"><label class="form-label">Estado</label><select class="form-select" id="filterStatus"><option value="">Todos</option><option value="1">Ativo</option><option value="0">Inativo</option></select></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()" style="border-radius:8px;font-size:.9rem;padding:8px 12px;"><i class="fas fa-redo me-1"></i> Limpar</button></div>
            </div>
        </div>

        <div class="main-card">
            <div class="main-card-header">
                <div class="title"><i class="fas fa-list-ul"></i><span>Lista de Utilizadores</span><span class="badge bg-secondary ms-2" id="listCount" style="font-size:.75rem;">0</span></div>
                <button class="btn-action" onclick="openCreateModal()"><i class="fas fa-plus"></i> Novo Utilizador</button>
            </div>
            <div class="main-card-body">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead><tr>
                            <th style="width:60px;">ID</th>
                            <th style="width:100px;">Código</th>
                            <th>Nome</th>
                            <th class="d-none d-md-table-cell">Email</th>
                            <th style="width:130px;">Perfil</th>
                            <th style="width:130px;">Cargo</th>
                            <th style="width:100px;">Estado</th>
                            <th style="width:220px;">Ações</th>
                        </tr></thead>
                        <tbody id="usersTableBody">
                            <?php if (empty($utilizadores)): ?>
                                <tr><td colspan="8"><div class="empty-state"><i class="fas fa-inbox"></i><h6>Nenhum utilizador</h6></div></td></tr>
                            <?php else: foreach ($utilizadores as $u):
                                $isActive = (int)$u['USER_STATUS'] === 1;
                                $isSysProf = (int)$u['PROFILE_IS_SYSTEM'] === 1;
                                $isSuperRow = ($u['PROFILE_CODE'] === 'SUPE');
                            ?>
                                <tr id="row-<?= (int)$u['USER_ID'] ?>">
                                    <td><strong>#<?= (int)$u['USER_ID'] ?></strong></td>
                                    <td class="cell-code"><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:.8rem;"><?= htmlspecialchars($u['USER_CODE']) ?></code></td>
                                    <td class="cell-name"><strong><?= htmlspecialchars($u['USER_FIRSTNAME'].' '.$u['USER_LASTNAME']) ?></strong></td>
                                    <td class="cell-email d-none d-md-table-cell" style="color:#7f8c8d;font-size:.85rem;"><?= htmlspecialchars($u['USER_EMAIL']) ?></td>
                                    <td class="cell-profile">
                                        <span class="badge-status badge-info" style="font-size:.75rem;"><?= htmlspecialchars($u['PROFILE_NAME'] ?? '—') ?></span>
                                    </td>
                                    <td class="cell-position" style="font-size:.82rem;color:#7f8c8d;"><?= htmlspecialchars($u['POSITION_NAME'] ?? '—') ?></td>
                                    <td class="cell-status">
                                        <?php if ($isActive): ?><span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>
                                        <?php else: ?><span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span><?php endif; ?>
                                    </td>
                                    <td class="cell-actions"
                                        data-id="<?= (int)$u['USER_ID'] ?>"
                                        data-code="<?= htmlspecialchars($u['USER_CODE']) ?>"
                                        data-firstname="<?= htmlspecialchars($u['USER_FIRSTNAME']) ?>"
                                        data-lastname="<?= htmlspecialchars($u['USER_LASTNAME']) ?>"
                                        data-name="<?= htmlspecialchars($u['USER_FIRSTNAME'].' '.$u['USER_LASTNAME']) ?>"
                                        data-email="<?= htmlspecialchars($u['USER_EMAIL']) ?>"
                                        data-gender="<?= htmlspecialchars($u['USER_GENDER']) ?>"
                                        data-birthdate="<?= htmlspecialchars($u['USER_BIRTHDATE']) ?>"
                                        data-profile-id="<?= (int)$u['PROFILE_ID'] ?>"
                                        data-profile-name="<?= htmlspecialchars($u['PROFILE_NAME'] ?? '') ?>"
                                        data-profile-code="<?= htmlspecialchars($u['PROFILE_CODE'] ?? '') ?>"
                                        data-position-id="<?= (int)$u['POSITION_ID'] ?>"
                                        data-position-name="<?= htmlspecialchars($u['POSITION_NAME'] ?? '') ?>"
                                        data-status="<?= (int)$u['USER_STATUS'] ?>"
                                        data-super="<?= $isSuperRow ? '1' : '0' ?>">
                                        <div class="action-links">
                                            <button class="btn-icon view" onclick="openViewModal(<?= (int)$u['USER_ID'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                            <button class="btn-icon toggle" onclick="openStatusModal(<?= (int)$u['USER_ID'] ?>)" title="Estado"><i class="fas fa-<?= $isActive?'toggle-on':'toggle-off' ?>"></i></button>
                                            <button class="btn-icon" onclick="openResetModal(<?= (int)$u['USER_ID'] ?>)" title="Reset password" style="color:#f39c12;"><i class="fas fa-key"></i></button>
                                            <button class="btn-icon edit" onclick="openEditModal(<?= (int)$u['USER_ID'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                            <button class="btn-icon delete" onclick="openDeleteModal(<?= (int)$u['USER_ID'] ?>)" title="Eliminar" <?= $isSuperRow ? 'disabled' : '' ?>><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal Form -->
        <div class="modal fade" id="formModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="formModalTitle"><i class="fas fa-plus-circle"></i> Novo Utilizador</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="userForm" onsubmit="handleFormSubmit(event)" novalidate>
                        <div class="modal-body">
                            <input type="hidden" id="formUserId">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Primeiro Nome <span class="required">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="formFirstname" maxlength="100">
                                    <div class="field-error" id="errorFirstname"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Apelido <span class="required">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="formLastname" maxlength="100">
                                    <div class="field-error" id="errorLastname"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Género <span class="required">*</span></label>
                                    <select class="form-select form-control-custom" id="formGender">
                                        <option value="M">Masculino</option>
                                        <option value="F">Feminino</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Data de Nascimento <span class="required">*</span></label>
                                    <input type="date" class="form-control form-control-custom" id="formBirthdate">
                                    <div class="field-error" id="errorBirthdate"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                    <small class="text-muted" style="font-size:.72rem;">Usada como password inicial (DDMMAAAA).</small>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Email <span class="required">*</span></label>
                                <input type="email" class="form-control form-control-custom" id="formEmail" maxlength="100">
                                <div class="field-error" id="errorEmail"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Perfil <span class="required">*</span></label>
                                    <select class="form-select form-control-custom" id="formProfile"></select>
                                    <div class="field-error" id="errorProfile"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label-custom">Cargo <span class="required">*</span></label>
                                    <select class="form-select form-control-custom" id="formPosition"></select>
                                    <div class="field-error" id="errorPosition"><i class="fas fa-exclamation-circle"></i> <span></span></div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label-custom">Password <small class="text-muted">(deixe vazio para usar a data de nascimento)</small></label>
                                <input type="text" class="form-control form-control-custom" id="formPassword" maxlength="100" autocomplete="new-password">
                                <div class="field-error" id="errorPassword"><i class="fas fa-exclamation-circle"></i> <span></span></div>
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

        <!-- Modal View -->
        <div class="modal fade" id="viewModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Detalhes</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body" id="viewBody"></div>
                    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
                </div>
            </div>
        </div>

        <!-- Modal Status -->
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

        <!-- Modal Reset Password -->
        <div class="modal fade" id="resetModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-key" style="font-size:4rem;color:#f39c12;margin-bottom:15px;"></i>
                        <h5 style="color:var(--primary-color);">Redefinir Password</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Redefinir para <strong id="resetUserName" style="color:#e67e22;"></strong>?</p>
                        <p class="text-muted mt-2" style="font-size:.8rem;">A nova password será a <strong>data de nascimento (DDMMAAAA)</strong>.</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-warning" id="confirmResetBtn" onclick="confirmReset()"><i class="fas fa-key"></i> Redefinir</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Delete -->
        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-exclamation-triangle modal-danger-icon"></i>
                        <h5 style="color:var(--primary-color);">Eliminar Utilizador</h5>
                        <p class="text-muted mb-0" style="font-size:.9rem;">Eliminar <strong id="deleteUserName" style="color:#e74c3c;"></strong>?</p>
                    </div>
                    <div class="modal-footer justify-content-center border-0 pb-4">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fas fa-trash"></i> Eliminar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const API_URL = 'users.php';
            let formModal, viewModal, statusModal, deleteModal, resetModal;
            let pendingStatusId = null, pendingDeleteId = null, pendingResetId = null;
            let OPTIONS = { profiles: [], positions: [] };

            document.addEventListener('DOMContentLoaded', () => {
                formModal   = new bootstrap.Modal(document.getElementById('formModal'));
                viewModal   = new bootstrap.Modal(document.getElementById('viewModal'));
                statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
                deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                resetModal  = new bootstrap.Modal(document.getElementById('resetModal'));
                loadStats();
                loadOptions();
                let t;
                document.getElementById('filterSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilters, 300); });
                document.getElementById('filterStatus').addEventListener('change', applyFilters);
                document.getElementById('filterProfile').addEventListener('change', applyFilters);
                document.getElementById('formStatus').addEventListener('change', function() { document.getElementById('formStatusLabel').textContent = this.checked ? 'Ativo' : 'Inativo'; });
                applyFilters();
            });

            function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
            function showFieldError(field, msg){ const map={firstname:'errorFirstname',lastname:'errorLastname',birthdate:'errorBirthdate',email:'errorEmail',profile_id:'errorProfile',position_id:'errorPosition',password:'errorPassword'}; const id=map[field]; if(!id) return; const el=document.getElementById(id); if(el){ el.querySelector('span').textContent=msg; el.classList.add('show'); } }
            function clearFieldError(field){ const map={firstname:'errorFirstname',lastname:'errorLastname',birthdate:'errorBirthdate',email:'errorEmail',profile_id:'errorProfile',position_id:'errorPosition',password:'errorPassword'}; const id=map[field]; if(id&&document.getElementById(id)) document.getElementById(id).classList.remove('show'); }
            function clearAllErrors(){ ['firstname','lastname','birthdate','email','profile_id','position_id','password'].forEach(clearFieldError); }

            function loadStats(){
                const fd = new FormData(); fd.append('action','stats');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ document.getElementById('statTotal').textContent=j.data.total; document.getElementById('statActive').textContent=j.data.active; document.getElementById('statInactive').textContent=j.data.inactive; document.getElementById('statMonth').textContent=j.data.month; }
                }).catch(console.error);
            }

            function loadOptions(){
                const fd = new FormData(); fd.append('action','options');
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (!j.success) return;
                    OPTIONS.profiles = j.data.profiles;
                    OPTIONS.positions = j.data.positions;
                    const sp = document.getElementById('formProfile');
                    sp.innerHTML = '<option value="">— Selecione —</option>';
                    j.data.profiles.forEach(p => { const o=document.createElement('option'); o.value=p.PROFILE_ID; o.textContent=p.PROFILE_NAME+' ('+p.PROFILE_CODE+')'; sp.appendChild(o); });
                    const pos = document.getElementById('formPosition');
                    pos.innerHTML = '<option value="">— Selecione —</option>';
                    j.data.positions.forEach(p => { const o=document.createElement('option'); o.value=p.POSITION_ID; o.textContent=p.POSITION_NAME+' ('+p.POSITION_CODE+')'; pos.appendChild(o); });
                    const fprof = document.getElementById('filterProfile');
                    j.data.profiles.forEach(p => { const o=document.createElement('option'); o.value=p.PROFILE_CODE; o.textContent=p.PROFILE_NAME; fprof.appendChild(o); });
                }).catch(console.error);
            }

            function applyFilters(){
                const search = document.getElementById('filterSearch').value.toLowerCase().trim();
                const status = document.getElementById('filterStatus').value;
                const profile = document.getElementById('filterProfile').value;
                const rows = Array.from(document.querySelectorAll('#usersTableBody tr[id^="row-"]'));
                if (!rows.length) return;
                let visible = 0;
                rows.forEach(row => {
                    const d = row.querySelector('.cell-actions').dataset;
                    const txt = (d.code + ' ' + d.name + ' ' + d.email).toLowerCase();
                    let show = true;
                    if (search && !txt.includes(search)) show = false;
                    if (status !== '' && String(d.status) !== status) show = false;
                    if (profile && d.profileCode !== profile) show = false;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                document.getElementById('listCount').textContent = visible;
            }
            function resetFilters(){ document.getElementById('filterSearch').value=''; document.getElementById('filterStatus').value=''; document.getElementById('filterProfile').value=''; applyFilters(); }

            function openViewModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                const st = d.status==='1' ? '<span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Ativo</span>' : '<span class="badge-status badge-inactive"><i class="fas fa-times-circle"></i> Inativo</span>';
                document.getElementById('viewBody').innerHTML = `
                    <div class="info-row"><div class="label">ID:</div><div class="value">#${d.id}</div></div>
                    <div class="info-row"><div class="label">Código:</div><div class="value"><code>${escapeHtml(d.code)}</code></div></div>
                    <div class="info-row"><div class="label">Nome:</div><div class="value">${escapeHtml(d.name)}</div></div>
                    <div class="info-row"><div class="label">Email:</div><div class="value">${escapeHtml(d.email)}</div></div>
                    <div class="info-row"><div class="label">Género:</div><div class="value">${d.gender==='M'?'Masculino':'Feminino'}</div></div>
                    <div class="info-row"><div class="label">Nascimento:</div><div class="value">${escapeHtml(d.birthdate)}</div></div>
                    <div class="info-row"><div class="label">Perfil:</div><div class="value">${escapeHtml(d.profileName)}</div></div>
                    <div class="info-row"><div class="label">Cargo:</div><div class="value">${escapeHtml(d.positionName)}</div></div>
                    <div class="info-row"><div class="label">Estado:</div><div class="value">${st}</div></div>
                `;
                viewModal.show();
            }

            function openCreateModal(){
                clearAllErrors();
                document.getElementById('formUserId').value='';
                document.getElementById('formFirstname').value='';
                document.getElementById('formLastname').value='';
                document.getElementById('formGender').value='M';
                document.getElementById('formBirthdate').value='';
                document.getElementById('formEmail').value='';
                document.getElementById('formProfile').value='';
                document.getElementById('formPosition').value='';
                document.getElementById('formPassword').value='';
                document.getElementById('formStatus').checked=true;
                document.getElementById('formStatusLabel').textContent='Ativo';
                document.getElementById('formModalTitle').innerHTML='<i class="fas fa-plus-circle"></i> Novo Utilizador';
                document.getElementById('formSubmitText').textContent='Guardar';
                formModal.show();
            }

            function openEditModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                clearAllErrors();
                document.getElementById('formUserId').value=d.id;
                document.getElementById('formFirstname').value=d.firstname;
                document.getElementById('formLastname').value=d.lastname;
                document.getElementById('formGender').value=d.gender;
                document.getElementById('formBirthdate').value=d.birthdate;
                document.getElementById('formEmail').value=d.email;
                document.getElementById('formProfile').value=d.profileId;
                document.getElementById('formPosition').value=d.positionId;
                document.getElementById('formPassword').value='';
                document.getElementById('formStatus').checked = d.status==='1';
                document.getElementById('formStatusLabel').textContent = d.status==='1'?'Ativo':'Inativo';
                document.getElementById('formModalTitle').innerHTML='<i class="fas fa-edit"></i> Editar Utilizador';
                document.getElementById('formSubmitText').textContent='Atualizar';
                formModal.show();
            }

            function handleFormSubmit(e){
                e.preventDefault(); clearAllErrors();
                const id        = document.getElementById('formUserId').value;
                const firstname = document.getElementById('formFirstname').value.trim();
                const lastname  = document.getElementById('formLastname').value.trim();
                const gender    = document.getElementById('formGender').value;
                const birthdate = document.getElementById('formBirthdate').value;
                const email     = document.getElementById('formEmail').value.trim();
                const profileId = parseInt(document.getElementById('formProfile').value) || 0;
                const positionId= parseInt(document.getElementById('formPosition').value) || 0;
                const password  = document.getElementById('formPassword').value.trim();
                const status    = document.getElementById('formStatus').checked ? 1 : 0;
                let hasError = false;
                if (!firstname) { showFieldError('firstname','Obrigatório.'); hasError = true; }
                if (!lastname)  { showFieldError('lastname','Obrigatório.'); hasError = true; }
                if (!birthdate) { showFieldError('birthdate','Obrigatório.'); hasError = true; }
                if (!email)     { showFieldError('email','Obrigatório.'); hasError = true; }
                if (profileId<=0)  { showFieldError('profile_id','Selecione.'); hasError = true; }
                if (positionId<=0) { showFieldError('position_id','Selecione.'); hasError = true; }
                if (hasError) return;
                const btn = document.getElementById('formSubmitBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';
                const fd = new FormData(); fd.append('action','save');
                if (id) fd.append('id', id);
                fd.append('firstname', firstname); fd.append('lastname', lastname);
                fd.append('gender', gender); fd.append('birthdate', birthdate);
                fd.append('email', email); fd.append('profile_id', profileId);
                fd.append('position_id', positionId); fd.append('password', password);
                fd.append('status', status);
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    if (j.success){ formModal.hide(); showToast(j.message,'success',6000); setTimeout(()=>location.reload(),1500); }
                    else if (j.errors) Object.entries(j.errors).forEach(([f,m]) => showFieldError(f,m));
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; });
            }

            function openStatusModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                if (d.super === '1') { showToast('Super Administrador não pode ser desativado.','warning'); return; }
                const isActive = d.status==='1';
                pendingStatusId = id;
                document.getElementById('statusModalTitle').textContent = isActive?'Desativar Utilizador':'Ativar Utilizador';
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

            function openResetModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                pendingResetId = id;
                document.getElementById('resetUserName').textContent = d.name;
                resetModal.show();
            }
            function confirmReset(){
                if (!pendingResetId) return;
                const btn = document.getElementById('confirmResetBtn'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const fd = new FormData(); fd.append('action','reset_password'); fd.append('id', pendingResetId);
                fetch(API_URL, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
                    resetModal.hide();
                    if (j.success){ showToast(j.message,'success',7000); }
                    else showToast(j.message||'Erro.','error');
                }).catch(console.error).finally(() => { btn.disabled=false; btn.innerHTML=orig; pendingResetId=null; });
            }

            function openDeleteModal(id){
                const row = document.getElementById('row-'+id); if (!row) return;
                const d = row.querySelector('.cell-actions').dataset;
                if (d.super === '1') { showToast('Super Administrador não pode ser eliminado.','warning'); return; }
                pendingDeleteId = id;
                document.getElementById('deleteUserName').textContent = d.name;
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