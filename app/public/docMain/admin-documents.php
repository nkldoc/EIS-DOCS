<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
Auth::requireLogin();
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
try { AdminDocuments::requireAdmin(); } catch(Throwable $e) { exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }
$_SESSION['app_csrf'] ??= bin2hex(random_bytes(32));
function adminDocEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$query=trim((string)($_GET['q']??'')); $filter=(string)($_GET['enabled']??'all');
$page=max(1,min(100000,(int)($_GET['page']??1))); $pageSize=25; $total=0; $pages=1;
if(!in_array($filter,['all','1','0'],true)) $filter='all';
$rows=[]; $message=(string)($_SESSION['admin_documents_message']??''); unset($_SESSION['admin_documents_message']); $db=null;
try {
    $db=Database::connect('docs');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        if(!safeCmp($_SESSION['app_csrf'],(string)($_POST['csrf']??''))) { http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง'); }
        $ids=$_POST['ids']??[]; if(!is_array($ids)) throw new InvalidArgumentException('กรุณาเลือกรายการ');
        $action=(string)($_POST['action']??''); AdminDocuments::apply($db,$ids,$action);
        $_SESSION['admin_documents_message']=($action==='delete'?'ลบรายการแล้ว':($action==='disable'?'ยกเลิกการใช้งานแล้ว':'เปิดใช้งานแล้ว')).' '.count(array_unique($ids)).' รายการ';
        header('Location: admin-documents.php?'.http_build_query(['q'=>$query,'enabled'=>$filter,'page'=>$page])); exit;
    }
    $where="WHERE (?='' OR CHARINDEX(?,doc_no)>0 OR CHARINDEX(?,template_name)>0 OR CHARINDEX(?,description)>0) AND (?='all' OR is_enabled=TRY_CONVERT(bit,?))";
    $params=[$query,$query,$query,$query,$filter,$filter];
    $countStmt=sqlsrv_query($db,"SELECT COUNT_BIG(*) AS total FROM dbo.DOCS_HDR $where",$params);
    if(!$countStmt) throw new RuntimeException('นับรายการเอกสารไม่สำเร็จ');
    $total=(int)(sqlsrv_fetch_array($countStmt,SQLSRV_FETCH_ASSOC)['total']??0); sqlsrv_free_stmt($countStmt);
    $pages=max(1,(int)ceil($total/$pageSize)); $page=min($page,$pages); $offset=($page-1)*$pageSize;
    $stmt=sqlsrv_query($db,"SELECT docs_id,doc_no,template_name,description,department,status,is_enabled FROM dbo.DOCS_HDR $where ORDER BY updated_at DESC,docs_id DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",[...$params,$offset,$pageSize]);
    if(!$stmt) throw new RuntimeException('ค้นหาเอกสารไม่สำเร็จ กรุณาติดตั้งฐานข้อมูลสถานะการใช้งาน');
    while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $rows[]=$row; sqlsrv_free_stmt($stmt);
} catch(Throwable $e) { $message=$e->getMessage(); }
finally { if($db!==null) sqlsrv_close($db); }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>จัดการรายการเอกสาร — EIS-DOCS</title><link rel="stylesheet" href="style.css"><style>body{padding:24px}main{max-width:1200px;margin:auto}.admin-search,.admin-actions{display:flex;gap:12px;flex-wrap:wrap;margin:20px 0}input,select{padding:10px}.admin-table{overflow:auto;background:white;border-radius:12px}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:14px;border-bottom:1px solid #ddd}small{display:block;color:#666}.disabled-document{color:#a33}.admin-pagination{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0}.admin-pagination a{padding:7px 12px;background:white;border:1px solid #ccd6e0;border-radius:7px}.admin-pagination a[aria-disabled="true"]{pointer-events:none;color:#999}</style></head><body><main><a href="index.php">← กลับหน้าหลัก</a><h1>จัดการรายการเอกสาร</h1><p>ค้นหาชื่อเอกสารหรือเลข PR แล้วเลือกรายการเพื่อจัดการ</p><p role="status"><?=adminDocEscape($message)?></p>
<form method="get" class="admin-search"><input name="q" value="<?=adminDocEscape($query)?>" placeholder="ชื่อเอกสาร หรือเลข PR" aria-label="ชื่อเอกสารหรือเลข PR" maxlength="200"><select name="enabled" aria-label="การใช้งาน"><option value="all">ทั้งหมด</option><option value="1" <?=$filter==='1'?'selected':''?>>ใช้งาน</option><option value="0" <?=$filter==='0'?'selected':''?>>ยกเลิกการใช้งาน</option></select><button class="btn btn-primary">ค้นหา</button></form>
<p>ยกเลิกการใช้งานเพื่อหยุดการแก้ไขและลงนาม เปิดใช้งานอีกครั้งจะคงสถานะและลำดับลงนามเดิม การลบจะลบรายการ ประวัติ Workflow และไฟล์ PDF/JSON ที่เกี่ยวข้องถาวร</p>
<form method="post" id="adminDocumentForm"><input type="hidden" name="csrf" value="<?=adminDocEscape($_SESSION['app_csrf'])?>"><div class="admin-actions"><button name="action" value="enable" class="btn btn-primary">เปิดใช้งานรายการที่เลือก</button><button name="action" value="disable" class="btn btn-subtle">ยกเลิกการใช้งานรายการที่เลือก</button><button name="action" value="delete" class="btn btn-subtle">ลบรายการที่เลือก</button><span id="selectedCount" aria-live="polite">เลือก 0 รายการ</span></div>
<div class="admin-table"><table><thead><tr><th><input type="checkbox" id="selectAllDocuments" aria-label="เลือกทั้งหมดในหน้านี้"></th><th>PR</th><th>ชื่อเอกสาร</th><th>หน่วยงาน</th><th>Workflow</th><th>การใช้งาน</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><input type="checkbox" name="ids[]" value="<?=adminDocEscape($row['docs_id'])?>" aria-label="เลือก <?=adminDocEscape($row['doc_no'].' '.$row['template_name'])?>"></td><td><?=adminDocEscape($row['doc_no'])?><small>ID <?=adminDocEscape($row['docs_id'])?></small></td><td><?=adminDocEscape($row['template_name'])?><small><?=adminDocEscape($row['description'])?></small></td><td><?=adminDocEscape($row['department'])?></td><td><?=adminDocEscape($row['status'])?></td><td class="<?=$row['is_enabled']?'':'disabled-document'?>"><?=$row['is_enabled']?'ใช้งาน':'ยกเลิกการใช้งาน'?></td></tr><?php endforeach ?>
<?php if(!$rows): ?><tr><td colspan="6">ไม่พบรายการเอกสาร</td></tr><?php endif ?></tbody></table></div></form>
<nav class="admin-pagination" aria-label="แบ่งหน้ารายการเอกสาร"><span>ทั้งหมด <?=adminDocEscape($total)?> รายการ · หน้า <?=adminDocEscape($page)?> / <?=adminDocEscape($pages)?></span><a href="?<?=adminDocEscape(http_build_query(['q'=>$query,'enabled'=>$filter,'page'=>max(1,$page-1)]))?>" aria-disabled="<?=$page<=1?'true':'false'?>">← ก่อนหน้า</a><a href="?<?=adminDocEscape(http_build_query(['q'=>$query,'enabled'=>$filter,'page'=>min($pages,$page+1)]))?>" aria-disabled="<?=$page>=$pages?'true':'false'?>">ถัดไป →</a></nav>
</main><script src="admin-documents.js?v=20261109"></script><?= Presence::script() ?></body></html>
