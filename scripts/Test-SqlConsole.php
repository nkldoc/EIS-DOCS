<?php
declare(strict_types=1);
require_once __DIR__.'/../app/src/SqlConsole.php';
$parser = new SqlConsole();
$columns = ['id','name','enabled','odd]column'];
$passed = 0;
foreach ([
    ["SELECT TOP 20 * FROM [dbo].[items] WHERE [id] = 7", 'SELECT', ['7']],
    ["SELECT [name],[id] FROM items WHERE name LIKE N'%ไทย%' AND (enabled = 1 OR enabled IS NULL)", 'SELECT', ['%ไทย%','1']],
    ["UPDATE dbo.items SET name = N'O''Brien; DELETE FROM x', enabled = NULL WHERE id = 1", 'UPDATE', ["O'Brien; DELETE FROM x",'1']],
    ["DELETE FROM items WHERE id >= -2 AND enabled IS NOT NULL;", 'DELETE', ['-2']],
    ["SELECT [odd]]column] FROM items", 'SELECT', []],
    ["-- header\n SELECT * FROM items WHERE id IN (1, -- item\n 2,3)", 'SELECT', ['1','2','3']],
    ["DELETE FROM items WHERE name NOT IN ('a--b', N'ไทย/*text*/') /* end */;", 'DELETE', ['a--b','ไทย/*text*/']],
    ["SELECT * FROM items --comment", 'SELECT', []],
    ["DELETE FROM items WHERE id=1 /* hi */", 'DELETE', ['1']],
] as [$query,$action,$params]) {
    $compiled = $parser->compile($query, 'dbo', 'items', $columns);
    if ($compiled['action'] !== $action || $compiled['params'] !== $params) throw new RuntimeException('Bad compile');
    if ($action === 'UPDATE' && $compiled['whereParams'] !== ['1']) throw new RuntimeException('SET parameters leaked into WHERE');
    $passed++;
}
foreach ([
    'UPDATE items SET name = \'x\'', 'DELETE FROM items',
    "UPDATE items SET name = 'WHERE id=1'", "DELETE FROM items WHERE name='x'; DELETE FROM items",
    'DELETE FROM items WHERE 1=1', 'SELECT * FROM other',
    'SELECT * FROM NMU_ERP.dbo.items', 'SELECT * FROM items; DELETE FROM items WHERE id=1',
    'UPDATE items SET name = (SELECT name FROM items) WHERE id=1',
    'EXEC xp_cmdshell \'whoami\'', 'SELECT * INTO copy FROM items',
    'SELECT * FROM items WHERE missing=1',
    'SELECT TOP 501 * FROM items',
    'DELETE FROM items WHERE id IN ()', 'DELETE FROM items WHERE id IN (SELECT id FROM items)',
    'DELETE FROM items WHERE id IN (1,)', 'UPDATE items SET enabled=1 -- WHERE id=1',
    'DELETE FROM items WHERE id IN (1); COMMIT', 'DELETE FROM items /* unclosed',
    'DELETE FROM items /* outer /* nested */ WHERE id=1 */',
    'UPDATE items SET enabled=1 WHERE id=1; COMMIT', 'SELECT * FROM items JOIN other ON id=id',
] as $query) {
    try { $parser->compile($query, 'dbo', 'items', $columns); }
    catch (InvalidArgumentException $expected) { $passed++; continue; }
    throw new RuntimeException('Unsafe query accepted: '.$query);
}
$fixture=file_get_contents(__DIR__.'/fixtures/sql-console-pr-transfer.sql');
$compiled=$parser->compile($fixture,'dbo','sp_tor',['sp_emp_id','c_code']);
if ($compiled['action']!=='UPDATE' || count($compiled['whereParams'])!==28 || count($compiled['params'])!==29 || $compiled['params'][0]!=='20334' || substr_count($compiled['where'],'?')!==28) throw new RuntimeException('PR transfer compile failed');
if ($compiled['whereParams'][0]!=='PR2569100019' || $compiled['whereParams'][27]!=='PR25690900006') throw new RuntimeException('PR identifiers changed');
echo 'PASS '.($passed+1)." SQL grammar / parameter / scope checks, including exact 28-PR transfer query\n";
