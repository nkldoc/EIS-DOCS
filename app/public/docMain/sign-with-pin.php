<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
if(!Auth::check()) { http_response_code(401); echo json_encode(['message'=>'กรุณาเข้าสู่ระบบ'],JSON_UNESCAPED_UNICODE); exit; }
$db=null; $transaction=false;
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') { http_response_code(405); throw new RuntimeException('Method ไม่ถูกต้อง'); }
    if(empty($_SESSION['app_csrf']) || !safeCmp($_SESSION['app_csrf'],(string)($_POST['csrf']??''))) { http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง'); }
    $id=filter_var($_POST['docs_id']??null,FILTER_VALIDATE_INT);
    if(!$id || (string)($_POST['user_id']??'')!==DocumentAccess::userId()) { http_response_code(403); throw new RuntimeException('บัญชีผู้ลงนามไม่ถูกต้อง'); }
    $db=Database::connect('docs');
    $verifiedPinVersion=UserPin::verify($db,(string)($_POST['pin']??''));
    if(!sqlsrv_begin_transaction($db)) throw new RuntimeException('ตรวจสอบเอกสารไม่สำเร็จ');
    $transaction=true;
    $pinState=sqlsrv_query($db,'SELECT pin_version FROM dbo.DOCS_USER_PIN WITH (HOLDLOCK) WHERE user_id=? AND reset_allowed=0 AND pin_version=?',[DocumentAccess::userId(),$verifiedPinVersion]);
    if(!$pinState) throw new RuntimeException('ตรวจสอบสถานะ PIN ไม่สำเร็จ');
    $pinRow=sqlsrv_fetch_array($pinState,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($pinState);
    if(!$pinRow) {http_response_code(403); throw new RuntimeException('กรุณาตั้ง PIN ใหม่หลัง admin ปลดล็อก');}
    $s=sqlsrv_query($db,"SELECT h.docs_id FROM dbo.DOCS_HDR h WITH (UPDLOCK,HOLDLOCK) WHERE h.docs_id=? AND h.is_enabled=1 AND h.status NOT IN ('DRAFT','INACTIVE') AND (h.pdf_doc_id=? OR h.file_path=?) AND EXISTS (SELECT 1 FROM dbo.DOCS_DTL d WHERE d.docs_id=h.docs_id AND d.user_id=? AND d.sign_status='PENDING' AND d.wf_order=(SELECT MIN(wf_order) FROM dbo.DOCS_DTL WHERE docs_id=h.docs_id AND sign_status='PENDING'))",[$id,(string)($_POST['docId']??''),(string)($_POST['docId']??''),DocumentAccess::userId()]);
    if(!$s) throw new RuntimeException('ตรวจสอบสิทธิ์ลงนามไม่สำเร็จ');
    $row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
    if(!$row) { http_response_code(403); throw new RuntimeException('ไม่มีสิทธิ์ลงนามเอกสารในลำดับนี้ หรือเอกสารถูกยกเลิก'); }
    $fields=$_POST; unset($fields['pin'],$fields['csrf'],$fields['docs_id']);
    foreach($_FILES as $name=>$file) {
        if($name!=='signatureImage' || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('ไฟล์ลายเซ็นไม่ถูกต้อง');
        $fields[$name]=new CURLFile($file['tmp_name'],'image/png','signature.png');
    }
    $curl=curl_init('http://127.0.0.1:8080/eis-docs/api/pdf/sign');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90]);
    $response=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    if($response===false || $status===0) throw new RuntimeException('ติดต่อบริการลงนามไม่สำเร็จ');
    if(!sqlsrv_commit($db)) throw new RuntimeException('ยืนยันการตรวจสอบเอกสารไม่สำเร็จ'); $transaction=false;
    if($status>=200 && $status<300) $_SESSION['pin_sign_grants'][$id]=['user_id'=>DocumentAccess::userId(),'expires'=>time()+300,'pin_version'=>(int)$pinRow['pin_version']];
    http_response_code($status); echo $response;
} catch(Throwable $e) { if($transaction) sqlsrv_rollback($db); if(http_response_code()<400) http_response_code(500); echo json_encode(['message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
finally { if($db!==null) sqlsrv_close($db); }
