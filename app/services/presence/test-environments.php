<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Presence.php';
$dir = sys_get_temp_dir() . '/presence-environments-' . bin2hex(random_bytes(8));
mkdir($dir); mkdir($dir . '/revoked');
$saved = [];
foreach (['EIS_PRESENCE_CONFIG','EIS_DOCS_PRESENCE_DIR','EIS_PRESENCE_WS_URL'] as $key) { $saved[$key] = getenv($key); putenv($key); }
session_id('environment-test');
$checks = 0;
function check(bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('Environment check failed: ' . ($checks + 1)); $checks++; }
try {
    putenv('EIS_PRESENCE_CONFIG=' . $dir . '/absent.php');
    foreach (['dev-eis.nmu.ac.th', 'localhost', '127.0.0.1'] as $host) { $_SERVER['HTTP_HOST'] = $host; check(Presence::enabled()); }
    foreach (['eis.nmu.ac.th', 'doc-eis.nmu.ac.th', 'untrusted.example'] as $host) { $_SERVER['HTTP_HOST'] = $host; check(!Presence::enabled()); }
    file_put_contents($dir . '/config.php', '<?php return ' . var_export(['enabled'=>true, 'directory'=>$dir], true) . ';');
    putenv('EIS_PRESENCE_CONFIG=' . $dir . '/config.php');
    foreach (['eis-docs','supplies'] as $system) file_put_contents($dir . '/' . $system . '.secret', bin2hex(random_bytes(32)));
    foreach (['eis.nmu.ac.th', 'doc-eis.nmu.ac.th', 'dev-eis.nmu.ac.th'] as $host) {
        $_SERVER['HTTP_HOST'] = $host; check(Presence::enabled());
        check(Presence::directory() === $dir);
        $token = Presence::token('SUPPLIES', '123', 'Test', 'Test', 'test');
        check($token['url'] === ($host === 'dev-eis.nmu.ac.th' ? 'wss://dev-eis.nmu.ac.th:4003/presence' : 'wss://doc-eis.nmu.ac.th/eis-docs/presence'));
        check(str_contains(Presence::script('SUPPLIES'), '/supplies/assets/js/presence-client.js'));
        check(str_contains(Presence::script(), '/eis-docs/assets/js/presence-client.js'));
    }
    putenv('EIS_PRESENCE_WS_URL=wss://custom.example/presence');
    check(Presence::token('EIS-DOCS','123','Test','Test','test',true)['url'] === 'wss://custom.example/presence');
    file_put_contents($dir . '/config.php', '<?php return ["enabled"=>false];');
    check(!Presence::enabled());
    echo "PASS: $checks environment checks (dev, both production hosts, shared config, asset paths, overrides and disabled deployment)\n";
} finally {
    foreach ($saved as $key=>$value) putenv($value === false ? $key : $key . '=' . $value);
    foreach (glob($dir . '/*') as $file) if (is_file($file)) unlink($file);
    rmdir($dir . '/revoked'); rmdir($dir);
}
