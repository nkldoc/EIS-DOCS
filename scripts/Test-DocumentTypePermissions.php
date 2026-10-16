<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/ProcurementTemplateStore.php';
require_once __DIR__.'/../app/src/ProcurementLibrary.php';
$directory=sys_get_temp_dir().'/eis-type-test-'.bin2hex(random_bytes(8));
$library=new ProcurementLibrary(new ProcurementTemplateStore($directory));
function requireTypeTest(bool $ok): void { if (!$ok) throw new RuntimeException('Document type assertion failed'); }
try {
    try { $library->handle('user',false,['action'=>'add_type','docType'=>'AM - ทรัพย์สิน']); throw new LogicException('Non-admin was allowed'); }
    catch (DomainException $e) {}
    $library->handle('admin',true,['action'=>'add_type','docType'=>'AM - ทรัพย์สิน']);
    $user=$library->handle('user',false,null);
    requireTypeTest(in_array('AM - ทรัพย์สิน',$user['types'],true) && !$user['canAddTypes']);
    $user=$library->handle('user',false,['action'=>'save','mode'=>'new','docType'=>'AM - ทรัพย์สิน','name'=>'ชุดส่วนตัว','catalog'=>[],'selectedIds'=>[]]);
    requireTypeTest(count($user['templates'])===1);
    requireTypeTest($library->handle('other',false,null)['templates']===[]);
    try { $library->handle('user',false,['action'=>'save','docType'=>'XX - ไม่อนุญาต','name'=>'ชุด','catalog'=>[],'selectedIds'=>[]]); throw new LogicException('Unknown type bypass allowed'); }
    catch (InvalidArgumentException $e) {}
    echo "PASS: admin adds shared type, non-admin denied, shared type selectable, templates private, save bypass denied\n";
} finally {
    foreach (glob($directory.'/*')?:[] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
