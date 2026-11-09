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
        $staged=[];
        try {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $stmt=self::query($db,"SELECT docs_id FROM dbo.DOCS_HDR WITH (UPDLOCK,HOLDLOCK) WHERE docs_id IN ($marks) ORDER BY docs_id",$ids);
            $found=[]; while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $found[]=$row['docs_id']; sqlsrv_free_stmt($stmt);
            if(count($found)!==count($ids)) throw new RuntimeException('บางรายการถูกลบแล้ว กรุณาค้นหาใหม่');
            if($action==='delete') {
                $files=self::filesForDeletion($db,$ids,$marks);
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
                foreach($files as $file) {
                    if(!is_file($file)) continue;
                    $temporary=$file.'.deleting-'.bin2hex(random_bytes(8));
                    if(!rename($file,$temporary)) throw new RuntimeException('ย้ายไฟล์เอกสารเพื่อเตรียมลบไม่สำเร็จ: '.basename($file));
                    $staged[$temporary]=$file;
                }
            } else {
                $stmt=self::query($db,"UPDATE dbo.DOCS_HDR SET is_enabled=?,updated_at=SYSDATETIME() WHERE docs_id IN ($marks)",[$action==='enable'?1:0,...$ids]); sqlsrv_free_stmt($stmt);
            }
            if(!sqlsrv_commit($db)) throw new RuntimeException('ยืนยันการจัดการเอกสารไม่สำเร็จ');
            foreach($staged as $temporary=>$original) if(!unlink($temporary)) error_log('Admin documents: cannot remove staged file '.$temporary);
            error_log('Admin documents: user='.DocumentAccess::userId().' action='.$action.' ids='.implode(',',$ids));
        } catch(Throwable $e) { sqlsrv_rollback($db); foreach($staged as $temporary=>$original) if(is_file($temporary)) rename($temporary,$original); throw $e; }
    }
    private static function filesForDeletion($db,array $ids,string $marks): array {
        $root=realpath(getenv('EIS_DOCS_PDF_STORAGE') ?: dirname(__DIR__,2).'/storage/pdf');
        if($root===false) throw new RuntimeException('ไม่พบโฟลเดอร์เก็บ PDF');
        $stmt=self::query($db,"SELECT pdf_doc_id,file_path FROM dbo.DOCS_HDR WHERE docs_id IN ($marks)",$ids);
        $files=[];
        while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) foreach(['pdf_doc_id','file_path'] as $field) {
            $path=self::storedPdfPath($root,(string)($row[$field]??''));
            if($path!==null) { $files[$path]=$path; $files[substr($path,0,-4).'.json']=substr($path,0,-4).'.json'; }
        }
        sqlsrv_free_stmt($stmt);
        // A file still used by another document must remain available.
        $stmt=self::query($db,"SELECT pdf_doc_id,file_path FROM dbo.DOCS_HDR WHERE docs_id NOT IN ($marks)",$ids);
        while($row=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) foreach(['pdf_doc_id','file_path'] as $field) {
            $path=self::storedPdfPath($root,(string)($row[$field]??''));
            if($path!==null) { unset($files[$path],$files[substr($path,0,-4).'.json']); }
        }
        sqlsrv_free_stmt($stmt);
        return array_values($files);
    }
    private static function storedPdfPath(string $root,string $value): ?string {
        $value=trim(str_replace('\\','/',$value));
        if($value==='' || str_contains($value,'..') || preg_match('/[?*"<>|]/',$value)) return null;
        $root=rtrim(str_replace('\\','/',$root),'/');
        if(preg_match('~^[a-zA-Z]:/~',$value)) {
            if(strncasecmp($value,$root.'/',strlen($root)+1)!==0) return null;
            $value=substr($value,strlen($root)+1);
        } elseif(str_starts_with($value,'/') || str_contains($value,':')) return null;
        if(!str_ends_with(strtolower($value),'.pdf')) $value.='.pdf';
        $candidate=$root.'/'.$value; $parent=realpath(dirname($candidate));
        $parent=str_replace('\\','/',(string)$parent);
        if($parent==='' || (strcasecmp($parent,$root)!==0 && strncasecmp($parent,$root.'/',strlen($root)+1)!==0)) return null;
        return $parent.'/'.basename($candidate);
    }
    private static function query($db,string $sql,array $params=[]) {
        $stmt=sqlsrv_query($db,$sql,$params); if(!$stmt) throw new RuntimeException('จัดการข้อมูลเอกสารไม่สำเร็จ'); return $stmt;
    }
}
