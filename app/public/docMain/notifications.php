<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/notification-service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!Auth::check()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE); exit; }

function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

try {
    $userId = (string) (Auth::user()['user_id'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!isset($_SESSION['app_csrf']) || !safeCmp((string) $_SESSION['app_csrf'], $csrf)) throw new RuntimeException('CSRF token ไม่ถูกต้อง');
        $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $connection = Database::connect('docs');
        if (($payload['action'] ?? '') === 'mark_all_read') {
            $statement = sqlsrv_query($connection, 'UPDATE dbo.DOCS_NOTIFICATION SET read_at = SYSDATETIME() WHERE user_id = ? AND read_at IS NULL', [$userId]);
        } else {
            $id = filter_var($payload['notification_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new InvalidArgumentException('รายการแจ้งเตือนไม่ถูกต้อง');
            $statement = sqlsrv_query($connection, 'UPDATE dbo.DOCS_NOTIFICATION SET read_at = COALESCE(read_at, SYSDATETIME()) WHERE notification_id = ? AND user_id = ?', [$id, $userId]);
        }
        if ($statement === false) throw new RuntimeException('อัปเดตการแจ้งเตือนไม่สำเร็จ');
        sqlsrv_free_stmt($statement); sqlsrv_close($connection);
        $runtime = require dirname(__DIR__, 2) . '/config/runtime.php';
        if ($runtime['environment'] === 'production') publishWorkflowNotification(['user_id'=>$userId,'notification_type'=>'READ_STATUS_CHANGED']);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE); exit;
    }
    if (($_GET['action'] ?? '') === 'ws_token') {
        $runtime = require dirname(__DIR__, 2) . '/config/runtime.php';
        $wsOverride = getenv('EIS_DOCS_WS_ENABLED');
        $wsEnabled = $wsOverride !== false && trim($wsOverride) !== ''
            ? filter_var($wsOverride, FILTER_VALIDATE_BOOL)
            : ($runtime['websocket_enabled'] ?? true);
        if (!$wsEnabled) { echo json_encode(['success'=>true,'enabled'=>false]); exit; }
        $secret = notificationSecret();
        if ($secret === '') { echo json_encode(['success' => true, 'enabled' => false], JSON_UNESCAPED_UNICODE); exit; }
        $payload = base64Url(json_encode(['u' => $userId, 'e' => time() + 300], JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'enabled' => true, 'token' => $payload . '.' . base64Url(hash_hmac('sha256', $payload, $secret, true))], JSON_UNESCAPED_UNICODE); exit;
    }
    $connection = Database::connect('docs');
    $sql = 'SELECT TOP (50) n.notification_id, n.docs_id, n.notification_type, n.wf_order, n.title, n.message, CONVERT(varchar(19), n.created_at, 120) created_at, n.read_at, h.doc_no FROM dbo.DOCS_NOTIFICATION n LEFT JOIN dbo.DOCS_HDR h ON h.docs_id = n.docs_id WHERE n.user_id = ? ORDER BY n.notification_id DESC';
    $statement = sqlsrv_query($connection, $sql, [$userId]);
    if ($statement === false) throw new RuntimeException('โหลดการแจ้งเตือนไม่สำเร็จ');
    $items = []; $unread = 0;
    while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) { $row['notification_id'] = (int) $row['notification_id']; $row['docs_id'] = $row['docs_id']===null ? null : (int) $row['docs_id']; $row['is_read'] = $row['read_at'] !== null; unset($row['read_at']); if (!$row['is_read']) $unread++; $items[] = $row; }
    sqlsrv_free_stmt($statement); sqlsrv_close($connection);
    echo json_encode(['success' => true, 'notifications' => $items, 'unread_count' => $unread], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) { http_response_code(500); echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE); }
