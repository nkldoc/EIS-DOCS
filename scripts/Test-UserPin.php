<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$id=random_int(1900000000,2000000000); $db=Database::connect('docs');
$_SESSION['auth']=['user_id'=>$id,'user_type'=>1]; unset($_SESSION['impersonation_admin']);
function pinCheck($ok,$message) { if(!$ok) throw new RuntimeException($message); }
function pinReject(callable $callback) { try {$callback();} catch(RuntimeException $e) {return;} throw new LogicException('Invalid PIN operation accepted'); }
try {
    pinCheck(!UserPin::valid('12345') && !UserPin::valid('1234567') && !UserPin::valid('abcdef') && UserPin::valid('012345'),'PIN validation failed');
    pinReject(fn()=>UserPin::verify($db,'123456'));
    UserPin::verify($db,'','012345'); pinCheck(UserPin::hasPin($db),'PIN was not created');
    $s=sqlsrv_query($db,'SELECT pin_hash FROM dbo.DOCS_USER_PIN WHERE user_id=?',[$id]);
    $hash=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['pin_hash']; sqlsrv_free_stmt($s);
    pinCheck($hash!=='012345' && password_verify('012345',$hash),'PIN not hashed');
    UserPin::verify($db,'012345');
    pinReject(fn()=>UserPin::verify($db,'999999','234567'));
    pinReject(fn()=>UserPin::verify($db,'012345','234567'));
    UserPin::requestReset($db); UserPin::requestReset($db);
    $s=sqlsrv_query($db,"SELECT request_id FROM dbo.DOCS_PIN_RESET_REQUEST WHERE user_id=? AND status='PENDING'",[$id]);
    $request=(int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['request_id'];
    pinCheck(!sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC),'Duplicate request created'); sqlsrv_free_stmt($s);
    $s=sqlsrv_query($db,"SELECT COUNT(*) n FROM dbo.DOCS_NOTIFICATION WHERE pin_request_id=? AND notification_type='PIN_RESET_REQUEST'",[$request]);
    $noticeCount=(int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']; sqlsrv_free_stmt($s);
    $s=sqlsrv_query($db,'SELECT COUNT(*) n FROM NMU_DATACENTER.dbo.dc_user WHERE i_type_user=2 AND i_enable=1 AND i_delete=2');
    pinCheck($noticeCount===(int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n'],'Not every admin notified'); sqlsrv_free_stmt($s);
    pinReject(fn()=>UserPin::approveReset($db,$request));
    $_SESSION['auth']=['user_id'=>1,'user_type'=>2]; UserPin::approveReset($db,$request);
    pinReject(fn()=>UserPin::approveReset($db,$request));
    $_SESSION['auth']=['user_id'=>$id,'user_type'=>1];
    $s=sqlsrv_query($db,"SELECT COUNT(*) n FROM dbo.DOCS_NOTIFICATION WHERE pin_request_id=? AND user_id=? AND notification_type='PIN_RESET_APPROVED'",[$request,(string)$id]);
    pinCheck((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']===1,'User approval notification missing or duplicated'); sqlsrv_free_stmt($s);
    pinCheck(UserPin::profile($db)['can_edit'],'Unlock did not allow editing');
    pinReject(fn()=>UserPin::verify($db,'012345'));
    UserPin::verify($db,'','234567');
    pinCheck(!UserPin::profile($db)['can_edit'] && UserPin::profile($db)['request_status']==='CONSUMED','Reset was not consumed');
    pinReject(fn()=>UserPin::verify($db,'234567','345678'));
    pinReject(fn()=>UserPin::verify($db,'012345'));
    UserPin::verify($db,'234567');
    $_SESSION['impersonation_admin']=['user_id'=>1,'user_type'=>2];
    pinReject(fn()=>UserPin::verify($db,'234567','345678')); unset($_SESSION['impersonation_admin']);
    for($i=0;$i<5;$i++) pinReject(fn()=>UserPin::verify($db,'999999'));
    pinReject(fn()=>UserPin::verify($db,'234567'));
    echo "PASS: locked PIN edits, one pending request, admin-only approval, one-time reset, old PIN invalidation, hashing, lockout\n";
} finally {
    sqlsrv_close($db);
    // Remove only this synthetic account's test row with the local DBA connection.
    exec('sqlcmd -S localhost -E -d EIS_DOCS -b -Q '.escapeshellarg('DELETE dbo.DOCS_NOTIFICATION WHERE pin_request_id IN (SELECT request_id FROM dbo.DOCS_PIN_RESET_REQUEST WHERE user_id='.(int)$id.'); DELETE dbo.DOCS_PIN_RESET_REQUEST WHERE user_id='.(int)$id.'; DELETE dbo.DOCS_USER_PIN WHERE user_id='.(int)$id),$cleanup,$code);
    if($code!==0) throw new RuntimeException('Test PIN cleanup failed');
}
