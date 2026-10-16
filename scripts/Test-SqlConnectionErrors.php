<?php
declare(strict_types=1);
require_once __DIR__.'/../app/src/Database.php';
foreach ([
    [['code'=>10065,'SQLSTATE'=>'08001','message'=>'unreachable host'],'เครือข่าย'],
    [['code'=>258,'SQLSTATE'=>'HYT00','message'=>'Login timeout expired'],'เครือข่าย'],
    [['code'=>18456,'SQLSTATE'=>'28000','message'=>'Login failed'],'18456'],
    [['code'=>4060,'SQLSTATE'=>'42000','message'=>'Cannot open database'],'ไม่มีสิทธิ์'],
    [['code'=>21,'SQLSTATE'=>'08001','message'=>'Encryption not supported on the client'],'TLS'],
    [['code'=>-49,'SQLSTATE'=>'IM002','message'=>'Missing driver'],'ODBC'],
] as [$error,$expected]) {
    $message=Database::consoleConnectionError([$error],'192.168.201.3,1433','sa');
    if (!str_contains($message,$expected)) throw new RuntimeException('Wrong error classification');
}
echo "PASS SQL network/authentication/permission/TLS/driver diagnostics\n";
