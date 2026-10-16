<?php
declare(strict_types=1);
return [
    'database' => ['host'=>'.', 'port'=>1433, 'driver'=>'ODBC Driver 18 for SQL Server', 'encrypt'=>true, 'trust_server_certificate'=>true],
    'credentials_file' => dirname(__DIR__) . '/database.local.php',
    'websocket_url' => '/eis-docs/ws/notifications',
    'supplies_impersonation_url' => '/supplies/access/impersonate',
    'websocket_enabled' => false,
];
