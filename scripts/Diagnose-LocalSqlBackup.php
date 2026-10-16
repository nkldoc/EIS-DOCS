<?php
declare(strict_types=1);
require_once __DIR__.'/../app/src/Database.php';
require_once __DIR__.'/../app/src/SqlConsole.php';
require_once __DIR__.'/../app/src/SqlBackup.php';
date_default_timezone_set('Asia/Bangkok');
putenv('APP_ENV=local');
$db=Database::connect('EIS_DOCS',true,true,'local');
$read=sqlsrv_query($db,"SELECT IS_SRVROLEMEMBER('sysadmin') AS sysadmin,HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','BACKUP DATABASE') AS can_backup");
if ($read) { echo json_encode(sqlsrv_fetch_array($read,SQLSRV_FETCH_ASSOC)).PHP_EOL;sqlsrv_free_stmt($read); }
if (!in_array('--backup',$argv,true)) { sqlsrv_close($db);exit; }
$plan=SqlBackup::plan(SqlBackup::settings('local'),'EIS_DOCS','local','EIS-DOCS','local-driver-check-'.date('Ymd-His'));
SqlBackup::prepare($plan);
try {
    SqlBackup::run($db,'EIS_DOCS',$plan['sql_path']);
    $bak=$plan['web_directory'].'/'.$plan['base'].'.bak';
    clearstatcache(true,$bak);echo 'bakBytes='.json_encode(is_file($bak)?filesize($bak):0).PHP_EOL;
    $zip=$plan['web_directory'].'/'.$plan['base'].'.zip';
    echo 'zipBytes='.SqlBackup::archive($bak,$zip,basename($bak)).PHP_EOL;
    $verify=new ZipArchive();if ($verify->open($zip)!==true) throw new RuntimeException('ZIP reopen failed');
    $contents=$verify->getFromName(basename($bak));$verify->close();
    if (!is_string($contents) || hash('sha256',$contents)!==hash_file('sha256',$bak)) throw new RuntimeException('ZIP contents differ from backup');
    echo 'PASS real local BACKUP completion, ZIP creation and byte comparison; warningHandlingRestored='.json_encode(sqlsrv_get_config('WarningsReturnAsErrors')).PHP_EOL;
    echo 'zipPath='.$zip.PHP_EOL;
} finally {sqlsrv_close($db);}
