(function(){
  'use strict';
  var jobs=[],identity,connected=false,current=null,detailMessages=[],more=false,checked=new Set(),pending=new Map(),batch=null;
  var labels={open:'รอตอบ',in_progress:'กำลังดำเนินการ',waiting:'ตอบแล้ว / รอผู้ใช้',closed:'ปิดงาน'},actions={question:'ถามกลับผู้ใช้ / รอผู้ใช้ตอบกลับ',mark_read:'Admin ล้างสถานะอ่านแล้ว',claim:'รับงาน',takeover:'รับช่วงงาน',release:'คืนงาน',reopen:'เปิดงานใหม่',close:'ปิดงาน',close_with_reply:'ตอบและปิดงาน',reply:'ตอบข้อความ',user_message:'ผู้ใช้ส่งข้อความ',user_reopen:'ผู้ใช้เปิดงานกลับมา',message_retracted:'ยกเลิกข้อความ'};
  var $=id=>document.getElementById(id),key=p=>JSON.stringify([p.system,String(p.uid)]),date=t=>t?new Date(t).toLocaleString('th-TH',{hour12:false}):'—';
  var requester=job=>job.requester||(job.ticket?job.people[0]:job.people[1]);
  function systemCode(value){var code=String(value||'').trim().toUpperCase().replace(/_/g,'-');return code==='FM-NMU'?'FM':code;}
  function inSystem(job){return !$('job-system').value||systemCode(requester(job).system)===$('job-system').value;}
  function readLabel(job){var r=job.requesterRead;if(!r)return '';return r.mode==='none'?'ยังไม่มีข้อความถึงผู้ใช้':r.unread?'ผู้ใช้ยังไม่อ่าน '+r.unread+' ข้อความ':r.mode==='admin'?'อ่านแล้ว โดย Admin '+(r.override?.by?.name||'')+' ล้างสถานะ · '+date(r.override?.at):'ผู้ใช้อ่านแล้ว'+(r.at?' · '+date(r.at):'');}
  function own(job){return job.assignee?.key===key(identity);}
  function eligible(job){return job.status!=='closed'&&(!job.assignee||own(job));}
  function transmit(value){if(!connected||!EISPresence.send(value)){ $('job-result').textContent='ยังไม่เชื่อมต่อ กรุณารอสักครู่';return false;}return true;}
  function remember(m){var i=detailMessages.findIndex(v=>v.id===m.id);if(i<0)detailMessages.push(m);else detailMessages[i]=m;}
  function cancelButton(m,node){if(!identity||m.sender.key!==key(identity)||m.retractedAt)return;var button=document.createElement('button');button.type='button';button.textContent='ยกเลิกข้อความ';button.disabled=!connected;button.onclick=()=>{if(confirm('ยกเลิกข้อความนี้สำหรับทุกคนในบทสนทนา?')&&transmit({type:'chat-retract',room:m.room,messageId:m.id})){button.disabled=true;$('job-result').textContent='กำลังยกเลิกข้อความ…';setTimeout(()=>{if(!detailMessages.find(v=>v.id===m.id)?.retractedAt){button.disabled=!connected;$('job-result').textContent='ยังไม่ได้รับยืนยัน กรุณาตรวจประวัติก่อนลองใหม่';}},10000);}};node.append(button);}
  function renderList(){
    checked.forEach(id=>{if(!jobs.some(j=>j.id===id&&eligible(j)))checked.delete(id);});
    Object.keys(labels).forEach(status=>$('count-'+status).textContent=jobs.filter(j=>inSystem(j)&&j.status===status).length);
    var list=$('job-list'),q=$('job-search').value.trim().toLowerCase(),filter=$('job-filter').value;list.replaceChildren();
    jobs.filter(j=>inSystem(j)&&(filter==='all'||(filter==='active'?j.status!=='closed':j.status===filter))&&(!$('job-mine').checked||own(j))&&(!q||[j.id,j.title,requester(j).name,requester(j).system].join(' ').toLowerCase().includes(q))).forEach(job=>{
      var node=document.createElement('div');node.className='job-item'+(current?.id===job.id?' selected':'');
      var checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.setAttribute('aria-label','เลือกงาน '+job.title);checkbox.checked=checked.has(job.id);checkbox.disabled=!eligible(job);checkbox.onchange=()=>{checkbox.checked?checked.add(job.id):checked.delete(job.id);selection();};
      var button=document.createElement('button');button.type='button';button.textContent=job.title;button.onclick=()=>select(job);
      var badge=document.createElement('span');badge.className='job-badge '+job.status;badge.textContent=labels[job.status]+(job.unread?' · ข้อความใหม่สำหรับคุณ '+job.unread:'');
      var meta=document.createElement('small');meta.textContent=(job.ticket?'ทีมผู้ดูแล':'ส่วนตัว')+' · '+requester(job).name+' · '+requester(job).system+' · '+(job.assignee?'ผู้รับผิดชอบ '+job.assignee.name:'ยังไม่มีผู้รับผิดชอบ')+' · '+date(job.updatedAt);
      var preview=document.createElement('small');preview.textContent=job.lastText.slice(0,100);var read=document.createElement("small");read.textContent=readLabel(job);node.append(checkbox,button,badge,meta,read,preview);list.append(node);
    });if(!list.children.length)list.textContent='ไม่มีงานตามตัวกรอง';selection();
  }
  function selection(){ $('bulk-selection').textContent='เลือก '+checked.size+' งาน (สูงสุด 10 งานต่อครั้ง)';$('bulk-confirm').hidden=true; }
  function select(job){current=job;detailMessages=[];more=false;$('job-placeholder').hidden=true;$('job-detail').hidden=false;$('job-result').textContent='';$('reply-text').value='';$('close-summary').value='';transmit({type:'chat-job-detail',room:job.id});renderList();renderDetail();}
  function renderDetail(){
    if(!current)return;$('job-sql').href='sql-manager.php?case='+encodeURIComponent(current.id);var person=requester(current);$('job-title').textContent=current.title;$('job-meta').textContent=labels[current.status]+' · '+(current.ticket?'ทีมผู้ดูแล':'ส่วนตัว')+' · '+person.name+' / '+person.system+' · '+(current.assignee?'ผู้รับผิดชอบ '+current.assignee.name:'ยังไม่มีผู้รับผิดชอบ')+' · เปิดเรื่อง '+date(current.createdAt)+' · '+current.id;
    $('job-read-state').textContent=readLabel(current)+(current.status==='waiting'?' · รอผู้ใช้ตอบกลับ จำนวนยังไม่อ่านเป็น 0 ไม่ได้ปิดงาน':'');
    $('job-summary').textContent=current.resolution?'สรุปการแก้ไข: '+current.resolution+' · '+(current.closedBy?.name||'')+(current.closedAt?' · ปิดเมื่อ '+date(current.closedAt):' · จากรอบก่อน'):'';
    document.querySelectorAll('[data-action]').forEach(button=>{var action=button.dataset.action;button.hidden=action==='mark_read'?!(current.requesterRead?.unread>0):action==='claim'?(!!current.assignee||current.status==='closed'):action==='takeover'?(!current.assignee||own(current)||current.status==='closed'):action==='release'?(!own(current)||current.status==='closed'):current.status!=='closed';button.disabled=!connected;});
    $('reply-send').disabled=!connected||!eligible(current);$('reply-chat').disabled=!connected;$('close-form').hidden=current.status==='closed'||!own(current);$('reply-close').disabled=!eligible(current);
    var log=$('job-messages');log.replaceChildren();detailMessages.forEach(m=>{var node=document.createElement('div');node.className='job-message'+(m.sender.admin?' admin':'');var small=document.createElement('small');small.textContent=(m.sender.admin?'Admin · ':'')+m.sender.name+' · '+date(m.at);var p=document.createElement('p');p.textContent=m.text;node.append(small,p);if(m.image){var img=document.createElement('img');img.src=m.image;img.alt='รูปประกอบการแจ้งเรื่อง';var a=document.createElement('a');a.href=m.image;a.download='job-image-'+m.id+'.'+(m.image.startsWith('data:image/png')?'png':m.image.startsWith('data:image/webp')?'webp':'jpg');a.textContent='ดาวน์โหลดรูป';var imageButton=document.createElement('button');imageButton.type='button';imageButton.className='eis-image-open';imageButton.setAttribute('aria-label','เปิดภาพขนาดใหญ่');imageButton.onclick=()=>window.EISImageViewer.open(m);var imageHint=document.createElement('span');imageHint.textContent='ดูภาพเต็มจอ / ขยายภาพ';imageButton.append(img,imageHint);node.append(imageButton,a);}cancelButton(m,node);log.append(node);});
    $('job-older').hidden=!more;
    var audit=$('job-audit');audit.replaceChildren();(current.audit||[]).slice().reverse().forEach(item=>{var p=document.createElement('p');p.textContent=date(item.at)+' · '+(actions[item.action]||item.action)+' · '+(item.by?.name||'')+(item.summary?' · '+item.summary:'');audit.append(p);});
  }
  function readCurrent(){if(!current||!detailMessages.length||document.visibilityState==='hidden')return;transmit({type:'chat-read',room:current.id,seq:detailMessages.at(-1).seq});}
  function reply(job,text,close,batchReply){var clientId=crypto.randomUUID();pending.set(clientId,{room:job.id,text,batch:batchReply});if(!transmit({type:'chat-send',clientId,room:job.id,version:job.version,text,kind:!batchReply&&$('reply-question').checked?'question':'message',closeWithReply:close})){result(clientId,'ยังไม่เชื่อมต่อ');return false;}setTimeout(()=>{if(pending.has(clientId))result(clientId,'ยังไม่ได้รับยืนยัน อาจบันทึกแล้ว กรุณาตรวจประวัติก่อนส่งซ้ำ');},10000);return true;}
  function result(clientId,error){var request=pending.get(clientId);if(!request)return;pending.delete(clientId);if(request.batch&&batch){batch.done++;if(error){var job=jobs.find(j=>j.id===request.room);batch.errors.push((job?requester(job).name:request.room)+': '+error);}else batch.success++;$('bulk-result').textContent='ส่งสำเร็จ '+batch.success+' / '+batch.total+' งาน'+(batch.errors.length?'\n'+batch.errors.join('\n'):'');if(batch.done===batch.total){$('bulk-review').disabled=false;batch=null;}}else{$('reply-send').disabled=!current||!eligible(current);$('job-result').textContent=error?'ส่งไม่สำเร็จ: '+error:'บันทึกคำตอบแล้ว ผู้ใช้ที่ออฟไลน์จะเห็นเมื่อกลับเข้าระบบ';if(!error&&$('reply-text').value===request.text)$('reply-text').value='';}}
  $('reply-form').onsubmit=function(e){e.preventDefault();if(!current||!eligible(current))return;var text=$('reply-text').value.trim();if(!text)return;if($('reply-question').checked&&$('reply-close').checked){$('job-result').textContent='ถามกลับผู้ใช้ต้องรอคำตอบ กรุณายกเลิกตัวเลือกปิดงาน';return;}if(reply(current,text,$('reply-close').checked,false)){$('reply-send').disabled=true;$('job-result').textContent='กำลังส่ง…';}};
  $('reply-chat').onclick=()=>{if(current)EISChat.room(current);};
  $('close-form').onsubmit=function(e){e.preventDefault();if(current)transmit({type:'chat-job',room:current.id,version:current.version,action:'close',summary:$('close-summary').value});};
  document.querySelectorAll('[data-action]').forEach(button=>button.onclick=()=>{if(current&&(button.dataset.action!=='mark_read'||confirm('ล้างสถานะข้อความถึงผู้ใช้เป็นอ่านแล้ว? ระบบจะบันทึกว่า Admin ล้างสถานะ และไม่ปิดงาน')))transmit({type:'chat-job',room:current.id,version:current.version,action:button.dataset.action});});
  $('job-older').onclick=()=>{if(current&&detailMessages.length)transmit({type:'chat-job-detail',room:current.id,before:detailMessages[0].seq});};
  $('bulk-review').onclick=function(){var selected=jobs.filter(j=>checked.has(j.id));if(!selected.length||selected.length>10||selected.some(j=>!eligible(j))||!$('bulk-text').value.trim()){ $('bulk-result').textContent='เลือกงานที่ตอบได้ 1–10 งาน และพิมพ์คำตอบก่อน';return;}$('bulk-recipients').textContent='ส่งถึง: '+selected.map(j=>requester(j).name+' / '+j.title).join(', ');$('bulk-confirm').hidden=false;};
  $('bulk-cancel').onclick=()=>{$('bulk-confirm').hidden=true;};
  $('bulk-form').onsubmit=function(e){e.preventDefault();if(batch||!connected)return;var selected=jobs.filter(j=>checked.has(j.id)),text=$('bulk-text').value.trim();if(!selected.length||selected.length>10||selected.some(j=>!eligible(j))||!text)return;batch={total:selected.length,done:0,success:0,errors:[]};$('bulk-review').disabled=true;$('bulk-confirm').hidden=true;$('bulk-result').textContent='กำลังส่ง '+selected.length+' งาน…';selected.forEach(job=>reply(job,text,false,true));};
  ['job-search','job-filter','job-system','job-mine'].forEach(id=>$(id).addEventListener('input',renderList));document.querySelectorAll('[data-filter]').forEach(button=>button.onclick=()=>{$('job-filter').value=button.dataset.filter;renderList();});document.addEventListener('visibilitychange',readCurrent);
  function connectionLog(){
    if(!EISPresence.diagnostics)return 'Client รุ่นเก่ายังไม่มี log กรุณา reload หน้า';
    return JSON.stringify(EISPresence.diagnostics(),null,2);
  }
  function updateConnectionLog(){if(!$('job-connection-log').hidden)$('job-log-output').textContent=connectionLog();}
  $('job-log-toggle').onclick=function(){var panel=$('job-connection-log');panel.hidden=!panel.hidden;this.setAttribute('aria-expanded',String(!panel.hidden));this.textContent=panel.hidden?'แสดง log การเชื่อมต่อ':'ซ่อน log การเชื่อมต่อ';updateConnectionLog();};
  $('job-reconnect').onclick=function(){if(!EISPresence.reconnect||!EISPresence.reconnect())$('job-connection').textContent='เซสชันสิ้นสุด กรุณาเข้าสู่ระบบใหม่';updateConnectionLog();};
  $('job-log-download').onclick=function(){var url=URL.createObjectURL(new Blob([connectionLog()],{type:'application/json;charset=utf-8'})),a=document.createElement('a');a.href=url;a.download='messenger-connection-'+new Date().toISOString().replace(/[:.]/g,'-')+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
  EISPresence.on(function(type,data){
    if(type==='diagnostic'){updateConnectionLog();return;}
    updateConnectionLog();
    if(['connecting','connected','disconnected','unauthorized'].includes(type))$('job-reconnect').disabled=type==='connecting'||type==='unauthorized';
    if(type==='connected'){connected=true;identity=data.identity;$('job-connection').textContent='● เชื่อมต่อแล้ว · ข้อความเก็บไว้แม้ Admin ออฟไลน์';transmit({type:'chat-inbox'});if(current){detailMessages=[];transmit({type:'chat-job-detail',room:current.id});renderDetail();}}
    else if(['disconnected','connecting','unauthorized'].includes(type)){connected=false;$('job-connection').textContent=type==='unauthorized'?'เซสชันสิ้นสุด กรุณา login ใหม่':type==='connecting'?'กำลังเชื่อมต่อ…':('การเชื่อมต่อขาด · '+(EISPresence.diagnostics?.().issue?.message||'กำลังลองใหม่')+' · กดแสดง log เพื่อตรวจสอบ');if(type==='unauthorized'){jobs=[];current=null;detailMessages=[];$('job-detail').hidden=true;$('job-placeholder').hidden=false;renderList();}if(current)renderDetail();}
    else if(type==='chat-inbox'){jobs=data.jobs;renderList();if(current){var updated=jobs.find(j=>j.id===current.id);if(updated){current=updated;renderDetail();}}}
    else if(type==='chat-job-detail'&&current?.id===data.room.id){current=data.room;data.messages.forEach(remember);detailMessages.sort((a,b)=>a.seq-b.seq);more=data.more;renderDetail();readCurrent();}
    else if(type==='chat-message'&&current?.id===data.room.id){current=data.room;remember(data.message);renderDetail();readCurrent();}
    else if(type==='chat-retracted'&&current?.id===data.room.id){current=data.room;remember(data.message);renderDetail();$('job-result').textContent='ยกเลิกข้อความแล้ว';}
    else if(type==='chat-job-updated'&&current?.id===data.room.id){current=data.room;renderDetail();$('job-result').textContent=current.status==='closed'?'ปิดงานเรียบร้อยแล้ว':'อัปเดตงานแล้ว';}
    else if(type==='chat-sent')result(data.clientId);
    else if(type==='chat-error'){if(data.clientId)result(data.clientId,data.error);else $('job-result').textContent=data.error;}
  });
}());
