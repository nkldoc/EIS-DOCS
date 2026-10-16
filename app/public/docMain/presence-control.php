<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function presenceReply(int $code, array $data): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if (!Auth::check() || !DocumentAccess::isAdministrator() || Auth::impersonating()) presenceReply(403, ['error'=>'Administrator access required']);
if (!Presence::enabled()) presenceReply(503, ['error'=>'Presence disabled']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) presenceReply(405, ['error'=>'Method not allowed']);
if ($method === 'POST' && (empty($_SESSION['app_csrf']) || !hash_equals((string)$_SESSION['app_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) presenceReply(403, ['error'=>'Invalid CSRF token']);
session_write_close();
$dir = Presence::directory() . '/control';
if (!is_dir($dir)) presenceReply(503, ['error'=>'Install production control task first']);
$lock = fopen($dir . '/request.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX)) presenceReply(503, ['error'=>'Control storage unavailable']);
$worker = is_file($dir . '/worker.json') ? json_decode(ltrim((string)file_get_contents($dir . '/worker.json'), "\xEF\xBB\xBF"), true) : [];
$ready = (int)($worker['at'] ?? 0) > time() - 180;
$pending = is_file($dir . '/restart.request');
if ($method === 'POST') {
    if (!$ready) presenceReply(503, ['error'=>'Control worker unavailable; check scheduled task EIS-Presence-Control']);
    $last = is_file($dir . '/last-request') ? (int)file_get_contents($dir . '/last-request') : 0;
    if ($pending || time() - $last < 90) presenceReply(429, ['error'=>'Restart pending or cooling down; wait 90 seconds']);
    if (file_put_contents($dir . '/restart.request', (string)time(), LOCK_EX) === false) presenceReply(503, ['error'=>'Cannot queue restart']);
    file_put_contents($dir . '/last-request', (string)time(), LOCK_EX);
    $pending = true;
}
flock($lock, LOCK_UN); fclose($lock);
$logs = [];
foreach (['control.log', 'service.log'] as $name) {
    $file = $dir . '/' . $name;
    if (!is_readable($file)) continue;
    $handle = fopen($file, 'rb');
    if (!$handle) continue;
    fseek($handle, max(0, (int)filesize($file) - 24000));
    $logs[$name] = stream_get_contents($handle); fclose($handle);
}
presenceReply(200, ['ready'=>$ready, 'pending'=>$pending, 'worker'=>$worker, 'logs'=>$logs]);
