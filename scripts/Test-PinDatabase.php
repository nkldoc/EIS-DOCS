<?php
if(PHP_SAPI!=='cli') exit;
if(in_array('--production',$argv,true)) putenv('APP_ENV=production');
require_once __DIR__.'/../app/src/bootstrap.php';
$db=Database::connect('docs');
$checks=[
 'PIN table read'=>'SELECT TOP 0 user_id,pin_hash,failed_attempts,locked_until,reset_allowed,pin_version FROM dbo.DOCS_USER_PIN',
 'Reset request table read'=>'SELECT TOP 0 request_id,user_id,status,approved_by,consumed_at FROM dbo.DOCS_PIN_RESET_REQUEST',
 'Notification schema'=>'SELECT TOP 0 pin_request_id FROM dbo.DOCS_NOTIFICATION',
 'Admin recipient lookup'=>'SELECT TOP 0 dc_user_id FROM NMU_DATACENTER.dbo.dc_user WHERE i_type_user=2 AND i_enable=1 AND i_delete=2',
];
$failed=false;
foreach($checks as $label=>$sql) {
    $s=sqlsrv_query($db,$sql);
    if(!$s) {$failed=true; echo 'FAIL '.$label.' SQL codes: '.implode(',',array_map(static fn($e)=>(int)$e['code'],sqlsrv_errors()?:[])).PHP_EOL;}
    else {echo 'PASS '.$label.PHP_EOL; sqlsrv_free_stmt($s);}
}
foreach(['DOCS_USER_PIN'=>['SELECT','INSERT','UPDATE'],'DOCS_PIN_RESET_REQUEST'=>['SELECT','INSERT','UPDATE'],'DOCS_NOTIFICATION'=>['SELECT','INSERT','UPDATE']] as $table=>$permissions) {
    foreach($permissions as $permission) {
        $s=sqlsrv_query($db,"SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', ?) allowed",['dbo.'.$table,$permission]);
        $ok=$s && (int)(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['allowed']??0)===1;
        if($s) sqlsrv_free_stmt($s);
        echo ($ok?'PASS ':'FAIL ').$table.' '.$permission.PHP_EOL;
        if(!$ok) $failed=true;
    }
}
$s=sqlsrv_query($db,"SELECT CASE WHEN is_nullable=1 THEN 1 ELSE 0 END allowed FROM sys.columns WHERE object_id=OBJECT_ID('dbo.DOCS_NOTIFICATION') AND name='docs_id'");
$ok=$s && (int)(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['allowed']??0)===1;
echo ($ok?'PASS ':'FAIL ').'Notification docs_id accepts NULL'.PHP_EOL;
if(!$ok) $failed=true;
sqlsrv_close($db); exit($failed?1:0);
