'use strict';
const fs=require('fs'),os=require('os'),path=require('path'),crypto=require('crypto'),assert=require('node:assert/strict'),{spawnSync}=require('child_process');
const directory=fs.mkdtempSync(path.join(os.tmpdir(),'procure-token-'));
fs.mkdirSync(path.join(directory,'sessions'));fs.mkdirSync(path.join(directory,'revoked'));
const secret=crypto.randomBytes(32).toString('hex');fs.writeFileSync(path.join(directory,'procure.secret'),secret);
const env={...process.env,EIS_PROCURE_SESSION_DIR:path.join(directory,'sessions'),EIS_DOCS_PRESENCE_DIR:directory};
function run(identity){const php=`<?php
$_SERVER['HTTP_HOST']='dev-eis.nmu.ac.th';$_SERVER['REQUEST_URI']='/procure/access/presence-token.php';
session_save_path(getenv('EIS_PROCURE_SESSION_DIR'));session_name('NMU_PROCURE_SESSID');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION=json_decode(base64_decode('${Buffer.from(JSON.stringify(identity)).toString('base64')}'),true);session_write_close();
http_response_code(200);ob_start();register_shutdown_function(function(){$body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>json_decode($body,true),'activity'=>$_SESSION['last_activity']??null]);});require 'D:/EIS-ERP/public/procure/access/presence-token.php';`;
const result=spawnSync('php',['-n'],{input:php,encoding:'utf8',env});assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);}
try{
const activity=Math.floor(Date.now()/1000)-60;const result=run({user_id:123456,user_name:'Fixture Procure',cost_name:'Test',last_login:'fixture',last_activity:activity});assert.equal(result.status,200);assert.equal(result.activity,activity);
const [encoded,signature]=result.body.token.split('.');assert.equal(signature,crypto.createHmac('sha256',secret).update(encoded).digest('hex'));const identity=JSON.parse(Buffer.from(encoded,'base64url'));assert.equal(identity.system,'PROCURE');assert.equal(identity.uid,'123456');assert.equal(identity.admin,false);
const named=run({user_id:123456,user_name:'Shared admin',i_type_user:2,admin_operator:'admin-art',last_login:'fixture',last_activity:activity});assert.equal(named.status,200);const namedPayload=JSON.parse(Buffer.from(named.body.token.split('.')[0],'base64url'));assert.equal(namedPayload.uid,'123456@admin-art');assert.equal(namedPayload.name,'admin-art-procure');assert.equal(namedPayload.admin,false);
const unnamed=run({user_id:123456,i_type_user:2,last_activity:activity});assert.equal(unnamed.status,409);assert.equal(unnamed.body.code,'ADMIN_NAME_REQUIRED');
const expired=run({user_id:123456,last_activity:Math.floor(Date.now()/1000)-7201});assert.equal(expired.status,401);assert(!expired.body.token);
console.log('PASS Procure signed identity, idle timeout and passive renewal');
const fixture={user_id:123456,last_login:'fixture',last_activity:Math.floor(Date.now()/1000)};
fs.writeFileSync(path.join(directory,'procure.secret'),'');
let unavailable=run(fixture);assert.equal(unavailable.status,503);assert.equal(unavailable.body.code,'PRESENCE_KEY_EMPTY');assert(!unavailable.body.token);
fs.unlinkSync(path.join(directory,'procure.secret'));
unavailable=run(fixture);assert.equal(unavailable.status,503);assert.equal(unavailable.body.code,'PRESENCE_KEY_UNREADABLE');assert(!JSON.stringify(unavailable.body).includes(directory));
env.EIS_DOCS_PRESENCE_DIR=path.join(directory,'missing-storage');
unavailable=run(fixture);assert.equal(unavailable.status,503);assert.equal(unavailable.body.code,'PRESENCE_STORAGE_UNAVAILABLE');
console.log('PASS safe diagnostics for missing storage, missing key and empty key');
}finally{fs.rmSync(directory,{recursive:true,force:true});}
