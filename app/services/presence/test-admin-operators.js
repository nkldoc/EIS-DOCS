'use strict';
const fs=require('fs'),os=require('os'),path=require('path'),crypto=require('crypto'),assert=require('node:assert/strict'),{spawnSync}=require('child_process');
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'admin-operators-'));fs.mkdirSync(path.join(dir,'revoked'));fs.mkdirSync(path.join(dir,'sessions'));for(const system of ['procure','nmu-eis'])fs.writeFileSync(path.join(dir,system+'.secret'),crypto.randomBytes(32).toString('hex'));
const php=`<?php
$_SERVER['HTTP_HOST']='dev-eis.nmu.ac.th';session_save_path(getenv('EIS_NMU_EIS_SESSION_DIR'));session_id('named-admin-fixture-session');session_start();
require 'D:/EIS-ERP/public/procure/conf/presence/AdminIdentity.php';require 'C:/xampp/htdocs/NMU_EIS/conf/messenger/AdminIdentity.php';
$checks=0;function check($value,$message){global $checks;if(!$value)throw new Exception($message);$checks++;}
function payload($result){return json_decode(base64_decode(strtr(explode('.',$result['token'])[0],'-_','+/')),true);}
foreach ([['ProcureAdminIdentity','ProcurePresence','PROCURE','procure'],['NMUEISAdminIdentity','NMUEISMessenger','NMU-EIS','NMU-EIS']] as [$identity,$presence,$system,$suffix]) {
 $_SESSION=['user_id'=>123456,'i_type_user'=>2,'last_login'=>'fixture','messenger_login_nonce'=>'fixture-nonce'];
 check($identity::needsName(),'Unnamed Admin needs identification');
 try{$identity::apply('bad<script>');throw new LogicException('Invalid name accepted');}catch(InvalidArgumentException $e){}
 check(!isset($_SESSION['admin_operator']),'Invalid name leaves identity untouched');
 check($identity::name('admin-art-'.$suffix)==='admin-art','System suffix normalizes');
 $oldKey=$presence::sessionKey($system,'123456','fixture');$identity::apply('admin-art-'.$suffix);
 check(is_file(getenv('EIS_DOCS_PRESENCE_DIR').'/revoked/'.$oldKey),'Name change revokes the previous identity');
 check(!$identity::needsName(),'Confirmed name avoids repeat prompt');
 $art=payload($presence::token($system,'123456','Shared account','Test','fixture',true));
 check($art['uid']==='123456@admin-art'&&$art['name']==='admin-art-'.$suffix,'Signed Admin identity has scope and system label');
 check($art['admin']===false,'Named Admin cannot gain Docs inbox privileges');
 $namedKey=$presence::sessionKey($system,'123456','fixture');$identity::apply('admin-art');
 check(!is_file(getenv('EIS_DOCS_PRESENCE_DIR').'/revoked/'.$namedKey),'Confirming the same name does not revoke itself');
 check($identity::forLogin(['dc_user_id'=>123456,'i_type_user'=>2])==='admin-art','Same account keeps the name on verified re-entry');
 check($identity::forLogin(['dc_user_id'=>654321,'i_type_user'=>2])==='','Different account cannot inherit the name');
 check($identity::forLogin(['dc_user_id'=>123456,'i_type_user'=>1])==='','Non-admin cannot inherit the name');
 $identity::apply('admin-eak');$eak=payload($presence::token($system,'123456','Shared account','Test','fixture'));
 check($art['uid']!==$eak['uid']&&$art['session']!==$eak['session'],'Different named Admins have independent online keys');
 check(is_file(getenv('EIS_DOCS_PRESENCE_DIR').'/revoked/'.$namedKey),'Previous named token revoked after changing identity');
 $_SESSION['i_type_user']=1;check($identity::operator()===''&&!$identity::needsName(),'Ordinary users cannot use the Admin alias');
 try{$identity::apply('admin-art');throw new LogicException('Non-admin identity accepted');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Non-admin apply denied');}
 $normal=payload($presence::token($system,'123456','Normal user','Test','fixture'));check($normal['uid']==='123456'&&$normal['name']==='Normal user','Normal user keeps their account identity');
}
session_write_close();echo 'PASS '.$checks.' named Admin identity checks';
`;
try{const result=spawnSync('php',['-n'],{input:php,encoding:'utf8',env:{...process.env,EIS_DOCS_PRESENCE_DIR:dir,EIS_PRESENCE_DIR:dir,EIS_NMU_EIS_SESSION_DIR:path.join(dir,'sessions')}});assert.equal(result.status,0,result.stdout+result.stderr);console.log(result.stdout);}finally{fs.rmSync(dir,{recursive:true,force:true});}
