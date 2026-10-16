<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
// Read-only local probe. Never uses production configuration.
putenv('APP_ENV=local'); putenv('DB_HOST=.');
require_once __DIR__.'/../app/src/Database.php';
require_once __DIR__.'/../app/src/SqlConsole.php';
$catalog = Database::connect('master', true, true);
$s = sqlsrv_query($catalog, 'SELECT name,CASE WHEN state=0 AND HAS_DBACCESS(name)=1 THEN 1 ELSE 0 END AS accessible FROM sys.databases ORDER BY name');
if (!$s) throw new RuntimeException('Cannot discover databases');
$names=[]; $visible=[];
while ($row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)) { $visible[]=$row['name']; if ((int)$row['accessible']) $names[]=$row['name']; }
sqlsrv_free_stmt($s); sqlsrv_close($catalog);
$config=require __DIR__.'/../app/config/database.php';
$other=array_values(array_diff($names,array_values($config['databases'])));
if ($other) {
    $probe=Database::connect($other[0],true,true);
    $s=sqlsrv_query($probe,'SELECT DB_NAME() AS name');
    if (!$s || sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['name'] !== $other[0]) throw new RuntimeException('Literal database selection failed');
    sqlsrv_free_stmt($s); sqlsrv_close($probe);
}
echo 'PASS database discovery: '.count($visible).' visible; '.count($names).' accessible; '.count($other)." accessible outside defaults".PHP_EOL;
$db = Database::connect('docs');
$s = sqlsrv_query($db, 'SELECT TOP 1 s.name AS schema_name,t.name AS table_name FROM sys.tables t JOIN sys.schemas s ON t.schema_id=s.schema_id WHERE t.is_ms_shipped=0 ORDER BY t.name');
if (!$s || !($table = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC))) throw new RuntimeException('No accessible local test table');
sqlsrv_free_stmt($s);
$s = sqlsrv_query($db, 'SELECT c.name FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id JOIN sys.schemas s ON t.schema_id=s.schema_id WHERE s.name=? AND t.name=?', [$table['schema_name'],$table['table_name']]);
if (!$s) throw new RuntimeException('Cannot load metadata');
$columns=[];
while ($row=sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $columns[]=$row['name'];
sqlsrv_free_stmt($s);
$query='SELECT TOP 1 * FROM '.SqlConsole::quote($table['schema_name']).'.'.SqlConsole::quote($table['table_name']);
$compiled=(new SqlConsole())->compile($query,$table['schema_name'],$table['table_name'],$columns);
$s=sqlsrv_query($db,$compiled['sql'],$compiled['params']);
if (!$s) throw new RuntimeException('Compiled SELECT failed');
if (sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC) === false) throw new RuntimeException('SELECT fetch failed');
sqlsrv_free_stmt($s);sqlsrv_close($db);
echo "PASS local SQL metadata and compiled SELECT (no data printed or changed)\n";
