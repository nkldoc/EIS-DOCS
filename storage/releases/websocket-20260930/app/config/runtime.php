<?php
declare(strict_types=1);

// APP_ENV overrides host detection, including CLI commands on Production.
$environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
if ($environment === '') {
    $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = $host === '::1' ? $host : trim((string) (parse_url('http://' . $host, PHP_URL_HOST) ?: $host), '[]');
    $environment = in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'local' : 'production';
}
$aliases = ['local'=>'local', 'development'=>'local', 'dev'=>'local', 'production'=>'production', 'prod'=>'production', 'product'=>'production'];
if (!isset($aliases[$environment])) throw new RuntimeException('APP_ENV must be local or production');
$environment = $aliases[$environment];
$profile = require __DIR__ . '/profiles/' . $environment . '.php';
$profile['environment'] = $environment;
return $profile;
