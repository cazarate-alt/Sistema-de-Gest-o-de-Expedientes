<?php
// ============================================================
// index.php — Ponto de entrada do sistema GED
//
// Fluxo:
//   1. Liga ao MySQL (sem DB definida)
//   2. Verifica se a BD "GED" existe
//   3. Verifica se as tabelas essenciais + utilizadores existem
//   4. Se algo faltar → executa DB.SQL (parser próprio)
//   5. Após instalar:
//        - Corrige a password do SUPER (hash real de 01011990)
//        - Cria os 6 utilizadores de exemplo (opcional)
//   6. Redireciona:
//        - Logado    → dashboard.php
//        - Não logado → login.php
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

$host    = 'localhost';
$db      = 'GED';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

// ------------------------------------------------------------
// Configuração de seed
// ------------------------------------------------------------
// Criar os 6 utilizadores de exemplo (SILSUE, MARFER, ...)?
$seedUsersEnabled = true;

// Ficheiro SQL — aceita DB.SQL / DB.sql / db.sql / db.SQL
$sqlFile = null;
foreach ([
    __DIR__ . '/DB.SQL',
    __DIR__ . '/DB.sql',
    __DIR__ . '/db.sql',
    __DIR__ . '/db.SQL',
] as $f) {
    if (file_exists($f)) { $sqlFile = $f; break; }
}

// ------------------------------------------------------------
// 1. Ligar ao MySQL SEM base de dados definida
// ------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    die(ged_error_page('Erro ao ligar ao MySQL', $e->getMessage()));
}

// ------------------------------------------------------------
// 2. Verificar se a BD existe
// ------------------------------------------------------------
$dbExists = false;
try {
    $st = $pdo->prepare(
        "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db"
    );
    $st->execute([':db' => $db]);
    $dbExists = (bool)$st->fetchColumn();
} catch (\Throwable $e) {
    $dbExists = false;
}

// ------------------------------------------------------------
// 3. Verificar se a BD tem tabelas + pelo menos 1 utilizador
// ------------------------------------------------------------
$structureOk = false;
$requiredTables = ['USERS','PROFILE','POSITION','ACTION','MODULE','PERMISSION'];

if ($dbExists) {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME IN ('" . implode("','", $requiredTables) . "')
        ");
        $st->execute([':db' => $db]);
        $tablesCount = (int)$st->fetchColumn();

        if ($tablesCount === count($requiredTables)) {
            $pdo->exec("USE `$db`");
            $st = $pdo->query("SELECT COUNT(*) FROM `USERS`");
            $structureOk = ((int)$st->fetchColumn() > 0);
        }
    } catch (\Throwable $e) {
        $structureOk = false;
    }
}

