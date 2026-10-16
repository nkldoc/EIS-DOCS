<?php
declare(strict_types=1);
final class SuppliesImpersonation
{
    public static function endpoint(): string
    {
        $runtime = require dirname(__DIR__).'/config/runtime.php';
        $url = trim((string)(getenv('SUPPLIES_IMPERSONATION_URL') ?: $runtime['supplies_impersonation_url']));
        if ($url !== '/supplies/access/impersonate' && !preg_match('~^https://[^/?#\s]+/supplies/access/impersonate(?:\.php)?$~D', $url)) {
            throw new RuntimeException('SUPPLIES_IMPERSONATION_URL ไม่ถูกต้อง');
        }
        return $url;
    }

    public static function issue(int $userId): string
    {
        if (!DocumentAccess::isAdministrator() || Auth::impersonating()) {
            http_response_code(403);
            throw new RuntimeException('เฉพาะ admin เท่านั้นที่สวมสิทธิ์ได้');
        }
        $db = Database::connect();
        try {
            $stmt = sqlsrv_query($db, 'SELECT c_user_name,dc_cost_id FROM dbo.dc_user WHERE dc_user_id=? AND i_enable=1 AND i_delete=2', [$userId]);
            if (!$stmt) throw new RuntimeException('ตรวจสอบบัญชีไม่สำเร็จ');
            $target = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if (!$target) throw new RuntimeException('ไม่พบบัญชีผู้ใช้ที่เปิดใช้งาน');
            if ((int)$target['dc_cost_id'] !== 38) throw new RuntimeException('ไม่สามารถ Login Supplies ได้: ผู้ใช้ไม่ได้อยู่หน่วยงานพัสดุ (dc_cost_id = 38)');
            $erp = Database::connect('erp');
            try {
                $stmt = sqlsrv_query($erp, 'SELECT dc_user_id FROM dbo.dc_user WHERE c_user_name=? AND dc_cost_id=38 AND i_enable=1 AND i_delete=2', [$target['c_user_name']]);
                if (!$stmt) {
                    error_log('Supplies account query failed: '.json_encode(sqlsrv_errors()));
                    throw new RuntimeException('ตรวจสอบบัญชี Supplies ไม่สำเร็จ กรุณาให้ผู้ดูแลตรวจสิทธิ์อ่าน NMU_ERP.dbo.dc_user ของบัญชีฐานข้อมูล EIS-DOCS');
                }
                $account = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                if (!$account) throw new RuntimeException('ไม่พบบัญชี Supplies ที่เปิดใช้งานในหน่วยงานพัสดุ');
            } finally { sqlsrv_close($erp); }
            $ticket = bin2hex(random_bytes(32));
            $stmt = sqlsrv_query($db, 'INSERT dbo.DOCS_SUPPLIES_IMPERSONATION(ticket_hash,admin_user_id,target_user_id,expires_at) VALUES (?,?,?,DATEADD(SECOND,60,SYSUTCDATETIME()))', [hash('sha256',$ticket),(int)Auth::user()['user_id'],$userId]);
            if (!$stmt) throw new RuntimeException('สร้างสิทธิ์ Login Supplies ไม่สำเร็จ กรุณาตรวจสอบการติดตั้งตารางสวมสิทธิ์');
            sqlsrv_free_stmt($stmt);
            error_log('Supplies impersonation issued: admin='.(int)Auth::user()['user_id'].' target='.$userId);
            return $ticket;
        } finally { sqlsrv_close($db); }
    }
}
