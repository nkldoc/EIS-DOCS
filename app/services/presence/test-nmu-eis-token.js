'use strict';
const fs=require('fs'),os=require('os'),path=require('path'),crypto=require('crypto'),assert=require('node:assert/strict'),{spawnSync}=require('child_process');
const directory=fs.mkdtempSync(path.join(os.tmpdir(),'nmu-eis-token-'));
fs.mkdirSync(path.join(directory,'sessions'));fs.mkdirSync(path.join(directory,'revoked'));
const secret=crypto.randomBytes(32).toString('hex');fs.writeFileSync(path.join(directory,'nmu-eis.secret'),secret);
const env={...process.env,EIS_NMU_EIS_SESSION_DIR:path.join(directory,'sessions'),EIS_DOCS_PRESENCE_DIR:directory};
function run(identity,revoke=false){const php=`<?php
$_SERVER['SERVER_NAME']='dev-eis.nmu.ac.th';$_SERVER['HTTP_HOST']='dev-eis.nmu.ac.th';$_SERVER['REQUEST_URI']='/NMU_EIS/access/messenger-token.php';
session_save_path(getenv('EIS_NMU_EIS_SESSION_DIR'));session_name('PHPSESSID');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION=json_decode(base64_decode('${Buffer.from(JSON.stringify(identity)).toString('base64')}'),true);${revoke ? "require 'C:/xampp/htdocs/NMU_EIS/conf/messenger/Presence.php';NMUEISMessenger::revoke('NMU-EIS',(string)$_SESSION['user_id'],(string)$_SESSION['last_login']);" : ''}session_write_close();
http_response_code(200);ob_start();register_shutdown_function(function(){$body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>json_decode($body,true),'diagnostic'=>json_decode($body,true)===null?$body:null,'activity'=>$_SESSION['last_activity']??null]);});require 'C:/xampp/htdocs/NMU_EIS/access/messenger-token.php';`;
const result=spawnSync('php',['-n'],{input:php,encoding:'utf8',env});assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);}
try{
const activity=Math.floor(Date.now()/1000)-60;const result=run({user_id:123456,user_name:'Fixture Procure',cost_name:'Test',last_login:'fixture',last_activity:activity,i_type_user:2,admin_operator:'admin-art'});assert.equal(result.status,200);assert.ok(result.body,result.diagnostic);assert.equal(result.activity,activity);
const [encoded,signature]=result.body.token.split('.');assert.equal(signature,crypto.createHmac('sha256',secret).update(encoded).digest('hex'));const identity=JSON.parse(Buffer.from(encoded,'base64url'));assert.equal(identity.system,'NMU-EIS');assert.equal(identity.uid,'123456@admin-art');assert.equal(identity.admin,false);assert.equal(identity.name,'admin-art-NMU-EIS');
const unnamed=run({user_id:123456,i_type_user:2});assert.equal(unnamed.status,409);assert.equal(unnamed.body.code,'ADMIN_NAME_REQUIRED');
const revoked=run({user_id:123456,last_login:'fixture',messenger_login_nonce:'revoked-fixture'},true);assert.equal(revoked.status,401);assert(!revoked.body.token);
const expired=run({});assert.equal(expired.status,401);assert(!expired.body.token);
console.log('PASS NMU-EIS signed identity, anonymous denial, revoked session and unchanged activity');
const fixture={user_id:123456,last_login:'fixture',last_activity:Math.floor(Date.now()/1000)};
fs.writeFileSync(path.join(directory,'nmu-eis.secret'),'');
let unavailable=run(fixture);assert.equal(unavailable.status,503);assert.equal(unavailable.body.code,'PRESENCE_KEY_EMPTY');assert(!unavailable.body.token);
fs.unlinkSync(path.join(directory,'nmu-eis.secret'));
unavailable=run(fixture);assert.equal(unavailable.status,503);assert.equal(unavailable.body.code,'PRESENCE_KEY_UNREADABLE');assert(!JSON.stringify(unavailable.body).includes(directory));
env.EIS_DOCS_PRESENCE_DIR=path.join(directory,'missing-storage');
unavailable=run(fixture);assert.equal(unavailable.status,503);assert.equal(unavailable.body.code,'PRESENCE_STORAGE_UNAVAILABLE');
console.log('PASS safe diagnostics for missing storage, missing key and empty key');
}finally{fs.rmSync(directory,{recursive:true,force:true});}
