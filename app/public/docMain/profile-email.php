<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ'],JSON_UNESCAPED_UNICODE); exit; }
$db=null;
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','POST'],true)) { http_response_code(405); throw new RuntimeException('Method ไม่ถูกต้อง'); }
    if ($method==='POST') {
        if (empty($_SESSION['app_csrf']) || !safeCmp($_SESSION['app_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) { http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง'); }
        $body=json_decode((string)file_get_contents('php://input'),true);
        $email=trim((string)($body['email']??''));
        if (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('กรุณาระบุอีเมลที่ถูกต้อง');
    }
    $db=Database::connect();
    // Resolve the employee through the current account, including during impersonation.
    $stmt=sqlsrv_query($db,'SELECT e.dc_emp_id,e.c_email FROM dbo.dc_user u INNER JOIN dbo.dc_emp e ON e.dc_emp_id=u.dc_emp_id WHERE u.dc_user_id=? AND u.i_enable=1 AND u.i_delete=2 AND e.i_enable=1 AND e.i_delete=2',[DocumentAccess::userId()]);
    if (!$stmt) throw new RuntimeException('โหลดอีเมลพนักงานไม่สำเร็จ');
    $employee=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt);
    if (!$employee) { http_response_code(404); throw new RuntimeException('บัญชีนี้ยังไม่มีข้อมูลพนักงานที่เชื่อมโยง กรุณาติดต่อผู้ดูแลระบบ'); }
    if ($method==='POST') {
        $stmt=sqlsrv_query($db,"UPDATE dbo.dc_emp SET c_email=? WHERE dc_emp_id=? AND i_enable=1 AND i_delete=2 AND ISNULL(c_email,'')=?",[$email,$employee['dc_emp_id'],(string)($employee['c_email']??'')]);
        if (!$stmt) throw new RuntimeException('บันทึกอีเมลไม่สำเร็จ');
        $changed=sqlsrv_rows_affected($stmt); sqlsrv_free_stmt($stmt);
        if ($changed!==1) { http_response_code(409); throw new RuntimeException('ข้อมูลอีเมลเปลี่ยนแปลงแล้ว กรุณาเปิดโปรไฟล์ใหม่'); }
        $employee['c_email']=$email;
    }
    $value=trim((string)($employee['c_email']??''));
    echo json_encode(['success'=>true,'email'=>$value,'can_save'=>$value===''],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    if(http_response_code()<400) http_response_code($e instanceof InvalidArgumentException?422:500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} finally { if($db!==null) sqlsrv_close($db); }
