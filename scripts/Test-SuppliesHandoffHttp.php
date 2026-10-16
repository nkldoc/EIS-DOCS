<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$db=Database::connect();
$s=sqlsrv_query($db,'SELECT TOP 1 dc_user_id FROM dbo.dc_user WHERE i_type_user=2 AND i_enable=1 AND i_delete=2');
$admin=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
$s=sqlsrv_query($db,'SELECT dc_user_id FROM dbo.dc_user WHERE c_user_name=? AND dc_cost_id=38 AND i_enable=1 AND i_delete=2',['phuriwach']);
$target=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($s);
if (!$admin || !$target) throw new RuntimeException('Test accounts unavailable');
$_SESSION['auth']=['user_id'=>(int)$admin['dc_user_id'],'user_type'=>2];
$ticket=SuppliesImpersonation::issue((int)$target['dc_user_id']);
$context=stream_context_create(['http'=>['follow_location'=>0,'ignore_errors'=>true,'timeout'=>15]]);
$body=file_get_contents('https://dev-eis.nmu.ac.th/supplies/access/impersonate?ticket='.rawurlencode($ticket),false,$context);
$headers=$http_response_header ?? [];
$status=$headers[0]??'';
if (!str_contains($status,'302')) {
    echo 'FAIL: '.$status.PHP_EOL;
    echo strip_tags((string)$body).PHP_EOL;
    exit(1);
}
$cookie='';
foreach($headers as $header) if (preg_match('/^Set-Cookie:\s*(NMU_SUPPLIES_SESSID=[^;]+)/i',$header,$match)) $cookie=$match[1];
if ($cookie==='') throw new RuntimeException('Missing supplies session cookie');
$context=stream_context_create(['http'=>['follow_location'=>0,'ignore_errors'=>true,'timeout'=>15,'header'=>'Cookie: '.$cookie]]);
$sessionId=substr($cookie,strpos($cookie,'=')+1);
$sessionFile='D:/EIS-ERP/storage/sessions/sess_'.$sessionId;
$sessionData=is_file($sessionFile)?file_get_contents($sessionFile):'';
if (!str_contains($sessionData,'user_id|i:'.(int)$target['dc_user_id'].';') || !str_contains($sessionData,'dc_cost_id|i:38;')) throw new RuntimeException('Supplies session identity mismatch');
echo "PASS: phuriwach handoff returned 302 and created a Supplies session\n";
$s=sqlsrv_query($db,'DELETE dbo.DOCS_SUPPLIES_IMPERSONATION WHERE ticket_hash=?',[hash('sha256',$ticket)]);
if($s)sqlsrv_free_stmt($s);
sqlsrv_close($db);
