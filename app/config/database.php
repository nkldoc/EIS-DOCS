<?php

declare(strict_types=1);

$runtime = require __DIR__ . '/runtime.php';
$defaults = $runtime['database'];
$databaseHost = getenv('DB_HOST') ?: $defaults['host'];
$localCredentialsFile = $runtime['credentials_file'];
$localCredentials = is_file($localCredentialsFile) ? require $localCredentialsFile : [];
$databaseUser = getenv('DB_USER');
$databasePassword = getenv('DB_PASSWORD');
// Empty IIS variables must not silently select Windows authentication in production.
if ($runtime['environment'] === 'production') {
    if ($databaseUser === false || trim($databaseUser) === '') {
        $databaseUser = (string) ($localCredentials['user'] ?? '');
        $databasePassword = (string) ($localCredentials['password'] ?? '');
    } elseif (($databasePassword === false || $databasePassword === '') && $databaseUser === ($localCredentials['user'] ?? null)) {
        $databasePassword = (string) ($localCredentials['password'] ?? '');
    }
}
$encryptSetting = getenv('DB_ENCRYPT');
$encryptConnection = $encryptSetting !== false
    ? filter_var($encryptSetting, FILTER_VALIDATE_BOOL)
    : $defaults['encrypt'];
$trustCertificateSetting = getenv('DB_TRUST_SERVER_CERTIFICATE');
$trustServerCertificate = $trustCertificateSetting !== false
    ? filter_var($trustCertificateSetting, FILTER_VALIDATE_BOOL)
    : $defaults['trust_server_certificate'];

return [
    'host' => $databaseHost,
    'port' => (int) (getenv('DB_PORT') ?: 1433),
    'driver' => getenv('DB_DRIVER') ?: ($defaults['driver'] ?? null),
    'user' => $databaseUser !== false ? $databaseUser : (string) ($localCredentials['user'] ?? ''),
    'password' => $databasePassword !== false ? $databasePassword : (string) ($localCredentials['password'] ?? ''),
    'encrypt' => $encryptConnection,
    'trust_server_certificate' => $trustServerCertificate,
    'databases' => [
        'eis' => getenv('DB_EIS') ?: 'NMU_EIS',
        'erp' => getenv('DB_ERP') ?: 'NMU_ERP',
        'datacenter' => getenv('DB_DATACENTER') ?: 'NMU_DATACENTER',
        'docs' => getenv('DB_DOCS') ?: 'EIS_DOCS',
    ],
];
