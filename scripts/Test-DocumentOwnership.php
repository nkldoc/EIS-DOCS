<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../app/src/bootstrap.php';
$db=Database::connect('docs');
$prefix='__doc_access_'.bin2hex(random_bytes(6));
$runner=tempnam(sys_get_temp_dir(),'doc_runner_');
$input=tempnam(sys_get_temp_dir(),'doc_input_');
$ids=[];
function verify($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function q($sql,$params=[]) { global $db; $s=sqlsrv_query($db,$sql,$params); if (!$s) throw new RuntimeException(json_encode(sqlsrv_errors())); return $s; }
function endpoint($name,$auth,$method='GET',$get=[],$body=[]) {
    global $runner,$input;
    file_put_contents($input,json_encode($body));
    $bootstrap=realpath(__DIR__.'/../app/src/bootstrap.php');
    $code=file_get_contents(__DIR__.'/../app/public/docMain/'.$name.'.php');
    $code=preg_replace('/require_once .*?bootstrap\.php\x27;/', 'require_once '.var_export($bootstrap,true).';',$code);
    $code=str_replace('php://input',str_replace('\\','/',$input),$code);
    $setup='require_once '.var_export($bootstrap,true).'; $_SESSION["auth"]='.var_export($auth,true).'; $_SESSION["app_csrf"]="test"; $_SERVER["HTTP_X_CSRF_TOKEN"]="test"; $_SERVER["REQUEST_METHOD"]='.var_export($method,true).'; $_GET='.var_export($get,true).';';
    $code=str_replace('declare(strict_types=1);','declare(strict_types=1);'.$setup,$code);
    file_put_contents($runner,$code);
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner),$lines,$exit);
    $result=json_decode(implode("\n",$lines),true);
    verify($exit===0 && is_array($result),'Invalid response: '.$name);
    return $result;
}
$testCost=random_int(1900000000,2000000000);
$owner=['user_id'=>'999999981','user_type'=>1,'full_name'=>'Ownership test','cost_id'=>$testCost];
$other=['user_id'=>'999999982','user_type'=>3,'full_name'=>'Other test','cost_id'=>$testCost];
$admin=['user_id'=>'999999983','user_type'=>2];
try {
    q('INSERT dbo.DOCS_DEPARTMENT_PERMISSION(dc_cost_id,granted_by) VALUES (?,?)',[$testCost,$prefix]);
    foreach ([$owner,$other] as $i=>$identity) {
        $r=endpoint('document-templates',$identity,'POST',[],['pr_code'=>$prefix.'_'.$i,'doc_type'=>'PR','template_name'=>$prefix,'route'=>[['user_id'=>$identity['user_id'],'signer_name'=>'Test signer']],'created_by'=>$admin['user_id']]);
        verify($r['success'],'Create document failed'); $ids[]=$r['docs_id'];
        $s=q('SELECT created_by FROM dbo.DOCS_HDR WHERE docs_id=?',[$r['docs_id']]);
        verify(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['created_by']===$identity['user_id'],'Creator was not taken from session');
    }
    // Creator and WF1 deliberately differ: access must follow WF1, not creator.
    q('UPDATE dbo.DOCS_HDR SET created_by=? WHERE docs_id=?',[$other['user_id'],$ids[0]]);
    q('UPDATE dbo.DOCS_HDR SET created_by=NULL WHERE docs_id=?',[$ids[1]]);
    q('INSERT dbo.DOCS_DTL(docs_id,wf_order,user_id,signer_name,review_action,sign_action,approve_action,sign_status) VALUES (?,2,?,?,1,0,0,?)',[$ids[0],$other['user_id'],'WF2 test','PENDING']);
    foreach ([[$owner,[$ids[0]]],[$other,[$ids[1]]],[$admin,$ids]] as [$identity,$expected]) {
        $r=endpoint('document-templates',$identity); verify($r['success'],'Document list failed');
        $visible=array_column($r['documents'],'docs_id');
        foreach($ids as $id) verify(in_array($id,$visible)===in_array($id,$expected),'Document list leaked data');
        $r=endpoint('document-templates',$identity,'GET',['id'=>$ids[0]]);
        verify($r['success'] && count($r['documents'])===($identity===$other?0:1),'Direct ID leaked data');
    }
    foreach (['POST','DELETE'] as $method) {
        $r=endpoint('document-templates',$other,$method,[],['docs_id'=>$ids[0],'action'=>'publish_pdf','pdf_doc_id'=>'forged']);
        verify(!$r['success'],'Foreign document mutation allowed');
    }
    q("UPDATE dbo.DOCS_HDR SET status='PENDING' WHERE docs_id=?",[$ids[0]]);
    $edit=['docs_id'=>$ids[0],'pr_code'=>$prefix.'_0','doc_type'=>'PR','template_name'=>$prefix,'description'=>'Updated before signing','route'=>[
        ['user_id'=>$owner['user_id'],'signer_name'=>'WF1 test','review_action'=>true,'sign_action'=>true],
        ['user_id'=>$other['user_id'],'signer_name'=>'WF2 test','sign_action'=>true],
    ]];
    $r=endpoint('document-templates',$owner,'POST',[],$edit);
    verify($r['success'],'Unsigned WF1 could not edit pending document');
    $s=q('SELECT status,description FROM dbo.DOCS_HDR WHERE docs_id=?',[$ids[0]]);
    $record=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
    verify($record['status']==='DRAFT' && $record['description']==='Updated before signing','Edit did not require regenerated PDF');
    $revision=$edit;
    $revision['preserve_workflow']=true;
    $revision['route']=array_reverse($edit['route']);
    $r=endpoint('document-templates',$owner,'POST',[],$revision);
    verify($r['success'],'Confirm revision failed');
    $s=q('SELECT user_id,wf_order FROM dbo.DOCS_DTL WHERE docs_id=? ORDER BY wf_order',[$ids[0]]);
    $wf1=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
    $wf2=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
    verify($wf1['user_id']===$owner['user_id'] && $wf2['user_id']===$other['user_id'],'Confirm revision changed original workflow');
    q("UPDATE dbo.DOCS_DTL SET sign_status='DONE',decision='PASS',signed_at=SYSDATETIME(),review_marks_json='[]' WHERE docs_id=? AND wf_order=2",[$ids[0]]);
    $publish=['action'=>'publish_pdf','docs_id'=>$ids[0],'pdf_doc_id'=>$prefix.'/regenerated'];
    $r=endpoint('document-templates',$other,'POST',[],$publish);
    verify(!$r['success'],'Non-owner restarted workflow');
    $r=endpoint('document-templates',$owner,'POST',[],$publish);
    verify($r['success'] && $r['workflow_reset'],'Publish did not restart workflow');
    $s=q("SELECT COUNT(*) n FROM dbo.DOCS_DTL WHERE docs_id=? AND (sign_status<>'PENDING' OR decision IS NOT NULL OR signed_at IS NOT NULL OR review_marks_json IS NOT NULL)",[$ids[0]]);
    verify((int)sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']===0,'Publish retained old workflow results');
    $s=q("SELECT TOP (1) wf_order,user_id FROM dbo.DOCS_DTL WHERE docs_id=? AND sign_status='PENDING' ORDER BY wf_order",[$ids[0]]);
    $first=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
    verify((int)$first['wf_order']===1 && $first['user_id']===$owner['user_id'],'Publish did not start with configured WF1');
    q("UPDATE dbo.DOCS_DTL SET sign_status='DONE',signed_at=SYSDATETIME() WHERE docs_id=? AND wf_order=1",[$ids[0]]);
    $edit['description']='Should not be saved';
    $r=endpoint('document-templates',$owner,'POST',[],$edit);
    verify(!$r['success'],'Already signed WF1 edited document');
    $s=q('SELECT description FROM dbo.DOCS_HDR WHERE docs_id=?',[$ids[0]]);
    verify(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['description']==='Updated before signing','Rejected edit modified document');
    $s=q('SELECT sign_status FROM dbo.DOCS_DTL WHERE docs_id=? AND wf_order=1',[$ids[0]]);
    verify(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['sign_status']==='DONE','Rejected edit reset signature');
    $r=endpoint('document-templates',$owner,'POST',[],$publish);
    verify(!$r['success'],'Publish reset an already signed WF1');
    q("UPDATE dbo.DOCS_DTL SET sign_status='PENDING',signed_at=NULL WHERE docs_id=? AND wf_order=1",[$ids[0]]);
    $r=endpoint('document-sets',$other,'POST',[],['name'=>$prefix,'ids'=>[$ids[0]]]); verify(!$r['success'],'Foreign document set allowed');
    $r=endpoint('document-sets',$owner,'POST',[],['name'=>$prefix,'ids'=>[$ids[0]]]); verify($r['success'],'Own set failed');
    $r=endpoint('document-sets',$other); verify($r['success'] && !in_array($prefix,array_column($r['sets'],'set_name')),'Foreign set leaked');
    foreach (['pr-documents'=>['sp_tor','tor_id','documents','id'], 'ap-search'=>['sp_check_period_hdr','sp_check_period_hdr_id','rows','id']] as $api=>$spec) {
        [$table,$pk,$key,$idKey]=$spec;
        $s=q("SELECT TOP (1) dc_user_create_id FROM NMU_ERP.dbo.$table WHERE dc_user_create_id IS NOT NULL AND c_code IS NOT NULL ORDER BY $pk DESC");
        $row=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC); verify((bool)$row,'No ERP fixture');
        $identity=['user_id'=>(string)$row['dc_user_create_id'],'user_type'=>1];
        foreach (['','%'] as $search) {
            $r=endpoint($api,$identity,'GET',['q'=>$search]); verify($r['success'],'ERP search failed: '.$api);
            foreach($r[$key] as $item) {
                $s=q("SELECT dc_user_create_id FROM NMU_ERP.dbo.$table WHERE $pk=?",[$item[$idKey]]);
                $creator=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['dc_user_create_id'];
                if ($api!=='pr-documents') verify((string)$creator===$identity['user_id'],'ERP search leaked foreign records');
            }
        }
        $r=endpoint($api,$admin); verify($r['success'] && count($r[$key])>0,'Administrator ERP search failed');
        $s=q("SELECT TOP (1) $pk id,c_code FROM NMU_ERP.dbo.$table WHERE dc_user_create_id<>? AND c_code IS NOT NULL ORDER BY $pk DESC",[$identity['user_id']]);
        $foreign=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
        if ($foreign) {
            $r=endpoint($api,$identity,'GET',['q'=>trim($foreign['c_code'])]);
            $found=in_array((string)$foreign['id'],array_map('strval',array_column($r[$key],$idKey)),true);
            verify($r['success'] && $found===($api==='pr-documents'),'Exact PR/AP search access incorrect');
            if ($api==='pr-documents') {
                $r=endpoint('document-templates',$owner,'POST',[],['pr_id'=>$foreign['id'],'pr_code'=>trim($foreign['c_code']),'doc_type'=>'PR','template_name'=>$prefix,'route'=>[['user_id'=>$owner['user_id'],'signer_name'=>'Test']]]);
                verify($r['success'],'Shared PR could not be attached');
                $ids[]=$r['docs_id'];
            } else {
                $r=endpoint('document-sets',$owner,'POST',[],['name'=>$prefix,'ids'=>[$ids[0]],'ap_id'=>$foreign['id']]);
                verify(!$r['success'],'Foreign AP could be attached');
            }
        }
    }
    echo "PASS: unsigned WF1 can edit; signed WF1 edit rejected without resetting signatures; WF1 visibility, shared PR and AP ownership retained\n";
} finally {
    q('DELETE dbo.DOCS_DEPARTMENT_PERMISSION WHERE dc_cost_id=? AND granted_by=?',[$testCost,$prefix]);
    q('DELETE dbo.DOCS_SET WHERE set_name=?',[$prefix]);
    foreach($ids as $id) { q('DELETE dbo.DOCS_DTL WHERE docs_id=?',[$id]); q('DELETE dbo.DOCS_HDR WHERE docs_id=?',[$id]); }
    unlink($runner);unlink($input);sqlsrv_close($db);
}
