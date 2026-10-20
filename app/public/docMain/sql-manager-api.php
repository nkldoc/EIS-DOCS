<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/SqlConsole.php';
require_once dirname(__DIR__, 2) . '/src/SqlBackup.php';
require_once dirname(__DIR__, 2) . '/src/SqlRestore.php';
header('Cache-Control: no-store, private');
if (!Auth::check() || !DocumentAccess::isAdministrator() || Auth::impersonating()) jsonResponse(['error'=>'ไม่มีสิทธิ์จัดการ SQL'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(['error'=>'ใช้ POST เท่านั้น'], 405);
$input = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') ? $_POST : json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !safeCmp((string)($_SESSION['app_csrf'] ?? ''), (string)($input['csrf'] ?? '')) || empty($_SESSION['app_csrf'])) jsonResponse(['error'=>'CSRF ไม่ถูกต้อง'], 403);
$db = null; $transaction = false;
function consoleQuery($db, string $sql, array $params = []) {
    $s = sqlsrv_query($db, $sql, $params, ['QueryTimeout'=>60]);
    if (!$s) { error_log('SQL console: '.json_encode(sqlsrv_errors())); throw new RuntimeException('SQL ไม่สำเร็จ กรุณาตรวจชนิดข้อมูล สิทธิ์ SQL และ server log'); }
    return $s;
}
function consoleRows($db, string $sql, array $params = []): array {
    $s = consoleQuery($db, $sql, $params); $rows = [];
    while (true) {
        $r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC);
        if ($r === false) throw new RuntimeException('อ่านผล SQL ไม่สำเร็จ');
        if ($r === null) break;
        foreach ($r as &$value) {
            if (is_resource($value)) $value = '0x'.bin2hex(stream_get_contents($value));
            elseif (is_string($value) && !preg_match('//u', $value)) $value = '0x'.bin2hex($value);
        }
        unset($value); $rows[] = $r;
    }
    sqlsrv_free_stmt($s); return $rows;
}
function consoleAudit(array $data): void {
    $dir = dirname(__DIR__, 3) . '/storage/logs';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('สร้างที่เก็บ audit ไม่สำเร็จ');
    $entry = ['at'=>date(DATE_ATOM), 'user'=>DocumentAccess::userId()] + $data;
    if (file_put_contents($dir . '/sql-console.jsonl', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL, FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('บันทึก audit ไม่สำเร็จ');
}
try {
    $config = require dirname(__DIR__, 2) . '/config/database.php';
    $action = (string)($input['action'] ?? '');
    $runtime = require dirname(__DIR__, 2) . '/config/runtime.php';
    $connection = (string)($input['connection'] ?? $runtime['environment']);
    if (!in_array($connection, ['local','production'], true)) throw new InvalidArgumentException('เลือก Local หรือ Production');
    // Discover databases with the same SQL login/server used by this environment.
    $db = Database::connect('master', true, true, $connection);
    $databases = consoleRows($db, "SELECT name AS [key],name,state_desc AS state,CASE WHEN state=0 AND HAS_DBACCESS(name)=1 THEN 1 ELSE 0 END AS accessible,CASE WHEN database_id<=4 THEN 1 ELSE 0 END AS isSystem FROM sys.databases ORDER BY name");
    if ($action === 'databases') {
        $server = consoleRows($db, "SELECT CONVERT(nvarchar(128),SERVERPROPERTY('ServerName')) AS name,ORIGINAL_LOGIN() AS login")[0];
        jsonResponse(['databases'=>$databases,'server'=>$server,'connection'=>$connection,'backup'=>SqlBackup::describe($connection)]);
    }
    $key = (string)($input['database'] ?? '');
    if (!in_array($key, array_column($databases, 'key'), true)) throw new InvalidArgumentException('ไม่พบฐานข้อมูลที่ออนไลน์หรือบัญชี SQL ไม่มีสิทธิ์เข้าถึง กรุณาโหลดรายชื่อใหม่');
    if ($action === 'restore') {
        if ($connection !== 'local') throw new RuntimeException('Restore is only available for Local (.)');
        consoleAudit(['action'=>'restore_start','connection'=>$connection,'database'=>$key]);
        set_time_limit(0);
        SqlRestore::run($db, $key, $_FILES['backup'] ?? [], ($input['replace'] ?? '') === '1', ($input['closeConnections'] ?? '') === '1');
        try { consoleAudit(['action'=>'restore_complete','connection'=>$connection,'database'=>$key]); } catch (Throwable $e) { error_log($e->getMessage()); }
        jsonResponse(['message'=>'Restore completed: Local / '.$key]);
    }
    $selectedDatabase = $databases[array_search($key,array_column($databases,'key'),true)];
    if (!(int)$selectedDatabase['accessible']) throw new RuntimeException('ฐานข้อมูล '.$key.' อยู่ในสถานะ '.$selectedDatabase['state'].' หรือบัญชี SQL ไม่มีสิทธิ์เข้าถึง');
    $database = $key;
    sqlsrv_close($db); $db = null;
    $db = Database::connect($database, true, true, $connection);
    if ($action === 'tables') jsonResponse(['tables'=>consoleRows($db, "SELECT s.name AS [schema],t.name AS [table] FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.is_ms_shipped=0 ORDER BY s.name,t.name")]);
    if ($action === 'backup') {
        $settings = SqlBackup::settings($connection);
        $plan = SqlBackup::plan($settings, $database, $connection, (string)($input['backupFolder'] ?? ''), (string)($input['backupName'] ?? ''));
        SqlBackup::prepare($plan);
        $name = $plan['base'] . '.bak';
        $sqlPath = $plan['sql_path'];
        consoleAudit(['action'=>'backup_start','connection'=>$connection,'database'=>$database,'file'=>$plan['relative'] . '/' . $name]);
        set_time_limit(0);
        SqlBackup::run($db, $database, $sqlPath);
        $backupPath = $plan['web_directory'] . '/' . $name;
        $path = $plan['web_directory'] . '/' . $plan['base'] . '.zip';
        $bytes = SqlBackup::archive($backupPath, $path, $name);
        $token = bin2hex(random_bytes(24));
        $_SESSION['sql_downloads'][$token] = ['path'=>$path, 'name'=>$plan['base'].'.zip','expires'=>time()+3600,'mime'=>'application/zip'];
        try { consoleAudit(['action'=>'backup_ready','connection'=>$connection,'database'=>$database,'file'=>$plan['relative'].'/'.$plan['base'].'.zip','bytes'=>$bytes]); }
        catch (Throwable $logError) { error_log('SQL backup audit: '.$logError->getMessage()); }
        jsonResponse(['download'=>'sql-manager-download.php?token='.rawurlencode($token),'filename'=>$_SESSION['sql_downloads'][$token]['name'],'bytes'=>$bytes,'folder'=>$plan['relative'],'expiresAt'=>date(DATE_ATOM,time()+3600)]);
    }
    if ($action === 'script') {
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '' || strlen($query) > 1000000 || preg_match('/^\s*GO\s*(?:--.*)?$/mi', $query)) throw new InvalidArgumentException('Empty/oversized script or unsupported GO separator');
        consoleAudit(['action'=>'script_attempt','connection'=>$connection,'database'=>$database,'queryHash'=>hash('sha256',$query)]);
        $previous = sqlsrv_get_config('WarningsReturnAsErrors'); sqlsrv_configure('WarningsReturnAsErrors', 0);
        try {
            $statement = consoleQuery($db, $query); $rows = [];
            do {
                if (sqlsrv_num_fields($statement) > 0) {
                    while (true) {
                        $row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC);
                        if ($row === false) throw new RuntimeException('Script result failed');
                        if ($row === null) break;
                        foreach ($row as &$value) { if (is_resource($value)) $value = '0x'.bin2hex(stream_get_contents($value)); } unset($value);
                        if (count($rows) < 500) $rows[] = $row;
                    }
                }
                $next = sqlsrv_next_result($statement);
                if ($next === false) throw new RuntimeException('Script failed; check server log and database state before retry');
            } while ($next === true);
            sqlsrv_free_stmt($statement);
            $open = consoleRows($db, 'SELECT @@TRANCOUNT AS n')[0]['n'];
            if ($open) { consoleQuery($db, 'WHILE @@TRANCOUNT > 0 ROLLBACK TRANSACTION'); throw new RuntimeException('Script left an open transaction; rolled back'); }
        } finally { sqlsrv_configure('WarningsReturnAsErrors', $previous); }
        try { consoleAudit(['action'=>'script_complete','connection'=>$connection,'database'=>$database]); } catch (Throwable $e) { error_log($e->getMessage()); }
        jsonResponse(['rows'=>$rows,'message'=>'Script completed (display limited to 500 rows)']);
    }
    $schema = (string)($input['schema'] ?? ''); $table = (string)($input['table'] ?? '');
    $columns = consoleRows($db, 'SELECT c.name,c.is_identity,c.is_computed,ty.name AS type FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE s.name=? AND t.name=? ORDER BY c.column_id', [$schema,$table]);
    if (!$columns) throw new InvalidArgumentException('ไม่พบตารางหรือไม่มีสิทธิ์');
    if ($action === 'columns') jsonResponse(['columns'=>$columns]);
    if (!in_array($action, ['preview','commit'], true)) throw new InvalidArgumentException('คำสั่งไม่ถูกต้อง');
    $query = (string)($input['query'] ?? '');
    $compiled = (new SqlConsole())->compile($query, $schema, $table, array_column($columns, 'name'));
    $hash = hash('sha256', json_encode([$connection,$key,$schema,$table,$query]));
    if ($compiled['action'] === 'SELECT') {
        if ($action !== 'preview') throw new InvalidArgumentException('SELECT ใช้เรียกดูข้อมูล');
        jsonResponse(['rows'=>consoleRows($db, $compiled['sql'], $compiled['params'])]);
    }
    $target = SqlConsole::quote($schema).'.'.SqlConsole::quote($table);
    if ($action === 'commit') {
        $preview = $_SESSION['sql_preview'] ?? []; unset($_SESSION['sql_preview']);
        if (($preview['hash'] ?? '') !== $hash || ($preview['expires'] ?? 0) < time() || !safeCmp((string)($preview['token'] ?? ''), (string)($input['token'] ?? ''))) throw new RuntimeException('กรุณาตรวจสอบคำสั่งอีกครั้งก่อนยืนยัน');
        if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่ม transaction ไม่สำเร็จ');
        $transaction = true;
    }
    $lock = $transaction ? ' WITH (UPDLOCK,HOLDLOCK)' : '';
    $count = (int)consoleRows($db, 'SELECT COUNT_BIG(*) AS n FROM '.$target.$lock.$compiled['where'], $compiled['whereParams'])[0]['n'];
    if ($count < 1 || $count > 1000) throw new RuntimeException('พบ '.$count.' แถว ต้องอยู่ระหว่าง 1–1,000 แถว กรุณาปรับ WHERE');
    if ($action === 'preview') {
        $token = bin2hex(random_bytes(24));
        $_SESSION['sql_preview'] = ['hash'=>$hash,'count'=>$count,'token'=>$token,'expires'=>time()+300];
        jsonResponse(['count'=>$count,'token'=>$token,'rows'=>consoleRows($db, 'SELECT TOP 50 * FROM '.$target.$compiled['where'], $compiled['whereParams'])]);
    }
    if ($count !== $preview['count']) throw new RuntimeException('จำนวนแถวเปลี่ยนไป กรุณาตรวจสอบใหม่');
    $audit = ['action'=>$compiled['action'], 'connection'=>$connection,'database'=>$database,'schema'=>$schema,'table'=>$table,'queryHash'=>$hash,'count'=>$count,'case'=>substr((string)($input['case'] ?? ''),0,160)];
    consoleAudit(['phase'=>'attempt'] + $audit);
    $s = consoleQuery($db, $compiled['sql'], $compiled['params']);
    do { $next = sqlsrv_next_result($s); if ($next === false) throw new RuntimeException('SQL ไม่สำเร็จ ยกเลิก transaction'); } while ($next === true);
    sqlsrv_free_stmt($s);
    if (!sqlsrv_commit($db)) throw new RuntimeException('บันทึก transaction ไม่สำเร็จ');
    $transaction = false;
    // A logging failure after commit must never be reported as a failed database write.
    try { consoleAudit(['phase'=>'committed'] + $audit); } catch (Throwable $logError) { error_log($logError->getMessage()); }
    jsonResponse(['message'=>'บันทึก '.$count.' แถวเรียบร้อยแล้ว']);
} catch (Throwable $error) {
    if ($transaction && $db) sqlsrv_rollback($db);
    error_log('SQL manager: '.$error->getMessage());
    jsonResponse(['error'=>$error->getMessage()], 400);
} finally { if ($db) { @sqlsrv_query($db, 'WHILE @@TRANCOUNT > 0 ROLLBACK TRANSACTION'); sqlsrv_close($db); } }
