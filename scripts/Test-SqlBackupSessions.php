<?php
declare(strict_types=1);
if (isset($argv[1]) && in_array($argv[1],['write','read','missing'],true)) {
    $_SERVER['HTTP_HOST']='localhost';
    session_id((string)$argv[2]);
    require __DIR__.'/../app/src/bootstrap.php';
    if ($argv[1]==='write') $_SESSION['login_csrf']='test-csrf-marker';
    elseif (($_SESSION['login_csrf']??'')!=='test-csrf-marker') throw new RuntimeException('Session did not persist');
    session_write_close();echo 'PASS';exit;
}
$directory=sys_get_temp_dir().'/eis-session-test-'.bin2hex(random_bytes(8));
$id='eissessiontest'.bin2hex(random_bytes(12));
mkdir($directory,0700);
putenv('APP_ENV=local');putenv('EIS_DOCS_SESSION_DIR='.$directory);
function child(string $mode,string $id): string {
    $process=proc_open([PHP_BINARY,'-n',__FILE__,$mode,$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start session test');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($process)!==0 || $err!=='') throw new RuntimeException('Session child failed');return $out;
}
try {
    if (child('write',$id)!=='PASS' || child('read',$id)!=='PASS') throw new RuntimeException('Session persistence failed');
    putenv('EIS_DOCS_SESSION_DIR='.$directory.'/missing');
    if (strpos(child('missing',$id),'ระบบ session ยังไม่พร้อม')===false) throw new RuntimeException('Invalid storage did not fail clearly');
    echo "PASS login-CSRF persistence between PHP requests and explicit unavailable-session-storage failure\n";
} finally {if (is_file($directory.'/sess_'.$id)) unlink($directory.'/sess_'.$id);rmdir($directory);}
