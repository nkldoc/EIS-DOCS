'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),os=require('os'),path=require('path');
const {ChatStore}=require('./chat');
const admin={system:'EIS-DOCS',uid:'1',name:'Admin',admin:true},user={system:'SUPPLIES',uid:'2',name:'User',admin:false},other={system:'EIS-DOCS',uid:'2',name:'Other',admin:false},admin2={system:'EIS-DOCS',uid:'3',name:'Admin 2',admin:true};
function fixture(t){const dir=fs.mkdtempSync(path.join(os.tmpdir(),'chat-test-'));t.after(()=>fs.rmSync(dir,{recursive:true,force:true}));return new ChatStore(dir);}
function input(extra={}){return {clientId:require('crypto').randomUUID(),text:'Test',room:'all',...extra};}
test('admin clearing requester unread is durable, audited, separate from actual reading and job status',t=>{
 const store=fixture(t),id=store.send(user,input({support:true})).message.room;
 const reply=store.send(admin,input({room:id,text:'Answer'})).message;
 const version=store.state.rooms[id].version;
 assert.throws(()=>store.job(user,{room:id,version,action:'mark_read'}));
 assert.throws(()=>store.job(admin,{room:id,version:version-1,action:'mark_read'}));
 const cleared=store.job(admin2,{room:id,version,action:'mark_read'});
 assert.equal(cleared.status,'waiting');assert.equal(cleared.requesterRead.mode,'admin');assert.equal(cleared.requesterRead.unread,0);
 assert.equal(cleared.requesterRead.override.by.name,'Admin 2');assert.equal(cleared.requesterRead.at,null);
 assert.equal(cleared.audit.at(-1).action,'mark_read');assert.equal(store.unread(user,reply),false);
 const restored=new ChatStore(path.dirname(store.file));assert.equal(restored.publicRoom(restored.state.rooms[id]).requesterRead.mode,'admin');assert.equal(restored.unread(user,reply),false);
 store.read(user,id,reply.seq);assert.equal(store.publicRoom(store.state.rooms[id]).requesterRead.mode,'user');assert.ok(store.publicRoom(store.state.rooms[id]).requesterRead.at);
 const next=store.send(admin,input({room:id,text:'Another answer',closeWithReply:true})).message;
 assert.equal(store.unread(user,next),true);assert.equal(store.publicRoom(store.state.rooms[id]).requesterRead.mode,'unread');
 const closed=store.job(admin,{room:id,version:store.state.rooms[id].version,action:'mark_read'});assert.equal(closed.status,'closed');assert.equal(closed.requesterRead.unread,0);
 const privateId=store.send(admin,input({target:user})).message.room;
 assert.throws(()=>store.job(admin2,{room:privateId,version:store.state.rooms[privateId].version,action:'mark_read'}));
});
test('only the sender may retract; text and image disappear durably and retry is idempotent',t=>{
 const store=fixture(t),image='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE2sAAAAASUVORK5CYII=',message=store.send(admin,input({target:user,text:'Private original',image})).message;
 assert.throws(()=>store.retract(user,{room:message.room,messageId:message.id}),/ตนเอง/);
 assert.throws(()=>store.retract(admin2,{room:message.room,messageId:message.id}),/ตนเอง/);
 const first=store.retract(admin,{room:message.room,messageId:message.id});
 assert.equal(first.message.kind,'retracted');assert.equal(first.message.text,'ยกเลิกข้อความแล้ว');assert.equal(first.message.image,null);assert.equal(store.unread(user,first.message),false);
 const version=store.state.rooms[message.room].version;
 assert.equal(store.retract(admin,{room:message.room,messageId:message.id}).duplicate,true);assert.equal(store.state.rooms[message.room].version,version);
 const reloaded=new ChatStore(path.dirname(store.file));assert.equal(reloaded.history(user)[0].kind,'retracted');assert.ok(!fs.readFileSync(store.file,'utf8').includes('Private original'));assert.ok(!fs.readFileSync(store.file,'utf8').includes(image));
});
test('retracting a reply used to close a job reopens it and removes its generated summary',t=>{
 const store=fixture(t),id=store.send(user,input({support:true,text:'Help'})).message.room;
 const response=store.send(admin,input({room:id,text:'Secret answer',closeWithReply:true})).message;
 store.retract(admin,{room:id,messageId:response.id});
 assert.equal(store.state.rooms[id].status,'open');assert.equal(store.state.rooms[id].lastAdminSeq,0);assert.equal(store.state.rooms[id].resolution,'');
 assert.throws(()=>store.job(admin,{room:id,version:store.state.rooms[id].version,action:'close',summary:'Done'}),/ตอบ/);
 assert.ok(!fs.readFileSync(store.file,'utf8').includes('Secret answer'));
});
test('retract preserves other valid replies, handles room-wide messages, and saves before acknowledgement',t=>{
 const store=fixture(t),id=store.send(user,input({support:true})).message.room;
 const old=store.send(admin,input({room:id,text:'Old reply'})).message;store.send(admin,input({room:id,text:'Final reply',closeWithReply:true}));
 store.retract(admin,{room:id,messageId:old.id});assert.equal(store.state.rooms[id].status,'closed');
 const shared=store.send(admin,input()).message;store.retract(admin,{room:'all',messageId:shared.id});assert.equal(store.history(user).at(-1).kind,'retracted');
 const fresh=store.send(admin,input()).message,write=fs.writeFileSync;
 fs.writeFileSync=(file,...args)=>{if(file===store.file+'.new')throw Object.assign(new Error('fail'),{code:'ENOSPC'});return write(file,...args);};
 try{assert.throws(()=>store.retract(admin,{room:'all',messageId:fresh.id}),/fail/);assert.equal(store.allMessages().find(m=>m.id===fresh.id).text,'Test');}finally{fs.writeFileSync=write;}
});
test('named operators sharing one database account have separate ownership, private rooms and audit',t=>{
 const store=fixture(t),eak={...admin,uid:'1@admin-eak',name:'admin-eak'},moo={...admin,uid:'1@admin-moo',name:'admin-moo'};
 const privateId=store.send(eak,input({target:user})).message.room;
 assert.throws(()=>store.detail(moo,privateId));
 const id=store.send(user,input({support:true})).message.room;
 store.job(eak,{room:id,version:1,action:'claim'});
 assert.throws(()=>store.send(moo,input({room:id})),/รับผิดชอบ/);
 store.job(moo,{room:id,version:2,action:'takeover'});
 store.send(moo,input({room:id,text:'Resolved',closeWithReply:true}));
 assert.equal(store.state.rooms[id].closedBy.name,'admin-moo');
 assert.deepEqual(store.state.rooms[id].audit.filter(a=>['claim','takeover'].includes(a.action)).map(a=>a.by.name),['admin-eak','admin-moo']);
});
test('only admin starts a room; shared room supports replies from both systems',t=>{const store=fixture(t);assert.throws(()=>store.send(user,input()),/สิทธิ์/);store.send(admin,input());store.send(user,input());store.send(other,input());assert.equal(store.history(other).length,3);});
test('private messages and images visible only to the two selected accounts, including other admins',t=>{const store=fixture(t);const sent=store.send(admin,input({target:user})).message;store.send(user,input({room:sent.room,text:'Reply'}));assert.equal(store.history(user).length,2);assert.equal(store.history(admin).length,2);assert.equal(store.history(other).length,0);assert.equal(store.history(admin2).length,0);assert.throws(()=>store.send(other,input({room:sent.room})),/สิทธิ์/);assert.throws(()=>store.read(admin2,sent.room,sent.seq),/สิทธิ์/);assert.throws(()=>store.send(user,input({target:other})),/Admin/);});
test('duplicate send is idempotent and unread/read survives restart',t=>{const store=fixture(t);const payload=input({target:user}),m=store.send(admin,payload).message;assert.equal(store.send(admin,payload).duplicate,true);assert.equal(store.allMessages().length,1);assert.equal(store.unread(user,m),true);store.read(user,m.room,m.seq);assert.equal(new ChatStore(path.dirname(store.file)).unread(user,m),false);assert.throws(()=>store.read(user,m.room,m.seq+1));});
test('rejects scripts, forged image types, oversized messages and user screen requests',t=>{const store=fixture(t);store.send(admin,input());for(const image of ['data:image/svg+xml;base64,PHN2Zz4=','data:image/png;base64,'+Buffer.alloc(20).toString('base64'),'data:image/jpeg;base64,'+Buffer.alloc(300*1024).toString('base64')])assert.throws(()=>store.send(admin,input({image})));assert.throws(()=>store.send(admin,input({text:'x'.repeat(2001)})));assert.throws(()=>store.send(user,input({kind:'screen_request'})));const png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE2sAAAAASUVORK5CYII=';assert.equal(store.send(admin,input({image:png})).message.image,png);});
test('failed persistence never acknowledges or adds a message',t=>{const store=fixture(t);const write=fs.writeFileSync;fs.writeFileSync=(file,...args)=>{if(file===store.file+'.new')throw Object.assign(new Error('fail'),{code:'ENOSPC'});return write(file,...args);};try{assert.throws(()=>store.send(admin,input()),/fail/);assert.equal(store.state.messages.length,0);}finally{fs.writeFileSync=write;}});
test('user opens a durable team job without any admin online; retry does not create duplicate jobs',t=>{const store=fixture(t),payload=input({support:true,text:'Offline issue'}),message=store.send(user,payload).message;assert.equal(store.state.rooms[message.room].status,'open');assert.equal(store.send(user,payload).duplicate,true);assert.equal(Object.keys(store.state.rooms).length,1);const restarted=new ChatStore(path.dirname(store.file));assert.equal(restarted.inbox(admin)[0].title,'Offline issue');assert.equal(restarted.inbox(admin2)[0].messageCount,1);assert.throws(()=>restarted.inbox(user));assert.throws(()=>restarted.detail(other,message.room));assert.ok(!restarted.rooms(admin)[0].messages);});
test('claim and takeover use versions and only the assignee can reply or close',t=>{const store=fixture(t),roomId=store.send(user,input({support:true})).message.room;let room=store.state.rooms[roomId];store.job(admin,{room:roomId,version:room.version,action:'claim'});assert.throws(()=>store.job(admin2,{room:roomId,version:1,action:'claim'}),/เปลี่ยนแปลง/);assert.throws(()=>store.send(admin2,input({room:roomId})),/รับผิดชอบ/);assert.throws(()=>store.job(admin,{room:roomId,version:2,action:'close',summary:'Done'}),/ตอบ/);room=store.state.rooms[roomId];store.job(admin2,{room:roomId,version:room.version,action:'takeover'});assert.equal(store.state.rooms[roomId].assignee.name,'Admin 2');assert.throws(()=>store.job(admin,{room:roomId,version:3,action:'release'}),/รับผิดชอบ/);});
test('screen request or read alone cannot close a job; reply then close records resolution and actor',t=>{const store=fixture(t),id=store.send(user,input({support:true})).message.room;store.send(admin,input({room:id,kind:'screen_request'}));assert.throws(()=>store.job(admin,{room:id,version:store.state.rooms[id].version,action:'close',summary:'Done'}),/ตอบ/);store.read(admin,id,store.state.seq);store.send(admin,input({room:id,text:'Fixed'}));assert.equal(store.state.rooms[id].status,'waiting');store.job(admin,{room:id,version:store.state.rooms[id].version,action:'close',summary:'Fixed setting'});assert.equal(store.state.rooms[id].status,'closed');assert.equal(store.state.rooms[id].resolution,'Fixed setting');assert.equal(store.state.rooms[id].closedBy.name,'Admin');assert.equal(store.state.rooms[id].audit.at(-1).summary,'Fixed setting');assert.throws(()=>store.job(user,{room:id,version:store.state.rooms[id].version,action:'close',summary:'Done'}));});
test('reply and close is atomic, then a new user message reopens with audit retained',t=>{const store=fixture(t),id=store.send(user,input({support:true})).message.room;store.send(admin,input({room:id,text:'Resolved',closeWithReply:true}));assert.equal(store.state.rooms[id].status,'closed');assert.throws(()=>store.send(admin,input({room:id})),/เปิดงานใหม่/);store.send(user,input({room:id,text:'Still an issue'}));assert.equal(store.state.rooms[id].status,'open');assert.ok(store.state.rooms[id].audit.some(e=>e.action==='user_reopen'));assert.equal(store.state.rooms[id].resolution,'Resolved');assert.throws(()=>store.job(admin,{room:id,version:store.state.rooms[id].version,action:'close',summary:'Again'}),/ตอบ/);});
test('ticket history is paginated and preserved independently from transient chat retention',t=>{const store=fixture(t),id=store.send(user,input({support:true})).message.room;for(let i=0;i<34;i++)store.send(user,input({room:id,text:'Message '+i}));const latest=store.detail(admin,id);assert.equal(latest.messages.length,30);assert.equal(latest.more,true);const older=store.detail(admin,id,latest.messages[0].seq);assert.equal(older.messages.length,5);assert.equal(older.more,false);store.state.messages=Array.from({length:501},(_,i)=>({...latest.messages[0],room:'all',seq:1000+i,at:0,clientId:'legacy-'+i}));store.state.seq=2000;store.send(admin,input({room:'all'}));assert.equal(store.state.messages.length,1);assert.equal(store.detail(admin,id).messages.length,30);assert.equal(store.state.rooms[id].messages.length,35);});
test('multi-user replies remain separate and stale or owned jobs are rejected independently',t=>{const store=fixture(t),one=store.send(user,input({support:true})).message.room,two=store.send(other,input({support:true})).message.room;store.send(admin,input({room:one,text:'Shared answer',version:1}));store.send(admin,input({room:two,text:'Shared answer',version:1}));assert.equal(store.detail(user,one).messages.at(-1).text,'Shared answer');assert.throws(()=>store.detail(user,two));assert.throws(()=>store.send(admin,input({room:one,text:'Stale',version:1})),/เปลี่ยนแปลง/);assert.equal(store.inbox(admin).filter(j=>j.status==='waiting').length,2);});
test('existing private chat migrates into its own admin inbox without widening access or losing reads',t=>{const store=fixture(t);const room={id:'private-legacy',members:[store.key(admin),store.key(user)],people:[admin,user],title:'Private'};const first={id:'old-1',seq:1,clientId:'old-client-000001',room:room.id,sender:{...user,key:store.key(user)},text:'Issue',kind:'message',at:10},second={id:'old-2',seq:2,clientId:'old-client-000002',room:room.id,sender:{...admin,key:store.key(admin)},text:'Answered',kind:'message',at:20};store.commit({seq:2,rooms:{[room.id]:room},messages:[first,second],seen:{[store.key(user)]:{[room.id]:1}}});const migrated=new ChatStore(path.dirname(store.file));assert.equal(migrated.inbox(admin)[0].status,'waiting');assert.equal(migrated.inbox(admin)[0].requester.name,'User');assert.equal(migrated.inbox(admin2).length,0);assert.throws(()=>migrated.detail(admin2,room.id));assert.equal(migrated.detail(user,room.id).messages.length,2);assert.equal(migrated.unread(user,second),true);migrated.job(admin,{room:room.id,version:1,action:'close',summary:'Fixed'});assert.equal(migrated.state.rooms[room.id].status,'closed');});
test('private recipient who is also an admin can reply but cannot manage the other admin job',t=>{const store=fixture(t),id=store.send(admin,input({target:admin2})).message.room;store.send(admin2,input({room:id,text:'Recipient reply'}));assert.equal(store.state.rooms[id].status,'open');assert.equal(store.inbox(admin2).length,0);assert.throws(()=>store.job(admin2,{room:id,version:2,action:'takeover'}));});

test('general private chat is durable and separate from tickets for the same two accounts',t=>{
 const store=fixture(t),ticket=store.send(admin,input({target:user})).message.room;
 const payload=input({target:user,conversation:true,text:'General chat'}),m=store.send(admin,payload).message;
 assert.notEqual(m.room,ticket);assert.equal(store.state.rooms[m.room].conversation,true);assert.equal(store.isJob(store.state.rooms[m.room]),false);
 assert.equal(store.state.rooms[m.room].status,undefined);assert.equal(store.inbox(admin).length,1);assert.equal(store.inbox(admin)[0].id,ticket);
 store.send(user,input({room:m.room,text:'General reply'}));assert.equal(store.detail(user,m.room).messages.length,2);
 assert.throws(()=>store.job(admin,{room:m.room,action:'claim'}),/สิทธิ์/);
 assert.throws(()=>store.send(admin,input({room:m.room,closeWithReply:true})),/ปิดงาน/);
 assert.throws(()=>store.detail(admin2,m.room),/สิทธิ์/);assert.throws(()=>store.send(other,input({room:m.room})),/สิทธิ์/);
 const restored=new ChatStore(path.dirname(store.file));assert.equal(restored.state.rooms[m.room].conversation,true);assert.equal(restored.inbox(admin).length,1);
 assert.equal(restored.send(admin,payload).duplicate,true);assert.equal(restored.detail(user,m.room).messages.length,2);
});
test('user may start general team chat offline; it never changes ticket status or appears in support reports',t=>{
 const store=fixture(t),ticket=store.send(user,input({support:true,text:'An issue'})).message.room;
 const payload=input({generalTeam:true,conversation:true,text:'Hello team'}),m=store.send(user,payload).message;
 assert.equal(store.state.rooms[m.room].team,true);assert.equal(store.state.rooms[m.room].status,undefined);
 assert.equal(store.inbox(admin).length,1);assert.equal(store.state.rooms[ticket].status,'open');
 store.send(admin,input({room:m.room,text:'Hello'}));store.send(admin2,input({room:m.room,text:'Another admin'}));
 assert.equal(store.detail(user,m.room).messages.length,3);assert.throws(()=>store.detail(other,m.room),/สิทธิ์/);
 store.read(user,m.room,store.state.seq);assert.equal(store.unread(user,store.detail(user,m.room).messages.at(-1)),false);
 const restored=new ChatStore(path.dirname(store.file));assert.equal(restored.send(user,payload).duplicate,true);
 assert.equal(restored.send(user,input({generalTeam:true,text:'Next'})).message.room,m.room);
 assert.equal(restored.inbox(admin2).length,1);
 const month=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit'}).format(new Date());
 assert.ok(!require('./report').monthlyReport(restored,admin,month).rows.some(r=>r.id===m.room));
 assert.throws(()=>store.send(admin,input({generalTeam:true})),/เลือกผู้ใช้/);
 assert.throws(()=>store.send(user,input({generalTeam:true,support:true})),/ประเภทเดียว/);
});
test('general conversation pagination and retraction survive restart without conversion into a ticket',t=>{
 const store=fixture(t),m=store.send(admin,input({target:user,conversation:true,text:'Withdraw me'})).message;
 for(let i=0;i<34;i++)store.send(user,input({room:m.room,text:'Message '+i}));
 const latest=store.detail(user,m.room);assert.equal(latest.messages.length,30);assert.equal(latest.more,true);
 assert.equal(store.detail(user,m.room,latest.messages[0].seq).messages.length,5);
 store.retract(admin,{room:m.room,messageId:m.id});const restored=new ChatStore(path.dirname(store.file));
 assert.equal(restored.state.rooms[m.room].messages.length,35);assert.equal(restored.state.rooms[m.room].messages[0].kind,'retracted');
 assert.equal(restored.inbox(admin).length,0);assert.equal(restored.state.rooms[m.room].job,undefined);
});
