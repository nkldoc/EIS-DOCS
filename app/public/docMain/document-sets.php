<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!Auth::check()) {http_response_code(401);echo json_encode(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ']);exit;}
try {
    $db=Database::connect('docs');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!safeCmp((string)($_SESSION['app_csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')) || empty($_SESSION['app_csrf'])) throw new RuntimeException('กรุณาเข้าสู่ระบบใหม่');
        $data=json_decode(file_get_contents('php://input'),true);
        $name=trim((string)($data['name']??''));
        $raw=$data['ids']??[];
        if ($name==='' || mb_strlen($name)>250 || !is_array($raw) || count($raw)<1 || count($raw)>200) throw new RuntimeException('ระบุชื่อชุดและเลือกเอกสาร 1–200 รายการ');
        $ids=[];
        foreach($raw as $id){if(!filter_var($id,FILTER_VALIDATE_INT) || (int)$id<1)throw new RuntimeException('เลขเอกสารไม่ถูกต้อง');$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));
        $check=sqlsrv_query($db,'SELECT COUNT(*) n FROM dbo.DOCS_HDR h WHERE h.is_enabled=1 AND docs_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND (?=1 OR EXISTS (SELECT 1 FROM dbo.DOCS_DTL d WHERE d.docs_id=h.docs_id AND d.wf_order=1 AND d.user_id=?))',[...$ids,...DocumentAccess::params()]);
        if(!$check || (int)sqlsrv_fetch_array($check,SQLSRV_FETCH_ASSOC)['n']!==count($ids))throw new RuntimeException('เอกสารบางรายการไม่พบแล้ว กรุณาโหลดข้อมูลใหม่');
        $ap=null;
        $apId=$data['ap_id']??null;
        if($apId!==null && $apId!=='') {
            if(!ctype_digit((string)$apId))throw new RuntimeException('เลขตรวจรับไม่ถูกต้อง');
            $apSql=<<<'SQL'
SELECT h.sp_check_period_hdr_id,h.c_code,n.c_name,h.f_total_add_vat_amt amount,c.c_code contract_code
FROM NMU_ERP.dbo.sp_check_period_hdr h
LEFT JOIN NMU_ERP.dbo.sp_tor_contract c ON c.sp_tor_contract_id=h.sp_tor_contract_id
OUTER APPLY (SELECT STUFF((SELECT N' / '+CONVERT(nvarchar(max),d.c_name)
 FROM NMU_ERP.dbo.sp_check_period_dtl d WHERE d.sp_check_period_hdr_id=h.sp_check_period_hdr_id
 ORDER BY d.sp_check_period_dtl_id FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,3,N'') c_name) n
WHERE h.sp_check_period_hdr_id=? AND (?=1 OR h.dc_user_create_id=?)
SQL;
            $apStmt=sqlsrv_query($db,$apSql,[$apId,...DocumentAccess::params()]);
            if(!$apStmt || !($ap=sqlsrv_fetch_array($apStmt,SQLSRV_FETCH_ASSOC)))throw new RuntimeException('ไม่พบเลขตรวจรับที่เลือก');
        }
        $stmt=sqlsrv_query($db,'INSERT dbo.DOCS_SET(set_name,document_ids,created_by,ap_id,ap_code,ap_name,ap_amount,contract_code) VALUES (?,?,?,?,?,?,?,?)',[$name,json_encode($ids),(string)Auth::user()['user_id'],$ap['sp_check_period_hdr_id']??null,$ap['c_code']??null,$ap['c_name']??null,$ap['amount']??null,$ap['contract_code']??null]);
        if(!$stmt)throw new RuntimeException('บันทึกชุดเอกสารไม่สำเร็จ');
    }
    $stmt=sqlsrv_query($db,'SELECT set_id,set_name,document_ids,ap_code,ap_name,ap_amount,contract_code,CONVERT(varchar(19),created_at,120) created_at FROM dbo.DOCS_SET WHERE (?=1 OR created_by=?) ORDER BY set_id DESC',DocumentAccess::params());
    if(!$stmt)throw new RuntimeException('โหลดชุดเอกสารไม่สำเร็จ');
    $sets=[];while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)){$row['ids']=json_decode($row['document_ids'],true);unset($row['document_ids']);$sets[]=$row;}
    echo json_encode(['success'=>true,'sets'=>$sets],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
