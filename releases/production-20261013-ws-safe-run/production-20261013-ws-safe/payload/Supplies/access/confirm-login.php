<?php
$presencePassiveRequest = true;
require_once '../conf/config.php';
require_once dirname(__DIR__) . '/conf/presence/SuppliesLogin.php';
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
$error = '';
$ajax = isset($_GET['live']) || (($_POST['live'] ?? '') === '1');
$redirect = null;
$_SESSION['supplies_confirmation_csrf'] ??= bin2hex(random_bytes(32));
if (isset($_SESSION['supplies_login_pending']) && $_SESSION['supplies_login_pending']['expires'] < time()) { unset($_SESSION['supplies_login_pending']); $error = 'คำยืนยันหมดอายุ กรุณาเข้าสู่ระบบใหม่'; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['supplies_confirmation_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่'; }
    elseif (($_POST['action'] ?? '') === 'cancel') {
        $cancelled = $_SESSION['supplies_login_pending'] ?? null;
        if (!empty($cancelled['owner'])) {
            try { SingleLogin::transfer(SingleLogin::scoped((string)$cancelled['row']['dc_user_id'], (string)($cancelled['operator'] ?? '')), $cancelled['owner'], $cancelled['nonce'], 0, 'cancelled'); } catch (RuntimeException $e) {}
        }
        unset($_SESSION['supplies_login_pending']); $redirect = !empty($_SESSION['user_id']) ? '/supplies/' : '/NMU_permission/';
    }
    elseif (in_array($_POST['action'] ?? '', ['replace','resend'], true)) {
        try { SuppliesLogin::confirm((string)($_POST['confirmation'] ?? ''), (string)($_POST['admin_operator'] ?? ''), ($_POST['action'] ?? '') === 'resend'); $redirect = '/supplies/'; }
        catch (RuntimeException $e) { $error = $e->getMessage(); }
    }
}
if ($redirect && !$ajax) { header('Location: ' . $redirect); exit; }
$pending = $_SESSION['supplies_login_pending'] ?? null;
$namedAdmin = !empty($pending['admin']); $replacing = !empty($pending['owner']);
$usage = $replacing ? SingleLogin::transfer(SingleLogin::scoped((string)$pending['row']['dc_user_id'], (string)($pending['operator'] ?? '')), $pending['owner']) : [];
$requestStatus = (($usage['request']['nonce'] ?? '') === ($pending['nonce'] ?? '')) ? ($usage['request']['status'] ?? '') : '';
if (!empty($usage['can_override'])) $requestStatus = 'stale';
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>$pending ? $requestStatus : 'expired', 'error'=>$error, 'redirect'=>$redirect, 'nonce'=>$pending['nonce'] ?? '', 'device'=>$usage['device'] ?? 'ไม่ทราบอุปกรณ์', 'last_seen'=>isset($usage['last_seen']) ? date('d/m/Y H:i:s', $usage['last_seen']) : '-', 'replacing'=>$replacing], JSON_UNESCAPED_UNICODE);
    exit;
}
function confirmationEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$statusLabels = [
    'stale'=>['✓', 'เข้าแทนที่ได้ทันที', 'ไม่พบอุปกรณ์ที่ยังเชื่อมต่อ เซสชันเดิมค้างอยู่ กด “เข้าสู่ระบบแทนเซสชันค้าง” ได้เลย'],
    'pending'=>['…', 'กำลังรออนุญาต', 'รอผู้ใช้งานเดิมตอบรับ หรือกดขออนุญาตอีกครั้ง'],
    'approved'=>['✓', 'ได้รับอนุญาตแล้ว', 'ผู้ใช้งานเดิมอนุญาตให้เข้าใช้งานแล้ว กดปุ่ม “เข้าสู่ระบบตอนนี้” ด้านล่าง'],
    'denied'=>['!', 'คำขอถูกปฏิเสธ', 'สามารถกดขออนุญาตอีกครั้งได้'],
];
$statusLabel = $statusLabels[$requestStatus] ?? ['', '', ''];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>เข้าสู่ระบบ · Supplies</title><link rel="stylesheet" href="/supplies/assets/css/admin-login.css?v=20261012-stale"></head><body class="login-page"><main class="login-card"><h1>Supplies</h1><h2><?= $namedAdmin ? 'ระบุชื่อผู้ดูแล' : 'ยืนยันการล็อกอินซ้ำ' ?></h2>
<div id="confirmation-error" class="alert" role="alert" <?=$error === '' ? 'hidden' : ''?>><?=confirmationEscape($error)?></div>
<?php if ($pending): ?><p>บัญชี <strong><?=confirmationEscape($pending['row']['c_full_name'])?></strong></p>
<?php if ($namedAdmin): ?><p>Admin ใช้งานพร้อมกันได้หลายคน กรุณาระบุชื่อของคุณ</p><?php endif ?>
<div id="active-usage" <?=$replacing ? '' : 'hidden'?>><p>บัญชีนี้กำลังใช้งานอยู่ ต้องได้รับอนุญาตจากผู้ใช้งานเดิมก่อนเข้าแทนที่</p><details><summary>รายละเอียดการใช้งาน / Device</summary><p id="usage-device" style="overflow-wrap:anywhere"><?=confirmationEscape($usage['device'] ?? 'ไม่ทราบอุปกรณ์')?></p><p>ใช้งานล่าสุด: <span id="usage-last-seen"><?=confirmationEscape(isset($usage['last_seen']) ? date('d/m/Y H:i:s', $usage['last_seen']) : '-')?></span></p></details></div>
<div id="confirmation-status" class="confirmation-status" data-status="<?=confirmationEscape($requestStatus)?>" role="status" aria-live="polite" aria-atomic="true" <?=$requestStatus === '' ? 'hidden' : ''?>>
<span id="confirmation-status-icon" class="confirmation-status-icon" aria-hidden="true"><?=confirmationEscape($statusLabel[0])?></span><div><strong id="confirmation-status-title"><?=confirmationEscape($statusLabel[1])?></strong><small id="confirmation-status-detail"><?=confirmationEscape($statusLabel[2])?></small></div></div>
<form id="confirmation-form" method="post" data-named-admin="<?=$namedAdmin ? '1' : '0'?>"><input type="hidden" name="csrf" value="<?=confirmationEscape($_SESSION['supplies_confirmation_csrf'])?>"><input type="hidden" name="confirmation" value="<?=confirmationEscape($pending['nonce'])?>">
<?php if ($namedAdmin): ?><label for="admin_operator">ชื่อผู้ดูแล</label><input id="admin_operator" name="admin_operator" placeholder="admin-eak หรือ admin-moo" pattern="admin-[a-z0-9][a-z0-9_-]{1,31}" maxlength="38" required value="<?=confirmationEscape($_POST['admin_operator'] ?? $pending['operator'] ?? '')?>"><p class="login-note">ชื่อแตกต่างกันจะไม่ออกจากระบบของ Admin คนอื่น</p><?php endif ?>
<button id="request-login" class="<?=in_array($requestStatus, ['approved','stale'], true) ? 'login-approved' : ''?>" name="action" value="<?=in_array($requestStatus, ['pending','denied'], true) ? 'resend' : 'replace'?>"><?= $namedAdmin && !$replacing ? 'เข้าสู่ระบบด้วยชื่อผู้ดูแล' : ($requestStatus === 'stale' ? 'เข้าสู่ระบบแทนเซสชันค้าง' : ($requestStatus === 'approved' ? 'เข้าสู่ระบบตอนนี้' : (in_array($requestStatus, ['pending','denied'], true) ? 'ขออนุญาตอีกครั้ง' : 'ขออนุญาตผู้ใช้งานเดิม'))) ?></button><button name="action" value="cancel" formnovalidate style="background:#64748b">ยกเลิก</button></form><p class="login-note">คำขอมีอายุ 2 นาที · ไม่มีผลต่อ EIS-DOCS</p><script src="/supplies/assets/js/login-confirmation.js?v=20261012-routes"></script>
<?php else: ?><p>ไม่มีคำขอยืนยันการล็อกอินที่ใช้ได้</p><a href="/NMU_permission/">กลับหน้าเข้าสู่ระบบ</a><?php endif ?></main></body></html>
