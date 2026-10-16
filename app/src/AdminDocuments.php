<?php
declare(strict_types=1);
final class AdminDocuments
{
    public static function requireAdmin(): void {
        if (!DocumentAccess::isAdministrator() || Auth::impersonating()) { http_response_code(403); throw new RuntimeException('เฉพาะ admin เท่านั้นที่จัดการรายการเอกสารได้'); }
    }
    public static function apply($db,array $ids,string $action): void {
        self::requireAdmin();
        if (!in_array($action,['enable','disable','delete'],true) || !$ids || count($ids)>100) throw new InvalidArgumentException('เลือกเอกสาร 1–100 รายการและคำสั่งที่ถูกต้อง');
        foreach($ids as $id) if(!filter_var($id,FILTER_VALIDATE_INT) || (int)$id<1) throw new InvalidArgumentException('รหัสเอกสารไม่ถูกต้อง');
        $ids=array_values(array_unique(array_map('intval',$ids)));
        if(!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่มจัดการเอกสารไม่สำเร็จ');
        try {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $stmt=self::query($db,"SELECT docs_id FROM dbo.DOCS_HDR WITH (UPDLOCK,HOLDLOCK) WHERE docs_id IN ($marks) ORDER BY docs_id",$ids);
            $found=[]; while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $found[]=$row['docs_id']; sqlsrv_free_stmt($stmt);
            if(count($found)!==count($ids)) throw new RuntimeException('บางรายการถูกลบแล้ว กรุณาค้นหาใหม่');
            if($action==='delete') {
                $stmt=self::query($db,'SELECT set_id,document_ids FROM dbo.DOCS_SET WITH (UPDLOCK,HOLDLOCK)');
                $sets=[]; while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $sets[]=$row; sqlsrv_free_stmt($stmt);
                foreach($sets as $set) {
                    $old=json_decode((string)$set['document_ids'],true);
                    if(!is_array($old)) throw new RuntimeException('ข้อมูลชุดเอกสารไม่ถูกต้อง');
                    $remaining=array_values(array_filter($old,static fn($id)=>!in_array((int)$id,$ids,true)));
                    if(count($remaining)===count($old)) continue;
                    $stmt=$remaining ? self::query($db,'UPDATE dbo.DOCS_SET SET document_ids=? WHERE set_id=?',[json_encode($remaining),$set['set_id']]) : self::query($db,'DELETE dbo.DOCS_SET WHERE set_id=?',[$set['set_id']]); sqlsrv_free_stmt($stmt);
                }
                foreach(['DOCS_NOTIFICATION','DOCS_DTL','DOCS_HDR'] as $table) { $stmt=self::query($db,"DELETE dbo.$table WHERE docs_id IN ($marks)",$ids); sqlsrv_free_stmt($stmt); }
            } else {
                $stmt=self::query($db,"UPDATE dbo.DOCS_HDR SET is_enabled=?,updated_at=SYSDATETIME() WHERE docs_id IN ($marks)",[$action==='enable'?1:0,...$ids]); sqlsrv_free_stmt($stmt);
            }
            if(!sqlsrv_commit($db)) throw new RuntimeException('ยืนยันการจัดการเอกสารไม่สำเร็จ');
            error_log('Admin documents: user='.DocumentAccess::userId().' action='.$action.' ids='.implode(',',$ids));
        } catch(Throwable $e) { sqlsrv_rollback($db); throw $e; }
    }
    private static function query($db,string $sql,array $params=[]) {
        $stmt=sqlsrv_query($db,$sql,$params); if(!$stmt) throw new RuntimeException('จัดการข้อมูลเอกสารไม่สำเร็จ'); return $stmt;
    }
}
