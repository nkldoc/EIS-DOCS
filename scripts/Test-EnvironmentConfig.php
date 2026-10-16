<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$configDir=dirname(__DIR__).'/app/config';
$keys=['APP_ENV','DB_HOST','DB_PORT','DB_USER','DB_PASSWORD','DB_ENCRYPT','DB_TRUST_SERVER_CERTIFICATE'];
$saved=[];
foreach($keys as $key) { $saved[$key]=getenv($key); putenv($key); }
$savedServer=$_SERVER;
function checkConfig(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    foreach ([['localhost','local'],['127.0.0.1:80','local'],['::1','local'],['[::1]:80','local'],['dev-eis.nmu.ac.th','local'],['DEV-EIS.NMU.AC.TH:443','local'],['doc-eis.nmu.ac.th','production'],['doc-eis.nmu.ac.th:443','production']] as [$host,$expected]) {
        unset($_SERVER['SERVER_NAME']); $_SERVER['HTTP_HOST']=$host;
        $runtime=require $configDir.'/runtime.php';
        checkConfig($runtime['environment']===$expected,'Host detection failed');
        checkConfig($runtime['websocket_url']==='/eis-docs/ws/notifications','WebSocket must use the page origin');
        $config=require $configDir.'/database.php';
        checkConfig($config['host']===($expected==='local'?'.':'192.168.201.3'),'Host selected incorrect database');
    }
    foreach ([
        ['dev-eis.nmu.ac.th', 'doc-eis.nmu.ac.th', 'local'],
        ['doc-eis.nmu.ac.th', 'localhost', 'production'],
        ['DEV-EIS.NMU.AC.TH.:443', 'localhost', 'local'],
        ['doc-eis.nmu.ac.th.:443', 'localhost', 'production'],
        ['', 'dev-eis.nmu.ac.th', 'local'],
        ['', 'doc-eis.nmu.ac.th', 'production'],
    ] as [$requestHost, $serverName, $expected]) {
        $_SERVER['HTTP_HOST'] = $requestHost;
        $_SERVER['SERVER_NAME'] = $serverName;
        $runtime = require $configDir.'/runtime.php';
        checkConfig($runtime['environment'] === $expected, 'IIS host binding detection failed');
        $config = require $configDir.'/database.php';
        checkConfig($config['host'] === ($expected === 'local' ? '.' : '192.168.201.3'), 'IIS binding selected incorrect database');
    }
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
    $runtime = require $configDir.'/runtime.php';
    checkConfig($runtime['environment'] === 'local', 'CLI default must be local');
    foreach (['local'=>'local','development'=>'local','dev'=>'local','prod'=>'production','production'=>'production','product'=>'production'] as $alias=>$expected) {
        putenv('APP_ENV='.$alias);
        $_SERVER['HTTP_HOST'] = $expected === 'local' ? 'doc-eis.nmu.ac.th' : 'dev-eis.nmu.ac.th';
        $runtime=require $configDir.'/runtime.php';
        checkConfig($runtime['environment']===$expected,'APP_ENV override failed');
    }
    putenv('APP_ENV=production');
    $runtime=require $configDir.'/runtime.php';
    checkConfig(basename($runtime['credentials_file'])==='database.production.local.php','Production used local credentials');
    $config=require $configDir.'/database.php';
    checkConfig($config['host']==='192.168.201.3' && $config['encrypt'] && $config['trust_server_certificate'],'Production defaults incorrect');
    putenv('DB_TRUST_SERVER_CERTIFICATE=false');
    $verifiedConfig = require __DIR__.'/../app/config/database.php';
    checkConfig($verifiedConfig['encrypt'] && !$verifiedConfig['trust_server_certificate'],'Trusted certificate validation override failed');
    putenv('DB_HOST=db.example.internal'); putenv('DB_PORT=1444'); putenv('DB_USER=test-user'); putenv('DB_PASSWORD=test-only-value'); putenv('DB_ENCRYPT=false'); putenv('DB_TRUST_SERVER_CERTIFICATE=true');
    $config=require $configDir.'/database.php';
    checkConfig($config['host']==='db.example.internal' && $config['port']===1444 && $config['user']==='test-user' && $config['password']==='test-only-value' && !$config['encrypt'] && $config['trust_server_certificate'],'Environment overrides failed');
    putenv('DB_USER='); putenv('DB_PASSWORD=');
    $config=require $configDir.'/database.php';
    $credentials = is_file($configDir.'/database.production.local.php') ? require $configDir.'/database.production.local.php' : [];
    checkConfig($config['user'] === (string)($credentials['user'] ?? '') && $config['password'] === (string)($credentials['password'] ?? ''),'Empty production environment did not use production credentials');
    putenv('APP_ENV=local');
    $config=require $configDir.'/database.php';
    checkConfig($config['user']==='' && $config['password']==='','Explicit empty credentials ignored');
    putenv('APP_ENV=typo');
    try { require $configDir.'/runtime.php'; throw new LogicException('Invalid environment accepted'); } catch (RuntimeException $expected) {}
    echo "PASS: local/production detection, aliases, isolated credentials, environment overrides and invalid mode rejection\n";
} finally {
    $_SERVER=$savedServer;
    foreach($saved as $key=>$value) putenv($value===false?$key:$key.'='.$value);
}
