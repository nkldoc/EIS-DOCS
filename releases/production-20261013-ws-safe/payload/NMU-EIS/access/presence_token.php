<?php
require_once('../conf/config.php');
require_once(__DIR__ . '/presence_common.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['error' => 'กรุณาเข้าสู่ระบบ']); exit;
}
require_once __DIR__ . '/../conf/messenger/AdminIdentity.php';
if (NMUEISAdminIdentity::needsName()) { http_response_code(409); echo json_encode(['code'=>'ADMIN_NAME_REQUIRED']); exit; }
$operator = NMUEISAdminIdentity::operator();
$secretFile = presence_dir() . '/secret.txt';
if (!is_readable($secretFile)) {
    http_response_code(503); echo json_encode(['error' => 'ยังไม่ได้ตั้งค่า Presence service']); exit;
}
$sessionKey = presence_session_key();
if (is_file(presence_dir() . '/revoked/' . $sessionKey)) {
    http_response_code(401); echo json_encode(['error' => 'เซสชันสิ้นสุดแล้ว กรุณาเข้าสู่ระบบใหม่']); exit;
}
$payload = [
    'uid' => (string)$_SESSION['user_id'] . ($operator !== '' ? '@' . $operator : ''), 'name' => $operator !== '' ? $operator . '-NMU-EIS' : (string)($_SESSION['user_name'] ?? ''),
    'department' => (string)($_SESSION['cost_name'] ?? ''), 'admin' => presence_admin(),
    'session' => $sessionKey, 'exp' => time() + 60
];
$encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $encoded, trim(file_get_contents($secretFile)));
session_write_close();
echo json_encode(['token' => $encoded . '.' . $signature, 'url' => getenv('EIS_PRESENCE_URL') ?: 'wss://dev-eis.nmu.ac.th:4001/presence']);
