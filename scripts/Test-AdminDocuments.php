<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$db=Database::connect('docs'); $ids=[]; $sets=[]; $prefix='__admin_docs_'.bin2hex(random_bytes(5));
function adminTestQuery($sql,$params=[]) { global $db; $s=sqlsrv_query($db,$sql,$params); if(!$s) throw new RuntimeException(json_encode(sqlsrv_errors())); return $s; }
function adminTestCheck($ok,$message) { if(!$ok) throw new RuntimeException($message); }
function enabledRow($id) { $s=adminTestQuery('SELECT is_enabled,status FROM dbo.DOCS_HDR WHERE docs_id=?',[$id]); return sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); }
try {
    foreach([1,2] as $i) {
        $s=adminTestQuery("INSERT dbo.DOCS_HDR(doc_no,doc_type,doc_type_name,department,requester_name,doc_date,amount,template_name,status) OUTPUT INSERTED.docs_id VALUES (?,'PR','PR','test','test',GETDATE(),0,?,'PENDING')",[$prefix.'_'.$i,$prefix.'_'.$i]);
        $ids[]=(int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['docs_id'];
    }
    adminTestQuery("INSERT dbo.DOCS_DTL(docs_id,wf_order,user_id,signer_name,review_action,sign_action,approve_action,sign_status) VALUES (?,1,'__admin_test','test',1,1,0,'DONE')",[$ids[0]]);
    adminTestQuery("INSERT dbo.DOCS_NOTIFICATION(user_id,docs_id,notification_type,title,message) VALUES ('__admin_test',?,'test','test','test')",[$ids[0]]);
    foreach([[$ids[0]],$ids] as $list) {
        $s=adminTestQuery('INSERT dbo.DOCS_SET(set_name,document_ids,created_by) OUTPUT INSERTED.set_id VALUES (?,?,?)',[$prefix,json_encode($list),'__admin_test']);
        $sets[]=(int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['set_id'];
    }
    $_SESSION['auth']=['user_id'=>1,'user_type'=>3];
    try { AdminDocuments::apply($db,[$ids[0]],'delete'); throw new LogicException('Non-admin allowed'); } catch(RuntimeException $e) { adminTestCheck(http_response_code()===403,'Wrong authorization response'); }
    http_response_code(200); $_SESSION['auth']=['user_id'=>1,'user_type'=>2];
    AdminDocuments::apply($db,$ids,'disable');
    adminTestCheck(!enabledRow($ids[0])['is_enabled'] && enabledRow($ids[0])['status']==='PENDING','Disable corrupted workflow');
    try { DocumentAccess::requireOwner($db,$ids[0]); throw new LogicException('Disabled document editable'); } catch(RuntimeException $e) { adminTestCheck(http_response_code()===403,'Disabled owner guard failed'); }
    http_response_code(200);
    AdminDocuments::apply($db,$ids,'enable');
    adminTestCheck(enabledRow($ids[0])['is_enabled'] && enabledRow($ids[0])['status']==='PENDING','Enable corrupted workflow');
    $s=adminTestQuery('SELECT sign_status FROM dbo.DOCS_DTL WHERE docs_id=?',[$ids[0]]);
    adminTestCheck(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['sign_status']==='DONE','Signing result reset');
    try { AdminDocuments::apply($db,[$ids[0],2147483646],'disable'); throw new LogicException('Missing document accepted'); } catch(RuntimeException $e) {}
    adminTestCheck(enabledRow($ids[0])['is_enabled'],'Partial mutation was committed');
    AdminDocuments::apply($db,[$ids[0]],'delete');
    adminTestCheck(!enabledRow($ids[0]),'Header not deleted');
    foreach(['DOCS_DTL','DOCS_NOTIFICATION'] as $table) { $s=adminTestQuery("SELECT COUNT(*) n FROM dbo.$table WHERE docs_id=?",[$ids[0]]); adminTestCheck((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']===0,'Dependent rows remain'); }
    $s=adminTestQuery('SELECT set_id,document_ids FROM dbo.DOCS_SET WHERE set_id IN (?,?) ORDER BY set_id',$sets);
    $remaining=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
    adminTestCheck((int)$remaining['set_id']===$sets[1] && json_decode($remaining['document_ids'],true)===[$ids[1]],'Set references not cleaned');
    adminTestCheck(!sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC),'Empty set remains');
    echo "PASS: admin authorization, bulk enable/disable, workflow preserved, disabled editing blocked, atomic failure, deletion and set cleanup\n";
} finally {
    foreach($sets as $id) adminTestQuery('DELETE dbo.DOCS_SET WHERE set_id=?',[$id]);
    foreach($ids as $id) { adminTestQuery('DELETE dbo.DOCS_NOTIFICATION WHERE docs_id=?',[$id]); adminTestQuery('DELETE dbo.DOCS_DTL WHERE docs_id=?',[$id]); adminTestQuery('DELETE dbo.DOCS_HDR WHERE docs_id=?',[$id]); }
    sqlsrv_close($db);
}
