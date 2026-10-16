<?php
declare(strict_types=1);
return [
    // Production SQL host from EIS-ERP/public/api-eis/procure/conf/database.php.
    'database' => ['host'=>'192.168.201.3', 'port'=>1433, 'encrypt'=>true, 'trust_server_certificate'=>false],
    'credentials_file' => dirname(__DIR__) . '/database.production.local.php',
    'websocket_url' => '/eis-docs/ws/notifications',
];
