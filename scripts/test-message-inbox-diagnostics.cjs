'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const elements=new Map();
function element(id){if(!elements.has(id))elements.set(id,{hidden:id==='job-connection-log',value:'',children:[],textContent:'',setAttribute(k,v){this[k]=v;},addEventListener(){},replaceChildren(){this.children=[];}});return elements.get(id);}
let listener,reconnects=0,download,revoked=false;
const diagnostics={state:'disconnected',issue:{code:'CONNECT_ERROR',message:'ตรวจสอบ IIS proxy'},logs:[{stage:'websocket',code:'CONNECT_ERROR'}]};
const context={document:{getElementById:element,querySelectorAll:()=>[],addEventListener(){},createElement(){return {click(){download={href:this.href,name:this.download};}};}},EISPresence:{on(fn){listener=fn;fn('disconnected');},diagnostics:()=>diagnostics,reconnect(){reconnects++;return true;}},Set,Map,Blob,URL:{createObjectURL:()=> 'blob:fixture',revokeObjectURL(){revoked=true;}},setTimeout(fn){fn();}};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../app/public/docMain/message-inbox.js'),'utf8'),context);
assert.match(element('job-connection').textContent,/IIS proxy/);
element('job-log-toggle').onclick();assert.equal(element('job-connection-log').hidden,false);assert.equal(element('job-log-toggle')['aria-expanded'],'true');assert.match(element('job-log-output').textContent,/CONNECT_ERROR/);
diagnostics.logs.push({code:'manual'});listener('diagnostic');assert.match(element('job-log-output').textContent,/manual/);
element('job-log-download').onclick();assert.equal(download.href,'blob:fixture');assert.match(download.name,/messenger-connection-.*\.json/);assert(revoked);
element('job-reconnect').onclick();assert.equal(reconnects,1);
listener('connecting');assert.match(element('job-connection').textContent,/กำลังเชื่อมต่อ/);assert(!element('job-connection').textContent.includes('การเชื่อมต่อขาด'));
listener('unauthorized');assert.equal(element('job-reconnect').disabled,true);
element('job-log-toggle').onclick();assert.equal(element('job-connection-log').hidden,true);assert.equal(element('job-log-toggle')['aria-expanded'],'false');
console.log('PASS inbox status, log toggle/live update, download and reconnect controls');
