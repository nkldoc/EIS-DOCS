(function () {
  'use strict';
  if (!window.EISPresence || window.EISChat) return;
  var identity, online=false, rooms={}, messages={}, current='inbox', target=null, image=null, seen={}, unread=new Set(), users=[], sending=false, draftSignature='', draftId='', pending=null;
  var chatMode='general', selectedModes={}, supportsConversations=false, unreadRooms=new Map(), badgeFrame=null;
  var controlsFrame=null, retracting=new WeakSet();
  var frame=null, renderedRoom=null, rendered=new Map(), forceBottom=false, details={}, detailRequests={}, detailBefore={};
  var launcher=document.createElement('button'); launcher.className='eis-chat-launch';launcher.textContent='💬 แจ้งแอดมิน';launcher.type='button';
  var dialog=document.createElement('dialog');dialog.className='eis-chat';dialog.setAttribute('aria-label','สนทนากับผู้ดูแลระบบ');
  dialog.innerHTML='<header><strong>ข้อความส่วนตัว</strong><button type="button" data-close aria-label="ซ่อนหน้าต่างสนทนา">×</button></header><div class="eis-chat-controls"><button type="button" data-support>+ เปิด Ticket แจ้งปัญหา</button><label>บทสนทนา<select data-rooms></select></label><div data-admin hidden><label data-recipient-label>ผู้ใช้ออนไลน์<select data-users><option value="">เลือกผู้ใช้เพื่อคุยส่วนตัว</option></select></label><button type="button" data-all>ส่งถึงห้องรวม</button> <button type="button" data-request>ขอภาพหน้าจอ</button><small data-page></small><a href="/eis-docs/docMain/message-inbox.php" target="_blank" rel="noopener" data-inbox>กล่องงาน / ตอบย้อนหลัง / ปิดงาน →</a></div></div><div class="eis-chat-log" role="log" aria-live="polite"></div><form><label>ข้อความ<textarea rows="2" maxlength="2000" placeholder="พิมพ์ข้อความ…"></textarea></label><div class="eis-chat-preview" hidden><img alt="รูปที่กำลังจะแนบ"><button type="button" data-remove>นำรูปออก</button></div><div class="eis-chat-actions"><button type="button" data-attach>แนบรูป</button><button type="button" data-screen>แชร์ภาพหน้าจอ</button><button class="eis-chat-send" type="submit">ส่งข้อความ</button></div><input type="file" accept="image/png,image/jpeg,image/webp" hidden></form><div class="eis-chat-status" role="status">กำลังเชื่อมต่อ…</div>';
  (document.body||document.documentElement).append(launcher,dialog);
  var dock=document.createElement('div');dock.className='eis-chat-dock';
  var moveHandle=document.createElement('button');moveHandle.type='button';moveHandle.className='eis-chat-move';moveHandle.textContent='⠿';moveHandle.title='ลากเพื่อย้ายปุ่มแจ้งแอดมิน หรือใช้ปุ่มลูกศร';moveHandle.setAttribute('aria-label',moveHandle.title);
  var hideLauncher=document.createElement('button');hideLauncher.type='button';hideLauncher.className='eis-chat-hide';hideLauncher.textContent='×';hideLauncher.title='ซ่อนปุ่มแจ้งแอดมิน';hideLauncher.setAttribute('aria-label',hideLauncher.title);
  var restoreLauncher=document.createElement('button');restoreLauncher.type='button';restoreLauncher.className='eis-chat-restore';restoreLauncher.textContent='💬';restoreLauncher.title='แสดงปุ่มแจ้งแอดมิน';restoreLauncher.setAttribute('aria-label',restoreLauncher.title);restoreLauncher.hidden=true;
  launcher.before(dock);dock.append(moveHandle,launcher,hideLauncher,restoreLauncher);
  var dockPosition=null,drag=null;
  function positionDock(x,y){
    var rect=dock.getBoundingClientRect();
    dockPosition={x:Math.max(0,Math.min(x,window.innerWidth-rect.width)),y:Math.max(0,Math.min(y,window.innerHeight-rect.height))};
    dock.style.left=dockPosition.x+'px';dock.style.top=dockPosition.y+'px';dock.style.right='auto';dock.style.bottom='auto';
  }
  function saveDock(){try{localStorage.setItem('eis-chat-dock',JSON.stringify({position:dockPosition,collapsed:!restoreLauncher.hidden}));}catch(e){}}
  function collapseDock(collapsed){moveHandle.hidden=launcher.hidden=hideLauncher.hidden=collapsed;restoreLauncher.hidden=!collapsed;if(dockPosition)positionDock(dockPosition.x,dockPosition.y);}
  hideLauncher.onclick=function(){dialog.close();collapseDock(true);saveDock();restoreLauncher.focus();};
  restoreLauncher.onclick=function(){collapseDock(false);saveDock();launcher.focus();};
  moveHandle.addEventListener('pointerdown',function(e){if(e.button!==0)return;var rect=dock.getBoundingClientRect();drag={id:e.pointerId,x:e.clientX,y:e.clientY,left:rect.left,top:rect.top};moveHandle.setPointerCapture(e.pointerId);e.preventDefault();});
  moveHandle.addEventListener('pointermove',function(e){if(drag&&drag.id===e.pointerId)positionDock(drag.left+e.clientX-drag.x,drag.top+e.clientY-drag.y);});
  function finishDrag(){if(drag){drag=null;saveDock();}}
  moveHandle.addEventListener('pointerup',finishDrag);moveHandle.addEventListener('pointercancel',finishDrag);moveHandle.addEventListener('lostpointercapture',finishDrag);
  moveHandle.addEventListener('keydown',function(e){var delta={ArrowLeft:[-10,0],ArrowRight:[10,0],ArrowUp:[0,-10],ArrowDown:[0,10]}[e.key];if(!delta)return;e.preventDefault();var rect=dock.getBoundingClientRect();positionDock(rect.left+delta[0],rect.top+delta[1]);saveDock();});
  window.addEventListener('resize',function(){if(dockPosition)positionDock(dockPosition.x,dockPosition.y);});
  try{var savedDock=JSON.parse(localStorage.getItem('eis-chat-dock'));if(savedDock){collapseDock(!!savedDock.collapsed);if(Number.isFinite(savedDock.position?.x)&&Number.isFinite(savedDock.position?.y))positionDock(savedDock.position.x,savedDock.position.y);}}catch(e){}
  var $=function(q){return dialog.querySelector(q);},log=$('.eis-chat-log'),status=$('.eis-chat-status');
  var modes=document.createElement('div');modes.className='eis-chat-modes';modes.setAttribute('role','group');modes.setAttribute('aria-label','ประเภทบทสนทนา');
  var generalTab=document.createElement('button'),ticketTab=document.createElement('button');generalTab.type=ticketTab.type='button';generalTab.textContent='ข้อความส่วนตัว';ticketTab.textContent='Ticket แจ้งปัญหา';modes.append(generalTab,ticketTab);dialog.insertBefore(modes,$('.eis-chat-controls'));
  var teamStart=document.createElement('button');teamStart.type='button';teamStart.className='eis-chat-team-start';teamStart.textContent='+ คุยกับทีมผู้ดูแล';dialog.insertBefore(teamStart,log);

  var backToInbox=document.createElement('button');backToInbox.type='button';backToInbox.className='eis-chat-back';backToInbox.textContent='← รายการข้อความ';backToInbox.onclick=function(){open('inbox');};log.before(backToInbox);
  var conversationList=document.createElement('div');conversationList.className='eis-chat-conversations';log.before(conversationList);
  function renderConversations(){
    conversationList.replaceChildren();
    var list=Object.values(rooms).filter(r=>r.id!=='all'&&!isTicket(r));
    function unreadCount(room){var count=0;unread.forEach(id=>{if(unreadRooms.get(id)===room.id)count++;});return count;}
    list.sort((a,b)=>(Number(unreadCount(b)>0)-Number(unreadCount(a)>0))||String(b.updatedAt||'').localeCompare(String(a.updatedAt||'')));
    if(!list.length){var empty=document.createElement('p');empty.textContent=identity?.admin?'ยังไม่มีข้อความจากผู้ใช้':'กดคุยกับทีมผู้ดูแลเพื่อเริ่มสนทนา';conversationList.append(empty);}
    list.forEach(function(room){
      var button=document.createElement('button');button.type='button';button.className='eis-chat-conversation';
      var person=room.team?room.people[0]:room.people.find(p=>!identity||key(p)!==key(identity));
      var displayName=identity?.admin?(person?.name||'ผู้ใช้'):'ทีมผู้ดูแล';
      var avatar=document.createElement('span');avatar.className='eis-chat-avatar';avatar.textContent=Array.from(displayName)[0];avatar.setAttribute('aria-hidden','true');
      var name=document.createElement('strong');name.textContent=displayName;var count=unreadCount(room);
      var summary=document.createElement('small'),last=(messages[room.id]||[]).at(-1);summary.textContent=last?(last.retractedAt?'ยกเลิกข้อความแล้ว':last.text||'รูปภาพ'):'คลิกเพื่อดูบทสนทนา';
      button.classList.toggle('has-unread',count>0);button.append(avatar,name,summary);
      var notice=document.createElement('small');notice.className='eis-chat-notice';notice.textContent=count?'มีข้อความใหม่ · '+count+' ข้อความ':(person?.system||'')+' · คลิกเพื่อสนทนา';button.append(notice);
      if(count){var badge=document.createElement('span');badge.className='eis-chat-count';badge.textContent=count;badge.setAttribute('aria-label',count+' ข้อความที่ยังไม่อ่าน');button.append(badge);}
      button.onclick=function(){open(room.id);};conversationList.append(button);
    });
  }
  function isTicket(room){return !!(room&&(room.ticket||room.job));}
  function hasHistory(room){return !!(room&&(isTicket(room)||room.conversation));}
  function syncMode(){
    var ticket=chatMode==='ticket',admin=!!identity?.admin;ticketTab.hidden=!!identity?.chatAdmin&&!identity?.docsAdmin;
    generalTab.setAttribute('aria-pressed',String(!ticket));ticketTab.setAttribute('aria-pressed',String(ticket));
    generalTab.classList.toggle('active',!ticket);ticketTab.classList.toggle('active',ticket);
    var active=rooms[current],person=active?.team?active.people[0]:active?.people?.find(p=>!identity||key(p)!==key(identity));
    $('header strong').textContent=ticket?'Ticket แจ้งปัญหา':current==='inbox'?'ข้อความจากผู้ใช้':admin?(person?.name||target?.name||'ข้อความส่วนตัว'):'คุยกับทีมผู้ดูแล';
    $('[data-support]').hidden=admin||!ticket;teamStart.hidden=admin||ticket;
    $('[data-recipient-label]').hidden=ticket;$('[data-all]').hidden=true;$('[data-page]').hidden=ticket;$('[data-inbox]').hidden=!ticket;
    $('form').hidden=current==='inbox';log.hidden=current==='inbox';conversationList.hidden=ticket||current!=='inbox';backToInbox.hidden=ticket||current==='inbox';$('textarea').disabled=current==='ticket-empty';$('.eis-chat-send').disabled=sending||current==='ticket-empty';
  }
  function switchMode(mode){
    var saved=selectedModes[mode],first=Object.values(rooms).find(r=>r.id!=='all'&&isTicket(r)===(mode==='ticket'));
    var room=saved&&rooms[saved]?saved:mode==='general'?'inbox':first?first.id:identity?.admin?'ticket-empty':'support-new';
    chatMode=mode;open(room);
    status.textContent=mode==='ticket'?(room==='ticket-empty'?'ยังไม่มี Ticket แจ้งปัญหา':'Ticket แจ้งปัญหา · ติดตามสถานะและการแก้ไข'):'ข้อความส่วนตัว · ข้อความไม่สร้าง Ticket';
  }
  generalTab.onclick=function(){switchMode('general');};ticketTab.onclick=function(){switchMode('ticket');};
  teamStart.onclick=function(){if(!identity){status.textContent='กำลังเชื่อมต่อ…';return;}var existing=Object.values(rooms).find(r=>r.conversation&&r.team&&r.members.includes(key(identity)));open(existing?existing.id:'team-new');};
  var older=document.createElement('button');older.type='button';older.className='eis-chat-older';older.textContent='โหลดข้อความก่อนหน้า';older.hidden=true;log.before(older);
  function requestDetail(room,before){
    if(!online||detailRequests[room])return;
    var request={type:'chat-job-detail',room:room};if(before)request.before=before;
    if(window.EISPresence.send(request)){
      detailBefore[room]=before||null;
      detailRequests[room]=setTimeout(function(){delete detailRequests[room];if(current===room){older.disabled=false;status.textContent='โหลดประวัติไม่สำเร็จ กรุณาลองอีกครั้ง';}},15000);
      if(current===room)older.disabled=true;
    }
  }
  older.onclick=function(){var first=(messages[current]||[])[0];if(first)requestDetail(current,first.seq);};
  function key(p){return JSON.stringify([p.system,String(p.uid)]);}
  function title(room){
    if(room.id==='all')return room.title;
    if(isTicket(room))return 'Ticket · '+room.title+' · '+({open:'รอตอบ',in_progress:'กำลังดำเนินการ',waiting:'รอผู้ใช้ตอบกลับ',closed:'ปิดงาน'}[room.status]||'รอตอบ');
    if(room.team)return identity?.admin?'ทีมผู้ดูแล · '+room.people[0].name+' ('+room.people[0].system+')':'คุยกับทีมผู้ดูแล';
    var other=room.people.find(p=>!identity||key(p)!==key(identity));return 'ส่วนตัว · '+(other?other.name+' ('+other.system+')':'ผู้ดูแลระบบ');
  }
  function options(){if(controlsFrame!==null)return;controlsFrame=requestAnimationFrame(function(){controlsFrame=null;updateOptions();});}
  function updateOptions(){
    var select=$('[data-rooms]');select.replaceChildren();
    if(chatMode==='general')select.add(new Option('Messenger','inbox'));
    Object.values(rooms).filter(r=>r.id!=='all'&&isTicket(r)===(chatMode==='ticket')).forEach(r=>select.add(new Option(title(r),r.id)));
    if(target)select.add(new Option('ส่วนตัว · '+target.name+' ('+target.system+')','new'));
    if(current==='support-new')select.add(new Option('Ticket ใหม่ · แจ้งปัญหาถึงทีมผู้ดูแล','support-new'));
    if(current==='team-new')select.add(new Option('สนทนาใหม่กับทีมผู้ดูแล','team-new'));
    if(current==='ticket-empty')select.add(new Option('ยังไม่มี Ticket แจ้งปัญหา','ticket-empty'));
    select.value=target?'new':current;syncMode();renderConversations();
  }
  function badge(){
    if(badgeFrame!==null)return;
    badgeFrame=requestAnimationFrame(function(){
      badgeFrame=null;var ticket=0,general=0;renderConversations();
      unreadRooms.forEach(function(room,id){if(!unread.has(id))unreadRooms.delete(id);});
      unread.forEach(function(id){isTicket(rooms[unreadRooms.get(id)])?ticket++:general++;});
      launcher.textContent='💬 แจ้งแอดมิน'+(unread.size?' ('+unread.size+')':'');
      ticketTab.textContent='Ticket แจ้งปัญหา'+(ticket?' ('+ticket+')':'');generalTab.textContent='ข้อความส่วนตัว'+(general?' ('+general+')':'');
    });
  }
  function page(){var person=target||(rooms[current]&&rooms[current].people&&rooms[current].people.find(p=>identity&&key(p)!==key(identity)));$('[data-page]').textContent=person?'หน้าที่เปิด: '+(users.find(u=>key(u)===key(person))?.pages.join(', ')||'ไม่ได้ออนไลน์ขณะนี้'):'';}
  function mark(){if(!dialog.open||document.visibilityState==='hidden'||!online||target||current==='support-new')return;var list=messages[current]||[],last=list.at(-1);if(!last||seen[current]>=last.seq)return;if(window.EISPresence.send({type:'chat-read',room:current,seq:last.seq})){seen[current]=last.seq;list.forEach(m=>unread.delete(m.id));badge();}}
  function remember(m){messages[m.room] ||= [];var i=messages[m.room].findIndex(v=>v.id===m.id);if(i<0)messages[m.room].push(m);else {var old=messages[m.room][i];if(old.text!==m.text||old.image!==m.image||old.retractedAt!==m.retractedAt||old.kind!==m.kind||old.sender.name!==m.sender.name)messages[m.room][i]=m;}if(m.retractedAt)unread.delete(m.id);}
  function cancelButton(m,node){if(!identity||m.sender.key!==key(identity)||m.retractedAt)return;var button=document.createElement('button');button.type='button';button.className='eis-chat-retract';button.textContent='ยกเลิกข้อความ';button.disabled=!online;button.onclick=function(){if(!confirm('ยกเลิกข้อความนี้สำหรับทุกคนในบทสนทนา?'))return;if(window.EISPresence.send({type:'chat-retract',room:m.room,messageId:m.id})){retracting.add(button);button.disabled=true;status.textContent='กำลังยกเลิกข้อความ…';setTimeout(()=>{retracting.delete(button);if(!messages[m.room]?.find(v=>v.id===m.id)?.retractedAt){button.disabled=!online;status.textContent='ยังไม่ได้รับยืนยัน กรุณาตรวจประวัติก่อนลองใหม่';}},10000);}};node.append(button);}
  function messageNode(m){var node=document.createElement('div');node.className='eis-chat-message'+(identity&&m.sender.key===key(identity)?' own':'');var small=document.createElement('small');small.textContent=(m.sender.admin?'Admin · ':'')+m.sender.name+' · '+m.sender.system+' · '+new Date(m.at).toLocaleTimeString('th-TH');var p=document.createElement('p');p.textContent=m.text;node.append(small,p);if(m.image){var img=document.createElement('img');img.loading='lazy';img.decoding='async';img.src=m.image;img.alt='ภาพที่แนบในบทสนทนา';var link=document.createElement('a');link.href=m.image;link.download='chat-image-'+m.id+'.'+(m.image.startsWith('data:image/png')?'png':m.image.startsWith('data:image/webp')?'webp':'jpg');link.textContent='ดาวน์โหลดภาพ';var imageButton=document.createElement('button');imageButton.type='button';imageButton.className='eis-image-open';imageButton.setAttribute('aria-label','เปิดภาพขนาดใหญ่');imageButton.onclick=()=>window.EISImageViewer.open(m);var imageHint=document.createElement('span');imageHint.textContent='ดูภาพเต็มจอ / ขยายภาพ';imageButton.append(img,imageHint);node.append(imageButton,link);}if(m.kind==='screen_request'){var hint=document.createElement('p');hint.textContent='เลือก “แชร์ภาพหน้าจอ” หรือ “แนบรูป” ตรวจภาพก่อนกดส่ง';node.append(hint);}cancelButton(m,node);if(m.retractedAt)node.classList.add('retracted');return node;}
  function render(){
    if(!dialog.open||frame!==null)return;
    frame=requestAnimationFrame(function(){
      frame=null;if(!dialog.open)return;
      var changed=renderedRoom!==current,scroll=log.scrollTop,height=log.scrollHeight;
      var bottom=forceBottom||changed||height-scroll-log.clientHeight<64;forceBottom=false;
      if(changed){log.replaceChildren();rendered.clear();renderedRoom=current;}
      var list=messages[current]||[],keep=new Set(list.map(m=>m.id)),anchor=null;
      log.setAttribute('data-empty',chatMode==='ticket'?(current==='ticket-empty'?'ยังไม่มี Ticket แจ้งปัญหา':'พิมพ์รายละเอียดปัญหาและแนบรูปเพื่อแจ้ง Ticket'):'เริ่มข้อความส่วนตัว · ข้อความในแท็บนี้ไม่สร้าง Ticket');
      rendered.forEach(function(entry,id){if(!keep.has(id)){entry.node.remove();rendered.delete(id);}});
      list.forEach(function(m){
        var entry=rendered.get(m.id);
        if(!entry||entry.message!==m){
          var node=messageNode(m);if(entry)entry.node.replaceWith(node);
          entry={message:m,node:node};rendered.set(m.id,entry);
        }
        var next=anchor?anchor.nextSibling:log.firstChild;
        if(next!==entry.node)log.insertBefore(entry.node,next);
        anchor=entry.node;
      });
      var first=list[0]?.id,prepended=!changed&&render.previousFirst&&first!==render.previousFirst&&keep.has(render.previousFirst);
      render.previousFirst=first;
      if(bottom&&!prepended)log.scrollTop=log.scrollHeight;
      else if(prepended)log.scrollTop=scroll+log.scrollHeight-height;
      else log.scrollTop=scroll;
      older.hidden=!details[current]?.more;older.disabled=!!detailRequests[current];
      log.querySelectorAll('.eis-chat-retract').forEach(button=>button.disabled=!online||retracting.has(button));
      mark();
    });
  }

  function open(room,person){if(room==='all')room='inbox';if(current!==room||!!target!==!!person){$('textarea').value='';image=null;$('.eis-chat-preview').hidden=true;}forceBottom=true;current=room;target=person||null;chatMode=isTicket(rooms[room])||room==='support-new'||room==='ticket-empty'?'ticket':'general';selectedModes[chatMode]=room;options();page();if(hasHistory(rooms[room])&&!details[room])requestDetail(room);if(!dialog.open)dialog.show();render();$('textarea').focus();}
  $('[data-support]').onclick=function(){open('support-new');status.textContent='ส่งเรื่องถึงทีมผู้ดูแล · Admin สามารถตอบย้อนหลังได้';};launcher.onclick=function(){open(current,target);};$('[data-close]').onclick=function(){dialog.close();};dialog.addEventListener('cancel',()=>{});
  $('[data-rooms]').onchange=function(){open(this.value,this.value==='new'?target:null);};$('[data-all]').onclick=function(){open('all');};
  $('[data-users]').onchange=function(){var person=users.find(u=>key(u)===this.value);if(person)window.EISChat.private(person);};
  function post(text,kind){if(sending)return;if(!online){status.textContent='การเชื่อมต่อขาด กรุณารอเชื่อมต่อใหม่';return;}if(current==='inbox')return;if(current==='ticket-empty'){status.textContent='เลือก Ticket ก่อนส่งข้อความ';return;}if((target||current==='team-new')&&!supportsConversations){status.textContent='ระบบข้อความส่วนตัวยังไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง';return;}if(!text.trim()&&!image)return;var signature=JSON.stringify([current,target&&key(target),text,kind,image]);if(signature!==draftSignature){draftSignature=signature;draftId=crypto.randomUUID();}var payload={type:'chat-send',clientId:draftId,text,kind,image};if(current==='support-new')payload.support=true;else if(current==='team-new'){payload.generalTeam=true;payload.conversation=true;}else if(target){payload.conversation=true;payload.generalTeam=true;payload.target={system:target.system,uid:target.uid,name:target.name};}else payload.room=current;if(window.EISPresence.send(payload)){pending=payload;sending=true;$('.eis-chat-send').disabled=true;status.textContent='กำลังส่ง…';setTimeout(function(){if(pending===payload&&sending){sending=false;$('.eis-chat-send').disabled=false;status.textContent='ยังไม่ได้รับยืนยันการส่ง กดส่งอีกครั้งเพื่อตรวจสอบ';}},10000);}}
  $('form').onsubmit=function(e){e.preventDefault();post($('textarea').value,'message');};
  $('[data-request]').onclick=function(){if(!target&&current==='all'){status.textContent='กรุณาเลือกผู้ใช้หรือบทสนทนาส่วนตัวก่อนขอภาพหน้าจอ';return;}post('รบกวนแนบภาพหน้าจอที่กำลังใช้งาน เพื่อให้ผู้ดูแลช่วยตรวจสอบครับ','screen_request');};
  function preview(data){image=data;$('.eis-chat-preview img').src=data;$('.eis-chat-preview').hidden=false;status.textContent='ตรวจภาพและข้อมูลที่แสดงก่อนกดส่ง';}
  function encode(source,width,height){var canvas=document.createElement('canvas'),scale=Math.min(1,1400/Math.max(width,height));canvas.width=Math.max(1,Math.round(width*scale));canvas.height=Math.max(1,Math.round(height*scale));canvas.getContext('2d').drawImage(source,0,0,canvas.width,canvas.height);var data;for(var quality=.8;quality>=.2;quality-=.15){data=canvas.toDataURL('image/jpeg',quality);if(data.length<340000)return data;}throw new Error('รูปมีรายละเอียดมากเกินไป กรุณาลดขนาดรูป');}
  $('[data-attach]').onclick=function(){$('input[type=file]').click();};
  $('input[type=file]').onchange=async function(){var file=this.files[0];this.value='';if(!file)return;try{if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>10*1024*1024)throw new Error('เลือก PNG, JPEG หรือ WebP ไม่เกิน 10 MB');var source=await createImageBitmap(file);try{preview(encode(source,source.width,source.height));}finally{source.close();}}catch(e){status.textContent=e.message;}};
  $('[data-screen]').onclick=async function(){var stream;this.disabled=true;try{if(!navigator.mediaDevices?.getDisplayMedia)throw new Error('เบราว์เซอร์นี้ไม่รองรับแชร์หน้าจอ กรุณาแนบรูปแทน');stream=await navigator.mediaDevices.getDisplayMedia({video:true,audio:false});var video=document.createElement('video');video.srcObject=stream;video.muted=true;await video.play();preview(encode(video,video.videoWidth,video.videoHeight));video.srcObject=null;}catch(e){status.textContent=e.name==='NotAllowedError'?'ยกเลิกการแชร์หน้าจอแล้ว':e.message;}finally{if(stream)stream.getTracks().forEach(track=>track.stop());this.disabled=false;}};
  $('[data-remove]').onclick=function(){image=null;$('.eis-chat-preview').hidden=true;};
  document.addEventListener('visibilitychange',mark);
  window.EISChat={private:function(person){var existing=identity&&Object.values(rooms).find(r=>!isTicket(r)&&r.id!=='all'&&(r.team||r.members?.includes(key(identity)))&&r.members.includes(key(person)));if(existing)open(existing.id);else open('new',person);},room:function(room){rooms[room.id]=room;open(room.id);},all:function(){open('all');},users:function(){return users;}};
  window.EISPresence.on(function(type,data){
    if(type==='connected'){supportsConversations=false;Object.values(detailRequests).forEach(clearTimeout);detailRequests={};details={};detailBefore={};unread.clear();badge();online=true;identity=data.identity;identity.docsAdmin=identity.admin;identity.admin=!!(identity.admin||identity.chatAdmin);$('[data-admin]').hidden=!identity.admin;$('[data-support]').hidden=identity.admin;status.textContent='เชื่อมต่อแล้ว';window.EISPresence.send({type:'page',path:location.pathname});if(data.replay)window.EISPresence.send({type:'chat-sync'});if(dialog.open&&hasHistory(rooms[current]))requestDetail(current);options();render();}
    else if(type==='disconnected'||type==='connecting'||type==='unauthorized'){online=false;sending=false;$('.eis-chat-send').disabled=false;status.textContent=type==='unauthorized'?'เซสชันสิ้นสุด':'กำลังเชื่อมต่อใหม่…';render();if(type==='unauthorized'){Object.values(detailRequests).forEach(clearTimeout);detailRequests={};details={};seen={};rendered.clear();renderedRoom=null;rooms={};messages={};unread.clear();image=null;$('.eis-chat-preview img').removeAttribute('src');$('.eis-chat-preview').hidden=true;log.replaceChildren();dialog.close();launcher.hidden=true;}}
    else if(type==='chat-ready'){supportsConversations=!!data.capabilities?.conversations;rooms={};data.rooms.forEach(r=>rooms[r.id]=r);Object.keys(messages).forEach(function(id){if(!rooms[id]){messages[id].forEach(m=>unread.delete(m.id));delete messages[id];delete details[id];}});if(current!=='all'&&current!=='new'&&current!=='support-new'&&current!=='team-new'&&current!=='ticket-empty'&&!rooms[current]){current=chatMode==='ticket'?(identity.admin?'ticket-empty':'support-new'):'inbox';target=null;}options();badge();render();}
    else if(type==='chat-inbox'){if(identity.admin)$('[data-inbox]').textContent='กล่องงาน / ปิดงาน · รอตอบ '+data.jobs.filter(j=>j.status==='open').length+' งาน →';}
    else if(type==='chat-roster'){users=data.users;var select=$('[data-users]'),old=select.value;select.replaceChildren(new Option('เลือกผู้ใช้เพื่อคุยส่วนตัว',''));users.filter(u=>!u.admin&&(!identity||key(u)!==key(identity))).forEach(u=>select.add(new Option(u.name+' · '+u.system,key(u))));select.value=old;page();}
    else if(type==='chat-message'){var m=data.message;rooms[data.room.id]=data.room;messages[m.room] ||= [];remember(m);if(!details[m.room])messages[m.room]=messages[m.room].slice(-100);if(data.unread&&!m.retractedAt){unread.add(m.id);unreadRooms.set(m.id,m.room);}else unread.delete(m.id);if(!data.replay&&(target||current==='support-new'||current==='team-new')&&m.sender.key===key(identity)){target=null;current=m.room;chatMode=isTicket(data.room)?'ticket':'general';selectedModes[chatMode]=m.room;}options();if(current===m.room)render();badge();}
    else if(type==='chat-retracted'){rooms[data.room.id]=data.room;remember(data.message);options();badge();if(current===data.room.id){render();status.textContent='ยกเลิกข้อความแล้ว';}}
    else if(type==='chat-job-updated'){rooms[data.room.id]=data.room;options();if(current===data.room.id)status.textContent=title(data.room)+(data.room.resolution?' · '+data.room.resolution:'');}
    else if(type==='chat-job-detail'){rooms[data.room.id]=data.room;if(!detailBefore[data.room.id])messages[data.room.id]=[];messages[data.room.id] ||= [];data.messages.forEach(remember);messages[data.room.id].sort((a,b)=>a.seq-b.seq);details[data.room.id]={more:data.more};clearTimeout(detailRequests[data.room.id]);delete detailRequests[data.room.id];if(current===data.room.id)render();}
    else if(type==='chat-sent'){if(pending&&pending.clientId===data.clientId){if($('textarea').value===pending.text)$('textarea').value='';if(image===pending.image){image=null;$('.eis-chat-preview').hidden=true;}pending=null;draftSignature='';sending=false;$('.eis-chat-send').disabled=false;options();}status.textContent=rooms[data.room]?.ticket&&!identity.admin?'บันทึกเรื่องแล้ว · ทีมผู้ดูแลสามารถตอบย้อนหลังได้':'บันทึกแล้ว · ส่งถึงผู้ใช้ออนไลน์ '+data.delivered+' คน';}
    else if(type==='chat-error'){sending=false;$('.eis-chat-send').disabled=false;status.textContent=data.error+' · กรุณาลองส่งใหม่';}
    else if(type==='chat-read-cleared'&&identity){if(data.reader.key===key(identity)){(messages[data.room]||[]).filter(m=>m.seq<=data.seq).forEach(m=>unread.delete(m.id));badge();if(data.room===current)mark();}else if(data.room===current)status.textContent='Admin '+data.markedBy.name+' ล้างสถานะอ่านแล้ว';}
    else if(type==='chat-read'&&identity){if(data.reader.key===key(identity)){(messages[data.room]||[]).filter(m=>m.seq<=data.seq).forEach(m=>unread.delete(m.id));badge();}else if(data.room===current)status.textContent='อ่านแล้ว · '+data.reader.name;}
  });
}());
