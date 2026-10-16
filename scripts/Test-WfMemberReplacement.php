<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Integration check: temporary groups only; always cleaned up.
require_once __DIR__ . '/../app/src/Database.php';
$db = Database::connect('docs');
function query($sql, $params = []) {
    global $db;
    $s = sqlsrv_query($db, $sql, $params);
    if (!$s) throw new RuntimeException(json_encode(sqlsrv_errors()));
    return $s;
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$prefix = '__wf_test_' . bin2hex(random_bytes(8));
$names = [$prefix, $prefix . '_copy', $prefix . '_invalid', $prefix . '_created', $prefix . '_renamed'];
$scopeNames = ['private'=>$prefix.'_private', 'department'=>$prefix.'_department', 'all'=>$prefix.'_all'];
$names = array_merge($names, array_values($scopeNames), [$prefix.'_denied', $prefix.'_scope_renamed']);
$runner = tempnam(sys_get_temp_dir(), 'wf_runner_');
$input = tempnam(sys_get_temp_dir(), 'wf_input_');
$endpoint = realpath(__DIR__ . '/../app/public/docMain/wf-groups.php');
$bootstrap = realpath(__DIR__ . '/../app/src/bootstrap.php');
$code = file_get_contents($endpoint);
$code = str_replace("require_once dirname(__DIR__, 2) . '/src/bootstrap.php';", 'require_once ' . var_export($bootstrap, true) . ';', $code);
$code = str_replace('php://input', str_replace('\\', '/', $input), $code);
$setup = '<?php require_once ' . var_export($bootstrap, true) . '; $_SESSION["auth"]=["user_id"=>"integration-test","user_type"=>2]; $_SESSION["app_csrf"]="integration-test"; $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["HTTP_X_CSRF_TOKEN"]="integration-test"; ?>';
$setup = str_replace('?>', '$_SESSION["auth"] = json_decode(file_get_contents(' . var_export($input, true) . '), true)["_test_auth"] ?? $_SESSION["auth"]; ?>', $setup);
$setup = str_replace('?>', '$testInput=json_decode(file_get_contents(' . var_export($input, true) . '),true); $_SERVER["REQUEST_METHOD"]=$testInput["_test_method"] ?? "POST"; $_GET=$testInput["_test_get"] ?? []; ?>', $setup);
file_put_contents($runner, str_replace('declare(strict_types=1);', 'declare(strict_types=1); ?>' . $setup . '<?php ', $code));
function api($payload) {
    global $input, $runner;
    file_put_contents($input, json_encode($payload));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner), $lines, $status);
    $result = json_decode(implode("\n", $lines), true);
    check($status === 0 && is_array($result), 'Endpoint returned invalid JSON');
    return $result;
}
function members($name) {
    $s = query('SELECT CAST(user_id AS varchar(100)) user_id, dc_emp_id, wf_order, review_action, sign_action, approve_action FROM dbo.WF_USER_DTL WHERE c_name=? ORDER BY wf_order', [$name]);
    $rows = []; while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    return $rows;
}
try {
    $s = query('SELECT TOP (3) CAST(u.dc_user_id AS varchar(100)) user_id, e.dc_emp_id FROM NMU_DATACENTER.dbo.dc_user u JOIN NMU_DATACENTER.dbo.dc_emp e ON e.dc_emp_id=u.dc_emp_id WHERE u.i_enable=1 AND u.i_delete=2 AND e.i_enable=1 AND e.i_delete=2 ORDER BY u.dc_user_id');
    $users = []; while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $users[] = $r;
    check(count($users) === 3, 'Need three active users');
    query('INSERT dbo.WF_USER_HDR(c_name) VALUES (?)', [$names[0]]);
    foreach ([0, 1] as $i) query('INSERT dbo.WF_USER_DTL(user_id,c_name,dc_emp_id,wf_order,review_action,sign_action,approve_action) VALUES (?,?,?,?,1,0,0)', [$users[$i]['user_id'], $names[0], $users[$i]['dc_emp_id'], $i + 1]);
    $before = members($names[0]);
    $replacement = ['old_user_id'=>$users[0]['user_id'], 'old_dc_emp_id'=>(string)$users[0]['dc_emp_id'], 'user_id'=>$users[2]['user_id'], 'dc_emp_id'=>(string)$users[2]['dc_emp_id']];
    $payload = ['action'=>'copy_group','source_name'=>$names[0],'group_name'=>$names[1],'replacements'=>[$replacement]];
    check(api($payload)['success'], 'Save As failed');
    check(members($names[0]) === $before, 'Save As changed source');
    $copy = members($names[1]);
    check($copy[0]['user_id'] === $users[2]['user_id'] && $copy[0]['wf_order'] === 1 && $copy[0]['review_action'] == 1 && count($copy) === 2, 'Copy member/order/actions incorrect');
    $payload['action']='rename_group'; $payload['group_name']=$names[0];
    check(api($payload)['success'], 'Save Update failed');
    check(members($names[0]) === $copy, 'Update mismatch');
    $replacement['old_user_id']=$users[2]['user_id']; $replacement['old_dc_emp_id']=(string)$users[2]['dc_emp_id'];
    $replacement['user_id']=$users[1]['user_id']; $replacement['dc_emp_id']=(string)$users[1]['dc_emp_id'];
    $payload=['action'=>'copy_group','source_name'=>$names[0],'group_name'=>$names[2],'replacements'=>[$replacement]];
    check(!api($payload)['success'], 'Duplicate user accepted');
    $s=query('SELECT COUNT(*) n FROM dbo.WF_USER_HDR WHERE c_name=?',[$names[2]]);
    check(sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)['n'] === 0, 'Failed copy was not rolled back');
    check(members($names[0]) === $copy, 'Failed copy changed source');
    $edit = ['old_user_id'=>$users[2]['user_id'], 'old_dc_emp_id'=>(string)$users[2]['dc_emp_id'], 'user_id'=>$users[2]['user_id'], 'dc_emp_id'=>(string)$users[2]['dc_emp_id'], 'wf_order'=>7, 'review_action'=>false, 'sign_action'=>true, 'approve_action'=>true];
    $payload = ['action'=>'copy_group','source_name'=>$names[0],'group_name'=>$names[2],'replacements'=>[$edit]];
    check(api($payload)['success'], 'Copy edited actions/order failed');
    check(members($names[0]) === $copy, 'Editing copy changed source');
    $edited = members($names[2]);
    check($edited[1]['wf_order'] === 2 && !$edited[1]['review_action'] && $edited[1]['sign_action'] && $edited[1]['approve_action'], 'Edited actions/order incorrect');
    $payload['action']='rename_group'; $payload['group_name']=$names[0];
    check(api($payload)['success'] && members($names[0]) === $edited, 'Update actions/order failed');
    foreach ([['wf_order'=>0], ['wf_order'=>1.5], ['review_action'=>false,'sign_action'=>false,'approve_action'=>false]] as $invalid) {
        $payload['replacements']=[array_replace($edit, $invalid)];
        check(!api($payload)['success'] && members($names[0]) === $edited, 'Invalid edit accepted or changed data');
    }
    $auth = ['user_id'=>$users[0]['user_id'], 'employee_id'=>$users[0]['dc_emp_id'], 'user_type'=>2];
    check(api(['action'=>'create_group','group_name'=>$names[3],'_test_auth'=>$auth])['success'], 'Create failed');
    $created=members($names[3]);
    check($created[0]['review_action'] && $created[0]['sign_action'] && !$created[0]['approve_action'], 'New WF1 defaults incorrect');
    check(api(['action'=>'set_current_user_wf1','group_name'=>$names[0],'_test_auth'=>$auth])['success'], 'Appoint new WF1 failed');
    $appointed=members($names[0]);
    check($appointed[0]['user_id']===$users[0]['user_id'] && $appointed[0]['wf_order']===1 && $appointed[0]['review_action'] && $appointed[0]['sign_action'], 'Appointed WF1 defaults incorrect');
    $auth = ['user_id'=>$users[2]['user_id'], 'employee_id'=>$users[2]['dc_emp_id'], 'user_type'=>2];
    check(api(['action'=>'set_current_user_wf1','group_name'=>$names[0],'_test_auth'=>$auth])['success'], 'Appoint existing WF1 failed');
    $appointed=members($names[0]);
    check($appointed[0]['user_id']===$users[2]['user_id'] && $appointed[0]['wf_order']===1 && $appointed[0]['review_action'] && $appointed[0]['sign_action'] && $appointed[0]['approve_action'], 'Existing WF1 defaults or approval preservation incorrect');
    $copyBeforeRename = members($names[1]);
    check(api(['action'=>'rename_group','source_name'=>$names[1],'group_name'=>$names[4]])['success'], 'Rename with members failed');
    check(members($names[4]) === $copyBeforeRename && members($names[1]) === [], 'Rename lost members');
    $s=query('SELECT COUNT(*) n FROM dbo.WF_USER_HDR WHERE c_name=?',[$names[1]]);
    check(sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)['n'] === 0, 'Old group name remained');
    check(!api(['action'=>'rename_group','source_name'=>$names[4],'group_name'=>$names[0]])['success'], 'Duplicate group name accepted');
    check(members($names[4]) === $copyBeforeRename, 'Failed rename changed members');
    check(api(['action'=>'delete_group','group_name'=>$names[4]])['success'], 'Delete group failed');
    $s=query('SELECT COUNT(*) n FROM dbo.WF_USER_HDR WHERE c_name=?',[$names[4]]);
    check(sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)['n'] === 0 && members($names[4]) === [], 'Delete left group or members');
    check(members($names[0]) === $appointed, 'Delete affected source group');
    check(!api(['action'=>'delete_group','group_name'=>$names[4]])['success'], 'Missing group deletion accepted');
    $owner=['user_id'=>'scope-owner','cost_id'=>123,'user_type'=>1];
    $colleague=['user_id'=>'scope-colleague','cost_id'=>123];
    $outsider=['user_id'=>'scope-outsider','cost_id'=>456];
    $missingCost=['user_id'=>'scope-no-cost'];
    $departmentUser=['user_id'=>'scope-department','cost_id'=>123,'user_type'=>3];
    $administrator=['user_id'=>'scope-admin','cost_id'=>456,'user_type'=>2];
    query('UPDATE dbo.WF_USER_HDR SET owner_user_id=? WHERE c_name=?',[$owner['user_id'],$names[0]]);
    foreach ($scopeNames as $scope=>$name) {
        check(api(['action'=>'copy_group','source_name'=>$names[0],'group_name'=>$name,'visibility'=>$scope,'owner_user_id'=>'forged','owner_cost_id'=>456,'_test_auth'=>$owner])['success'], 'Scoped copy failed');
        check(members($name) === $appointed, 'Scoped copy lost signers');
        $s=query('SELECT visibility,owner_user_id,owner_cost_id FROM dbo.WF_USER_HDR WHERE c_name=?',[$name]);
        $metadata=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC);
        check($metadata['visibility']===$scope && $metadata['owner_user_id']===$owner['user_id'] && $metadata['owner_cost_id']===123, 'Owner/department must come from session');
    }
    foreach ([[$owner,['private','department','all']],[$colleague,['all']],[$outsider,['all']],[$missingCost,['all']],[$departmentUser,['all']],[$administrator,['private','department','all']]] as [$identity,$expected]) {
        $response=api(['_test_method'=>'GET','_test_get'=>['q'=>$prefix],'_test_auth'=>$identity]);
        check($response['success'], 'Scoped list failed');
        $ids=array_column($response['groups'],'id');
        foreach ($scopeNames as $scope=>$name) {
            $allowed=in_array($scope,$expected,true);
            check(in_array($name,$ids,true)===$allowed, 'List visibility mismatch: '.$scope);
            $detail=api(['_test_method'=>'GET','_test_get'=>['group_id'=>$name],'_test_auth'=>$identity]);
            check($detail['success']===$allowed, 'Direct access visibility mismatch: '.$scope);
            if ($allowed) check($detail['group']['can_edit'] === ($identity === $owner || $identity === $administrator), 'Read-only group edit permission incorrect');
        }
    }
    foreach (['delete_group','add_member','remove_member','update_order','set_current_user_wf1'] as $action) {
        check(!api(['action'=>$action,'group_name'=>$scopeNames['private'],'_test_auth'=>$outsider])['success'], 'Unauthorized mutation allowed');
    }
    foreach (['copy_group','rename_group'] as $action) {
        check(!api(['action'=>$action,'source_name'=>$scopeNames['private'],'group_name'=>$prefix.'_denied','_test_auth'=>$outsider])['success'], 'Unauthorized source access allowed');
    }
    foreach ([$colleague,$outsider,$departmentUser] as $identity) {
        foreach ($scopeNames as $name) {
            foreach (['delete_group','remove_member','add_member','update_order','set_current_user_wf1'] as $action) {
                check(!api(['action'=>$action,'group_name'=>$name,'_test_auth'=>$identity,'user_type'=>2])['success'], 'Non-owner or forged Administrator bypassed policy');
            }
            check(!api(['action'=>'rename_group','source_name'=>$name,'group_name'=>$name,'_test_auth'=>$identity])['success'], 'Non-owner update allowed');
        }
    }
    check(!api(['action'=>'copy_group','source_name'=>$names[0],'group_name'=>$prefix.'_denied','visibility'=>'department','_test_auth'=>$missingCost])['success'], 'Department scope accepted without login department');
    check(!api(['action'=>'copy_group','source_name'=>$names[0],'group_name'=>$prefix.'_denied','visibility'=>'invalid','_test_auth'=>$owner])['success'], 'Invalid scope accepted');
    check(api(['action'=>'rename_group','source_name'=>$scopeNames['private'],'group_name'=>$prefix.'_scope_renamed','visibility'=>'all','_test_auth'=>$owner])['success'], 'Private rename failed');
    check(!api(['_test_method'=>'GET','_test_get'=>['group_id'=>$prefix.'_scope_renamed'],'_test_auth'=>$outsider])['success'], 'Rename changed private visibility');
    $s=query('SELECT COUNT(*) n FROM dbo.WF_USER_HDR WHERE c_name=?',[$prefix.'_denied']);
    check(sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n']===0, 'Denied operation created group');
    $sharedBefore=members($scopeNames['all']);
    check(api(['action'=>'copy_group','source_name'=>$scopeNames['all'],'group_name'=>$prefix.'_denied','_test_auth'=>$outsider])['success'], 'Employee cannot copy public group');
    check(members($scopeNames['all'])===$sharedBefore && members($prefix.'_denied')===$sharedBefore, 'Public copy changed original or lost signers');
    $ownCopy=api(['_test_method'=>'GET','_test_get'=>['group_id'=>$prefix.'_denied'],'_test_auth'=>$outsider]);
    check($ownCopy['success'] && $ownCopy['group']['can_edit'], 'Employee does not own copied group');
    $testGroup=$scopeNames['all'];
    $beforeMove=members($testGroup);
    $moving=$beforeMove[count($beforeMove)-1];
    $move=['old_user_id'=>$moving['user_id'],'old_dc_emp_id'=>(string)$moving['dc_emp_id'],'user_id'=>$moving['user_id'],'dc_emp_id'=>(string)$moving['dc_emp_id'],'wf_order'=>1];
    check(api(['action'=>'rename_group','source_name'=>$testGroup,'group_name'=>$testGroup,'replacements'=>[$move]])['success'], 'Move signer to WF1 failed');
    $ordered=members($testGroup);
    check($ordered[0]['user_id']===$moving['user_id'] && array_column($ordered,'wf_order')===[1,2,3], 'Move did not shift/renumber signers');
    check(api(['action'=>'set_current_user_wf1','group_name'=>$testGroup,'_test_auth'=>['user_id'=>$moving['user_id'],'employee_id'=>$moving['dc_emp_id'], 'user_type'=>2]])['success'], 'Repeat WF1 failed');
    check(array_column(members($testGroup),'wf_order')===[1,2,3], 'Repeated appointment left gaps');
    $removed=$ordered[1];
    check(api(['action'=>'remove_member','group_name'=>$testGroup,'user_id'=>$removed['user_id'],'dc_emp_id'=>(string)$removed['dc_emp_id']])['success'], 'Remove signer failed');
    check(array_column(members($testGroup),'wf_order')===[1,2], 'Remove left gaps');
    check(api(['action'=>'add_member','group_name'=>$testGroup,'user_id'=>$removed['user_id'],'dc_emp_id'=>(string)$removed['dc_emp_id'],'wf_order'=>2,'review_action'=>true])['success'], 'Insert signer failed');
    $ordered=members($testGroup);
    check(array_column($ordered,'wf_order')===[1,2,3] && $ordered[1]['user_id']===$removed['user_id'], 'Insert did not shift signers');
    query('UPDATE dbo.WF_USER_DTL SET wf_order=9 WHERE c_name=?',[$testGroup]);
    check(api(['action'=>'rename_group','source_name'=>$testGroup,'group_name'=>$testGroup])['success'], 'Normalize legacy duplicates failed');
    check(array_column(members($testGroup),'wf_order')===[1,2,3], 'Save left legacy duplicate positions');
    check(api(['action'=>'rename_group','source_name'=>$prefix.'_scope_renamed','group_name'=>$prefix.'_scope_renamed','_test_auth'=>$administrator])['success'], 'Administrator cannot update another private group');
    check(api(['action'=>'delete_group','group_name'=>$prefix.'_scope_renamed','_test_auth'=>$administrator])['success'], 'Administrator cannot delete another private group');
    check(api(['action'=>'delete_group','group_name'=>$scopeNames['department'],'_test_auth'=>$owner])['success'], 'Owner cannot delete own group');
    echo "PASS: contiguous WF ordering after move, repeated WF1, insert, delete and legacy duplicates\n";
    echo "PASS: Administrator manages all; employees read/copy public groups, manage own groups, cannot mutate others; private/department access and forged-role bypass denied\n";
} finally {
    foreach ($names as $name) {
        query('DELETE dbo.WF_USER_DTL WHERE c_name=?', [$name]);
        query('DELETE dbo.WF_USER_HDR WHERE c_name=?', [$name]);
    }
    unlink($runner); unlink($input);
    sqlsrv_close($db);
}
