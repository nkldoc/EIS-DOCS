<?php
// CLI only. Uses the existing local Supplies database configuration without printing credentials.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$config=require __DIR__.'/../app/config/database.php';
if (!in_array(strtolower($config['host']),['localhost','127.0.0.1','.'],true)) throw new RuntimeException('Local database only');
$db=Database::connect();
$s=sqlsrv_query($db,'SELECT SUSER_SNAME() login_name');
$login=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['login_name'];
sqlsrv_free_stmt($s); sqlsrv_close($db);
$suppliesConfig=file_get_contents('D:/ERP/nmu_supplies/src/main/webapp/conf/config.php');
foreach (['DB_SERVER','DB_USER','DB_PASS'] as $key) {
    if (!preg_match('/define\("'.$key.'",\s*"([^"\\\\]*)"\)/',$suppliesConfig,$match)) throw new RuntimeException('Cannot read local database setting '.$key);
    define($key,$match[1]);
}
if (!in_array(strtolower(DB_SERVER),['localhost','127.0.0.1','.'],true)) throw new RuntimeException('Local Supplies database only');
$db=sqlsrv_connect(DB_SERVER,['Database'=>$config['databases']['datacenter'],'UID'=>DB_USER,'PWD'=>DB_PASS,'CharacterSet'=>'UTF-8','TrustServerCertificate'=>true]);
if (!$db) throw new RuntimeException('Cannot connect using local Supplies configuration');
function repairQuery($db,string $sql): void {
    $s=sqlsrv_query($db,$sql);
    if (!$s) { foreach(sqlsrv_errors()?:[] as $e) fwrite(STDERR,$e['message'].PHP_EOL); exit(1); }
    sqlsrv_free_stmt($s);
}
$principal='['.str_replace(']',']]',$login).']';
$literal="N'".str_replace("'","''",$login)."'";
repairQuery($db,file_get_contents(__DIR__.'/../database/supplies-impersonation.sql'));
repairQuery($db,'GRANT INSERT, SELECT, UPDATE, DELETE ON dbo.DOCS_SUPPLIES_IMPERSONATION TO '.$principal);
$erp='['.str_replace(']',']]',$config['databases']['erp']).']';
repairQuery($db,'USE '.$erp.'; IF DATABASE_PRINCIPAL_ID('.$literal.') IS NULL CREATE USER '.$principal.' FOR LOGIN '.$principal.'; GRANT SELECT ON dbo.dc_user TO '.$principal.';');
sqlsrv_close($db);
echo "PASS: local handoff table installed and required object permissions granted\n";
