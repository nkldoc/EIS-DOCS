<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::requireLogin();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
$_SESSION['app_csrf'] ??= bin2hex(random_bytes(32));
$message = '';
$rows = [];
$query = trim((string)($_GET['q'] ?? ''));
function escapeIdentity($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!safeCmp($_SESSION['app_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง'); }
        if (($_POST['action'] ?? '') === 'cancel_duplicate') { unset($_SESSION['docs_impersonation_pending']); header('Location: index.php'); exit; }
        if (($_POST['action'] ?? '') === 'confirm_duplicate') {
            $pending = $_SESSION['docs_impersonation_pending'] ?? null;
            if (!$pending || $pending['expires'] < time() || !hash_equals($pending['nonce'], (string)($_POST['confirmation'] ?? ''))) { unset($_SESSION['docs_impersonation_pending']); throw new RuntimeException('คำยืนยันหมดอายุ กรุณาลองใหม่'); }
            if ($pending['kind'] === 'restore') Auth::stopImpersonating($pending['owner']);
            else Auth::impersonate($pending['uid'], $pending['owner']);
        }
        elseif (($_POST['action'] ?? '') === 'restore') Auth::stopImpersonating();
        elseif (($_POST['action'] ?? '') === 'login') {
            $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new RuntimeException('กรุณาเลือกผู้ใช้');
            if (($_POST['system'] ?? 'docs') === 'supplies') {
                $endpoint = SuppliesImpersonation::endpoint();
                $ticket = SuppliesImpersonation::issue($id);
                header('Location: ' . $endpoint . '?ticket=' . rawurlencode($ticket)); exit;
            }
            Auth::impersonate($id);
        } else throw new RuntimeException('คำสั่งไม่ถูกต้อง');
        header('Location: index.php'); exit;
    }
    if (!DocumentAccess::isAdministrator() || Auth::impersonating()) { http_response_code(403); throw new RuntimeException('เฉพาะ admin เท่านั้นที่ค้นหาเพื่อสวมสิทธิ์ได้'); }
    if ($query !== '') {
        $db = Database::connect();
        try {
            $stmt = sqlsrv_query($db, 'SELECT TOP 50 u.dc_user_id,u.c_user_name,u.c_full_name,e.c_name employee_name FROM dbo.dc_user u LEFT JOIN dbo.dc_emp e ON e.dc_emp_id=u.dc_emp_id WHERE u.i_enable=1 AND u.i_delete=2 AND (CHARINDEX(?,u.c_user_name)>0 OR CHARINDEX(?,u.c_full_name)>0 OR CHARINDEX(?,e.c_name)>0) ORDER BY u.c_full_name,u.dc_user_id', [$query,$query,$query]);
            if (!$stmt) throw new RuntimeException('ค้นหาผู้ใช้ไม่สำเร็จ');
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
            sqlsrv_free_stmt($stmt);
        } finally { sqlsrv_close($db); }
    }
} catch (Throwable $error) { $message = $error->getMessage(); }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>สวมสิทธิ์ผู้ใช้ — EIS-DOCS</title><link rel="stylesheet" href="style.css"><style>body{padding:24px}main{max-width:960px;margin:auto}.identity-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px;background:white;border-bottom:1px solid #ddd}.identity-row small{display:block}input{padding:10px;max-width:100%}</style></head><body><main><a href="index.php">← กลับหน้าหลัก</a><h1>สวมสิทธิ์ผู้ใช้</h1><p>ค้นหาชื่อพนักงานหรือชื่อผู้ใช้ แล้วเลือก Login เพื่อใช้งานด้วยสิทธิ์ของบัญชีนั้น</p><p role="status"><?=escapeIdentity($message)?></p>
<?php if (isset($_SESSION['docs_impersonation_pending'])): $duplicate = $_SESSION['docs_impersonation_pending']; ?>
<section role="alert"><h2>ยืนยันการล็อกอินซ้ำ</h2><p><?=escapeIdentity($duplicate['name'])?> มีเซสชันใช้งานอยู่ หากยืนยัน เซสชันเดิมจะออกจาก EIS-DOCS</p><form method="post"><input type="hidden" name="csrf" value="<?=escapeIdentity($_SESSION['app_csrf'])?>"><input type="hidden" name="confirmation" value="<?=escapeIdentity($duplicate['nonce'])?>"><button class="btn btn-primary" name="action" value="confirm_duplicate">ยืนยันแทนที่เซสชันเดิม</button><button class="btn btn-subtle" name="action" value="cancel_duplicate">ยกเลิก</button></form></section>
<?php endif ?>
<?php if (DocumentAccess::isAdministrator() && !Auth::impersonating()): ?>
<form method="get"><input name="q" value="<?=escapeIdentity($query)?>" aria-label="ชื่อพนักงานหรือชื่อผู้ใช้" placeholder="ชื่อพนักงาน หรือชื่อผู้ใช้" maxlength="200" required><button class="btn btn-primary">ค้นหา</button></form>
<?php if ($query !== '' && !$rows): ?><p>ไม่พบผู้ใช้</p><?php endif ?>
<?php foreach ($rows as $row): ?><div class="identity-row"><div><?=escapeIdentity($row['employee_name'] ?: $row['c_full_name'])?><small>ชื่อผู้ใช้: <?=escapeIdentity($row['c_user_name'])?> · <?=escapeIdentity($row['c_full_name'])?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=escapeIdentity($_SESSION['app_csrf'])?>"><input type="hidden" name="action" value="login"><input type="hidden" name="user_id" value="<?=escapeIdentity($row['dc_user_id'])?>"><button class="btn btn-primary" name="system" value="docs">Login EIS-DOCS</button> <button class="btn btn-primary" name="system" value="supplies">Login Supplies</button></form></div><?php endforeach ?>
<?php if (count($rows) === 50): ?><p>แสดง 50 รายการแรก กรุณาระบุชื่อให้ละเอียดขึ้น</p><?php endif ?>
<?php endif ?></main><?= Presence::script() ?></body></html>
