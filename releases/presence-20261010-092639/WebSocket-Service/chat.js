'use strict';
const fs = require('fs'), path = require('path'), crypto = require('crypto');
class ChatStore {
  constructor(directory, now = () => Date.now()) {
    this.file = path.join(directory, 'chat.json'); this.now = now;
    this.state = fs.existsSync(this.file) ? JSON.parse(fs.readFileSync(this.file, 'utf8')) : {seq:0, rooms:{}, messages:[], seen:{}};
    if (!Number.isInteger(this.state.seq) || !this.state.rooms || !Array.isArray(this.state.messages) || !this.state.seen) throw new Error('Invalid chat storage');
    const migrated=JSON.parse(JSON.stringify(this.state));let changed=false;
    for(const room of Object.values(migrated.rooms))if(room.id!=='all'&&!room.ticket&&!room.job&&room.people?.length===2){
      const messages=migrated.messages.filter(m=>m.room===room.id),owner=room.people[0];
      room.job=true;room.messages=messages;room.version=1;room.createdAt=messages[0]?.at||this.now();room.updatedAt=messages.at(-1)?.at||room.createdAt;
      room.assignee={key:this.key(owner),name:owner.name};room.status=messages.at(-1)?.sender.key===this.key(owner)?'waiting':'open';room.audit=[{action:'migrated',by:{name:'ระบบ'},at:this.now()}];
      const userMessages=messages.filter(m=>m.sender.key!==this.key(owner)),replies=messages.filter(m=>m.sender.key===this.key(owner)&&m.kind!=='screen_request');
      room.lastUserSeq=userMessages.at(-1)?.seq||0;room.lastUserAt=userMessages.at(-1)?.at||null;room.lastAdminSeq=replies.at(-1)?.seq||0;room.lastAdminAt=replies.at(-1)?.at||null;
      room.title=(userMessages[0]?.text||messages[0]?.text||'สนทนาส่วนตัว').slice(0,80);
      migrated.messages=migrated.messages.filter(m=>m.room!==room.id);changed=true;
    }
    if(changed)this.commit(migrated);
  }
  key(identity) { return JSON.stringify([identity.system, String(identity.uid)]); }
  allowed(room, identity) { return room && (room.id === 'all' || room.members.includes(this.key(identity)) || (room.ticket && identity.admin)); }
  isJob(room){return !!(room&&(room.ticket||room.job));}
  managed(room,identity){return identity.admin&&(room.ticket||(room.job&&this.key(room.people[0])===this.key(identity)));}
  allMessages(state=this.state) { return [...state.messages,...Object.values(state.rooms).filter(r=>this.isJob(r)).flatMap(r=>r.messages||[])].sort((a,b)=>a.seq-b.seq); }
  rooms(identity) { return Object.values(this.state.rooms).filter(r => this.allowed(r,identity)).map(r=>this.publicRoom(r)); }
  history(identity) { return this.allMessages().filter(m => this.allowed(this.state.rooms[m.room],identity)).slice(-40); }
  publicRoom(room) {
    const {messages,...metadata}=room;if(!this.isJob(room))return metadata;
    const requester=room.ticket?room.people[0]:room.people[1],requesterKey=this.key(requester);
    const received=(messages||[]).filter(m=>!m.retractedAt&&m.sender.key!==requesterKey),latest=received.at(-1)?.seq||0;
    const seen=this.state.seen[requesterKey]?.[room.id]||0,actual=room.requesterActualSeq??(room.readOverride?0:seen);
    return {...metadata,requester,requesterRead:{unread:received.filter(m=>m.seq>seen).length,seq:seen,mode:!latest?'none':actual>=latest?'user':seen>=latest?'admin':'unread',at:room.requesterReadAt||null,override:room.readOverride||null}};
  }
  inbox(identity) {
    if(!identity.admin) throw new Error('เฉพาะ Admin ที่เปิดกล่องงานได้');
    return Object.values(this.state.rooms).filter(r=>this.isJob(r)&&this.managed(r,identity)).map(r=>({...this.publicRoom(r),unread:(r.messages||[]).filter(m=>this.unread(identity,m)).length,lastText:(r.messages||[]).at(-1)?.text||'',messageCount:(r.messages||[]).length,requester:r.ticket?r.people[0]:r.people[1]})).sort((a,b)=>b.updatedAt-a.updatedAt);
  }
  detail(identity, roomId, before=Infinity) {
    const room=this.state.rooms[roomId];if(!this.isJob(room)||!this.allowed(room,identity))throw new Error('ไม่มีสิทธิ์เข้าถึงงาน');
    const list=(room.messages||[]).filter(m=>m.seq<before),messages=list.slice(-30);
    return {room:this.publicRoom(room),messages,more:list.length>messages.length};
  }
  job(identity,input) {
    if(!identity.admin)throw new Error('เฉพาะ Admin ที่จัดการงานได้');
    const next=JSON.parse(JSON.stringify(this.state)),room=next.rooms[input.room];
    if(!this.isJob(room)||!this.managed(room,identity))throw new Error('ไม่มีสิทธิ์จัดการงาน');
    if(room.version!==input.version)throw new Error('งานมีการเปลี่ยนแปลง กรุณาโหลดข้อมูลล่าสุด');
    const own=room.assignee?.key===this.key(identity),actor={key:this.key(identity),name:identity.name};
    if(input.action==='mark_read'){
      const requester=room.ticket?room.people[0]:room.people[1],reader=this.key(requester);
      const received=room.messages.filter(m=>!m.retractedAt&&m.sender.key!==reader),latest=received.at(-1)?.seq;
      if(!latest)throw new Error('ยังไม่มีข้อความที่ส่งถึงผู้ใช้ให้ล้างสถานะ');
      next.seen[reader] ||= {};
      room.requesterActualSeq ??= next.seen[reader][room.id]||0;
      if((next.seen[reader][room.id]||0)>=latest)return this.publicRoom(this.state.rooms[room.id]);
      next.seen[reader][room.id]=latest;room.readOverride={seq:latest,by:actor,at:this.now()};
    }else if(input.action==='claim'||input.action==='takeover'){
      if(input.action==='claim'&&room.assignee&&!own)throw new Error('งานนี้มีผู้รับผิดชอบแล้ว');
      if(room.status==='closed')throw new Error('กรุณาเปิดงานใหม่ก่อนรับงาน');
      room.assignee=actor;room.status='in_progress';
    }else if(input.action==='release'){
      if(!own)throw new Error('เฉพาะผู้รับผิดชอบที่คืนงานได้');if(room.status==='closed')throw new Error('งานปิดแล้ว');room.assignee=null;room.status='open';
    }else if(input.action==='close'){
      if(room.status==='closed')throw new Error('งานปิดแล้ว');
      if(!own)throw new Error('กรุณารับงานก่อนปิดงาน');
      if(!room.lastAdminAt||room.lastAdminSeq<room.lastUserSeq)throw new Error('กรุณาตอบข้อความล่าสุดของผู้ใช้ก่อนปิดงาน');
      const summary=typeof input.summary==='string'?input.summary.trim():'';
      if(!summary||summary.length>2000)throw new Error('กรุณาระบุสรุปการแก้ไขไม่เกิน 2,000 ตัวอักษร');
      room.status='closed';room.resolution=summary;delete room.resolutionSeq;room.closedAt=this.now();room.closedBy=actor;
    }else if(input.action==='reopen'){
      if(room.status!=='closed')throw new Error('งานยังไม่ปิด');room.status='open';room.closedAt=null;
    }else throw new Error('คำสั่งจัดการงานไม่ถูกต้อง');
    room.version++;room.updatedAt=this.now();room.audit.push({action:input.action,by:actor,at:this.now(),...(input.action==='close'?{summary:room.resolution}:{})});
    this.commit(next);return this.publicRoom(room);
  }
  unread(identity, message) { return !message.retractedAt && message.sender.key !== this.key(identity) && (this.state.seen[this.key(identity)]?.[message.room] || 0) < message.seq; }
  retract(identity, input) {
    if(typeof input.messageId!=='string'||! /^[a-f0-9-]{36}$/i.test(input.messageId))throw new Error('รหัสข้อความไม่ถูกต้อง');
    const next=JSON.parse(JSON.stringify(this.state)),message=this.allMessages(next).find(m=>m.id===input.messageId&&m.room===input.room),room=next.rooms[input.room];
    if(!message||!this.allowed(room,identity)||message.sender.key!==this.key(identity))throw new Error('ยกเลิกได้เฉพาะข้อความของตนเอง');
    if(message.retractedAt)return {message,room:this.publicRoom(room),duplicate:true};
    const originalText=message.text;
    message.text='ยกเลิกข้อความแล้ว';message.image=null;message.kind='retracted';message.retractedAt=this.now();
    if(this.isJob(room)){
      const active=room.messages.filter(m=>!m.retractedAt),isReply=m=>m.sender.admin&&(room.ticket||m.sender.key===this.key(room.people[0]))&&m.kind!=='screen_request';
      const reply=active.filter(isReply).at(-1),user=active.filter(m=>!m.sender.admin||(!room.ticket&&m.sender.key!==this.key(room.people[0]))).at(-1);
      room.lastAdminSeq=reply?.seq||0;room.lastAdminAt=reply?.at||null;room.lastUserSeq=user?.seq||0;room.lastUserAt=user?.at||null;
      const withdrawClosure=room.resolutionSeq===message.seq||(!room.resolutionSeq&&room.closedAt===message.at&&room.audit.some(a=>a.action==='close_with_reply'&&a.at===message.at&&a.by?.key===message.sender.key));
      if(withdrawClosure||(room.status==='closed'&&(!reply||room.lastAdminSeq<room.lastUserSeq))){room.status='open';room.resolution='';room.closedAt=null;room.closedBy=null;delete room.resolutionSeq;}
      else if(room.status!=='closed'){room.status=reply&&room.lastAdminSeq>=room.lastUserSeq?'waiting':(room.assignee&&!user?'in_progress':'open');}
      for(const event of room.audit)if(event.summary===originalText)delete event.summary;
      if(room.resolution===originalText)room.resolution='';
      room.title=(active.find(m=>!isReply(m))?.text||active[0]?.text||(room.ticket?'แจ้งเรื่องถึงทีมผู้ดูแล':'สนทนาส่วนตัว')).slice(0,80);
      room.version++;room.updatedAt=this.now();room.audit.push({action:'message_retracted',messageId:message.id,by:{key:this.key(identity),name:identity.name},at:message.retractedAt});
    }
    this.commit(next);return {message,room:this.publicRoom(room),duplicate:false};
  }
  image(value) {
    if (!value) return null;
    if (typeof value !== 'string') throw new Error('รูปภาพไม่ถูกต้อง');
    const match = /^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+/]+={0,2})$/.exec(value);
    if (!match) throw new Error('รองรับรูป PNG, JPEG และ WebP เท่านั้น');
    const bytes = Buffer.from(match[2], 'base64');
    if (bytes.length > 256*1024 || bytes.length < 12 || bytes.toString('base64') !== match[2]) throw new Error('รูปต้องไม่เกิน 256 KB');
    const valid = match[1] === 'png' ? bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])) : match[1] === 'jpeg' ? bytes[0]===255 && bytes[1]===216 && bytes[2]===255 : bytes.toString('ascii',0,4)==='RIFF' && bytes.toString('ascii',8,12)==='WEBP';
    if (!valid) throw new Error('ชนิดไฟล์รูปภาพไม่ตรงกับข้อมูล');
    return value;
  }
  commit(next) {
    fs.writeFileSync(this.file + '.new',JSON.stringify(next));
    for(let attempt=0;;attempt++) {try {fs.renameSync(this.file+'.new',this.file);break;} catch(e) {if(!['EPERM','EACCES','EBUSY'].includes(e.code)||attempt>=10)throw e;Atomics.wait(new Int32Array(new SharedArrayBuffer(4)),0,0,50);}}
    this.state=next;
  }
  send(identity, input) {
    if (typeof input.clientId !== 'string' || !/^[a-zA-Z0-9-]{16,64}$/.test(input.clientId)) throw new Error('รหัสข้อความไม่ถูกต้อง');
    const duplicate = this.allMessages().find(m => m.clientId===input.clientId && m.sender.key===this.key(identity));
    if (duplicate) return {message:duplicate, duplicate:true};
    const next = JSON.parse(JSON.stringify(this.state)); let room = next.rooms[input.room];
    if(input.support===true){
      const id='ticket-'+crypto.randomUUID(),person={system:identity.system,uid:String(identity.uid),name:identity.name};
      room=next.rooms[id]={id,ticket:true,title:(typeof input.text==='string'?input.text.trim().slice(0,80):'')||'แจ้งปัญหาพร้อมรูป',members:[this.key(identity)],people:[person],status:'open',assignee:null,version:0,createdAt:this.now(),updatedAt:this.now(),audit:[],messages:[]};
    } else if (input.target) {
      if (!identity.admin || !['EIS-DOCS','SUPPLIES'].includes(input.target.system) || !/^\d{1,20}(?:@admin-[a-z0-9][a-z0-9_-]{1,31})?$/.test(String(input.target.uid))) throw new Error('เฉพาะ Admin ที่เริ่มสนทนาส่วนตัวได้');
      const members=[this.key(identity),this.key(input.target)];
      if(members[0]===members[1]) throw new Error('กรุณาเลือกผู้ใช้อื่น');
      const id='private-'+crypto.createHash('sha256').update(JSON.stringify(members)).digest('hex');
      room=next.rooms[id] ||= {id,members,job:true,messages:[],version:0,status:'open',assignee:{key:this.key(identity),name:identity.name},createdAt:this.now(),updatedAt:this.now(),audit:[],title:'สนทนาส่วนตัว',people:[{system:identity.system,uid:String(identity.uid),name:identity.name},{system:input.target.system,uid:String(input.target.uid),name:String(input.target.name||input.target.uid).slice(0,120)}]};
    } else if(input.room==='all' && !room && identity.admin) room=next.rooms.all={id:'all',members:[],title:'ห้องรวม · EIS-DOCS / Supplies'};
    if (!this.allowed(room,identity)) throw new Error('ไม่มีสิทธิ์เข้าถึงบทสนทนา');
    const text=typeof input.text==='string'?input.text.trim():'';
    if (text.length>2000) throw new Error('ข้อความต้องไม่เกิน 2,000 ตัวอักษร');
    const image=this.image(input.image);
    if(!text&&!image) throw new Error('กรุณาพิมพ์ข้อความหรือแนบรูป');
    const responder=this.isJob(room)?this.managed(room,identity):identity.admin;
    if(input.kind==='question'&&(!this.isJob(room)||!identity.admin||!responder||!text||input.closeWithReply))throw new Error('ถามกลับได้เฉพาะ Admin ในงาน และต้องไม่ปิดงานพร้อมกัน');
    if(input.kind==='screen_request'&&!identity.admin) throw new Error('เฉพาะ Admin ที่ขอภาพหน้าจอได้');
    if(this.isJob(room)&&responder){
      if(input.version!==undefined&&room.version!==input.version)throw new Error('งานมีการเปลี่ยนแปลง กรุณาโหลดข้อมูลล่าสุด');
      if(room.status==='closed')throw new Error('กรุณาเปิดงานใหม่ก่อนตอบ');
      if(room.assignee&&room.assignee.key!==this.key(identity))throw new Error('งานนี้มี Admin คนอื่นรับผิดชอบ กรุณารับช่วงงานก่อนตอบ');
      room.assignee={key:this.key(identity),name:identity.name};
    }
    if(input.closeWithReply){
      if(!this.isJob(room)||!responder||input.kind==='screen_request')throw new Error('ไม่สามารถตอบและปิดงานนี้ได้');
      if(!text)throw new Error('กรุณาพิมพ์คำตอบเพื่อใช้สรุปปิดงาน');
    }
    const message={id:crypto.randomUUID(),clientId:input.clientId,seq:++next.seq,room:room.id,sender:{key:this.key(identity),system:identity.system,uid:String(identity.uid),name:identity.name,admin:identity.admin===true},text,image,kind:['screen_request','question'].includes(input.kind)?input.kind:'message',at:this.now()};
    if(this.isJob(room)){
      room.messages.push(message);room.updatedAt=this.now();room.version++;
      if(responder){room.status=input.kind==='screen_request'?'in_progress':'waiting';if(input.kind!=='screen_request'){room.lastAdminAt=message.at;room.lastAdminSeq=message.seq;}}
      else{if(room.status==='closed')room.audit.push({action:'user_reopen',at:message.at,by:{key:this.key(identity),name:identity.name}});room.status='open';room.lastUserAt=message.at;room.lastUserSeq=message.seq;room.closedAt=null;}
      if(room.title==='สนทนาส่วนตัว')room.title=text.slice(0,80)||'แนบภาพในบทสนทนา';
      room.audit.push({action:responder?(input.kind==='question'?'question':'reply'):'user_message',messageId:message.id,at:message.at,by:{key:this.key(identity),name:identity.name}});
      if(input.closeWithReply){room.status='closed';room.resolution=text;room.resolutionSeq=message.seq;room.closedAt=message.at;room.closedBy=room.assignee;room.audit.push({action:'close_with_reply',at:message.at,by:room.assignee,summary:text});}
    }else next.messages.push(message);
    next.messages=next.messages.filter(m=>m.at>=this.now()-30*86400000).slice(-500);
    let size=Buffer.byteLength(JSON.stringify(next.messages));
    while(size>16*1024*1024&&next.messages.length>1){size-=Buffer.byteLength(JSON.stringify(next.messages.shift()));}
    if(Buffer.byteLength(JSON.stringify(next))>128*1024*1024)throw new Error('พื้นที่ประวัติสนทนาถึงขีดจำกัด กรุณาติดต่อผู้ดูแลระบบ');
    this.commit(next); return {message,duplicate:false};
  }
  read(identity,roomId,seq) {
    if(!this.allowed(this.state.rooms[roomId],identity) || !Number.isInteger(seq) || seq<0 || seq>this.state.seq) throw new Error('ไม่มีสิทธิ์อ่านบทสนทนา');
    const latest=this.allMessages().filter(m=>m.room===roomId).at(-1)?.seq||0;
    if(seq>latest)throw new Error('รหัสข้อความไม่ถูกต้อง');
    const next=JSON.parse(JSON.stringify(this.state)),key=this.key(identity);
    next.seen[key] ||= {}; next.seen[key][roomId]=Math.max(next.seen[key][roomId]||0,seq);
    const room=next.rooms[roomId];
    if(this.isJob(room)&&key===this.key(room.ticket?room.people[0]:room.people[1])){room.requesterActualSeq=Math.max(room.requesterActualSeq||0,seq);room.requesterReadAt=this.now();}
    this.commit(next);
  }
}
module.exports={ChatStore};
