<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Cache-Control: no-store, private');
if (!Auth::check() || !DocumentAccess::isAdministrator() || Auth::impersonating()) { http_response_code(403); exit('ไม่มีสิทธิ์'); }
$download = $_SESSION['sql_downloads'][(string)($_GET['token'] ?? '')] ?? null;
if (!$download || $download['expires'] < time() || !is_file($download['path'])) { http_response_code(404); exit('ไฟล์หมดอายุหรือไม่พร้อมดาวน์โหลด'); }
$handle = fopen($download['path'], 'rb');
if (!$handle) { http_response_code(404); exit('อ่านไฟล์ไม่ได้'); }
session_write_close();
set_time_limit(0);
header('Content-Type: '.(($download['mime'] ?? '') === 'application/zip' ? 'application/zip' : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="'.$download['name'].'"');
header('Content-Length: '.filesize($download['path']));
while (ob_get_level()) ob_end_clean();
fpassthru($handle); fclose($handle);
