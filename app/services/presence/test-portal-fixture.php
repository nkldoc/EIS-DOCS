<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$action = $argv[1] ?? '';
if ($action === 'deny') {
    if (!preg_match('/^[a-f0-9]{48}$/D', $argv[2] ?? '')) throw new RuntimeException('Invalid fixture session');
    session_name('PHPSESSID'); session_save_path('C:/xampp/htdocs/NMU_permission/tmp/sessions'); session_id($argv[2]); session_start();
    $_SESSION['i_permit_supplies']=0; $_SESSION['i_permit_procure']=0; session_write_close(); echo '{}'; exit;
}
if ($action === 'read') {
    session_name($argv[2]); session_save_path('D:/EIS-ERP/storage/sessions'); session_id($argv[3]); session_start(['read_and_close'=>true]);
    echo json_encode(['uid'=>$_SESSION['user_id']??null,'center'=>$_SESSION['dc_center_user']??null,'instance'=>$_SESSION['login_instance']??null]); exit;
}
$source=file_get_contents('C:/xampp/htdocs/NMU_permission/conf/config.php'); $values=[];
foreach (['DB_SERVER','DB_NAME','DB_USER','DB_PASS','DB_CHARSET'] as $key) {preg_match('/define\("'. $key .'",\s*"([^"]*)"\)/',$source,$match);$values[$key]=$match[1]??'';}
$db=sqlsrv_connect($values['DB_SERVER'],['Database'=>$values['DB_NAME'],'UID'=>$values['DB_USER'],'PWD'=>$values['DB_PASS'],'CharacterSet'=>'UTF-8','Driver'=>'ODBC Driver 18 for SQL Server','Encrypt'=>true,'TrustServerCertificate'=>true]);
if (!$db) throw new RuntimeException('Fixture SQL connection failed');
$stmt=sqlsrv_query($db,'SELECT TOP 100 c.dc_user_id,c.i_type_user,s.dc_user_id supplies_id,p.dc_user_id procure_id FROM NMU_DATACENTER.dbo.dc_user c JOIN NMU_DATACENTER.dbo.dc_user_permit permit ON permit.dc_user_id=c.dc_user_id JOIN NMU_ERP.dbo.dc_user s ON s.c_user_name=c.c_user_name JOIN EIS_PROCURE.dbo.dc_user p ON p.c_user_name=c.c_user_name WHERE c.i_enable=1 AND c.i_delete=2 AND s.i_enable=1 AND s.i_delete=2 AND p.i_enable=1 AND p.i_delete=2 AND permit.i_permit_supplies>0 AND permit.i_permit_procure>0 ORDER BY c.dc_user_id');
if (!$stmt) throw new RuntimeException('Fixture query failed');
$row=null;
while ($candidate=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    $registry='D:/EIS-DOCS/storage/presence/logins/'.hash('sha256','SUPPLIES|'.$candidate['supplies_id']).'.json';
    if (!file_exists($registry)) {$row=$candidate;break;}
}
if (!$row) throw new RuntimeException('No unused permitted account available for read-only login test');
$id=bin2hex(random_bytes(24));session_name('PHPSESSID');session_save_path('C:/xampp/htdocs/NMU_permission/tmp/sessions');session_id($id);session_start();
$_SESSION=['dc_user_id'=>(int)$row['dc_user_id'],'i_type_user'=>(int)$row['i_type_user'],'c_full_name'=>'Portal integration fixture','cost_name'=>'Test','i_permit_eis'=>0,'i_permit_nmu'=>0,'i_permit_fm'=>0,'i_permit_supplies'=>1,'i_permit_procure'=>1];session_write_close();
echo json_encode(['id'=>$id,'center'=>(int)$row['dc_user_id'],'supplies'=>(int)$row['supplies_id'],'procure'=>(int)$row['procure_id'],'registry'=>$registry]);
