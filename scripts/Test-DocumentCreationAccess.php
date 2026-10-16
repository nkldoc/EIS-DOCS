<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$db=Database::connect('docs');
function checkCreation($identity, bool $expected): void {
    global $db;
    $_SESSION['auth']=$identity;
    $allowed=true;
    try { DocumentCreationAccess::requireAllowed($db); }
    catch (RuntimeException $e) { if (http_response_code() !== 403) throw $e; $allowed=false; }
    if ($allowed !== $expected) throw new RuntimeException('Unexpected creation access');
    http_response_code(200);
}
if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('Transaction failed');
try {
    $id=2147483646;
    $s=sqlsrv_query($db, 'DELETE dbo.DOCS_DEPARTMENT_PERMISSION WHERE dc_cost_id=?', [$id]);
    if (!$s) throw new RuntimeException('Permission setup failed');
    checkCreation(['user_id'=>1,'user_type'=>2],true);
    checkCreation(['user_id'=>2,'user_type'=>3,'cost_id'=>$id],false);
    $s=sqlsrv_query($db, 'INSERT dbo.DOCS_DEPARTMENT_PERMISSION(dc_cost_id,granted_by) VALUES (?,?)', [$id,'__test__']);
    if (!$s) throw new RuntimeException('Permission grant failed');
    checkCreation(['user_id'=>2,'user_type'=>3,'cost_id'=>$id],true);
    checkCreation(['user_id'=>3,'user_type'=>1,'cost_id'=>$id],true);
    checkCreation(['user_id'=>4,'user_type'=>1,'cost_id'=>$id-1],false);
    checkCreation(['user_id'=>4,'user_type'=>1],false);
    $s=sqlsrv_query($db, 'DELETE dbo.DOCS_DEPARTMENT_PERMISSION WHERE dc_cost_id=?', [$id]);
    if (!$s) throw new RuntimeException('Permission revoke failed');
    checkCreation(['user_id'=>2,'user_type'=>3,'cost_id'=>$id],false);
    echo "PASS: administrator, grant, department isolation, missing department, revoke\n";
} finally { sqlsrv_rollback($db); sqlsrv_close($db); }
