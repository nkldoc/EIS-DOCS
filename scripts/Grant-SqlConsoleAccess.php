<?php
declare(strict_types=1);
// Explicit CLI maintenance action; uses the operator's Windows SQL identity.
if (PHP_SAPI !== 'cli') exit;
if (!in_array('--apply', $argv, true)) { fwrite(STDERR,"Use --apply to grant local eis_docs_app access.\n"); exit(1); }
$login = 'eis_docs_app';
$options = ['Database'=>'master','Encrypt'=>true,'TrustServerCertificate'=>true,'LoginTimeout'=>5,'CharacterSet'=>'UTF-8'];
function grantQuery($db, string $sql, array $params = []) {
    $s=sqlsrv_query($db,$sql,$params,['QueryTimeout'=>30]);
    if (!$s) throw new RuntimeException('SQL maintenance failed; codes '.implode(',',array_map(static fn($e)=>(int)$e['code'],sqlsrv_errors()?:[])));
    return $s;
}
function grantQuote(string $name): string { return '['.str_replace(']',']]',$name).']'; }
$master=sqlsrv_connect('.',$options);
if (!$master) throw new RuntimeException('Cannot open local SQL with Windows identity');
$s=grantQuery($master,"SELECT IS_SRVROLEMEMBER('sysadmin') AS allowed");
if ((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['allowed']!==1) throw new RuntimeException('Run with an authorized SQL administrator Windows identity');
sqlsrv_free_stmt($s);
$s=grantQuery($master,'SELECT name FROM sys.server_principals WHERE name=? AND type IN (\'S\',\'U\',\'G\')',[$login]);
if (!sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)) throw new RuntimeException('Target login does not exist');
sqlsrv_free_stmt($s);
$s=grantQuery($master,"SELECT name,database_id,is_read_only FROM sys.databases WHERE state=0 AND source_database_id IS NULL ORDER BY database_id");
$databases=[];
while ($row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)) $databases[]=$row;
sqlsrv_free_stmt($s);sqlsrv_close($master);
$failures=0;
foreach ($databases as $database) {
    $db=null;$transaction=false;
    try {
        if ((int)$database['is_read_only']) throw new RuntimeException('Read-only database: cannot change permissions');
        $db=sqlsrv_connect('.',array_replace($options,['Database'=>$database['name']]));
        if (!$db || !sqlsrv_begin_transaction($db)) throw new RuntimeException('Cannot start permission transaction');
        $transaction=true;
        $s=grantQuery($db,'SELECT name FROM sys.database_principals WHERE sid=SUSER_SID(?)',[$login]);
        $existing=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);sqlsrv_free_stmt($s);
        $user=$existing ? $existing['name'] : $login;
        if (!$existing) { $s=grantQuery($db,'CREATE USER '.grantQuote($user).' FOR LOGIN '.grantQuote($login));sqlsrv_free_stmt($s); }
        $s=grantQuery($db,'GRANT CONNECT TO '.grantQuote($user));sqlsrv_free_stmt($s);
        $roles=['db_datareader'];
        if ((int)$database['database_id']>4) $roles=array_merge($roles,['db_datawriter','db_backupoperator']);
        foreach ($roles as $role) {
            $s=grantQuery($db,'SELECT IS_ROLEMEMBER(?,?) AS member',[$role,$user]);
            $member=(int)(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['member']??0);sqlsrv_free_stmt($s);
            if (!$member) { $s=grantQuery($db,'ALTER ROLE '.grantQuote($role).' ADD MEMBER '.grantQuote($user));sqlsrv_free_stmt($s); }
        }
        if (!sqlsrv_commit($db)) throw new RuntimeException('Permission commit failed');
        $transaction=false;
        echo 'GRANTED '.$database['name'].' : CONNECT, '.implode(', ',$roles).PHP_EOL;
    } catch (Throwable $error) {
        if ($transaction && $db) sqlsrv_rollback($db);
        $failures++;echo 'FAILED '.$database['name'].' : '.$error->getMessage().PHP_EOL;
    } finally { if ($db) sqlsrv_close($db); }
}
exit($failures ? 1 : 0);
