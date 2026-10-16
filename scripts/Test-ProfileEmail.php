<?php
if (PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__);
$runner=tempnam(sys_get_temp_dir(),'email_runner_');
$input=tempnam(sys_get_temp_dir(),'email_input_');
$source=file_get_contents($root.'/app/public/docMain/profile-email.php');
$source=str_replace("require_once dirname(__DIR__,2).'/src/bootstrap.php';",'require_once '.var_export($root.'/app/src/bootstrap.php',true).';',$source);
$source=str_replace('Database::connect()','$GLOBALS["profileTestDb"]',$source);
$source=str_replace('sqlsrv_close($db);','{ sqlsrv_rollback($db); sqlsrv_close($db); }',$source);
$source=str_replace('php://input',str_replace('\\','/',$input),$source);
$setup= <<<'PHP'
$GLOBALS['profileTestDb']=Database::connect();
$testDb=$GLOBALS['profileTestDb'];
$s=sqlsrv_query($testDb,'SELECT TOP 1 u.dc_user_id,e.dc_emp_id FROM dbo.dc_user u JOIN dbo.dc_emp e ON e.dc_emp_id=u.dc_emp_id WHERE u.i_enable=1 AND u.i_delete=2 AND e.i_enable=1 AND e.i_delete=2');
if(!$s || !($testEmployee=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))) throw new RuntimeException('No employee test account');
sqlsrv_free_stmt($s);
if(!sqlsrv_begin_transaction($testDb)) throw new RuntimeException('Transaction failed');
$s=sqlsrv_query($testDb,'UPDATE dbo.dc_emp SET c_email=? WHERE dc_emp_id=?',[$argv[1]==='existing'?'existing@example.com':null,$testEmployee['dc_emp_id']]);
if(!$s) throw new RuntimeException('Email setup failed'); sqlsrv_free_stmt($s);
$_SESSION['auth']=['user_id'=>(int)$testEmployee['dc_user_id']];
$_SESSION['app_csrf']='test';
$_SERVER['HTTP_X_CSRF_TOKEN']=$argv[1]==='csrf'?'wrong':'test';
$_SERVER['REQUEST_METHOD']=$argv[1]==='read'?'GET':'POST';
PHP;
$source=str_replace('header(\'Content-Type: application/json; charset=utf-8\');',$setup."\nheader('Content-Type: application/json; charset=utf-8');",$source);
file_put_contents($runner,$source);
try {
    foreach(['read','save','existing','invalid','csrf'] as $scenario) {
        file_put_contents($input,json_encode(['email'=>$scenario==='invalid'?'invalid-address':'saved@example.com']));
        $lines=[]; exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner).' '.escapeshellarg($scenario),$lines,$code);
        $result=json_decode(implode("\n",$lines),true);
        if($code!==0 || !is_array($result)) throw new RuntimeException('Invalid endpoint result: '.$scenario);
        $expected=in_array($scenario,['read','save','existing'],true);
        if($result['success']!==$expected) throw new RuntimeException('Unexpected result: '.$scenario);
        if($scenario==='read' && (!$result['can_save'] || $result['email']!=='')) throw new RuntimeException('Missing email not editable');
        if($scenario==='save' && ($result['email']!=='saved@example.com' || $result['can_save'])) throw new RuntimeException('Saved email not returned');
    }
    echo "PASS: empty email display, saving, editing existing email, validation, CSRF (all changes rolled back)\n";
} finally { unlink($runner); unlink($input); }
