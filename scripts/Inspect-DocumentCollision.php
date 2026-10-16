<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$db=Database::connect('docs');
$s=sqlsrv_query($db,'SELECT docs_id,doc_no,template_name,status,created_by,created_at FROM dbo.DOCS_HDR WHERE doc_no=?',['PR25691000240']);
if (!$s) throw new RuntimeException('Cannot inspect document');
while($row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)) echo json_encode($row,JSON_UNESCAPED_UNICODE).PHP_EOL;
sqlsrv_free_stmt($s); sqlsrv_close($db);
