(function () {
  'use strict';
  var panel, stopped = false, deciding = false;
  async function poll() {
    if (stopped) return;
    try {
      if (deciding) return;
      var response = await fetch('/supplies/access/login-approval', {cache:'no-store', credentials:'same-origin', signal:AbortSignal.timeout(10000)});
      if (response.status === 401) { stopped = true; if (panel) panel.remove(); return; }
      if (!response.ok) return;
      var data = await response.json(), request = data.request;
      if (!request || request.status !== 'pending') { if (panel) panel.remove(); panel = null; return; }
      if (panel && panel.dataset.nonce === request.nonce) return;
      if (panel) panel.remove();
      panel = document.createElement('section'); panel.dataset.nonce = request.nonce; panel.setAttribute('role','dialog'); panel.setAttribute('aria-label','คำขอเข้าสู่ระบบ');
      panel.style.cssText = 'position:fixed;right:24px;top:24px;z-index:2147483646;background:white;color:#17324d;padding:24px;border-radius:12px;box-shadow:0 8px 40px #0005;max-width:420px;font:16px Tahoma,sans-serif';
      var message = document.createElement('p'); message.textContent = 'มีผู้ขอเข้าสู่ระบบบัญชีนี้จากอุปกรณ์อื่น หากอนุญาต เซสชันนี้จะสิ้นสุดเมื่อผู้ขอเข้าสู่ระบบ';
      var device = document.createElement('p'); device.textContent = request.device; device.style.overflowWrap = 'anywhere'; panel.append(message, device);
      var feedback = document.createElement('p'); feedback.setAttribute('role','status'); panel.appendChild(feedback);
      [['approved','อนุญาต'],['denied','ปฏิเสธ']].forEach(function(choice){
        var button = document.createElement('button'); button.textContent = choice[1]; button.style.cssText = 'padding:10px 20px;margin:5px;cursor:pointer';
        button.onclick = async function(){
          if (deciding) return;
          deciding = true;
          var currentPanel = panel, buttons = currentPanel.querySelectorAll('button'); buttons.forEach(function(b){b.disabled=true;});
          try {
            var result = await fetch('/supplies/access/login-approval', {method:'POST', credentials:'same-origin', signal:AbortSignal.timeout(10000), body:new URLSearchParams({csrf:data.csrf,nonce:request.nonce,decision:choice[0]})});
            if (result.status === 409) { currentPanel.remove(); if (panel === currentPanel) panel = null; return; }
            if (!result.ok) throw new Error('ไม่สามารถส่งผลได้ กรุณาลองใหม่');
            currentPanel.remove(); if (panel === currentPanel) panel = null;
          } catch (error) { feedback.textContent = 'การเชื่อมต่อขัดข้อง กรุณาลองใหม่'; buttons.forEach(function(b){b.disabled=false;}); }
          finally { deciding = false; }
        }; panel.appendChild(button);
      }); document.body.appendChild(panel);
    } catch (error) {} finally { if (!stopped) setTimeout(poll,1000); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',poll); else poll();
})();
