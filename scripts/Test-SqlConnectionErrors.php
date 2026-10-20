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

foreach ([
    ['.',1433,false,'tcp:127.0.0.1,1433'],
    ['localhost',1444,false,'tcp:127.0.0.1,1444'],
    ['.',1433,true,'.'],
    ['192.168.201.3',1433,false,'192.168.201.3,1433'],
    ['server\\INSTANCE',1433,false,'server\\INSTANCE'],
    ['tcp:server,1444',1433,false,'tcp:server,1444'],
] as [$host,$port,$windows,$expected]) {
    if (Database::serverName($host,$port,$windows) !== $expected) throw new RuntimeException('Incorrect SQL endpoint');
}
echo "PASS local TCP, Windows authentication, named instance and explicit port endpoints\n";
