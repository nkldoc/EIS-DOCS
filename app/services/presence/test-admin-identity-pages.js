'use strict';
const fs=require('fs'),os=require('os'),path=require('path'),assert=require('node:assert/strict'),{spawnSync}=require('child_process');const dir=fs.mkdtempSync(path.join(os.tmpdir(),'admin-pages-'));fs.mkdirSync(path.join(dir,'sessions'));fs.mkdirSync(path.join(dir,'revoked'));
const apps=[{root:'D:/EIS-ERP/public/procure',session:'NMU_PROCURE_SESSID',label:'procure'},{root:'C:/xampp/htdocs/NMU_EIS',session:'PHPSESSID',label:'NMU-EIS'}];let checks=0;
function run(app,identity,post){const php=`<?php
$_SERVER['SERVER_NAME']='dev-eis.nmu.ac.th';$_SERVER['HTTP_HOST']='dev-eis.nmu.ac.th';$_SERVER['REQUEST_URI']='/fixture';$_SERVER['REQUEST_METHOD']='${post?'POST':'GET'}';
session_save_path(getenv('EIS_NMU_EIS_SESSION_DIR'));session_name('${app.session}');session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=json_decode(base64_decode('${Buffer.from(JSON.stringify({...identity,admin_identity_csrf:'fixture-csrf'})).toString('base64')}'),true);session_write_close();
$_POST=json_decode(base64_decode('${Buffer.from(JSON.stringify(post||{})).toString('base64')}'),true);
http_response_code(200);ob_start();register_shutdown_function(function(){ $html=ob_get_clean();echo json_encode(['status'=>http_response_code(),'operator'=>$_SESSION['admin_operator']??'','html'=>$html]);});require '${app.root}/access/admin-identity.php';`;
const result=spawnSync('php',['-n'],{input:php,encoding:'utf8',env:{...process.env,EIS_DOCS_PRESENCE_DIR:dir,EIS_PRESENCE_DIR:dir,EIS_NMU_EIS_SESSION_DIR:path.join(dir,'sessions'),EIS_PROCURE_SESSION_DIR:path.join(dir,'sessions')}});assert.equal(result.status,0,result.stdout+result.stderr);return JSON.parse(result.stdout);}
try{for(const app of apps){const admin={user_id:123456,i_type_user:2,last_login:'fixture'};let r=run(app,admin);assert.equal(r.status,200);assert(r.html.includes('admin-art-'+app.label));checks+=2;
r=run(app,admin,{csrf:'wrong',admin_operator:'admin-art'});assert.equal(r.status,403);assert.equal(r.operator,'');checks+=2;
r=run(app,admin,{csrf:'fixture-csrf',admin_operator:'admin-art-'+app.label});assert.equal(r.status,303);assert.equal(r.operator,'admin-art');checks+=2;
r=run(app,admin,{csrf:'fixture-csrf',admin_operator:'bad<script>'});assert.equal(r.operator,'');checks++;
r=run(app,{user_id:123456,i_type_user:1},{csrf:'fixture-csrf',admin_operator:'admin-art'});assert.equal(r.status,302);assert.equal(r.operator,'');checks+=2;
r=run(app,{});assert.equal(r.status,302);checks++;}console.log('PASS '+checks+' Admin identity form checks including CSRF and account authorization');}finally{fs.rmSync(dir,{recursive:true,force:true});}
