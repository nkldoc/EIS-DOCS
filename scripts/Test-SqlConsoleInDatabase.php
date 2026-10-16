<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
putenv('APP_ENV=local');putenv('DB_HOST=.');
require_once __DIR__.'/../app/src/Database.php';
require_once __DIR__.'/../app/src/SqlConsole.php';
$db=Database::connect('tempdb',true,true,'local');
function fixtureQuery($db,string $sql,array $params=[]) {
    $s=sqlsrv_query($db,$sql,$params);
    if (!$s) throw new RuntimeException('Fixture query failed: '.json_encode(sqlsrv_errors()));
    return $s;
}
$transaction=false;
try {
    $s=fixtureQuery($db,'CREATE TABLE #ConsolePrFixture (c_code nvarchar(40) PRIMARY KEY,sp_emp_id int NOT NULL)');sqlsrv_free_stmt($s);
    $query=file_get_contents(__DIR__.'/fixtures/sql-console-pr-transfer.sql');
    $query=str_replace('UPDATE sp_tor','UPDATE [#ConsolePrFixture]',$query);
    $compiled=(new SqlConsole())->compile($query,'dbo','#ConsolePrFixture',['sp_emp_id','c_code']);
    foreach (array_merge($compiled['whereParams'],['PR_UNRELATED']) as $code) { $s=fixtureQuery($db,'INSERT #ConsolePrFixture VALUES (?,7)',[$code]);sqlsrv_free_stmt($s); }
    if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('Cannot begin fixture transaction');
    $transaction=true;
    $s=fixtureQuery($db,'SELECT COUNT(*) AS n FROM #ConsolePrFixture'.$compiled['where'],$compiled['whereParams']);
    if ((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']!==28) throw new RuntimeException('Wrong preview count');sqlsrv_free_stmt($s);
    $s=fixtureQuery($db,$compiled['sql'],$compiled['params']);
    if (sqlsrv_rows_affected($s)!==28) throw new RuntimeException('Wrong update count');sqlsrv_free_stmt($s);
    $s=fixtureQuery($db,'SELECT COUNT(*) AS n FROM #ConsolePrFixture WHERE sp_emp_id=20334');
    if ((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']!==28) throw new RuntimeException('Wrong updated values');sqlsrv_free_stmt($s);
    sqlsrv_rollback($db);$transaction=false;
    $s=fixtureQuery($db,'SELECT COUNT(*) AS n FROM #ConsolePrFixture WHERE sp_emp_id=7');
    if ((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']!==29) throw new RuntimeException('Rollback failed');sqlsrv_free_stmt($s);
    echo "PASS exact PR query preview/update/rollback on isolated local temp table; no application rows changed\n";
} finally {if ($transaction) sqlsrv_rollback($db);sqlsrv_close($db);}
