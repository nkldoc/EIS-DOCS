(function () {
  'use strict';
  var root = document.getElementById('presence-control');
  if (!root) return;
  var restart = document.getElementById('presence-restart'), busy = false;
  async function refresh(method) {
    if (busy) return;
    busy = true; restart.disabled = true;
    try {
      var response = await fetch('presence-control.php', { method: method || 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-CSRF-Token': root.dataset.csrf }, signal: AbortSignal.timeout(15000) });
      var result = await response.json();
      if (!response.ok) throw new Error(result.error || ('HTTP ' + response.status));
      document.getElementById('presence-service-status').textContent = (result.pending ? 'รอรีสตาร์ทบริการ' : result.ready ? 'ตัวควบคุมพร้อมทำงาน' : 'ตัวควบคุมไม่ตอบสนอง') + ' · Task: ' + ((result.worker || {}).state || 'unknown') + ' · ดูสถานะการเชื่อมต่อ WebSocket ที่แถบด้านบน';
      document.getElementById('presence-service-log').textContent = Object.keys(result.logs).map(function (name) { return '=== ' + name + ' ===\n' + result.logs[name]; }).join('\n') || 'ยังไม่มี log';
      restart.disabled = !result.ready || result.pending;
    } catch (error) {
      document.getElementById('presence-service-status').textContent = error.message;
    } finally { busy = false; }
  }
  restart.addEventListener('click', function () {
    if (window.confirm('รีสตาร์ท WebSocket ทุกระบบ? ผู้ใช้งาน Messenger จะหลุดชั่วคราวและเชื่อมต่อกลับอัตโนมัติ')) refresh('POST');
  });
  document.getElementById('presence-refresh').addEventListener('click', function () { refresh(); });
  document.getElementById('presence-reconnect').addEventListener('click', function () { if (window.EISPresence) window.EISPresence.reconnect(); });
  setInterval(function () {
    if (window.EISPresence && window.EISPresence.diagnostics) document.getElementById('presence-browser-log').textContent = JSON.stringify(window.EISPresence.diagnostics(), null, 2);
  }, 2000);
  setInterval(function () { if (!document.hidden) refresh(); }, 10000);
  refresh();
}());
