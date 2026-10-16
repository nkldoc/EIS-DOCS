<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/ProcurementTemplateStore.php';
require_once __DIR__.'/../app/src/ProcurementLibrary.php';
$directory=sys_get_temp_dir().'/eis-dept-test-'.bin2hex(random_bytes(8));
$library=new ProcurementLibrary(new ProcurementTemplateStore($directory));
function verifyDepartment(bool $ok): void { if (!$ok) throw new RuntimeException('Department template assertion failed'); }
$request=['action'=>'save','mode'=>'new','docType'=>'PR - ใบขอซื้อ/ขอจ้าง','name'=>'ชุดพัสดุ','catalog'=>[],'selectedIds'=>[],'visibility'=>'department','costId'=>999];
try {
    $saved=$library->handle('owner',false,$request,38);
    $id=$saved['savedId'];
    $peer=$library->handle('peer',false,null,38);
    verifyDepartment(count($peer['templates'])===1 && $peer['templates'][0]['canEdit']===false);
    verifyDepartment($peer['templates'][0]['costId']===38);
    verifyDepartment($library->handle('other',false,null,39)['templates']===[]);
    try { $library->handle('peer',false,array_merge($request,['mode'=>'update','id'=>$id]),38); throw new LogicException('Peer overwrite allowed'); }
    catch (DomainException $e) {}
    $copy=$library->handle('peer',false,array_merge($request,['name'=>'ชุดสำเนา','visibility'=>'private']),38);
    verifyDepartment(count($copy['templates'])===2);
    $library->handle('owner',false,array_merge($request,['mode'=>'update','id'=>$id,'visibility'=>'private']),38);
    verifyDepartment(count($library->handle('peer',false,null,38)['templates'])===1);
    verifyDepartment($library->handle('other',false,null,38)['templates']===[]);
    try { $library->handle('no-dept',false,$request,0); throw new LogicException('Missing department allowed'); }
    catch (InvalidArgumentException $e) {}
    echo "PASS: same department sharing, cross-department isolation, server department enforced, owner-only edit, copy, private visibility restored\n";
} finally {
    foreach(glob($directory.'/*')?:[] as $file) unlink($file);
    if(is_dir($directory)) rmdir($directory);
}
