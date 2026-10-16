'use strict';
const https=require('https'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),{spawnSync}=require('child_process');
const fixture=path.join(__dirname,'test-portal-fixture.php'), jar={}, owned=new Set(); let identity,instance;
function php(args) {const r=spawnSync('php',[fixture,...args],{encoding:'utf8'});if(r.status) throw new Error(r.stderr||r.stdout);return JSON.parse(r.stdout);}
function request(url, cookies=true) {return new Promise((resolve,reject)=>{
  const req=https.get(new URL(url,'https://dev-eis.nmu.ac.th'),{rejectUnauthorized:false,headers:cookies?{Cookie:Object.entries(jar).map(([k,v])=>k+'='+v).join('; ')}:{}},res=>{
    if(cookies) for(const cookie of res.headers['set-cookie']||[]) {const pair=cookie.split(';')[0],i=pair.indexOf('=');jar[pair.slice(0,i)]=pair.slice(i+1);if(pair.slice(i+1)) owned.add(pair.slice(i+1));}
    let body='';res.on('data',b=>body+=b);res.on('end',()=>resolve({status:res.statusCode,location:res.headers.location,body}));
  });req.on('error',reject);req.setTimeout(10000,()=>req.destroy(new Error('Request timeout')));
});}
async function follow(url,cookies=true) {const visited=[];for(let i=0;i<8;i++){const r=await request(url,cookies);visited.push(url);if(r.status>=300&&r.status<400&&r.location){url=r.location;continue;}return {...r,visited};}throw new Error('Too many redirects');}
(async()=>{try {
  identity=php(['seed']);jar.PHPSESSID=identity.id;owned.add(identity.id);
  const sessionFile='C:/xampp/htdocs/NMU_permission/tmp/sessions/sess_'+identity.id;
  const grant=spawnSync('icacls',[sessionFile,'/grant','IIS_IUSRS:M'],{encoding:'utf8'});assert.equal(grant.status,0);
  const menu=await follow('/NMU_permission/entrance');assert.equal(menu.status,200);assert.ok(menu.body.includes('launch.php?system=supplies'));assert.ok(menu.body.includes('launch.php?system=procure'));
  for(const system of ['supplies','procure']) {
    const page=await follow('/NMU_permission/launch.php?system='+system);assert.equal(page.status,200);assert.ok(!/Connection could not|SQLSTATE|Fatal error/.test(page.body));assert.ok(!page.body.includes("window.location.href ='./access/logout.php'"));assert.ok(page.visited.some(v=>v.endsWith('/'+system+'/')));
    const state=php(['read',system==='supplies'?'NMU_SUPPLIES_SESSID':'NMU_PROCURE_SESSID',jar[system==='supplies'?'NMU_SUPPLIES_SESSID':'NMU_PROCURE_SESSID']]);assert.equal(state.uid,identity[system]);assert.equal(state.center,identity.center);if(system==='supplies') instance=state.instance;
    const denied=await follow('/'+system+'/access/login_1.php?ss_user_id='+identity[system]+'&portal_entry=1',false);assert.equal(denied.status,401);
  }
  const anonymous=await follow('/NMU_permission/launch.php?system=supplies',false);assert.ok(anonymous.visited.includes('/NMU_permission/'));
  const originalSupply=jar.NMU_SUPPLIES_SESSID;delete jar.NMU_SUPPLIES_SESSID;
  const duplicate=await follow('/NMU_permission/launch.php?system=supplies');assert.equal(duplicate.status,200);assert.ok(duplicate.visited.some(v=>v.includes('/supplies/access/confirm-login.php')));
  const old=php(['read','NMU_SUPPLIES_SESSID',originalSupply]);assert.equal(old.uid,identity.supplies);assert.equal(old.instance,instance);
  php(['deny',identity.id]);
  for(const system of ['supplies','procure']) assert.equal((await follow('/NMU_permission/launch.php?system='+system)).status,403);
  console.log('PASS: actual IIS entrance menu, per-system account mapping, authenticated Supplies/Procure sessions, duplicate confirmation preserves old login, anonymous/permission denial');
}finally{
  for(const id of owned) if(/^[a-zA-Z0-9,-]{16,128}$/.test(id)) for(const dir of ['C:/xampp/htdocs/NMU_permission/tmp/sessions','D:/EIS-ERP/storage/sessions']) {const file=path.join(dir,'sess_'+id);if(fs.existsSync(file))fs.unlinkSync(file);}
  if(identity&&instance&&fs.existsSync(identity.registry)){const record=JSON.parse(fs.readFileSync(identity.registry,'utf8'));if(record.instance===instance)fs.unlinkSync(identity.registry);}
}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
