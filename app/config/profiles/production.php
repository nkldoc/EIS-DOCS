<?php
declare(strict_types=1);
return [
    // Production SQL host from EIS-ERP/public/api-eis/procure/conf/database.php.
    // Temporary compatibility for the internal SQL Server's untrusted certificate.
    // Keep encryption enabled. Set DB_TRUST_SERVER_CERTIFICATE=false after installing a trusted certificate.
    'database' => ['host'=>'192.168.201.3', 'port'=>1433, 'encrypt'=>true, 'trust_server_certificate'=>true],
    'credentials_file' => dirname(__DIR__) . '/database.production.local.php',
    'websocket_url' => '/eis-docs/ws/notifications',
    'supplies_impersonation_url' => 'https://eis.nmu.ac.th/supplies/access/impersonate',
];
