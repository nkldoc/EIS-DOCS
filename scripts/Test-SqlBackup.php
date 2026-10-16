<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/src/SqlBackup.php';
date_default_timezone_set('Asia/Bangkok');
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $fn): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection'); }
$root = sys_get_temp_dir() . '/sql-backup-test-' . bin2hex(random_bytes(8));
$plans = [];
try {
    putenv('APP_ENV=local');
    putenv('SQL_BACKUP_LOCAL_SQL_DIR=D:\SQLBackups');
    putenv('SQL_BACKUP_LOCAL_WEB_DIR=' . $root);
    $settings = SqlBackup::settings('local');
    check($settings['sql_directory'] === 'D:\SQLBackups', 'Windows path accepted');
    putenv('SQL_BACKUP_LOCAL_WEB_DIR=\\sql-host\EISSqlBackup$');
    check(SqlBackup::settings('local')['web_directory'] === '\\sql-host\EISSqlBackup$', 'UNC mapping accepted');
    putenv('SQL_BACKUP_LOCAL_WEB_DIR=relative/path');rejected(function () { SqlBackup::settings('local'); });
    putenv('SQL_BACKUP_LOCAL_WEB_DIR=' . $root);
    foreach (['../escape','a/b','a\\b','C:drive','name.zip','CON','NUL','LPT1',str_repeat('x',81),'x' . "\0"] as $bad) {
        rejected(function () use ($bad) { SqlBackup::component($bad); });
    }
    $plan = SqlBackup::plan($settings, 'EIS_DOCS', 'local', 'daily', 'docs-snapshot');$plans[]=$plan;
    $second = SqlBackup::plan($settings, 'EIS_DOCS', 'local', 'daily', 'docs-snapshot');$plans[]=$second;
    check($plan['relative'] !== $second['relative'], 'Repeated names cannot overwrite earlier backup');
    SqlBackup::prepare($plan);SqlBackup::prepare($second);
    check(is_dir($plan['web_directory']), 'Destination directories created');
    check(count(glob($plan['web_directory'].'/.backup-probe-*')) === 0, 'Read/write probe leaves no temporary files');
    rejected(function () use ($root) { SqlBackup::probe($root.'/does-not-exist'); });
    $bak = $plan['web_directory'] . '/' . $plan['base'] . '.bak';
    $zipPath = $plan['web_directory'] . '/' . $plan['base'] . '.zip';
    $content = str_repeat('backup-fixture-' . "\0", 10000);file_put_contents($bak,$content);
    $size = SqlBackup::archive($bak,$zipPath,basename($bak));check($size > 0,'ZIP generated');
    $zip = new ZipArchive();check($zip->open($zipPath) === true,'ZIP can reopen');
    check($zip->numFiles === 1 && $zip->getFromName(basename($bak)) === $content,'ZIP restores exact backup bytes');$zip->close();
    check(is_file($bak),'Original backup retained');
    rejected(function () use ($bak,$zipPath) { SqlBackup::archive($bak,$zipPath,basename($bak)); });
    rejected(function () use ($plan) { SqlBackup::archive($plan['web_directory'].'/missing.bak',$plan['web_directory'].'/missing.zip','missing.bak'); });
    $defaults = SqlBackup::plan($settings,'[Thai] db','production','','');
    check(strpos($defaults['relative'],'backup-Thai-db/production-') === 0,'Automatic database folder/connection');
    check(strpos($defaults['base'],'Thai-db-') === 0,'Automatic safe database name');
    $docs = SqlBackup::plan($settings,'EIS_DOCS','production','','');
    check(strpos($docs['relative'],'backup-EIS-DOCS/production-') === 0,'EIS-DOCS folder');
    check((bool)preg_match('/^EIS-DOC-\d{8}-\d{6}$/',$docs['base']),'EIS-DOC date/time name');
    check($plan['base'] === 'docs-snapshot','Explicit filename is preserved without a second timestamp');
    $public = realpath(__DIR__ . '/../app/public');
    $unsafe = SqlBackup::plan(['web_directory'=>$public,'sql_directory'=>$public],'db','local','test','test');
    rejected(function () use ($unsafe) { SqlBackup::prepare($unsafe); });
    echo "PASS SQL backup paths, input validation, automatic folders/names, unique requests, ZIP byte round-trip and public-root protection\n";
} finally {
    foreach ($plans as $plan) {
        foreach ([$plan['base'].'.bak',$plan['base'].'.zip'] as $name) { $path=$plan['web_directory'].'/'.$name;if (is_file($path)) unlink($path); }
        if (is_dir($plan['web_directory'])) rmdir($plan['web_directory']);
    }
    if (is_dir($root.'/daily')) rmdir($root.'/daily');
    if (is_dir($root)) rmdir($root);
}
