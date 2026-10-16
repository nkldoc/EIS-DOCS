<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!Auth::check()) { http_response_code(401); echo json_encode(['allowed'=>false]); exit; }
$db=null;
try {
    $id=filter_var($_GET['docs_id']??null,FILTER_VALIDATE_INT);
    $docId=(string)($_GET['docId']??'');
    $db=Database::connect('docs');
    $s=sqlsrv_query($db,"SELECT h.docs_id FROM dbo.DOCS_HDR h WHERE h.docs_id=? AND h.is_enabled=1 AND h.status NOT IN ('DRAFT','INACTIVE') AND (h.pdf_doc_id=? OR h.file_path=?) AND EXISTS (SELECT 1 FROM dbo.DOCS_DTL d WHERE d.docs_id=h.docs_id AND d.user_id=? AND d.sign_action=1 AND d.sign_status='PENDING' AND d.wf_order=(SELECT MIN(wf_order) FROM dbo.DOCS_DTL WHERE docs_id=h.docs_id AND sign_status='PENDING'))",[$id?:0,$docId,$docId,DocumentAccess::userId()]);
    if (!$s) throw new RuntimeException('ตรวจสอบสิทธิ์ไม่สำเร็จ');
    $allowed=(bool)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
    echo json_encode(['allowed'=>$allowed,'user_id'=>DocumentAccess::userId()]);
} catch(Throwable $e) { http_response_code(500); echo json_encode(['allowed'=>false]); }
finally {if($db!==null)sqlsrv_close($db);}
