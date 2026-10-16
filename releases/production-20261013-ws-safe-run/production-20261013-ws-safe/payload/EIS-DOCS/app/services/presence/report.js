'use strict';
function monthlyReport(store,identity,month){
 if(!identity.admin)throw new Error('เฉพาะ Admin ที่ดูรายงานได้');
 if(typeof month!=='string'||!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)||+month.slice(0,4)<2000||+month.slice(0,4)>2100)throw new Error('เดือนรายงานไม่ถูกต้อง');
 const [year,m]=month.split('-').map(Number),start=Date.UTC(year,m-1,1)-7*3600000,end=Date.UTC(year,m,1)-7*3600000;
 const rows=[];
 for(const room of Object.values(store.state.rooms)){
  if(!store.isJob(room)||!store.managed(room,identity))continue;
  if(![room.createdAt,...(room.audit||[]).map(a=>a.at),...(room.messages||[]).map(a=>a.at)].some(t=>t>=start&&t<end))continue;
  const requester=room.ticket?room.people[0]:room.people[1],events=[];
  events.push({stage:'เข้ามาแจ้งปัญหา',at:room.createdAt,by:requester.name,text:room.title,inMonth:room.createdAt>=start&&room.createdAt<end});
  for(const a of room.audit||[]){
   const stage={claim:'รับปัญหา',takeover:'รับช่วงปัญหา',release:'คืนงานเข้าคิว',reply:'ตอบผู้ใช้ / รอผู้ใช้ตอบกลับ',question:'ถามกลับผู้ใช้ / รอผู้ใช้ตอบกลับ',user_message:'ผู้ใช้ส่งข้อความ',user_reopen:'ผู้ใช้เปิดงานใหม่',reopen:'เปิดงานใหม่',close:'แก้ปัญหาเรียบร้อย / ปิดงาน',close_with_reply:'แก้ปัญหาเรียบร้อย / ปิดงาน',mark_read:'Admin ล้างสถานะอ่านแล้ว',message_retracted:'ยกเลิกข้อความ'}[a.action];
   if(!stage)continue;
   const message=(room.messages||[]).find(msg=>a.messageId?msg.id===a.messageId:msg.at===a.at&&msg.sender.key===a.by?.key&&((a.action==='question'&&msg.kind==='question')||(a.action==='reply'&&msg.sender.key!==store.key(requester))||(a.action==='user_message'&&msg.sender.key===store.key(requester))));
   events.push({stage,at:a.at,by:a.by?.name||'',text:message?.text||a.summary||'',inMonth:a.at>=start&&a.at<end});
  }
  events.sort((a,b)=>a.at-b.at);
  const first=stage=>events.find(e=>e.stage.startsWith(stage))?.at||null;
  rows.push({id:room.id,title:room.title,system:requester.system,requester:requester.name,assignee:room.assignee?.name||'',status:room.status,createdAt:room.createdAt,receivedAt:first('รับปัญหา')||first('รับช่วงปัญหา')||first('ตอบผู้ใช้')||first('ถามกลับ'),questionAt:first('ถามกลับ'),waitingAt:room.lastAdminAt||null,userReplyAt:room.lastUserAt>room.createdAt?room.lastUserAt:null,resolvedAt:room.status==='closed'?room.closedAt:null,closedAt:room.status==='closed'?room.closedAt:null,resolution:room.resolution||'',events});
 }
 return {month,generatedAt:store.now(),rows:rows.sort((a,b)=>b.createdAt-a.createdAt)};
}
module.exports={monthlyReport};
