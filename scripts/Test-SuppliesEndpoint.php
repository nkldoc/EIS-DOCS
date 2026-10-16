<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/SuppliesImpersonation.php';
$env=getenv('APP_ENV'); $override=getenv('SUPPLIES_IMPERSONATION_URL');
try {
    putenv('SUPPLIES_IMPERSONATION_URL');
    putenv('APP_ENV=production');
    if (SuppliesImpersonation::endpoint()!=='https://eis.nmu.ac.th/supplies/access/impersonate') throw new RuntimeException('Production endpoint incorrect');
    putenv('APP_ENV=local');
    if (SuppliesImpersonation::endpoint()!=='/supplies/access/impersonate') throw new RuntimeException('Local endpoint incorrect');
    putenv('SUPPLIES_IMPERSONATION_URL=https://custom.nmu.ac.th/supplies/access/impersonate');
    if (SuppliesImpersonation::endpoint()!=='https://custom.nmu.ac.th/supplies/access/impersonate') throw new RuntimeException('Override ignored');
    putenv("SUPPLIES_IMPERSONATION_URL=https://eis.nmu.ac.th/supplies/access/impersonate\r\nInjected: value");
    try { SuppliesImpersonation::endpoint(); throw new LogicException('Invalid URL allowed'); }
    catch (RuntimeException $e) {}
    echo "PASS: production host, local path, override, invalid URL rejected\n";
} finally {
    putenv($env===false?'APP_ENV':'APP_ENV='.$env);
    putenv($override===false?'SUPPLIES_IMPERSONATION_URL':'SUPPLIES_IMPERSONATION_URL='.$override);
}
