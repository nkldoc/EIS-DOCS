<?php
declare(strict_types=1);
require_once __DIR__.'/../app/src/SqlConsole.php';
require_once __DIR__.'/../app/src/SqlBackup.php';
$settings=true;$mode='ok';$results=[];$freed=0;$lastSql='';$lastParams=[];
function sqlsrv_get_config($name) { return $GLOBALS['settings']; }
function sqlsrv_configure($name,$value) { $GLOBALS['settings']=$value;return true; }
function sqlsrv_prepare($db,$sql,$params,$options) { $GLOBALS['lastSql']=$sql;$GLOBALS['lastParams']=$params;return $GLOBALS['mode']==='prepare-fail'?false:(object)['statement'=>true]; }
function sqlsrv_execute($s) { return $GLOBALS['mode']!=='execute-fail'; }
function sqlsrv_next_result($s) { return array_shift($GLOBALS['results']); }
function sqlsrv_errors() { return [['SQLSTATE'=>'42000','code'=>3201,'message'=>'Cannot open backup device. Operating system error 5(Access is denied.)']]; }
function sqlsrv_free_stmt($s) { $GLOBALS['freed']++;return true; }
function expect(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$results=[true,true,null];SqlBackup::run(null,'odd]db','D:\SQL\backup.bak');
expect($settings===true,'Warning setting restored');expect($freed===1,'Statement released after completion');expect(!$results,'Progress results fully drained');
expect(strpos($lastSql,'[odd]]db]')!==false,'Database identifier quoted');expect($lastParams===['D:\SQL\backup.bak'],'Backup path parameterized');
foreach (['prepare-fail','execute-fail','late-fail'] as $mode) {
    $settings=false;$results=$mode==='late-fail'?[true,false]:[];$before=$freed;$failed=false;
    try { SqlBackup::run(null,'db','D:\SQL\backup.bak'); } catch (RuntimeException $error) { $failed=true;expect(strpos($error->getMessage(),'OS error 5')!==false,'Actionable OS error'); }
    expect($failed,'Real SQL errors cannot report success');expect($settings===false,'Previous false setting preserved');expect($freed===$before+($mode==='prepare-fail'?0:1),'Error path statement cleanup');
}
expect(strpos(SqlBackup::errorMessage([['code'=>262]]),'BACKUP DATABASE')!==false,'SQL permission diagnosis');
expect(strpos(SqlBackup::errorMessage([['code'=>3201,'message'=>'Operating system error 3']]),'OS error 3')!==false,'Missing path diagnosis');
echo "PASS SQL backup result draining, parameterization, warning-setting restoration, real error handling and statement cleanup\n";
