'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm');
for(const file of ['D:/EIS-DOCS/app/services/presence/server.js','C:/xampp/htdocs/NMU_EIS/services/presence/server.js'])test(file+': bounded output queue preserves images and coalesces queued snapshots',()=>{
const source=fs.readFileSync(file,'utf8');const start=source.indexOf('function send('),end=source.indexOf('function live(',start)>0?source.indexOf('function live(',start):source.indexOf('function broadcast(',start);const context={Buffer,WebSocket:{OPEN:1}};vm.createContext(context);vm.runInContext(source.slice(start,end),context);const frames=[],callbacks=[];const ws={readyState:1,send(text,cb){frames.push(JSON.parse(text));callbacks.push(cb);},close(code){this.closeCode=code;this.readyState=3;}};
for(let i=0;i<40;i++)context.send(ws,{type:'chat-message',id:i,image:'x'.repeat(250000)});assert.equal(ws.readyState,1);for(let i=0;i<40;i++)callbacks.shift()();assert.equal(frames.length,40);assert.equal(frames[39].id,39);assert.equal(ws.outputBytes,0);
for(let i=0;i<10;i++)context.send(ws,{type:'snapshot',revision:i});assert.equal(ws.output.length,1);callbacks.shift()();callbacks.shift()();assert.equal(frames.at(-1).revision,9);assert.equal(ws.outputBytes,0);
for(let i=0;i<100;i++)context.send(ws,{type:'chat-message',image:'x'.repeat(250000)});assert.equal(ws.closeCode,1013);
});