// ------------------------------------------------------------
// 4. Instalação automática
// ------------------------------------------------------------
if (!$dbExists || !$structureOk) {

    if ($sqlFile === null) {
        die(ged_error_page(
            'Ficheiro DB.SQL não encontrado',
            "Coloque o ficheiro DB.SQL (ou DB.sql) na raiz do projeto:\n\n" . __DIR__
        ));
    }

    try {
        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('O ficheiro ' . basename($sqlFile) . ' está vazio ou não pode ser lido.');
        }

        // Reconnecta sem dbname (a base ainda pode não existir)
        $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Divide em instruções individuais
        $statements = ged_split_sql($sql);

        if (empty($statements)) {
            throw new \RuntimeException('Nenhuma instrução SQL válida foi encontrada.');
        }

        $executed = 0;

        foreach ($statements as $i => $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || $stmt === ';') {
                continue;
            }

            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (\Throwable $e) {
                $preview = substr($stmt, 0, 500);
                if (strlen($stmt) > 500) $preview .= "\n... [truncado]";

                throw new \RuntimeException(
                    "Erro no statement #" . ($i + 1) . " (executados com sucesso: $executed de " . count($statements) . ")\n\n" .
                    "=== INÍCIO DO STATEMENT ===\n" .
                    $preview . "\n" .
                    "=== FIM DO STATEMENT ===\n\n" .
                    "Detalhes MySQL: " . $e->getMessage()
                );
            }
        }

        // Certifica que estamos a usar a DB
        $pdo->exec("USE `$db`");

        // ----------------------------------------------------
        // 4.1 Corrigir a password do SUPER com hash real
        //     Password = data de nascimento em DDMMAAAA
        // ----------------------------------------------------
        try {
            // Pega a data de nascimento do SUPER
            $st = $pdo->prepare(
                "SELECT USER_BIRTHDATE FROM `USERS` WHERE USER_CODE = 'SUPER' LIMIT 1"
            );
            $st->execute();
            $birthdate = $st->fetchColumn();

            if (!$birthdate) {
                $birthdate = '1990-01-01';
            }

            // Converte "1990-01-01" → "01011990"
            $plain = ged_date_to_password($birthdate);
            if ($plain === '') $plain = '01011990';

            // Gera o hash bcrypt
            $hash = password_hash($plain, PASSWORD_DEFAULT);

            // Atualiza o SUPER
            $st = $pdo->prepare(
                "UPDATE `USERS` SET USER_PASSWORD = :h WHERE USER_CODE = 'SUPER'"
            );
            $st->execute([':h' => $hash]);

            // Se não existir, cria
            $st = $pdo->query("SELECT COUNT(*) FROM `USERS` WHERE USER_CODE = 'SUPER'");
            if ((int)$st->fetchColumn() === 0) {
                $st = $pdo->prepare("
                    INSERT INTO `USERS`
                        (USER_CODE, USER_FIRSTNAME, USER_LASTNAME, USER_GENDER,
                         USER_BIRTHDATE, USER_EMAIL, USER_PASSWORD,
                         PROFILE_ID, POSITION_ID, USER_STATUS)
                    SELECT
                        'SUPER', 'Super', 'Administrador', 'M',
                        '1990-01-01', 'superadmin@ged.local', :h,
                        p.PROFILE_ID, po.POSITION_ID, 1
                    FROM `PROFILE` p, `POSITION` po
                    WHERE p.PROFILE_CODE='SUPE' AND po.POSITION_CODE='SIST'
                ");
                $st->execute([':h' => $hash]);
            }
        } catch (\Throwable $e) {
            error_log('Falha ao corrigir password do SUPER: ' . $e->getMessage());
        }

        // ----------------------------------------------------
        // 4.2 Criar utilizadores de exemplo (opcional)
        //     Password = data de nascimento em DDMMAAAA
        // ----------------------------------------------------
        if ($seedUsersEnabled) {
            try {
                $seedUsers = [
                    ['SILSUE', 'Silvestre Julio', 'Sueia',    'M', '1996-02-26', 'sjsueiaw@gmail.com',        'SUPE', 'SIST'],
                    ['MARFER', 'Maria',           'Fernandes','F', '1988-05-10', 'maria.fernandes@ged.local', 'ADMI', 'ADMG'],
                    ['CARMA',  'Carlos',          'Mabote',   'M', '1975-11-22', 'carlos.mabote@ged.local',   'DIRE', 'DIRE'],
                    ['ANAMUI', 'Ana',             'Muianga',  'F', '1992-03-15', 'ana.muianga@ged.local',     'SECR', 'SECR'],
                    ['JOAMAC', 'João',            'Machava',  'M', '1980-08-08', 'joao.machava@ged.local',    'DOCE', 'DOCE'],
                    ['PEDCUN', 'Pedro',           'Cuna',     'M', '2002-09-30', 'pedro.cuna@ged.local',      'ESTU', 'ESTU'],
                ];

                $stProfile  = $pdo->prepare("SELECT PROFILE_ID FROM `PROFILE` WHERE PROFILE_CODE = :c LIMIT 1");
                $stPosition = $pdo->prepare("SELECT POSITION_ID FROM `POSITION` WHERE POSITION_CODE = :c LIMIT 1");

                $stInsert = $pdo->prepare("
                    INSERT INTO `USERS`
                        (USER_CODE, USER_FIRSTNAME, USER_LASTNAME, USER_GENDER,
                         USER_BIRTHDATE, USER_EMAIL, USER_PASSWORD,
                         PROFILE_ID, POSITION_ID, USER_STATUS)
                    VALUES
                        (:code, :fn, :ln, :g, :bd, :email, :pw, :pid, :posid, 1)
                    ON DUPLICATE KEY UPDATE
                        USER_FIRSTNAME = VALUES(USER_FIRSTNAME),
                        USER_LASTNAME  = VALUES(USER_LASTNAME),
                        USER_GENDER    = VALUES(USER_GENDER),
                        USER_BIRTHDATE = VALUES(USER_BIRTHDATE),
                        USER_EMAIL     = VALUES(USER_EMAIL),
                        USER_PASSWORD  = VALUES(USER_PASSWORD),
                        PROFILE_ID     = VALUES(PROFILE_ID),
                        POSITION_ID    = VALUES(POSITION_ID),
                        USER_STATUS    = 1
                ");

                foreach ($seedUsers as $u) {
                    [$code, $fn, $ln, $g, $bd, $email, $profCode, $posCode] = $u;

                    // Password = data de nascimento em DDMMAAAA
                    $plain = ged_date_to_password($bd);
                    if ($plain === '') continue;

                    $hash = password_hash($plain, PASSWORD_DEFAULT);

                    $stProfile->execute([':c' => $profCode]);
                    $profileId = (int)$stProfile->fetchColumn();

                    $stPosition->execute([':c' => $posCode]);
                    $positionId = (int)$stPosition->fetchColumn();

                    if ($profileId <= 0 || $positionId <= 0) {
                        error_log("Seed: perfil/cargo não encontrado para $code");
                        continue;
                    }

                    $stInsert->execute([
                        ':code'  => $code,
                        ':fn'    => $fn,
                        ':ln'    => $ln,
                        ':g'     => $g,
                        ':bd'    => $bd,
                        ':email' => $email,
                        ':pw'    => $hash,
                        ':pid'   => $profileId,
                        ':posid' => $positionId,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Falha ao criar utilizadores de exemplo: ' . $e->getMessage());
            }
        }

        header('Location: index.php?installed=1&n=' . $executed);
        exit;

    } catch (\Throwable $e) {
        die(ged_error_page('Falha na instalação', $e->getMessage()));
    }
}

// ------------------------------------------------------------
// 5. BD OK → decidir destino
// ------------------------------------------------------------
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;


// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Converte várias representações de data para DDMMAAAA.
 *
 * Exemplos:
 *   '1990-01-01'  →  '01011990'
 *   '26/02/1996'  →  '26021996'
 *   '2002-09-30'  →  '30092002'
 */
function ged_date_to_password(string $date): string {
    $date = trim($date);
    if ($date === '') return '';

    // Formato SQL padrão Y-m-d
    $dt = \DateTime::createFromFormat('Y-m-d', $date);
    if ($dt && $dt->format('Y-m-d') === $date) {
        return $dt->format('dmY');
    }

    // Outros formatos comuns
    $formats = ['d/m/Y', 'd-m-Y', 'Y/m/d', 'Ymd', 'dmY'];
    foreach ($formats as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $date);
        if ($dt && $dt->format($fmt) === $date) {
            return $dt->format('dmY');
        }
    }

    // Último recurso: strtotime
    $ts = strtotime($date);
    if ($ts !== false) {
        return date('dmY', $ts);
    }

    return '';
}

/**
 * Gera uma página HTML de erro formatada.
 */
function ged_error_page(string $title, string $message): string {
    return '<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8f9fa; margin: 0; padding: 40px 20px; }
        .box { max-width: 900px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.08); overflow: hidden; }
        .head { background: linear-gradient(135deg, #c0392b, #e74c3c); color: white; padding: 24px 30px; }
        .head h1 { margin: 0; font-size: 1.3rem; }
        .body { padding: 28px 30px; }
        pre { background: #f8d7da; color: #721c24; padding: 16px; border-radius: 8px; white-space: pre-wrap; word-break: break-word; font-size: .85rem; line-height: 1.5; }
        a { color: #c0392b; }
    </style>
</head>
<body>
    <div class="box">
        <div class="head"><h1>' . htmlspecialchars($title) . '</h1></div>
        <div class="body">
            <pre>' . htmlspecialchars($message) . '</pre>
            <p><a href="index.php">← Tentar novamente</a></p>
        </div>
    </div>
</body>
</html>';
}

/**
 * Devolve a última palavra alfabética escrita no buffer.
 */
function ged_last_word(string $buffer): string {
    if (preg_match('/([A-Za-z_]+)\s*$/', $buffer, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

/**
 * Divide um script SQL em instruções individuais.
 *
 * Suporta:
 *   - BEGIN..END, IF..END IF (aninhados), CASE..END CASE,
 *     LOOP..END LOOP, WHILE..END WHILE, REPEAT..END REPEAT
 *   - SIGNAL SQLSTATE '...' SET MESSAGE_TEXT = '...';
 *   - Strings ('...', "...", `...`) com escapes
 *   - Comentários (-- linha e bloco)
 *   - DELIMITER $$ / DELIMITER ;
 */
function ged_split_sql(string $sql): array {
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/^\s*DELIMITER\s+\S+\s*$/mi', '', $sql);
    $sql = preg_replace('/\$\$\s*/', ";\n", $sql);

    $statements = [];
    $buffer     = '';
    $len        = strlen($sql);
    $inString   = false;
    $stringChar = '';
    $stack      = [];
    $inSignalSet = false;

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        // Comentário de linha
        if (!$inString && $ch === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $buffer .= "\n";
            continue;
        }

        // Strings
        if ($inString) {
            if ($ch === '\\') { $buffer .= $ch . $next; $i++; continue; }
            if ($ch === $stringChar) $inString = false;
            $buffer .= $ch;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString   = true;
            $stringChar = $ch;
            $buffer    .= $ch;
            continue;
        }

        // Palavras-chave
        if (ctype_alpha($ch)) {
            $word = '';
            $j = $i;
            while ($j < $len && (ctype_alpha($sql[$j]) || $sql[$j] === '_')) {
                $word .= $sql[$j];
                $j++;
            }
            $upper = strtoupper($word);

            // END ...
            if ($upper === 'END') {
                $k = $j;
                while ($k < $len && ctype_space($sql[$k])) $k++;
                $nextWord = '';
                while ($k < $len && (ctype_alpha($sql[$k]) || $sql[$k] === '_')) {
                    $nextWord .= $sql[$k];
                    $k++;
                }
                $nextUpper = strtoupper($nextWord);

                if (in_array($nextUpper, ['IF','CASE','LOOP','WHILE','REPEAT'], true)) {
                    if (!empty($stack)) {
                        while (!empty($stack) && end($stack) !== $nextUpper) array_pop($stack);
                        if (!empty($stack)) array_pop($stack);
                    }
                    $buffer .= 'END ' . $nextWord;
                    $i = $k - 1;
                    continue;
                }

                if (!empty($stack) && end($stack) === 'BEGIN') {
                    array_pop($stack);
                } elseif (!empty($stack)) {
                    while (!empty($stack) && end($stack) !== 'BEGIN') array_pop($stack);
                    if (!empty($stack)) array_pop($stack);
                }

                $buffer .= $word;
                $i = $j - 1;
                continue;
            }

            // IF ...
            if ($upper === 'IF') {
                $prevWord = ged_last_word($buffer);

                $k = $j;
                while ($k < $len && ctype_space($sql[$k])) $k++;
                $nextWord = '';
                while ($k < $len && (ctype_alpha($sql[$k]) || $sql[$k] === '_')) {
                    $nextWord .= $sql[$k];
                    $k++;
                }
                $nextUpper = strtoupper($nextWord);

                if (!empty($stack)
                    && !in_array($nextUpper, ['EXISTS','NOT'], true)
                    && $prevWord !== 'END') {

                    $top = end($stack);
                    if (in_array($top, ['BEGIN','IF','CASE','LOOP','WHILE','REPEAT'], true)) {
                        $lookAhead = strtoupper(substr($sql, $j, 500));
                        if (preg_match('/\bTHEN\b/', $lookAhead)) {
                            $stack[] = 'IF';
                        }
                    }
                }
                $buffer .= $word;
                $i = $j - 1;
                continue;
            }

            // CASE / LOOP / WHILE / REPEAT
            if (in_array($upper, ['CASE','LOOP','WHILE','REPEAT'], true)) {
                if (!empty($stack)) {
                    $prevWord = ged_last_word($buffer);
                    $top = end($stack);
                    if ($prevWord !== 'END'
                        && in_array($top, ['BEGIN','IF','CASE','LOOP','WHILE','REPEAT'], true)) {
                        $stack[] = $upper;
                    }
                }
                $buffer .= $word;
                $i = $j - 1;
                continue;
            }

            // BEGIN
            if ($upper === 'BEGIN') {
                $stack[] = 'BEGIN';
                $buffer .= $word;
                $i = $j - 1;
                continue;
            }

            // SIGNAL
            if ($upper === 'SIGNAL') {
                $inSignalSet = true;
                $buffer .= $word;
                $i = $j - 1;
                continue;
            }

            $buffer .= $word;
            $i = $j - 1;
            continue;
        }

        // Ponto e vírgula
        if ($ch === ';') {
            if ($inSignalSet && !empty($stack)) {
                $buffer .= $ch;
                if (stripos($buffer, 'MESSAGE_TEXT') !== false) $inSignalSet = false;
                continue;
            }

            if (empty($stack)) {
                $stmt = trim($buffer);
                if ($stmt !== '') $statements[] = $stmt;
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
            continue;
        }

        $buffer .= $ch;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') $statements[] = $stmt;

    return $statements;
}