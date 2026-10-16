<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
function ensureIdentity(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=Database::connect();
$stmt=sqlsrv_query($db, 'SELECT TOP 1 dc_user_id,dc_emp_id,dc_cost_id,c_user_name,c_full_name,i_type_user FROM dbo.dc_user WHERE i_enable=1 AND i_delete=2 AND i_type_user=2 ORDER BY dc_user_id');
ensureIdentity((bool)$stmt,'Admin query failed');
$admin=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt);
ensureIdentity((bool)$admin,'No enabled administrator for integration test');
$stmt=sqlsrv_query($db, 'SELECT TOP 1 dc_user_id,dc_cost_id,i_type_user FROM dbo.dc_user WHERE i_enable=1 AND i_delete=2 AND i_type_user<>2 ORDER BY dc_user_id');
ensureIdentity((bool)$stmt,'User query failed');
$target=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt); sqlsrv_close($db);
ensureIdentity((bool)$target,'No enabled user for integration test');
$_SESSION['auth']=['user_id'=>(int)$target['dc_user_id'],'user_type'=>3];
try { Auth::impersonate((int)$admin['dc_user_id']); throw new LogicException('Non-admin was allowed'); }
catch (RuntimeException $e) { ensureIdentity(http_response_code()===403,'Non-admin rejection failed'); }
http_response_code(200);
$_SESSION['auth']=['user_id'=>(int)$admin['dc_user_id'],'user_type'=>2];
$_SESSION['app_csrf']='original';
$sessionBefore=session_id();
Auth::impersonate((int)$target['dc_user_id']);
ensureIdentity(Auth::impersonating(),'Missing impersonation state');
ensureIdentity((int)Auth::user()['user_id']===(int)$target['dc_user_id'],'Wrong effective user');
ensureIdentity((int)Auth::user()['cost_id']===(int)$target['dc_cost_id'],'Wrong department');
ensureIdentity(!DocumentAccess::isAdministrator(),'Admin access leaked');
ensureIdentity(session_id()!==$sessionBefore && $_SESSION['app_csrf']!=='original','Session/token not rotated');
try { Auth::impersonate((int)$admin['dc_user_id']); throw new LogicException('Nested impersonation allowed'); }
catch (RuntimeException $e) { ensureIdentity(http_response_code()===403,'Nested rejection failed'); }
http_response_code(200);
$token=$_SESSION['app_csrf'];
Auth::stopImpersonating();
ensureIdentity(!Auth::impersonating() && (int)Auth::user()['user_id']===(int)$admin['dc_user_id'],'Admin restore failed');
ensureIdentity($_SESSION['app_csrf']!==$token,'Restore token not rotated');
Auth::logout();
ensureIdentity(!Auth::check() && !isset($_SESSION['impersonation_admin']),'Logout did not clear identities');
echo "PASS: non-admin denied, target permissions, session rotation, nested switch denied, restore, logout\n";
