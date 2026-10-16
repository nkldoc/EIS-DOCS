'use strict';
const fs=require('fs'),os=require('os'),path=require('path'),crypto=require('crypto'),assert=require('node:assert/strict'),{spawn}=require('child_process'),{WebSocket}=require('ws');
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ws-safety-')),secrets={},clients=[];
for(const source of ['eis-docs','supplies','procure','nmu-eis']){secrets[source]=crypto.randomBytes(32).toString('hex');fs.writeFileSync(path.join(dir,source+'.secret'),secrets[source]);}
fs.mkdirSync(path.join(dir,'revoked'));fs.mkdirSync(path.join(dir,'logins'));
const config=path.join(dir,'config.json');fs.writeFileSync(config,JSON.stringify({directory:dir,transport:'http',port:4007,origins:['https://dev-eis.nmu.ac.th']}));
const env={...process.env,EIS_PRESENCE_SERVER_CONFIG:config};for(const key of ['EIS_DOCS_PRESENCE_DIR','EIS_DOCS_PRESENCE_PORT','EIS_PRESENCE_TRANSPORT'])delete env[key];
const child=spawn(process.execPath,[path.join(__dirname,'server.js')],{env,stdio:['ignore','pipe','pipe']});let stderr='';child.stderr.on('data',b=>stderr+=b);
const delay=ms=>new Promise(r=>setTimeout(r,ms));
function send(ws,data){ws.send(JSON.stringify(data));}
async function wait(ws,type,predicate=()=>true){for(let i=0;i<200;i++){const index=ws.inbox.findIndex(m=>m.type===type&&predicate(m));if(index>=0)return ws.inbox.splice(index,1)[0];await delay(10);}throw new Error('Timeout waiting '+type);}
async function connect(identity){identity={...identity,session:identity.session||crypto.randomBytes(32).toString('hex')};const ws=new WebSocket('ws://127.0.0.1:4007/presence',{origin:'https://dev-eis.nmu.ac.th'});ws.inbox=[];ws.identity=identity;clients.push(ws);ws.on('message',raw=>ws.inbox.push(JSON.parse(raw)));ws.on('error',()=>{});await new Promise((resolve,reject)=>{ws.once('open',resolve);ws.once('error',reject);});const encoded=Buffer.from(JSON.stringify({...identity,exp:Math.floor(Date.now()/1000)+60})).toString('base64url');send(ws,{type:'authenticate',token:encoded+'.'+crypto.createHmac('sha256',secrets[identity.system.toLowerCase()]).update(encoded).digest('hex')});await wait(ws,'authenticated');await wait(ws,'chat-ready');return ws;}
function message(ws,extra){const clientId=crypto.randomUUID();send(ws,{type:'chat-send',clientId,text:'Test',...extra});return clientId;}

async function denied(identity,change){identity={...identity,session:crypto.randomBytes(32).toString('hex'),exp:Math.floor(Date.now()/1000)+60};const encoded=Buffer.from(JSON.stringify(identity)).toString('base64url');let token=encoded+'.'+crypto.createHmac('sha256',secrets[identity.system.toLowerCase()]).update(encoded).digest('hex');if(change==='signature')token=token.slice(0,-1)+(token.endsWith('0')?'1':'0');const ws=new WebSocket('ws://127.0.0.1:4007/presence',{origin:'https://dev-eis.nmu.ac.th'});clients.push(ws);ws.on('error',()=>{});const result=new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Denial timeout')),3000);ws.once('close',code=>{clearTimeout(timer);resolve(code);});});ws.on('open',()=>send(ws,{type:'authenticate',token,observer:change==='observer'}));assert.equal(await result,1008);}
(async()=>{try{
let ready=false;for(let i=0;i<40;i++){try{const r=await fetch('http://127.0.0.1:4007/health');ready=r.ok;if(ready)break;}catch(_){}await delay(100);}assert(ready,stderr);
const probe=await connect({system:'PROCURE',uid:'9',name:'Duplicate-process fixture'});
const before=fs.readFileSync(path.join(dir,'history.json'),'utf8');
const duplicate=spawn(process.execPath,[path.join(__dirname,'server.js')],{env,stdio:['ignore','ignore','ignore']});
const duplicateCode=await new Promise(r=>duplicate.once('exit',r));assert.equal(duplicateCode,1);assert.equal(fs.readFileSync(path.join(dir,'history.json'),'utf8'),before);
probe.close();
await denied({system:'PROCURE',uid:'1'},'signature');await denied({system:'PROCURE',uid:'../invalid'});await denied({system:'PROCURE',uid:'1',admin:true},'observer');
const user=await connect({system:'PROCURE',uid:'1',name:'Safety fixture'});const ended=new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Rate limit timeout')),3000);user.once('close',code=>{clearTimeout(timer);resolve(code);});});for(let i=0;i<121;i++)send(user,{type:'page',path:'/procure/'});assert.equal(await ended,1008);
const admin=await connect({system:'EIS-DOCS',uid:'1',name:'Safety Admin',admin:true});const heavyEnded=new Promise(resolve=>admin.once('close',resolve));for(let i=0;i<11;i++)send(admin,{type:'subscribe',filters:{start:'2026-10-13',end:'2026-10-13'}});assert.equal(await heavyEnded,1008);
const wrongOrigin=new WebSocket('ws://127.0.0.1:4007/presence',{origin:'https://example.com'});clients.push(wrongOrigin);wrongOrigin.on('error',()=>{});const originStatus=await new Promise(resolve=>wrongOrigin.on('unexpected-response',(req,res)=>{resolve(res.statusCode);res.resume();req.destroy();}));assert.equal(originStatus,403);
console.log('PASS bad signature, malformed identity, forbidden observer, request/query rate limits and Origin checks; duplicate service cannot mutate live history');
}finally{for(const ws of clients)ws.terminate();child.kill();await new Promise(r=>{if(child.exitCode!==null)r();else child.once('exit',r);});fs.rmSync(dir,{recursive:true,force:true});}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
