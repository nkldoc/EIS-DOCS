<?php
// Keep the existing Supplies idle timeout: background renewal is not activity.
$presencePassiveRequest = true;
require_once '../conf/config.php';
require_once dirname(__DIR__) . '/conf/presence/Presence.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'กรุณาเข้าสู่ระบบ Supplies', 'code'=>!empty($_SESSION['login_replaced']) ? 'SESSION_REPLACED' : 'UNAUTHENTICATED']); exit; }
try {
    $result = Presence::token('SUPPLIES', (string)$_SESSION['user_id'], (string)($_SESSION['user_name'] ?? ''), (string)($_SESSION['cost_name'] ?? ''), (string)($_SESSION['last_login'] ?? ''));
    session_write_close(); echo json_encode($result);
} catch (UnexpectedValueException $e) { http_response_code(401); echo json_encode(['error'=>'เซสชันสิ้นสุดแล้ว']); }
catch (Throwable $e) { http_response_code(503); echo json_encode(['error'=>'Presence service ยังไม่พร้อมใช้งาน']); }
