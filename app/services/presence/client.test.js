'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm');
const files=['D:/EIS-DOCS/app/public/assets/js/presence-client.js','D:/EIS-ERP/public/supplies/assets/js/presence-client.js','D:/EIS-ERP/public/procure/assets/js/presence-client.js','C:/xampp/htdocs/NMU_EIS/js/presence-client.js','C:/xampp/htdocs/NMU_EIS/assets/messenger/js/presence-client.js'];
async function flush(){for(let i=0;i<15;i++)await Promise.resolve();}
function fixture(file){let time=0,id=0;const timers=new Map(),events={},requests=[],sockets=[];const messenger=file.includes('assets/messenger/'),name=messenger?'EISMessengerPresence':'EISPresence';
class Socket{static OPEN=1;static CLOSING=2;constructor(url){this.url=url;this.readyState=0;this.bufferedAmount=0;this.messages=[];sockets.push(this);}send(raw){if(this.readyState!==1)throw Error('Closed');this.messages.push(JSON.parse(raw));}close(){if(this.readyState===3)return;this.readyState=3;this.onclose?.({code:1000});}open(){this.readyState=1;this.onopen?.();}message(type,extra={}){this.onmessage?.({data:JSON.stringify({type,...extra})});}}
const window={addEventListener:(type,fn)=>events[type]=fn},document={addEventListener(){},createElement(){return {style:{},append(){},setAttribute(){}}},body:{appendChild(){}}},location={href:'https://dev-eis.nmu.ac.th/NMU_EIS/',origin:'https://dev-eis.nmu.ac.th',protocol:'https:',assign(url){location.redirect=url;}},navigator={onLine:true};window.location=location;window[name+'Config']={tokenUrl:'/fixture',loginUrl:'/login'};
const context={window,document,location,navigator,URL,AbortController,WebSocket:Socket,Set,Math:{random:()=>0,floor:Math.floor,min:Math.min},setTimeout(fn,delay){const n=++id;timers.set(n,{fn,at:time+delay});return n;},clearTimeout(n){timers.delete(n);},fetch(url,options){return new Promise((resolve,reject)=>{const request={options,resolve(status=200,body={token:'fixture',url:'wss://dev-eis.nmu.ac.th:4003/presence'}){resolve({status,ok:status>=200&&status<300,json:async()=>body});}};requests.push(request);options.signal.addEventListener('abort',()=>reject(Error('Aborted')));});}};vm.createContext(context);const source=fs.readFileSync(file,'utf8');vm.runInContext(source,context);
return {window,api:window[name],loadAgain:()=>vm.runInContext(source,context),events,requests,sockets,navigator,location,timers,async advance(ms){const target=time+ms;while(true){const next=[...timers].filter(([,v])=>v.at<=target).sort((a,b)=>a[1].at-b[1].at)[0];if(!next)break;timers.delete(next[0]);time=next[1].at;next[1].fn();await flush();}time=target;await flush();},async auth(){requests.at(-1).resolve();await flush();const ws=sockets.at(-1);ws.open();ws.message('authenticated',{identity:{system:'PROCURE',uid:'1'}});await flush();return ws;}};}
for(const file of files){
test(file+': singleton and serialized token renewal',async()=>{const f=fixture(file);f.loadAgain();f.events.online();assert.equal(f.requests.length,1);const ws=await f.auth();ws.message('authenticated');await f.advance(20000);assert.equal(f.requests.length,2);await f.advance(20000);assert.equal(f.requests.length,3); // timeout closes and triggers exactly one reconnect
assert.equal(f.sockets.length,1);f.api.logout();await f.advance(60000);assert.equal(f.requests.length,3);});
test(file+': logout and pagehide cancel pending authentication',async()=>{const f=fixture(file);f.api.logout();f.requests[0].resolve();await flush();await f.advance(60000);assert.equal(f.sockets.length,0);assert(f.requests[0].options.signal.aborted);const g=fixture(file);g.events.pagehide();g.requests[0].resolve();await flush();assert.equal(g.sockets.length,0);g.events.pageshow({persisted:true});assert.equal(g.requests.length,2);await g.auth();assert.equal(g.sockets.length,1);});
test(file+': stale callbacks cannot replace or close a new socket',async()=>{const f=fixture(file),old=await f.auth(),lateClose=old.onclose;old.close();await f.advance(3000);const fresh=await f.auth();lateClose();old.message('authenticated');assert(f.api.send({type:'page',path:'/procure/'}));assert.equal(fresh.readyState,1);assert.equal(fresh.messages.at(-1).type,'page');});
test(file+': offline pauses retries, 401 stops, backpressure refuses sends',async()=>{const f=fixture(file),ws=await f.auth();ws.bufferedAmount=2*1024*1024;assert.equal(f.api.send({type:'chat-send'}),false);ws.bufferedAmount=0;f.navigator.onLine=false;f.events.offline();await f.advance(60000);assert.equal(f.requests.length,1);f.navigator.onLine=true;f.events.online();f.requests.at(-1).resolve(401,{code:'UNAUTHENTICATED'});await flush();await f.advance(60000);assert.equal(f.requests.length,2);assert.equal(f.api.send({}),false);});
test(file+': slow token timeout and retry backoff are bounded',async()=>{const f=fixture(file);await f.advance(8000);assert(f.requests[0].options.signal.aborted);await f.advance(3000);assert.equal(f.requests.length,2);f.requests[1].resolve(503,{});await flush();assert.equal([...f.timers.values()][0].at,17000);await f.advance(6000);assert.equal(f.requests.length,3);f.requests[2].resolve(200,{token:'fixture',url:'ws://dev-eis.nmu.ac.th:4003/presence'});await flush();assert.equal(f.sockets.length,0);});
test(file+': subscriber exceptions and unsubscription do not break connection',async()=>{const f=fixture(file);let count=0;f.api.on(()=>{throw Error('View');});const off=f.api.on(type=>{if(type==='connected')count++;});await f.auth();assert.equal(count,1);off();f.sockets[0].close();await f.advance(3000);await f.auth();assert.equal(count,1);});
}

