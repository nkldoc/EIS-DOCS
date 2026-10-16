<?php
declare(strict_types=1);
final class DocumentCreationAccess
{
    public static function allowed($db): bool
    {
        if (DocumentAccess::isAdministrator()) return true;
        $stmt = sqlsrv_query($db, 'SELECT dc_cost_id FROM dbo.DOCS_DEPARTMENT_PERMISSION WHERE dc_cost_id=?', [(int)(Auth::user()['cost_id'] ?? 0)]);
        if (!$stmt) throw new RuntimeException('ตรวจสอบสิทธิ์จัดทำเอกสารไม่สำเร็จ กรุณาติดตั้งฐานข้อมูลสิทธิ์หน่วยงาน');
        $allowed = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return (bool) $allowed;
    }

    public static function requireAllowed($db): void
    {
        if (!self::allowed($db)) { http_response_code(403); throw new RuntimeException('หน่วยงานของคุณยังไม่ได้รับสิทธิ์จัดทำเอกสาร กรุณาติดต่อผู้ดูแลระบบ'); }
    }
}
