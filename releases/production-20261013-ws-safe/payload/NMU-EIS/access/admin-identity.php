<?php
require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../conf/messenger/AdminIdentity.php';
header('Cache-Control: no-store, private');
if (empty($_SESSION['user_id'])) { header('Location: /NMU_permission/'); exit; }
if (isset($_SESSION['impersonation_admin_center_id'])) { http_response_code(403); exit('ไม่มีสิทธิ์ระบุชื่อผู้ดูแล'); }
if ((int)($_SESSION['i_type_user'] ?? 0) !== 2) { header('Location: /NMU_EIS/'); exit; }
$error = '';
$_SESSION['admin_identity_csrf'] ??= bin2hex(random_bytes(32));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['admin_identity_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่'; }
    else {
        try { NMUEISAdminIdentity::apply((string)($_POST['admin_operator'] ?? '')); unset($_SESSION['admin_identity_csrf']); session_write_close(); header('Location: /NMU_EIS/', true, 303); exit; }
        catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    }
}
function identityEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ระบุชื่อผู้ดูแล · NMU-EIS</title><style>body{font:16px Tahoma,sans-serif;background:#f2f6f5;color:#164b41;margin:0;padding:24px}main{max-width:440px;margin:8vh auto;background:white;padding:28px;border:1px solid #c8dad1;border-radius:14px}input,button{font:inherit;box-sizing:border-box;padding:12px;width:100%;margin:10px 0;border:1px solid #c8dad1;border-radius:8px}button{background:#006754;color:white;cursor:pointer}.error{color:#a32f22}p{line-height:1.7}</style></head><body><main><h1>ระบุชื่อผู้ดูแล</h1><p>ระบบ NMU-EIS · ชื่อจะแสดงใน User Online และ Ticket เช่น <strong>admin-art-NMU-EIS</strong></p><p class="error" role="alert"><?=identityEscape($error)?></p><form method="post"><input type="hidden" name="csrf" value="<?=identityEscape($_SESSION['admin_identity_csrf'])?>"><label for="admin_operator">ชื่อผู้ดูแล</label><input id="admin_operator" name="admin_operator" maxlength="48" placeholder="admin-art หรือ admin-eak" required autocomplete="off" value="<?=identityEscape($_POST['admin_operator'] ?? $_SESSION['admin_operator'] ?? '')?>"><button type="submit">เข้าใช้งานด้วยชื่อผู้ดูแล</button></form></main></body></html>
