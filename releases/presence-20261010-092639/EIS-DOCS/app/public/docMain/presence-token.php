<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!Auth::check()) jsonResponse(['error'=>'กรุณาเข้าสู่ระบบ EIS-DOCS', 'code'=>!empty($_SESSION['login_replaced']) ? 'SESSION_REPLACED' : 'UNAUTHENTICATED'], 401);
$user = Auth::user();
try {
    $result = Presence::token('EIS-DOCS', (string)$user['user_id'], (string)($user['full_name'] ?? ''), (string)($user['cost_name'] ?? ''), (string)($user['login_at'] ?? ''), DocumentAccess::isAdministrator() && !Auth::impersonating());
    session_write_close(); jsonResponse($result);
} catch (UnexpectedValueException $e) { jsonResponse(['error'=>'เซสชันสิ้นสุดแล้ว กรุณาเข้าสู่ระบบใหม่'], 401); }
catch (Throwable $e) { jsonResponse(['error'=>'Presence service ยังไม่พร้อมใช้งาน'], 503); }
