<?php

declare(strict_types=1);

function notificationSecret(): string
{
    $configured = trim((string) getenv('EIS_DOCS_WS_SECRET'));
    if ($configured !== '') return $configured;
    // ใช้รหัสฐานข้อมูลที่มีอยู่ร่วมกันเป็นกุญแจสำรอง เพื่อให้ WebSocket ใช้งานได้
    // โดยไม่ต้องเพิ่มค่า environment ใหม่ (สามารถกำหนด EIS_DOCS_WS_SECRET เพื่อ override ได้)
    $config = require dirname(__DIR__, 2) . '/config/database.php';
    $password = trim((string) ($config['password'] ?? ''));
    return $password === '' ? '' : hash('sha256', $password . '|eis-docs-websocket-v1');
}

function publishWorkflowNotification(array $notification): void
{
    $secret = notificationSecret();
    if ($secret === '') return;
    $configuredUrl = trim((string) getenv('EIS_DOCS_NOTIFICATION_API_URL'));
    $url = rtrim($configuredUrl !== '' ? $configuredUrl : 'http://127.0.0.1:8080/eis-docs', '/') . '/api/notifications/publish';
    $body = json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) return;
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-EIS-DOCS-Internal-Secret: {$secret}\r\n",
        'content' => $body,
        'timeout' => 2,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $context);
}

/** @return array<string,mixed>|null */
function createWorkflowNotification($connection, string $userId, int $docsId, int $wfOrder, string $type, string $docNo, string $message): ?array
{
    $title = $type === 'RETURNED_TO_WF1' ? 'เอกสารถูกส่งกลับให้แก้ไข' : 'มีเอกสารรอดำเนินการ';
    $sql = 'INSERT INTO dbo.DOCS_NOTIFICATION (user_id, docs_id, notification_type, wf_order, title, message) OUTPUT INSERTED.notification_id, INSERTED.created_at VALUES (?, ?, ?, ?, ?, ?)';
    $statement = sqlsrv_query($connection, $sql, [$userId, $docsId, $type, $wfOrder, $title, $message]);
    if ($statement === false) throw new RuntimeException('บันทึกการแจ้งเตือนไม่สำเร็จ');
    $row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC) ?: [];
    sqlsrv_free_stmt($statement);
    return [
        'notification_id' => (int) ($row['notification_id'] ?? 0), 'user_id' => $userId,
        'docs_id' => $docsId, 'doc_no' => $docNo, 'notification_type' => $type,
        'wf_order' => $wfOrder, 'title' => $title, 'message' => $message,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}
