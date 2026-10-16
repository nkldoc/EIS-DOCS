<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::requireLogin();
$_SESSION['app_csrf'] ??= bin2hex(random_bytes(32));

$user = Auth::user();
$fullName = trim((string) ($user['full_name'] ?? 'ผู้ใช้งาน'));
$initial = function_exists('mb_substr') ? mb_substr($fullName, 0, 1, 'UTF-8') : substr($fullName, 0, 1);
$runtime = require dirname(__DIR__, 2) . '/config/runtime.php';
$webSocketUrl = trim((string) getenv('EIS_DOCS_WS_URL'));
if ($webSocketUrl === '') $webSocketUrl = $runtime['websocket_url'];
$template = file_get_contents(__DIR__ . '/index.html');
if ($template !== false && $runtime['environment'] === 'production') {
    $template = str_replace('{{DEPARTMENT_CONFIG_MENU}}', '<a class="menu-item" href="https://eis.nmu.ac.th/supplies/" target="_blank" rel="noopener"><span class="menu-item-icon">↗</span><span class="menu-item-text"><span class="menu-item-title">ระบบพัสดุ Supplies</span></span></a>{{DEPARTMENT_CONFIG_MENU}}', $template);
}

if ($template === false) {
    http_response_code(500);
    exit('ไม่สามารถโหลดหน้าโปรแกรมได้');
}

$canCreateDocument = DocumentAccess::isAdministrator();
if (!$canCreateDocument) {
    $permissionDb = null;
    try {
        $permissionDb = Database::connect('docs');
        $canCreateDocument = DocumentCreationAccess::allowed($permissionDb);
    } catch (Throwable $error) {
        error_log('Document creation menu permission: ' . $error->getMessage());
    } finally {
        if ($permissionDb !== null) sqlsrv_close($permissionDb);
    }
}
if (!$canCreateDocument) {
    $template = preg_replace('/<button\b[^>]*data-view="create"[^>]*>.*?<\/button>/s', '', $template);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');

$impersonationMenu = '';
if (Auth::impersonating()) {
    $impersonationMenu = '<div style="padding:14px;background:#fff3cd;color:#664d03">กำลังสวมสิทธิ์: ' . htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') . '<form method="post" action="impersonate.php"><input type="hidden" name="action" value="restore"><input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['app_csrf'], ENT_QUOTES, 'UTF-8') . '"><button type="submit" class="btn btn-subtle">กลับสู่บัญชี admin</button></form></div>';
} elseif (DocumentAccess::isAdministrator()) {
    $impersonationMenu = '<a href="impersonate.php" style="display:block;padding:14px;color:inherit;text-decoration:none">👤 สวมสิทธิ์ผู้ใช้</a><a href="admin-documents.php" style="display:block;padding:14px;color:inherit;text-decoration:none">🗂️ จัดการรายการเอกสาร</a>';
    $pendingPinRequests = 0;
    $pinRequestsDb = null;
    try {
        $pinRequestsDb = Database::connect('docs');
        $pinRequestsStmt = sqlsrv_query($pinRequestsDb, "SELECT COUNT(*) n FROM dbo.DOCS_PIN_RESET_REQUEST WHERE status='PENDING'");
        if ($pinRequestsStmt) { $pendingPinRequests = (int)sqlsrv_fetch_array($pinRequestsStmt,SQLSRV_FETCH_ASSOC)['n']; sqlsrv_free_stmt($pinRequestsStmt); }
    } catch (Throwable $e) {error_log('PIN reset menu: '.$e->getMessage());}
    finally {if($pinRequestsDb!==null) sqlsrv_close($pinRequestsDb);}
    $impersonationMenu .= '<a href="admin-pin-requests.php" style="display:block;padding:14px;color:inherit;text-decoration:none">🔔 คำขอปลดล็อก PIN' . ($pendingPinRequests ? ' <strong>('.$pendingPinRequests.')</strong>' : '') . '</a>';
}
$template = str_replace('{{DEPARTMENT_CONFIG_MENU}}', $impersonationMenu . '{{DEPARTMENT_CONFIG_MENU}}', $template);

echo strtr($template, [
    '{{PRESENCE_TRACKING_SCRIPT}}' => Presence::script(),
    '{{ADMIN_USER_ONLINE_MENU}}' => Presence::enabled() && DocumentAccess::isAdministrator() && !Auth::impersonating()
        ? '<a class="menu-item" href="user-online.php" target="_blank" rel="noopener" title="ตรวจสอบผู้ใช้ออนไลน์ EIS-DOCS และ Supplies"><span class="menu-item-icon" aria-hidden="true">🟢</span><span class="menu-item-text"><span class="menu-item-title">ผู้ใช้งานออนไลน์ (Admin)</span><span class="menu-item-desc">EIS-DOCS / Supplies · เวลาเข้า–ออก ↗</span></span></a>'
        : '',
    '{{DEPARTMENT_CONFIG_MENU}}' => DocumentAccess::isAdministrator() ? '<a href="department-config.php" style="display:block;padding:14px;color:inherit;text-decoration:none">⚙️ กำหนดสิทธิ์หน่วยงาน</a>' : '',
    '{{USER_INITIAL}}' => htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'),
    '{{USER_ID}}' => htmlspecialchars((string) ($user['user_id'] ?? ''), ENT_QUOTES, 'UTF-8'),
    '{{USER_FULL_NAME}}' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    '{{USER_ROLE}}' => htmlspecialchars((string) ($user['cost_name'] ?? 'ผู้ใช้งานระบบ'), ENT_QUOTES, 'UTF-8'),
    '{{USER_NAME}}' => htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
    '{{USER_COST_CODE}}' => htmlspecialchars((string) ($user['cost_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
    '{{LOGOUT_URL}}' => htmlspecialchars(appUrl('logout.php'), ENT_QUOTES, 'UTF-8'),
    '{{APP_CSRF}}' => htmlspecialchars($_SESSION['app_csrf'], ENT_QUOTES, 'UTF-8'),
    '{{NOTIFICATION_WS_URL}}' => htmlspecialchars($webSocketUrl, ENT_QUOTES, 'UTF-8'),
]);
