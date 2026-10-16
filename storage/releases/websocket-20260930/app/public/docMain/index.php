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

if ($template === false) {
    http_response_code(500);
    exit('ไม่สามารถโหลดหน้าโปรแกรมได้');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');

echo strtr($template, [
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
