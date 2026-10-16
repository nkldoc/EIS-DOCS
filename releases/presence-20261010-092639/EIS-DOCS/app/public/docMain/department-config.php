<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::requireLogin();
if (!DocumentAccess::isAdministrator()) { http_response_code(403); exit('เฉพาะผู้ดูแลระบบเท่านั้น'); }
$_SESSION['app_csrf'] ??= bin2hex(random_bytes(32));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
$message = '';
$departments = [];
$allowed = [];
$query = trim((string)($_GET['q'] ?? ''));
function permissionRows($db, string $sql, array $params = []): array {
    $stmt = sqlsrv_query($db, $sql, $params);
    if (!$stmt) throw new RuntimeException('โหลดข้อมูลไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูลสิทธิ์หน่วยงานและสิทธิ์อ่าน dc_cost');
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
    return $rows;
}
function h($text): string { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
try {
    $db = Database::connect('docs');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!safeCmp($_SESSION['app_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง'); }
        $id = filter_var($_POST['dc_cost_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new InvalidArgumentException('กรุณาเลือกหน่วยงาน');
        if (($_POST['action'] ?? '') === 'grant') {
            $stmt = sqlsrv_query($db, 'INSERT dbo.DOCS_DEPARTMENT_PERMISSION(dc_cost_id,granted_by) SELECT c.dc_cost_id,? FROM NMU_DATACENTER.dbo.dc_cost c WHERE c.dc_cost_id=? AND c.i_enable=1 AND c.i_delete=2 AND c.i_last=1 AND NOT EXISTS (SELECT 1 FROM dbo.DOCS_DEPARTMENT_PERMISSION p WITH (UPDLOCK,HOLDLOCK) WHERE p.dc_cost_id=c.dc_cost_id)', [DocumentAccess::userId(),$id]);
            if (!$stmt || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('เพิ่มสิทธิ์ไม่สำเร็จ หน่วยงานอาจมีสิทธิ์แล้วหรือปิดใช้งาน');
        } elseif (($_POST['action'] ?? '') === 'revoke') {
            $stmt = sqlsrv_query($db, 'DELETE dbo.DOCS_DEPARTMENT_PERMISSION WHERE dc_cost_id=?', [$id]);
            if (!$stmt) throw new RuntimeException('ถอนสิทธิ์ไม่สำเร็จ');
        } else { throw new InvalidArgumentException('คำสั่งไม่ถูกต้อง'); }
        sqlsrv_free_stmt($stmt);
        $_SESSION['department_permission_message'] = 'บันทึกสิทธิ์หน่วยงานแล้ว';
        header('Location: department-config.php?q=' . rawurlencode($query)); exit;
    }
    $message = (string)($_SESSION['department_permission_message'] ?? '');
    unset($_SESSION['department_permission_message']);
    $allowed = permissionRows($db, 'SELECT p.dc_cost_id,c.c_code,c.c_name FROM dbo.DOCS_DEPARTMENT_PERMISSION p LEFT JOIN NMU_DATACENTER.dbo.dc_cost c ON c.dc_cost_id=p.dc_cost_id ORDER BY c.c_name');
    if ($query !== '') $departments = permissionRows($db, 'SELECT TOP 50 c.dc_cost_id,c.c_code,c.c_name,CASE WHEN p.dc_cost_id IS NULL THEN 0 ELSE 1 END allowed FROM NMU_DATACENTER.dbo.dc_cost c LEFT JOIN dbo.DOCS_DEPARTMENT_PERMISSION p ON p.dc_cost_id=c.dc_cost_id WHERE c.i_enable=1 AND c.i_delete=2 AND c.i_last=1 AND (CHARINDEX(?,c.c_name)>0 OR CHARINDEX(?,c.c_code)>0) ORDER BY c.c_name,c.dc_cost_id', [$query,$query]);
    sqlsrv_close($db);
} catch (Throwable $error) { $message = $error->getMessage(); error_log('Department configuration: '.$message); }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>กำหนดสิทธิ์หน่วยงาน — EIS-DOCS</title><link rel="stylesheet" href="style.css"><style>body{padding:24px}main{max-width:960px;margin:auto}section{background:white;border:1px solid #ddd;border-radius:12px;padding:24px;margin:20px 0}.row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid #eee}.row small{display:block;color:#666}input{padding:10px;max-width:100%}button{cursor:pointer}</style></head><body><main>
<a href="index.php">← กลับหน้าหลัก</a><h1>กำหนดสิทธิ์จัดทำเอกสาร</h1><p>เพิ่มหน่วยงานที่สามารถสร้างเอกสาร เช่น ฝ่ายพัสดุ สมาชิกในหน่วยงานที่ได้รับสิทธิ์สามารถจัดทำเอกสารได้ ผู้ดูแลระบบมีสิทธิ์เสมอ</p>
<p role="status"><?=h($message)?></p>
<section><h2>ค้นหาหน่วยงาน</h2><form method="get"><input name="q" value="<?=h($query)?>" placeholder="ชื่อหรือรหัสหน่วยงาน เช่น พัสดุ" aria-label="ชื่อหรือรหัสหน่วยงาน" maxlength="200" required><button class="btn btn-primary">ค้นหา</button></form>
<?php if ($query !== '' && !$departments): ?><p>ไม่พบหน่วยงาน</p><?php endif ?>
<?php foreach ($departments as $row): ?><div class="row"><div><?=h($row['c_name'])?><small><?=h($row['c_code'])?> · ID <?=h($row['dc_cost_id'])?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['app_csrf'])?>"><input type="hidden" name="dc_cost_id" value="<?=h($row['dc_cost_id'])?>"><input type="hidden" name="action" value="grant"><button class="btn btn-primary" <?= $row['allowed'] ? 'disabled' : '' ?>><?=$row['allowed'] ? 'มีสิทธิ์แล้ว' : 'เพิ่มสิทธิ์'?></button></form></div><?php endforeach ?>
<?php if (count($departments) === 50): ?><p>แสดง 50 รายการแรก กรุณาระบุชื่อให้ละเอียดขึ้น</p><?php endif ?></section>
<section><h2>หน่วยงานที่ได้รับสิทธิ์</h2><p>การถอนสิทธิ์หยุดการสร้างเอกสารใหม่ เอกสารเดิมและสิทธิ์ลงนามยังใช้งานได้</p>
<?php if (!$allowed): ?><p>ยังไม่มีหน่วยงานที่ได้รับสิทธิ์</p><?php endif ?>
<?php foreach ($allowed as $row): ?><div class="row"><div><?=h($row['c_name'] ?? 'ไม่พบหน่วยงาน')?><small><?=h($row['c_code'])?> · ID <?=h($row['dc_cost_id'])?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['app_csrf'])?>"><input type="hidden" name="dc_cost_id" value="<?=h($row['dc_cost_id'])?>"><input type="hidden" name="action" value="revoke"><button class="btn btn-subtle">ถอนสิทธิ์</button></form></div><?php endforeach ?></section>
</main><?= Presence::script() ?></body></html>
