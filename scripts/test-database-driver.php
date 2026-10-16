<?php
declare(strict_types=1);
if (extension_loaded('sqlsrv')) throw new RuntimeException('Run with php -n');
require dirname(__DIR__).'/app/src/Database.php';
function sqlsrv_connect($server, $options) { $GLOBALS['options']=$options; return fopen('php://memory','r+'); }
foreach (['DB_DRIVER','DB_ENCRYPT','DB_TRUST_SERVER_CERTIFICATE'] as $setting) putenv($setting);
putenv('DB_USER=fixture'); putenv('DB_PASSWORD=fixture');
putenv('APP_ENV=local');
$connection=Database::connect();fclose($connection);
if ($GLOBALS['options']['Driver']!=='ODBC Driver 18 for SQL Server' || !$GLOBALS['options']['Encrypt']) throw new RuntimeException('Local driver config failed');
putenv('APP_ENV=production');
$connection=Database::connect();fclose($connection);
if (isset($GLOBALS['options']['Driver']) || !$GLOBALS['options']['Encrypt']) throw new RuntimeException('Production defaults changed');
putenv('DB_DRIVER=ODBC Driver 18 for SQL Server');
$connection=Database::connect();fclose($connection);
if ($GLOBALS['options']['Driver']!=='ODBC Driver 18 for SQL Server') throw new RuntimeException('Driver override failed');
echo "PASS: local driver 18 with encryption, production defaults, explicit driver override\n";
