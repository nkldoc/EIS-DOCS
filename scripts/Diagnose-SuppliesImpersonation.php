<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
function reportQuery($db, string $sql, array $params=[]): void {
    $s=sqlsrv_query($db,$sql,$params);
    if (!$s) { foreach(sqlsrv_errors()?:[] as $e) echo $e['message'].PHP_EOL; return; }
    while($row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)) echo json_encode($row,JSON_UNESCAPED_UNICODE).PHP_EOL;
    sqlsrv_free_stmt($s);
}
$db=Database::connect();
reportQuery($db,'SELECT DB_NAME() database_name,USER_NAME() database_user,SUSER_SNAME() login_name');
reportQuery($db,'SELECT dc_user_id,c_user_name,dc_cost_id,i_enable,i_delete FROM dbo.dc_user WHERE c_user_name=?',['phuriwach']);
reportQuery($db,"SELECT OBJECT_ID('dbo.DOCS_SUPPLIES_IMPERSONATION') ticket_table");
sqlsrv_close($db);
$db=Database::connect('erp');
reportQuery($db,'SELECT dc_user_id,c_user_name,dc_cost_id,i_enable,i_delete FROM dbo.dc_user WHERE c_user_name=?',['phuriwach']);
sqlsrv_close($db);