for(const file of files){
test(file+': successful renewal waits for the previous token request',async()=>{const f=fixture(file),ws=await f.auth();ws.message('authenticated');await f.advance(20000);assert.equal(f.requests.length,2);await f.advance(5000);assert.equal(f.requests.length,2);f.requests[1].resolve();await flush();assert.equal(ws.messages.filter(m=>m.type==='renew').length,1);await f.advance(19999);assert.equal(f.requests.length,2);await f.advance(1);assert.equal(f.requests.length,3);});
test(file+': authentication watchdog retries exactly once',async()=>{const f=fixture(file);f.requests[0].resolve();await flush();assert.equal(f.sockets.length,1);await f.advance(10000);assert.equal(f.sockets[0].readyState,3);await f.advance(3000);assert.equal(f.requests.length,2);f.events.online();assert.equal(f.requests.length,2);});
test(file+': Admin prompt redirects only to allowed same-origin routes',async()=>{const f=fixture(file);f.requests[0].resolve(409,{code:'ADMIN_NAME_REQUIRED',url:'/procure/access/admin-identity.php'});await flush();assert.equal(f.location.redirect,'https://dev-eis.nmu.ac.th/procure/access/admin-identity.php');assert.equal(f.sockets.length,0);const g=fixture(file);g.requests[0].resolve(409,{code:'ADMIN_NAME_REQUIRED',url:'https://example.com/procure/access/admin-identity.php'});await flush();assert.equal(g.location.redirect,undefined);});
}

const diagnosticsFile=files[0];
test('diagnostics distinguish token HTTP failure, malformed response and authentication timeout',async()=>{
  const f=fixture(diagnosticsFile);f.requests[0].resolve(503,{});await flush();
  assert.equal(f.api.diagnostics().issue.code,'HTTP_503');assert.equal(f.api.diagnostics().state,'disconnected');
  const g=fixture(diagnosticsFile);g.requests[0].resolve(200,{});await flush();
  assert.equal(g.api.diagnostics().issue.code,'INVALID_RESPONSE');assert.equal(g.sockets.length,0);
  const h=fixture(diagnosticsFile);h.requests[0].resolve();await flush();await h.advance(10000);
  assert.equal(h.api.diagnostics().issue.code,'TIMEOUT');assert.equal(h.api.diagnostics().issue.stage,'authentication');
});
test('diagnostics omit secrets, identities, query strings and server close reasons; history stays bounded',async()=>{
  const f=fixture(diagnosticsFile);f.window.EISPresenceConfig.tokenUrl='/fixture?token=TOKEN_SECRET#SECRET_FRAGMENT';
  f.requests[0].resolve(200,{token:'TOKEN_SECRET',url:'wss://user:PASSWORD@dev-eis.nmu.ac.th/presence?secret=QUERY_SECRET#SECRET_FRAGMENT'});await flush();
  const ws=f.sockets[0];ws.open();ws.message('authenticated',{identity:{uid:'PRIVATE_UID',name:'PRIVATE_NAME'}});
  ws.onclose({code:1008,reason:'SERVER_SECRET',wasClean:false});
  let raw=JSON.stringify(f.api.diagnostics());
  for(const value of ['TOKEN_SECRET','PASSWORD','QUERY_SECRET','SECRET_FRAGMENT','PRIVATE_UID','PRIVATE_NAME','SERVER_SECRET'])assert(!raw.includes(value),value);
  assert.equal(f.api.diagnostics().issue.code,'AUTH_REJECTED');
  for(let i=0;i<60;i++){assert(f.api.reconnect());f.requests.at(-1).resolve(503,{});await flush();}
  assert.equal(f.api.diagnostics().logs.length,100);
  const copy=f.api.diagnostics();copy.logs[0].code='modified';assert.notEqual(f.api.diagnostics().logs[0].code,'modified');
});
test('manual reconnect cancels old request and cannot revive ended sessions; subscribers replay current state',async()=>{
  const f=fixture(diagnosticsFile);assert(f.api.reconnect());assert(f.requests[0].options.signal.aborted);assert.equal(f.requests.length,2);
  f.requests[0].resolve();await flush();assert.equal(f.sockets.length,0);
  f.requests[1].resolve(401,{});await flush();let state;f.api.on(type=>state=type);
  assert.equal(state,'unauthorized');assert.equal(f.api.reconnect(),false);assert.equal(f.requests.length,2);
});
