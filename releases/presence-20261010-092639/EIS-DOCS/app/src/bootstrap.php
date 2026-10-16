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
    session_name('EIS_DOCS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
