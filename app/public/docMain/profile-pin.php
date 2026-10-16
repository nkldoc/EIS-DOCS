<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
if(!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
$db=null;
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)) { http_response_code(405); throw new RuntimeException('Method ไม่ถูกต้อง'); }
    if($method==='POST') {
        if(empty($_SESSION['app_csrf']) || !safeCmp($_SESSION['app_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) { http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง'); }
        $body=json_decode((string)file_get_contents('php://input'),true);
        $action=(string)($body['action']??'save');
        if(!in_array($action,['save','request_reset'],true)) throw new InvalidArgumentException('คำสั่งไม่ถูกต้อง');
        $pin=(string)($body['pin']??'');
        if($action==='save' && $pin!==(string)($body['confirm_pin']??'')) throw new InvalidArgumentException('รหัส PIN ยืนยันไม่ตรงกัน');
    }
    $db=Database::connect('docs');
    if($method==='POST') {
        if($action==='request_reset') UserPin::requestReset($db);
        else UserPin::verify($db,'',$pin);
    }
    echo json_encode(['success'=>true,...UserPin::profile($db)]);
} catch(Throwable $e) { if(http_response_code()<400) http_response_code($e instanceof InvalidArgumentException?422:500); echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
finally { if($db!==null) sqlsrv_close($db); }
