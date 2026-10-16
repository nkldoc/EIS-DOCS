<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
function expectSupplies(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = Database::connect();
$stmt = sqlsrv_query($db, 'SELECT TOP 1 dc_user_id FROM dbo.dc_user WHERE i_enable=1 AND i_delete=2 AND i_type_user=2');
$admin = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt);
expectSupplies((bool)$admin, 'No admin available');
$_SESSION['auth'] = ['user_id'=>(int)$admin['dc_user_id'],'user_type'=>3];
try { SuppliesImpersonation::issue(1); throw new LogicException('Non-admin allowed'); }
catch (RuntimeException $e) { expectSupplies(http_response_code()===403, 'Non-admin rejection failed'); }
http_response_code(200);
$_SESSION['auth']['user_type'] = 2;
$stmt = sqlsrv_query($db, 'SELECT TOP 1 dc_user_id FROM dbo.dc_user WHERE i_enable=1 AND i_delete=2 AND dc_cost_id<>38');
$outside = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt);
expectSupplies((bool)$outside, 'No outside department user available');
try { SuppliesImpersonation::issue((int)$outside['dc_user_id']); throw new LogicException('Outside department allowed'); }
catch (RuntimeException $e) { expectSupplies(str_contains($e->getMessage(),'dc_cost_id = 38'), 'Missing department warning'); }
$stmt = sqlsrv_query($db, 'SELECT TOP 1 u.dc_user_id FROM dbo.dc_user u INNER JOIN NMU_ERP.dbo.dc_user s ON s.c_user_name=u.c_user_name AND s.dc_cost_id=38 AND s.i_enable=1 AND s.i_delete=2 WHERE u.dc_cost_id=38 AND u.i_enable=1 AND u.i_delete=2 ORDER BY CASE WHEN u.c_user_name=? THEN 0 ELSE 1 END,u.dc_user_id', ['phuriwach']);
if (!$stmt) { foreach (sqlsrv_errors() ?: [] as $error) fwrite(STDERR, $error['message'].PHP_EOL); exit(1); }
$target = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt);
if ($target) {
    $before = $_SESSION['auth'];
    $ticket = SuppliesImpersonation::issue((int)$target['dc_user_id']);
    expectSupplies($_SESSION['auth']===$before, 'Docs admin changed');
    try {
        for ($i=0;$i<2;$i++) {
            $stmt=sqlsrv_query($db, 'UPDATE dbo.DOCS_SUPPLIES_IMPERSONATION SET used_at=SYSUTCDATETIME() OUTPUT inserted.target_user_id WHERE ticket_hash=? AND used_at IS NULL AND expires_at>SYSUTCDATETIME()', [hash('sha256',$ticket)]);
            expectSupplies((bool)$stmt,'Consume failed');
            $row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($stmt);
            expectSupplies($i===0 ? (int)($row['target_user_id']??0)===(int)$target['dc_user_id'] : !$row, 'One-use validation failed');
        }
    } finally {
        $stmt=sqlsrv_query($db,'DELETE dbo.DOCS_SUPPLIES_IMPERSONATION WHERE ticket_hash=?',[hash('sha256',$ticket)]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }
    echo "PASS: non-admin denied, department warning, supplies mapping, docs admin preserved, one-use ticket\n";
} else echo "PASS: non-admin denied, department warning; SKIP: no matching supplies user for ticket test\n";
sqlsrv_close($db);
