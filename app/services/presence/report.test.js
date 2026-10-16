'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),os=require('os'),path=require('path'),crypto=require('crypto');
const {ChatStore}=require('./chat'),{monthlyReport}=require('./report');
test('monthly support report uses Bangkok boundaries, retains steps, protects private jobs and validates questions',t=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'report-'));t.after(()=>fs.rmSync(dir,{recursive:true,force:true}));let now=Date.parse('2026-09-30T16:59:59Z');const store=new ChatStore(dir,()=>now);
 const admin={system:'EIS-DOCS',uid:'1',name:'admin-eak',admin:true},moo={...admin,uid:'3',name:'admin-moo'},user={system:'SUPPLIES',uid:'2',name:'User',admin:false};
 const send=(who,extra)=>store.send(who,{clientId:crypto.randomUUID(),text:'Issue',...extra});
 const id=send(user,{support:true}).message.room;now+=1000;
 store.job(admin,{room:id,version:1,action:'claim'});now+=1000;
 const question=send(admin,{room:id,kind:'question',text:'Which screen?'}).message;assert.equal(store.state.rooms[id].status,'waiting');assert.equal(question.kind,'question');
 assert.throws(()=>send(user,{room:id,kind:'question'}));assert.throws(()=>send(admin,{room:id,kind:'question',closeWithReply:true}));
 now+=1000;send(user,{room:id,text:'Purchase screen'});now+=1000;send(admin,{room:id,text:'Fixed',closeWithReply:true});
 const privateId=send(admin,{target:user,text:'Private'}).message.room;
 const october=monthlyReport(store,admin,'2026-10');assert.equal(october.rows.length,2);const row=october.rows.find(r=>r.id===id);
 assert.equal(row.status,'closed');assert.equal(row.receivedAt,Date.parse('2026-09-30T17:00:00Z'));assert.equal(row.questionAt,question.at);assert.equal(row.resolution,'Fixed');assert.equal(row.closedAt,now);assert.ok(row.events.some(e=>e.text==='Which screen?'));assert.ok(row.events.some(e=>e.text==='Purchase screen'));
 assert.equal(monthlyReport(store,moo,'2026-10').rows.some(r=>r.id===privateId),false);assert.equal(monthlyReport(store,admin,'2026-09').rows.length,1);assert.equal(monthlyReport(store,admin,'2026-11').rows.length,0);
 assert.throws(()=>monthlyReport(store,user,'2026-10'));for(const month of ['2026-13','2026-00','x','0999-01'])assert.throws(()=>monthlyReport(store,admin,month));
 assert.equal(monthlyReport(new ChatStore(dir),admin,'2026-10').rows.find(r=>r.id===id).questionAt,question.at);
});
