(function(){
  'use strict';
  if(window.EISImageViewer)return;
  var active=null,scale=1,mode='fit',drag=null,previousFocus=null,ownsFullscreen=false;
  var dialog=document.createElement('dialog');dialog.className='eis-image-viewer';dialog.setAttribute('aria-label','ดูภาพในบทสนทนา');
  dialog.innerHTML='<div class="eis-image-toolbar"><strong>ภาพในบทสนทนา</strong><button type="button" data-minus aria-label="ย่อภาพ">−</button><output aria-live="polite" data-scale>100%</output><button type="button" data-plus aria-label="ขยายภาพ">+</button><button type="button" data-fit>พอดีหน้าจอ</button><button type="button" data-original>100%</button><button type="button" data-full>เต็มจอ</button><a data-download>ดาวน์โหลด</a><button type="button" data-close aria-label="ปิดภาพ">ปิด ✕</button></div><div class="eis-image-stage" tabindex="0" aria-label="ภาพขยาย ใช้ปุ่มบวกหรือลบเพื่อปรับขนาด ลากเพื่อเลื่อนภาพ"><div class="eis-image-canvas"><img alt="ภาพที่แนบในบทสนทนา" draggable="false"></div></div><div class="eis-image-help" role="status">กด + หรือ − เพื่อปรับขนาด · ลากเพื่อเลื่อนภาพ · Esc เพื่อปิด</div>';
  if(document.body)document.body.append(dialog);
  else document.addEventListener('DOMContentLoaded',()=>document.body.append(dialog),{once:true});
  var $=q=>dialog.querySelector(q),stage=$('.eis-image-stage'),canvas=$('.eis-image-canvas'),img=$('img'),help=$('.eis-image-help');
  function layout(next,x,y){
    if(!img.naturalWidth||!stage.clientWidth)return;
    var old=scale,oldWidth=img.naturalWidth*old,oldHeight=img.naturalHeight*old;
    var px=x??stage.clientWidth/2,py=y??stage.clientHeight/2;
    var imageX=(stage.scrollLeft+px-Math.max(24,(stage.clientWidth-oldWidth)/2))/old;
    var imageY=(stage.scrollTop+py-Math.max(24,(stage.clientHeight-oldHeight)/2))/old;
    scale=Math.max(.05,Math.min(5,next));
    var width=img.naturalWidth*scale,height=img.naturalHeight*scale;
    canvas.style.width=Math.max(stage.clientWidth,width+48)+'px';canvas.style.height=Math.max(stage.clientHeight,height+48)+'px';
    img.style.width=width+'px';img.style.height=height+'px';
    img.style.left=Math.max(24,(stage.clientWidth-width)/2)+'px';img.style.top=Math.max(24,(stage.clientHeight-height)/2)+'px';
    stage.scrollLeft=imageX*scale+Math.max(24,(stage.clientWidth-width)/2)-px;stage.scrollTop=imageY*scale+Math.max(24,(stage.clientHeight-height)/2)-py;
    $('[data-scale]').textContent=Math.round(scale*100)+'%';$('[data-minus]').disabled=scale<=.05;$('[data-plus]').disabled=scale>=5;
  }
  function fit(){mode='fit';if(img.naturalWidth){layout(Math.min(1,(stage.clientWidth-48)/img.naturalWidth,(stage.clientHeight-48)/img.naturalHeight));stage.scrollLeft=0;stage.scrollTop=0;}}
  function zoom(factor,x,y){mode='custom';layout(scale*factor,x,y);}
  function clear(){active=null;img.removeAttribute('src');$('[data-download]').removeAttribute('href');drag=null;stage.classList.remove('dragging');}
  function close(){if(ownsFullscreen&&document.fullscreenElement&&document.exitFullscreen)document.exitFullscreen().catch(()=>{});if(dialog.open)dialog.close();clear();}
  dialog.addEventListener('close',()=>{clear();var focus=previousFocus;previousFocus=null;if(focus?.isConnected)focus.focus();});dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
  $('[data-close]').onclick=close;$('[data-minus]').onclick=()=>zoom(1/1.25);$('[data-plus]').onclick=()=>zoom(1.25);$('[data-fit]').onclick=fit;$('[data-original]').onclick=()=>{mode='custom';layout(1);};
  $('[data-full]').disabled=!document.documentElement.requestFullscreen;
  $('[data-full]').onclick=async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else{await document.documentElement.requestFullscreen();ownsFullscreen=true;}}catch(_){help.textContent='เปิดภาพขนาดเต็มหน้าต่างแล้ว เบราว์เซอร์นี้ไม่อนุญาตโหมดเต็มจอ';}};
  function resize(){if(!dialog.open)return;requestAnimationFrame(()=>{if(mode==='fit')fit();else layout(scale);});}
  document.addEventListener('fullscreenchange',()=>{if(!document.fullscreenElement)ownsFullscreen=false;$('[data-full]').textContent=document.fullscreenElement?'ออกจากเต็มจอ':'เต็มจอ';resize();});window.addEventListener('resize',resize);
  img.onload=()=>{if(active&&dialog.open){fit();help.textContent='กด + หรือ − เพื่อปรับขนาด · ลากเพื่อเลื่อนภาพ · Esc เพื่อปิด';}};
  img.onerror=()=>{help.textContent='ไม่สามารถแสดงรูปนี้ได้ กรุณาเปิดใหม่อีกครั้ง';};
  stage.addEventListener('wheel',e=>{e.preventDefault();var bounds=stage.getBoundingClientRect();zoom(e.deltaY<0?1.15:1/1.15,e.clientX-bounds.left,e.clientY-bounds.top);},{passive:false});
  stage.addEventListener('pointerdown',e=>{if(e.button!==0)return;drag={id:e.pointerId,x:e.clientX,y:e.clientY,left:stage.scrollLeft,top:stage.scrollTop};stage.setPointerCapture(e.pointerId);stage.classList.add('dragging');});
  stage.addEventListener('pointermove',e=>{if(!drag||drag.id!==e.pointerId)return;stage.scrollLeft=drag.left+drag.x-e.clientX;stage.scrollTop=drag.top+drag.y-e.clientY;});
  function endDrag(){drag=null;stage.classList.remove('dragging');}stage.addEventListener('pointerup',endDrag);stage.addEventListener('pointercancel',endDrag);stage.addEventListener('lostpointercapture',endDrag);
  dialog.addEventListener('keydown',e=>{if(e.key==='+'||e.key==='='){e.preventDefault();zoom(1.25);}else if(e.key==='-'){e.preventDefault();zoom(1/1.25);}else if(e.key==='0'){e.preventDefault();fit();}});
  window.EISImageViewer={open:function(message){
    if(!message?.image||message.retractedAt||!/^data:image\/(png|jpeg|webp);base64,/.test(message.image))return;
    previousFocus=document.activeElement;active={id:message.id,room:message.room};mode='fit';scale=1;
    $('[data-download]').href=message.image;$('[data-download]').download='chat-image-'+message.id+'.'+(message.image.startsWith('data:image/png')?'png':message.image.startsWith('data:image/webp')?'webp':'jpg');
    help.textContent='กำลังโหลดรูป…';if(!dialog.open)dialog.showModal();img.src=message.image;if(img.complete&&img.naturalWidth)fit();
  },close:close};
  if(window.EISMessengerPresence)EISMessengerPresence.on((type,data)=>{if(type==='chat-retracted'&&active?.id===data.message.id&&active.room===data.message.room)close();else if(['unauthorized','connected'].includes(type))close();});
}());
