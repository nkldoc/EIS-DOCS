<?php
declare(strict_types=1);
return [
    'database' => ['host'=>'localhost', 'port'=>1433, 'encrypt'=>false, 'trust_server_certificate'=>true],
    'credentials_file' => dirname(__DIR__) . '/database.local.php',
    'websocket_url' => 'ws://localhost:8080/eis-docs/ws/notifications',
];
