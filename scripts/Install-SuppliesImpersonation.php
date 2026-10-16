<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$db = Database::connect();
$stmt = sqlsrv_query($db, file_get_contents(__DIR__.'/../database/supplies-impersonation.sql'));
if (!$stmt) { foreach (sqlsrv_errors() ?: [] as $error) fwrite(STDERR, $error['message'].PHP_EOL); exit(1); }
sqlsrv_free_stmt($stmt);
sqlsrv_close($db);
echo "PASS: supplies impersonation schema installed\n";
