<?php
$presencePassiveRequest = true;
require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../conf/messenger/Presence.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['code'=>'UNAUTHENTICATED']); exit; }
$_SESSION['messenger_login_nonce'] ??= bin2hex(random_bytes(16));
require_once __DIR__ . '/../conf/messenger/AdminIdentity.php';
if (NMUEISAdminIdentity::needsName()) { http_response_code(409); echo json_encode(['code'=>'ADMIN_NAME_REQUIRED','url'=>'/NMU_EIS/access/admin-identity.php']); exit; }
$identity = $_SESSION;
session_write_close();
try {
    echo json_encode(NMUEISMessenger::token('NMU-EIS', (string)$identity['user_id'], (string)($identity['user_name'] ?? ''), (string)($identity['cost_name'] ?? ''), (string)($identity['last_login'] ?? '')));
} catch (UnexpectedValueException $e) { http_response_code(401); echo json_encode(['code'=>'UNAUTHENTICATED']); }
catch (Throwable $e) { http_response_code(503); echo json_encode(['error'=>'Messenger service ยังไม่พร้อมใช้งาน']); }
