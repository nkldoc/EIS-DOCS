<?php
declare(strict_types=1);

final class DocumentAccess
{
    public static function userId(): string { return (string) (Auth::user()['user_id'] ?? ''); }
    public static function isAdministrator(): bool { return (int) (Auth::user()['user_type'] ?? 0) === 2; }
    public static function params(): array { return [self::isAdministrator() ? 1 : 0, self::userId()]; }
    public static function requireOwner($db, int $id): void
    {
        $stmt = sqlsrv_query($db, 'SELECT h.docs_id FROM dbo.DOCS_HDR h WHERE h.docs_id=? AND h.is_enabled=1 AND (?=1 OR EXISTS (SELECT 1 FROM dbo.DOCS_DTL d WHERE d.docs_id=h.docs_id AND d.wf_order=1 AND d.user_id=?))', [$id, ...self::params()]);
        if (!$stmt) throw new RuntimeException('ตรวจสอบสิทธิ์เอกสารไม่สำเร็จ');
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$row) { http_response_code(403); throw new RuntimeException('ไม่พบเอกสารหรือคุณไม่มีสิทธิ์เข้าถึงเอกสารนี้'); }
    }

    // Call inside the save transaction so signing cannot race with route replacement.
    public static function requireUnsignedWf1($db, int $id): void
    {
        $stmt = sqlsrv_query($db, 'SELECT docs_id FROM dbo.DOCS_HDR WITH (UPDLOCK,HOLDLOCK) WHERE docs_id=? AND is_enabled=1', [$id]);
        if (!$stmt || !sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) throw new RuntimeException('ไม่พบเอกสารที่ต้องการแก้ไข');
        sqlsrv_free_stmt($stmt);
        $stmt = sqlsrv_query($db, 'SELECT user_id,sign_status,signed_at FROM dbo.DOCS_DTL WITH (UPDLOCK,HOLDLOCK) WHERE docs_id=? AND wf_order=1', [$id]);
        if (!$stmt) throw new RuntimeException('ตรวจสอบสถานะ WF1 ไม่สำเร็จ');
        $first = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$first || (!self::isAdministrator() && (string)$first['user_id'] !== self::userId())) {
            http_response_code(403); throw new RuntimeException('เฉพาะ WF1 ของเอกสารนี้เท่านั้นที่แก้ไขได้');
        }
        if ($first['sign_status'] !== 'PENDING' || $first['signed_at'] !== null) {
            http_response_code(409); throw new RuntimeException('WF1 ลงนามแล้ว ไม่สามารถแก้ไขรายการลงนามได้ กรุณาโหลดข้อมูลใหม่');
        }
    }
}
