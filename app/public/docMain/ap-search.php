<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(!Auth::check()){http_response_code(401);echo json_encode(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ']);exit;}
try {
 $q=trim((string)($_GET['q']??''));
 if(mb_strlen($q)>150)throw new RuntimeException('คำค้นยาวเกินไป');
 $db=Database::connect('erp');
 $pattern='%'.str_replace(['[','%','_'],['[[]','[%]','[_]'],$q).'%';
 $sql=<<<'SQL'
SELECT TOP (50) CONVERT(varchar(30),h.sp_check_period_hdr_id) id,h.c_code,
 h.f_total_add_vat_amt amount,n.c_name,c.c_code contract_code
FROM dbo.sp_check_period_hdr h
LEFT JOIN dbo.sp_tor_contract c ON c.sp_tor_contract_id=h.sp_tor_contract_id
OUTER APPLY (SELECT STUFF((SELECT N' / '+CONVERT(nvarchar(max),d.c_name)
 FROM dbo.sp_check_period_dtl d WHERE d.sp_check_period_hdr_id=h.sp_check_period_hdr_id
 ORDER BY d.sp_check_period_dtl_id FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,3,N'') c_name) n
WHERE (h.c_code LIKE ? OR EXISTS (SELECT 1 FROM dbo.sp_check_period_dtl d WHERE d.sp_check_period_hdr_id=h.sp_check_period_hdr_id AND d.c_name LIKE ?) OR c.c_code LIKE ?)
 AND (?=1 OR h.dc_user_create_id=?)
ORDER BY h.sp_check_period_hdr_id DESC
SQL;
 $stmt=sqlsrv_query($db,$sql,[$pattern,$pattern,$pattern,...DocumentAccess::params()]);if(!$stmt)throw new RuntimeException('ค้นหาเลขตรวจรับไม่สำเร็จ');
 $rows=[];while($r=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC))$rows[]=$r;
 echo json_encode(['success'=>true,'rows'=>$rows],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
