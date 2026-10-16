<?php
require_once '../conf/config.php';
require_once dirname(__DIR__) . '/conf/presence/SuppliesLogin.php';
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
$error = '';
$_SESSION['supplies_confirmation_csrf'] ??= bin2hex(random_bytes(32));
if (isset($_SESSION['supplies_login_pending']) && $_SESSION['supplies_login_pending']['expires'] < time()) { unset($_SESSION['supplies_login_pending']); $error = 'คำยืนยันหมดอายุ กรุณาเข้าสู่ระบบใหม่'; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['supplies_confirmation_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่'; }
    elseif (($_POST['action'] ?? '') === 'cancel') { unset($_SESSION['supplies_login_pending']); header('Location: ' . (!empty($_SESSION['user_id']) ? '/supplies/' : '/NMU_permission/')); exit; }
    elseif (($_POST['action'] ?? '') === 'replace') {
        try { SuppliesLogin::confirm((string)($_POST['confirmation'] ?? ''), (string)($_POST['admin_operator'] ?? '')); header('Location: /supplies/'); exit; }
        catch (RuntimeException $e) { $error = $e->getMessage(); }
    }
}
$pending = $_SESSION['supplies_login_pending'] ?? null;
$namedAdmin = !empty($pending['admin']); $replacing = !empty($pending['owner']);
function confirmationEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>เข้าสู่ระบบ · Supplies</title><link rel="stylesheet" href="/supplies/assets/css/admin-login.css"></head><body class="login-page"><main class="login-card"><h1>Supplies</h1><h2><?= $namedAdmin ? 'ระบุชื่อผู้ดูแล' : 'ยืนยันการล็อกอินซ้ำ' ?></h2>
<?php if ($error): ?><div class="alert" role="alert"><?=confirmationEscape($error)?></div><?php endif ?>
<?php if ($pending): ?><p>บัญชี <strong><?=confirmationEscape($pending['row']['c_full_name'])?></strong></p>
<?php if ($namedAdmin): ?><p>Admin ใช้งานพร้อมกันได้หลายคน กรุณาระบุชื่อของคุณ เพื่อแสดงในรายชื่อออนไลน์และข้อความ</p><?php if ($replacing): ?><p class="alert">ชื่อผู้ดูแลนี้ใช้งานอยู่ ยืนยันเพื่อแทนที่เฉพาะเซสชันชื่อนี้ หรือเปลี่ยนชื่อผู้ดูแล</p><?php endif ?><?php else: ?><p>มีเซสชันใช้งานอยู่ หากยืนยัน เซสชันเดิมจะออกจาก Supplies</p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?=confirmationEscape($_SESSION['supplies_confirmation_csrf'])?>"><input type="hidden" name="confirmation" value="<?=confirmationEscape($pending['nonce'])?>">
<?php if ($namedAdmin): ?><label for="admin_operator">ชื่อผู้ดูแล</label><input id="admin_operator" name="admin_operator" placeholder="admin-eak หรือ admin-moo" pattern="admin-[a-z0-9][a-z0-9_-]{1,31}" maxlength="38" required value="<?=confirmationEscape($_POST['admin_operator'] ?? $pending['operator'] ?? '')?>"><p class="login-note">ชื่อแตกต่างกันจะไม่ออกจากระบบของ Admin คนอื่น</p><?php endif ?>
<button name="action" value="replace"><?= $namedAdmin && !$replacing ? 'เข้าสู่ระบบด้วยชื่อผู้ดูแล' : 'ยืนยันเข้าสู่ระบบแทนที่เซสชันเดิม' ?></button><button name="action" value="cancel" formnovalidate style="background:#64748b">ยกเลิก</button></form><p class="login-note">คำยืนยันมีอายุ 2 นาที · ไม่มีผลต่อ EIS-DOCS</p>
<?php else: ?><p>ไม่มีคำขอยืนยันการล็อกอินที่ใช้ได้</p><a href="/NMU_permission/">กลับหน้าเข้าสู่ระบบ</a><?php endif ?></main></body></html>
