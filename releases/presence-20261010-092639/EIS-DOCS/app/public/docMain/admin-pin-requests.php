<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
Auth::requireLogin();
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
try {AdminDocuments::requireAdmin();} catch(Throwable $e) {exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
$_SESSION['app_csrf'] ??= bin2hex(random_bytes(32));
function pinRequestEscape($value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
$db=null; $rows=[]; $message=(string)($_SESSION['pin_reset_message']??''); unset($_SESSION['pin_reset_message']);
try {
    $db=Database::connect('docs');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        if(!safeCmp($_SESSION['app_csrf'],(string)($_POST['csrf']??''))) {http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง');}
        $id=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT);
        if(!$id || $id<1) throw new InvalidArgumentException('คำขอไม่ถูกต้อง');
        UserPin::approveReset($db,$id);
        $_SESSION['pin_reset_message']='ปลดล็อกแล้ว ผู้ใช้สามารถตั้ง PIN ใหม่ได้หนึ่งครั้ง';
        header('Location: admin-pin-requests.php'); exit;
    }
    $s=sqlsrv_query($db,"SELECT TOP 100 r.request_id,r.user_id,r.status,CONVERT(varchar(19),r.requested_at,120) requested_at,CONVERT(varchar(19),r.approved_at,120) approved_at,u.c_full_name,u.c_user_name,a.c_full_name approved_name FROM dbo.DOCS_PIN_RESET_REQUEST r LEFT JOIN NMU_DATACENTER.dbo.dc_user u ON u.dc_user_id=r.user_id LEFT JOIN NMU_DATACENTER.dbo.dc_user a ON a.dc_user_id=r.approved_by ORDER BY CASE WHEN r.status='PENDING' THEN 0 ELSE 1 END,r.request_id DESC");
    if(!$s) throw new RuntimeException('โหลดคำขอไม่สำเร็จ');
    while($row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)) $rows[]=$row; sqlsrv_free_stmt($s);
} catch(Throwable $e) {$message=$e->getMessage();}
finally {if($db!==null) sqlsrv_close($db);}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>แจ้งเตือนคำขอปลดล็อก PIN — EIS-DOCS</title><link rel="stylesheet" href="style.css"><style>body{padding:24px}main{max-width:1100px;margin:auto}.pin-request-table{overflow:auto;background:white}table{border-collapse:collapse;width:100%}td,th{padding:14px;border-bottom:1px solid #ddd;text-align:left}small{display:block;color:#666}</style></head><body><main><a href="index.php">← กลับหน้าหลัก</a><h1>แจ้งเตือนคำขอปลดล็อก PIN</h1><p>ตรวจสอบผู้แจ้งก่อนปลดล็อก ผู้ใช้จะตั้งรหัสใหม่ด้วยตนเองได้หนึ่งครั้ง โดยไม่ต้องใช้ PIN เดิม</p><p role="status"><?=pinRequestEscape($message)?></p><p><a href="admin-pin-requests.php" class="btn btn-subtle">รีเฟรชคำขอ</a></p>
<div class="pin-request-table"><table><thead><tr><th>ผู้แจ้ง</th><th>เวลาที่แจ้ง</th><th>สถานะ</th><th>การดำเนินการ</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?=pinRequestEscape($row['c_full_name']??'ไม่พบชื่อผู้ใช้')?><small><?=pinRequestEscape($row['c_user_name'])?> · ID <?=pinRequestEscape($row['user_id'])?></small></td><td><?=pinRequestEscape($row['requested_at'])?></td><td><?=['PENDING'=>'รอปลดล็อก','APPROVED'=>'ปลดล็อกแล้ว รอตั้ง PIN ใหม่','CONSUMED'=>'ตั้ง PIN ใหม่แล้ว'][$row['status']]?><small><?=pinRequestEscape($row['approved_name'])?> <?=pinRequestEscape($row['approved_at'])?></small></td><td><?php if($row['status']==='PENDING'): ?><form method="post" class="pin-approve-form"><input type="hidden" name="csrf" value="<?=pinRequestEscape($_SESSION['app_csrf'])?>"><input type="hidden" name="request_id" value="<?=pinRequestEscape($row['request_id'])?>"><button class="btn btn-primary">ปลดล็อกให้ตั้ง PIN ใหม่</button></form><?php endif ?></td></tr><?php endforeach ?>
<?php if(!$rows): ?><tr><td colspan="4">ยังไม่มีคำขอปลดล็อก PIN</td></tr><?php endif ?></tbody></table></div><p>แสดงคำขอล่าสุดสูงสุด 100 รายการ โดยแสดงคำขอที่รอดำเนินการก่อน</p></main><script>document.querySelectorAll('.pin-approve-form').forEach(form=>form.addEventListener('submit',event=>{if(!confirm('ยืนยันปลดล็อกให้ผู้ใช้ตั้ง PIN ใหม่ได้หนึ่งครั้ง?'))event.preventDefault();}));</script><?= Presence::script() ?></body></html>
