<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/apiUtil.php';
require_once __DIR__ . '/helpers/safeCmp.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/SingleLogin.php';
require_once __DIR__ . '/SuppliesImpersonation.php';
require_once __DIR__ . '/DocumentAccess.php';
require_once __DIR__ . '/DocumentCreationAccess.php';
require_once __DIR__ . '/AdminDocuments.php';
require_once __DIR__ . '/UserPin.php';

date_default_timezone_set('Asia/Bangkok');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__, 2) . '/storage/logs/php-error.log');

const APP_BASE_PATH = '/eis-docs';

function appUrl(string $path = ''): string
{
    return APP_BASE_PATH . ($path === '' ? '/' : '/' . ltrim($path, '/'));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Keep sessions independent of the Windows temp directory and pool identity when configured.
    $sessionDirectory = getenv('EIS_DOCS_SESSION_DIR') ?: '';
    $sessionConfigFile = dirname(__DIR__) . '/config/session.' . (require dirname(__DIR__) . '/config/runtime.php')['environment'] . '.php';
    if ($sessionDirectory === '' && is_file($sessionConfigFile)) $sessionDirectory = (string)((require $sessionConfigFile)['directory'] ?? '');
    if ($sessionDirectory !== '') {
        if (!is_dir($sessionDirectory) || !is_readable($sessionDirectory) || !is_writable($sessionDirectory)) {
            error_log('EIS-DOCS session directory is unavailable for the PHP process identity');
            http_response_code(503); exit('ระบบ session ยังไม่พร้อม กรุณาตรวจสิทธิ์โฟลเดอร์ session');
        }
        session_save_path($sessionDirectory);
    }
    session_name('EIS_DOCS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        error_log('EIS-DOCS session_start failed');
        http_response_code(503); exit('เปิด session ไม่สำเร็จ กรุณาตรวจสิทธิ์โฟลเดอร์ session');
    }
}
