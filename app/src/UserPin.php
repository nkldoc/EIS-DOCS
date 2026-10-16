<?php
declare(strict_types=1);
final class UserPin
{
    public static function valid(string $pin): bool { return preg_match('/^[0-9]{6}$/D',$pin)===1; }
    private static function query($db,string $sql,array $params=[]) {
        $s=sqlsrv_query($db,$sql,$params);
        if(!$s) {
            $errors=sqlsrv_errors()?:[];
            $codes=array_map(static fn($e)=>(int)($e['code']??0),$errors);
            // Never log SQL parameters: they may contain a PIN or its hash.
            error_log('PIN database failure: '.json_encode(array_map(static fn($e)=>['code'=>$e['code']??null,'state'=>$e['SQLSTATE']??null],$errors)));
            if(array_intersect($codes,[207,208])) throw new RuntimeException('ฐานข้อมูล PIN ยังติดตั้งไม่ครบ กรุณารัน database/production/04_PIN_schema_permissions.sql บน EIS_DOCS');
            if(in_array(229,$codes,true)) throw new RuntimeException('บัญชีฐานข้อมูลไม่มีสิทธิ์ใช้งานตาราง PIN กรุณารัน database/production/04_PIN_schema_permissions.sql ด้วยบัญชี DBA');
            throw new RuntimeException('ตรวจสอบ PIN ไม่สำเร็จ กรุณาดูรหัสข้อผิดพลาดใน storage/logs/php-error.log');
        }
        return $s;
    }
    public static function hasPin($db): bool {
        $s=self::query($db,'SELECT user_id FROM dbo.DOCS_USER_PIN WHERE user_id=?',[DocumentAccess::userId()]);
        $row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s); return (bool)$row;
    }
    public static function profile($db): array {
        $s=self::query($db,"SELECT p.reset_allowed,(SELECT TOP 1 r.status FROM dbo.DOCS_PIN_RESET_REQUEST r WHERE r.user_id=p.user_id ORDER BY request_id DESC) request_status FROM dbo.DOCS_USER_PIN p WHERE p.user_id=?",[DocumentAccess::userId()]);
        $row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
        return ['has_pin'=>(bool)$row,'can_edit'=>!Auth::impersonating() && (!$row || (bool)$row['reset_allowed']), 'can_request'=>!Auth::impersonating() && (bool)$row && !$row['reset_allowed'] && ($row['request_status']??'')!=='PENDING', 'request_status'=>$row['request_status']??null];
    }
    public static function requestReset($db): void {
        if(Auth::impersonating()) {http_response_code(403); throw new RuntimeException('ไม่สามารถแจ้งลืม PIN ขณะสวมสิทธิ์');}
        if(!sqlsrv_begin_transaction($db)) throw new RuntimeException('ส่งคำขอไม่สำเร็จ');
        try {
            $s=self::query($db,'SELECT reset_allowed FROM dbo.DOCS_USER_PIN WITH (UPDLOCK,HOLDLOCK) WHERE user_id=?',[DocumentAccess::userId()]);
            $row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
            if(!$row || $row['reset_allowed']) throw new RuntimeException('คุณสามารถตั้ง PIN ได้อยู่แล้ว');
            $s=self::query($db,"SELECT request_id FROM dbo.DOCS_PIN_RESET_REQUEST WHERE user_id=? AND status='PENDING'",[DocumentAccess::userId()]);
            $pending=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
            if(!$pending) {
                $s=self::query($db,'INSERT dbo.DOCS_PIN_RESET_REQUEST(user_id) OUTPUT INSERTED.request_id VALUES (?)',[DocumentAccess::userId()]);
                $requestId=(int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['request_id']; sqlsrv_free_stmt($s);
                $name=trim((string)(Auth::user()['full_name']??Auth::user()['username']??DocumentAccess::userId()));
                $s=self::query($db,"INSERT dbo.DOCS_NOTIFICATION(user_id,docs_id,notification_type,title,message,pin_request_id) SELECT CONVERT(nvarchar(100),dc_user_id),NULL,'PIN_RESET_REQUEST',N'มีคำขอปลดล็อก PIN',?,? FROM NMU_DATACENTER.dbo.dc_user WHERE i_type_user=2 AND i_enable=1 AND i_delete=2",[$name.' แจ้งลืม PIN กรุณาตรวจสอบและปลดล็อกให้ตั้งรหัสใหม่',$requestId]); sqlsrv_free_stmt($s);
            }
            if(!sqlsrv_commit($db)) throw new RuntimeException('ส่งคำขอไม่สำเร็จ');
        } catch(Throwable $e) {sqlsrv_rollback($db); throw $e;}
    }
    public static function approveReset($db,int $requestId): void {
        AdminDocuments::requireAdmin();
        $s=self::query($db,'SELECT user_id FROM dbo.DOCS_PIN_RESET_REQUEST WHERE request_id=?',[$requestId]);
        $request=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
        if(!$request) throw new RuntimeException('ไม่พบคำขอ');
        if(!sqlsrv_begin_transaction($db)) throw new RuntimeException('ปลดล็อกไม่สำเร็จ');
        try {
            $s=self::query($db,'SELECT user_id FROM dbo.DOCS_USER_PIN WITH (UPDLOCK,HOLDLOCK) WHERE user_id=?',[$request['user_id']]);
            $pin=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
            if(!$pin) throw new RuntimeException('ไม่พบข้อมูล PIN');
            $s=self::query($db,"UPDATE dbo.DOCS_PIN_RESET_REQUEST SET status='APPROVED',approved_by=?,approved_at=SYSDATETIME() WHERE request_id=? AND status='PENDING'",[DocumentAccess::userId(),$requestId]);
            $changed=sqlsrv_rows_affected($s); sqlsrv_free_stmt($s);
            if($changed!==1) throw new RuntimeException('คำขอนี้ดำเนินการแล้ว กรุณาโหลดใหม่');
            $s=self::query($db,'UPDATE dbo.DOCS_USER_PIN SET reset_allowed=1,pin_version=pin_version+1,failed_attempts=0,locked_until=NULL WHERE user_id=?',[$request['user_id']]); sqlsrv_free_stmt($s);
            $s=self::query($db,"UPDATE dbo.DOCS_NOTIFICATION SET read_at=COALESCE(read_at,SYSDATETIME()) WHERE pin_request_id=? AND notification_type='PIN_RESET_REQUEST'",[$requestId]); sqlsrv_free_stmt($s);
            $s=self::query($db,"INSERT dbo.DOCS_NOTIFICATION(user_id,docs_id,notification_type,title,message,pin_request_id) VALUES (?,NULL,'PIN_RESET_APPROVED',N'ปลดล็อก PIN แล้ว',N'admin ปลดล็อกให้แล้ว เปิดโปรไฟล์เพื่อตั้ง PIN ใหม่ได้หนึ่งครั้ง',?)",[(string)$request['user_id'],$requestId]); sqlsrv_free_stmt($s);
            if(!sqlsrv_commit($db)) throw new RuntimeException('ปลดล็อกไม่สำเร็จ');
            error_log('PIN reset approved: admin='.DocumentAccess::userId().' request='.$requestId.' user='.$request['user_id']);
        } catch(Throwable $e) {sqlsrv_rollback($db); throw $e;}
    }
    public static function verify($db,string $pin,?string $replacement=null): int {
        if($replacement!==null && Auth::impersonating()) { http_response_code(403); throw new RuntimeException('กรุณากลับสู่บัญชีของตนเองก่อนแก้ไข PIN'); }
        if($replacement!==null && !self::valid($replacement)) throw new InvalidArgumentException('PIN ต้องเป็นตัวเลข 6 หลัก');
        if(!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่มตรวจสอบ PIN ไม่สำเร็จ');
        $error='';
        try {
            $s=self::query($db,'SELECT pin_hash,pin_version,reset_allowed,failed_attempts,CASE WHEN locked_until>SYSDATETIME() THEN 1 ELSE 0 END locked FROM dbo.DOCS_USER_PIN WITH (UPDLOCK,HOLDLOCK) WHERE user_id=?',[DocumentAccess::userId()]);
            $row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
            if(!$row) {
                if($replacement===null) $error='กรุณาตั้ง PIN 6 หลักในโปรไฟล์ก่อนลงนาม';
                else { $s=self::query($db,'INSERT dbo.DOCS_USER_PIN(user_id,pin_hash) VALUES (?,?)',[DocumentAccess::userId(),password_hash($replacement,PASSWORD_DEFAULT)]); sqlsrv_free_stmt($s); }
            } elseif($replacement!==null) {
                if(!$row['reset_allowed']) $error='PIN ตั้งแล้ว ไม่สามารถแก้ไขได้ กรุณาแจ้งลืมรหัสให้ admin ปลดล็อก';
                else {
                    $s=self::query($db,'UPDATE dbo.DOCS_USER_PIN SET pin_hash=?,reset_allowed=0,pin_version=pin_version+1,failed_attempts=0,locked_until=NULL,updated_at=SYSDATETIME() WHERE user_id=?',[password_hash($replacement,PASSWORD_DEFAULT),DocumentAccess::userId()]); sqlsrv_free_stmt($s);
                    $s=self::query($db,"UPDATE dbo.DOCS_PIN_RESET_REQUEST SET status='CONSUMED',consumed_at=SYSDATETIME() WHERE user_id=? AND status='APPROVED'",[DocumentAccess::userId()]); sqlsrv_free_stmt($s);
                    unset($_SESSION['pin_sign_grants']);
                }
            } elseif($row['reset_allowed']) $error='admin ปลดล็อกแล้ว กรุณาตั้ง PIN ใหม่ในโปรไฟล์ก่อนลงนาม';
            elseif($row['locked']) $error='กรอก PIN ไม่ถูกต้องหลายครั้ง กรุณารอ 5 นาที';
            elseif(!self::valid($pin) || !password_verify($pin,$row['pin_hash'])) {
                $s=self::query($db,'UPDATE dbo.DOCS_USER_PIN SET failed_attempts=CASE WHEN failed_attempts>=4 THEN 0 ELSE failed_attempts+1 END,locked_until=CASE WHEN failed_attempts>=4 THEN DATEADD(minute,5,SYSDATETIME()) ELSE NULL END WHERE user_id=?',[DocumentAccess::userId()]); sqlsrv_free_stmt($s); $error='PIN ไม่ถูกต้อง';
            } else {
                $s=self::query($db,'UPDATE dbo.DOCS_USER_PIN SET pin_hash=?,failed_attempts=0,locked_until=NULL,updated_at=SYSDATETIME() WHERE user_id=?',[$replacement===null?$row['pin_hash']:password_hash($replacement,PASSWORD_DEFAULT),DocumentAccess::userId()]); sqlsrv_free_stmt($s);
            }
            if(!sqlsrv_commit($db)) throw new RuntimeException('บันทึก PIN ไม่สำเร็จ');
        } catch(Throwable $e) { sqlsrv_rollback($db); throw $e; }
        if($error!=='') { http_response_code(403); throw new RuntimeException($error); }
        return $row ? (int)$row['pin_version']+($replacement!==null?1:0) : 1;
    }
}
